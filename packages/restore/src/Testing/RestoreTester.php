<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Testing;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SWBackupIt\Core\Crypto\KeyProviderInterface;
use SWBackupIt\Core\Database\ConnectionConfig;
use SWBackupIt\Core\Database\ConnectionFactory;
use SWBackupIt\Core\Database\DatabaseExporter;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Files\OverwriteMode;
use SWBackupIt\Restore\RestoreEngine;
use SWBackupIt\Restore\RestoreOutcome;
use SWBackupIt\Restore\RestoreRequest;

/**
 * Automated test restore in an isolated environment (PLAN.md §22): files go to a private temp directory, the database to a
 * freshly created scratch database that is dropped afterwards. No production file, table or setting is touched.
 *
 * "passed" proves the backup can actually be restored and the restored data matches the manifest; it still does not prove
 * that the restored site behaves correctly (plugins, server differences). Without privileges to create a scratch database
 * the database part is reported as "skipped" and the overall result is "partial".
 */
final class RestoreTester
{
    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * @param ConnectionConfig|null $scratchAdmin Account that may CREATE/DROP DATABASE (null = skip the database check).
     */
    public function run(string $backupDirectory, ?KeyProviderInterface $keys, string $workDirectory, ?ConnectionConfig $scratchAdmin = null): RestoreTestResult
    {
        $result = new RestoreTestResult();
        $started = microtime(true);
        $root = rtrim($workDirectory, '/') . '/restore-test-' . bin2hex(random_bytes(5));
        AtomicFile::ensureDirectory($root . '/files');
        $scratch = null;
        try {
            $set = BackupSet::open($backupDirectory, $keys, $root . '/decrypted');
            $manifest = $set->manifest();
            $hasDatabase = $manifest->hasDatabase();
            $database = null;
            if ($hasDatabase) {
                if ($scratchAdmin === null) {
                    $result->add('database', 'skipped', 'No account with CREATE DATABASE privilege available.');
                } else {
                    $scratch = $this->createScratchDatabase($scratchAdmin, $result);
                    $database = $scratch === null ? null : new ConnectionConfig($scratchAdmin->host, $scratch, $scratchAdmin->user, $scratchAdmin->password, $scratchAdmin->port, $scratchAdmin->socket, $scratchAdmin->charset);
                }
            }
            $request = new RestoreRequest(
                $backupDirectory,
                $root . '/files',
                (string) realpath($root . '/files'),
                $root . '/state',
                $manifest->hasFiles(),
                $database !== null,
                $database,
                $database === null ? '' : $database->name,
                false,
                OverwriteMode::Always,
                keys: $keys,
            );
            $engine = new RestoreEngine($this->logger);
            do {
                $outcome = $engine->run($request, new ResourceBudget(60.0));
            } while ($outcome->status === RestoreOutcome::CONTINUE);
            $result->add('restore', $outcome->isSuccess() ? 'passed' : 'failed', $outcome->isSuccess() ? 'Files and database restored into the isolated environment.' : implode('; ', $outcome->messages));
            if ($outcome->isSuccess()) {
                $this->checkFiles($set, $root . '/files', $result);
                if ($database !== null) {
                    $this->checkDatabase($set, $database, $result);
                }
            }
            $set->close();
        } catch (SwbiException $exception) {
            $result->add('restore', 'failed', $exception->getMessage());
        } finally {
            if ($scratch !== null && $scratchAdmin !== null) {
                $this->dropScratchDatabase($scratchAdmin, $scratch);
            }
            AtomicFile::removeTree($root);
            $result->seconds = microtime(true) - $started;
        }

        return $result;
    }

    private function createScratchDatabase(ConnectionConfig $admin, RestoreTestResult $result): ?string
    {
        $name = 'swbi_rt_' . bin2hex(random_bytes(5));
        try {
            $pdo = new PDO(
                'mysql:' . ($admin->socket !== null ? 'unix_socket=' . $admin->socket : 'host=' . $admin->host . ';port=' . $admin->port) . ';charset=' . $admin->charset,
                $admin->user,
                $admin->password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $pdo->exec('CREATE DATABASE ' . DatabaseExporter::id($name) . ' CHARACTER SET utf8mb4');
        } catch (\PDOException) {
            $result->add('database', 'skipped', 'The scratch database could not be created (missing privilege).');

            return null;
        }

        return $name;
    }

    private function dropScratchDatabase(ConnectionConfig $admin, string $name): void
    {
        if (!str_starts_with($name, 'swbi_rt_')) {
            return; // Safety net: only ever drop databases this tester created.
        }
        try {
            $pdo = new PDO(
                'mysql:' . ($admin->socket !== null ? 'unix_socket=' . $admin->socket : 'host=' . $admin->host . ';port=' . $admin->port),
                $admin->user,
                $admin->password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $pdo->exec('DROP DATABASE IF EXISTS ' . DatabaseExporter::id($name));
        } catch (\PDOException $exception) {
            $this->logger->warning('The scratch database could not be dropped.', ['database' => $name]);
        }
    }

    private function checkFiles(BackupSet $set, string $directory, RestoreTestResult $result): void
    {
        if (!$set->manifest()->hasFiles()) {
            return;
        }
        $expected = 0;
        $missing = [];
        foreach ($set->indexReader()->read() as [$entry]) {
            if ($entry['t'] !== 'f' || in_array($entry['st'] ?? 'ok', ['unreadable', 'vanished'], true)) {
                continue;
            }
            $expected++;
            if (!is_file($directory . '/' . $entry['p']) && count($missing) < 5) {
                $missing[] = (string) $entry['p'];
            }
        }
        $result->add('files', $missing === [] ? 'passed' : 'failed', $missing === [] ? sprintf('%d files present and checksummed.', $expected) : 'Missing after restore: ' . implode(', ', $missing));
    }

    private function checkDatabase(BackupSet $set, ConnectionConfig $database, RestoreTestResult $result): void
    {
        $pdo = ConnectionFactory::create($database);
        $tables = (array) ($set->manifest()->section('database')['tables'] ?? []);
        $problems = [];
        $checked = 0;
        foreach ($tables as $table) {
            if (($table['type'] ?? 'table') !== 'table') {
                continue;
            }
            $statement = $pdo->query('SELECT COUNT(*) FROM ' . DatabaseExporter::id((string) $table['name']));
            $rows = $statement === false ? -1 : (int) $statement->fetchColumn();
            $checked++;
            if (isset($table['exported_rows']) && $rows !== (int) $table['exported_rows']) {
                $problems[] = sprintf('%s: %d rows restored, %d exported', $table['name'], $rows, $table['exported_rows']);
            }
        }
        $result->add('database', $problems === [] ? 'passed' : 'failed', $problems === [] ? sprintf('%d tables restored with the exported row counts.', $checked) : implode('; ', array_slice($problems, 0, 5)));
        $prefix = (string) ($set->manifest()->section('source')['table_prefix'] ?? '');
        if ($prefix !== '') {
            $options = $pdo->query("SHOW TABLES LIKE '" . str_replace(['_', '%'], ['\\_', '\\%'], $prefix) . "options'");
            if ($options !== false && $options->fetch() !== false) {
                $url = $pdo->query('SELECT option_value FROM ' . DatabaseExporter::id($prefix . 'options') . " WHERE option_name = 'siteurl'");
                $found = $url !== false ? $url->fetchColumn() : false;
                $result->add('wordpress', is_string($found) && $found !== '' ? 'passed' : 'failed', is_string($found) && $found !== '' ? 'The WordPress options table is readable (siteurl present).' : 'siteurl option missing in the restored database.');
            }
        }
    }
}
