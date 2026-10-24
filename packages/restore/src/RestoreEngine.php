<?php

declare(strict_types=1);

namespace SWBackupIt\Restore;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SWBackupIt\Core\Database\ConnectionFactory;
use SWBackupIt\Core\Event\EventDispatcher;
use SWBackupIt\Core\Event\EventDispatcherInterface;
use SWBackupIt\Core\Event\Events;
use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\Restore\Config\WpConfigEditor;
use SWBackupIt\Restore\Files\FileRestorer;
use SWBackupIt\Restore\Files\RestoreOptions;
use SWBackupIt\Restore\Migration\DatabaseMigrator;
use SWBackupIt\Restore\Migration\TablePrefixMigrator;
use SWBackupIt\Restore\Sql\SqlImporter;
use Throwable;

/**
 * Orchestrates a restore: verify -> files -> database -> wp-config -> done.
 *
 * The engine is resumable (state in RestoreStateStore) and never reports success if any phase failed.
 * Strategy instead of a promised rollback: verification first, atomic per-file replacement,
 * and phase checkpoints so a failed restore can be continued or repeated idempotently.
 */
final class RestoreEngine
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly EventDispatcherInterface $events = new EventDispatcher(),
    ) {
    }

    /**
     * Executes (or continues) a restore for at most the given time budget.
     *
     * @param callable(string, array<string, mixed>): void|null $onProgress
     */
    public function run(RestoreRequest $request, ResourceBudget $budget, ?callable $onProgress = null): RestoreOutcome
    {
        $store = new RestoreStateStore($request->stateDirectory);
        $state = $store->load();
        if ($state !== [] && ($state['fingerprint'] ?? null) !== $request->fingerprint()) {
            // State of a different restore (other target, database, selection, ...): never continue it for this request.
            $this->logger->notice('Discarding restore state of a different restore request.');
            $store->clear();
            $state = [];
        }
        $phase = (string) ($state['phase'] ?? 'start');
        $set = null;
        try {
            $set = BackupSet::open($request->backupDirectory, $request->keys, rtrim($request->stateDirectory, '/') . '/decrypted');
            $target = $this->confirmTarget($request, $set);
            if ($phase === 'start') {
                $this->events->dispatch(Events::BEFORE_RESTORE, ['backup_id' => $set->manifest()->backupId(), 'target' => $target]);
                $state = ['phase' => $request->verifyFirst ? 'verify' : 'files', 'started' => time(), 'fingerprint' => $request->fingerprint()];
                $store->save($state);
            }
            while (true) {
                $phase = (string) $state['phase'];
                if ($phase === 'verify') {
                    $this->verify($set);
                    $state['phase'] = 'files';
                    $store->save($state);
                } elseif ($phase === 'files') {
                    if ($request->restoreFiles && $set->manifest()->hasFiles()) {
                        $result = (new FileRestorer($set, $this->options($request, $target), $this->logger))->run(
                            is_array($state['files'] ?? null) ? $state['files'] : [],
                            $budget,
                            function (array $fileState) use (&$state, $store): void {
                                $state['files'] = $fileState;
                                $store->save($state);
                            },
                            $onProgress === null ? null : static fn (string $path, array $s) => $onProgress('files', ['path' => $path] + $s),
                        );
                        $state['files'] = $result->state;
                        if ($result->failures !== []) {
                            // The file phase starts over on the next run so the failed entries are retried (files restored
                            // correctly meanwhile are skipped as identical in the default overwrite mode).
                            unset($state['files']);
                            $store->save($state);

                            return $this->failed($set, 'files', $result->failures, $state);
                        }
                        if (!$result->finished) {
                            $store->save($state);
                            $set->close();

                            return new RestoreOutcome(RestoreOutcome::CONTINUE, 'files', [], $state);
                        }
                    }
                    $state['phase'] = 'database';
                    $store->save($state);
                } elseif ($phase === 'database') {
                    if ($request->restoreDatabase && $set->manifest()->hasDatabase() && !$request->dryRun) {
                        $done = $this->importDatabase($request, $set, $state, $store, $budget, $onProgress);
                        if (!$done) {
                            $set->close();

                            return new RestoreOutcome(RestoreOutcome::CONTINUE, 'database', [], $state);
                        }
                    }
                    $state['phase'] = 'migrate';
                    $store->save($state);
                } elseif ($phase === 'migrate') {
                    $migrated = $this->migrate($request, $set, $state, $store, $budget);
                    if (!$migrated) {
                        $set->close();

                        return new RestoreOutcome(RestoreOutcome::CONTINUE, 'migrate', [], $state);
                    }
                    $state['phase'] = 'config';
                    $store->save($state);
                } elseif ($phase === 'config') {
                    $messages = [...$this->fileNotes($state), ...$this->updateConfig($request, $set, $target)];
                    $state['phase'] = 'done';
                    $state['messages'] = $messages;
                    $store->save($state);
                } else {
                    break;
                }
            }
            $this->events->dispatch(Events::AFTER_RESTORE, ['backup_id' => $set->manifest()->backupId(), 'target' => $target]);
            $summary = $state;
            $set->close();
            $store->clear();

            return new RestoreOutcome(RestoreOutcome::COMPLETED, 'done', $state['messages'] ?? [], $summary);
        } catch (Throwable $throwable) {
            $set?->close();
            $message = $throwable instanceof SwbiException ? $throwable->getMessage() : 'Unexpected restore error.';
            $this->logger->error($message, ['exception' => $throwable::class]);
            $this->events->dispatch(Events::RESTORE_FAILED, ['error' => $message]);

            return new RestoreOutcome(RestoreOutcome::FAILED, $phase, [$message], $state);
        }
    }

    /**
     * Resolves and validates the target directory and the explicit confirmations.
     */
    private function confirmTarget(RestoreRequest $request, BackupSet $set): string
    {
        $target = realpath($request->targetPath);
        if ($target === false || !is_dir($target)) {
            throw new ConfigurationException('The restore target directory does not exist.');
        }
        if ($request->confirmedTargetPath !== $target) {
            throw new ConfigurationException('The target path was not confirmed correctly (expected: ' . $target . ').');
        }
        if ($target === '/' || $target === dirname($target)) {
            throw new ConfigurationException('Refusing to restore into the filesystem root.');
        }
        $backup = $set->directory();
        if ($target === $backup || str_starts_with($backup . '/', $target . '/') || str_starts_with($target . '/', $backup . '/')) {
            throw new ConfigurationException('The restore target and the backup directory must not contain each other.');
        }
        if ($request->restoreDatabase && $set->manifest()->hasDatabase() && !$request->dryRun) {
            if ($request->database === null) {
                throw new ConfigurationException('A database connection is required to restore the database.');
            }
            if ($request->confirmedDatabase !== $request->database->name) {
                throw new ConfigurationException('The target database name was not confirmed correctly.');
            }
            if ($request->databaseTables !== null) {
                $known = array_map(
                    static fn (mixed $table): string => is_array($table) ? (string) ($table['name'] ?? '') : '',
                    (array) ($set->manifest()->section('database')['tables'] ?? []),
                );
                if ($request->databaseTables === [] || array_diff($request->databaseTables, $known) !== []) {
                    throw new ConfigurationException('The selected tables are not part of the backup.', ['tables' => implode(',', array_diff($request->databaseTables, $known))]);
                }
            }
        }

        return $target;
    }

    private function options(RestoreRequest $request, string $target): RestoreOptions
    {
        return new RestoreOptions($target, $request->overwrite, $request->selection, $request->dryRun, true, $request->limits);
    }

    private function verify(BackupSet $set): void
    {
        $report = (new Verifier())->verify($set);
        if (!$report->isOk()) {
            throw new ConfigurationException('Backup verification failed: ' . implode('; ', array_slice($report->errors(), 0, 5)));
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param callable(string, array<string, mixed>): void|null $onProgress
     * @return bool true when the database phase is complete
     */
    private function importDatabase(RestoreRequest $request, BackupSet $set, array &$state, RestoreStateStore $store, ResourceBudget $budget, ?callable $onProgress): bool
    {
        $config = $request->database;
        if ($config === null) {
            throw new ConfigurationException('A database connection is required to restore the database.');
        }
        $pdo = ConnectionFactory::create($config);
        $importer = new SqlImporter($pdo, $this->logger, $request->databaseTables);
        $parts = $set->manifest()->databaseParts();
        $db = is_array($state['database'] ?? null) ? $state['database'] : ['part' => 0, 'statements' => 0];
        if ($db === ['part' => 0, 'statements' => 0]) {
            // A fresh import: progress of an earlier, interrupted URL migration in this database is obsolete.
            SqlImporter::reset($pdo);
            DatabaseMigrator::reset($pdo);
        }
        while ($db['part'] < count($parts)) {
            $part = $parts[$db['part']];
            $path = $set->databasePartPath((string) $part['name']);
            $importer->importPart($path, (int) $db['statements'], function (int $count) use (&$db, &$state, $store, $part, $onProgress): void {
                $db['statements'] = $count;
                $state['database'] = $db;
                $store->save($state);
                if ($onProgress !== null) {
                    $onProgress('database', ['part' => $part['name'], 'statements' => $count]);
                }
            });
            $db = ['part' => $db['part'] + 1, 'statements' => 0];
            $state['database'] = $db;
            $store->save($state);
            if ($db['part'] < count($parts) && $budget->exhausted()) {
                return false;
            }
        }
        $importer->finalize();

        return true;
    }

    /**
     * URL/path and table prefix migration (after the import). Resumable; see DatabaseMigrator.
     *
     * @param array<string, mixed> $state
     * @return bool true when complete
     */
    private function migrate(RestoreRequest $request, BackupSet $set, array &$state, RestoreStateStore $store, ResourceBudget $budget): bool
    {
        $wanted = $request->replacements !== [] || $request->newTablePrefix !== null;
        if (!$wanted || !$request->restoreDatabase || $request->database === null || !$set->manifest()->hasDatabase()) {
            return true;
        }
        $pdo = ConnectionFactory::create($request->database);
        $oldPrefix = (string) ($set->manifest()->section('source')['table_prefix'] ?? '');
        $prefix = $oldPrefix;
        if ($request->newTablePrefix !== null && $request->newTablePrefix !== $oldPrefix && !$request->migrationDryRun) {
            if (($state['prefix_migrated'] ?? false) !== true) {
                $backupTables = array_values(array_filter(array_map(
                    static fn (mixed $table): string => is_array($table) ? (string) ($table['name'] ?? '') : '',
                    (array) ($set->manifest()->section('database')['tables'] ?? []),
                )));
                $only = $request->databaseTables ?? ($backupTables !== [] ? $backupTables : null);
                $count = (new TablePrefixMigrator($pdo))->migrate($oldPrefix, $request->newTablePrefix, $only);
                $state['prefix_migrated'] = true;
                $store->save($state);
                $this->logger->info('Table prefix migrated.', ['tables' => $count]);
            }
            $prefix = $request->newTablePrefix;
        }
        if ($request->replacements !== []) {
            // Selective restore: only the restored tables are migrated (under their possibly renamed prefix).
            $tables = $request->databaseTables === null ? [] : array_map(
                static fn (string $table): string => str_starts_with($table, $oldPrefix) ? $prefix . substr($table, strlen($oldPrefix)) : $table,
                $request->databaseTables,
            );
            $result = (new DatabaseMigrator($pdo, $this->logger))->run($request->replacements, $prefix, $tables, $request->migrationDryRun, $budget);
            $state['migration'] = $result->toArray();
            $store->save($state);

            return $result->finished;
        }

        return true;
    }

    /**
     * Notes about files that need the user's attention: symlinks are never recreated (security policy), files that changed
     * while they were archived may be inconsistent.
     *
     * @param array<string, mixed> $state
     * @return list<string>
     */
    private function fileNotes(array $state): array
    {
        $files = is_array($state['files'] ?? null) ? $state['files'] : [];
        $notes = [];
        $groups = [
            'symlinks' => ['symlink_list', 'Symbolic links from the backup were not recreated (security policy). Recreate them manually if needed:'],
            'changed' => ['changed_list', 'These files changed while the backup was running; their restored content may be inconsistent:'],
        ];
        foreach ($groups as $counter => [$listKey, $headline]) {
            $count = (int) ($files[$counter] ?? 0);
            if ($count === 0) {
                continue;
            }
            $list = array_values((array) ($files[$listKey] ?? []));
            $notes[] = $headline;
            foreach ($list as $item) {
                $notes[] = '  ' . (string) $item;
            }
            if ($count > count($list)) {
                $notes[] = '  … (+' . ($count - count($list)) . ')';
            }
        }

        return $notes;
    }

    /**
     * @return list<string> Warnings/notes for the user.
     */
    private function updateConfig(RestoreRequest $request, BackupSet $set, string $target): array
    {
        $messages = [];
        if (!$request->restoreFiles || !$request->updateWpConfig || $request->dryRun || $request->database === null) {
            return $messages;
        }
        $path = $target . '/wp-config.php';
        if (!is_file($path)) {
            $messages[] = 'wp-config.php was not found in the target; database settings were not updated.';

            return $messages;
        }
        $db = $request->database;
        $host = $db->socket !== null ? $db->host . ':' . $db->socket : ($db->port !== 3306 ? $db->host . ':' . $db->port : $db->host);
        $prefix = $request->newTablePrefix ?? (string) ($set->manifest()->section('source')['table_prefix'] ?? '');
        $constants = ['DB_NAME' => $db->name, 'DB_USER' => $db->user, 'DB_PASSWORD' => $db->password, 'DB_HOST' => $host];
        $source = $set->manifest()->section('source');
        foreach (['WP_HOME' => 'home_url', 'WP_SITEURL' => 'site_url'] as $constant => $key) {
            $old = (string) ($source[$key] ?? '');
            if ($old !== '' && isset($request->replacements[$old])) {
                $constants[$constant] = $request->replacements[$old];
            }
        }
        $changed = (new WpConfigEditor($path))->update($constants, $prefix !== '' ? $prefix : null);
        $this->logger->info('wp-config.php updated.', ['changed' => $changed]);
        // Constants defined with expressions (getenv(), other constants, ...) cannot be rewritten safely. The restored copy could
        // otherwise silently keep using the original database, so the user has to check them.
        $missing = array_values(array_diff(['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'], $changed));
        if ($missing !== []) {
            $messages[] = 'Some database settings in wp-config.php are not plain values and were NOT updated. Check them before using the site:';
            foreach ($missing as $constant) {
                $messages[] = '  ' . $constant;
            }
        }

        return $messages;
    }

    /**
     * @param list<string> $failures
     * @param array<string, mixed> $state
     */
    private function failed(BackupSet $set, string $phase, array $failures, array $state): RestoreOutcome
    {
        $this->events->dispatch(Events::RESTORE_FAILED, ['backup_id' => $set->manifest()->backupId(), 'phase' => $phase]);

        return new RestoreOutcome(RestoreOutcome::FAILED, $phase, array_slice($failures, 0, 20), $state);
    }
}
