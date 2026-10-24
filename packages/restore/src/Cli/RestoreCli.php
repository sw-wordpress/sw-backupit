<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Cli;

use SWBackupIt\Core\Crypto\EnvironmentKeyProvider;
use SWBackupIt\Core\Crypto\KeyProviderInterface;
use SWBackupIt\Core\Crypto\StaticKeyProvider;
use SWBackupIt\Core\Database\ConnectionConfig;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Storage\BackupDownloader;
use SWBackupIt\Core\Storage\StorageFactory;
use SWBackupIt\Core\Storage\StorageTarget;
use SWBackupIt\Core\Support\Bytes;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\Restore\Files\Comparator;
use SWBackupIt\Restore\Files\ComparisonResult;
use SWBackupIt\Restore\Files\OverwriteMode;
use SWBackupIt\Restore\Files\Selection;
use SWBackupIt\Restore\RestoreEngine;
use SWBackupIt\Restore\RestoreOutcome;
use SWBackupIt\Restore\RestoreRequest;
use SWBackupIt\Restore\Web\WebSetup;

/**
 * Standalone restore command line (no WordPress, no WP-CLI).
 *
 * Exit codes: 0 success, 1 failure/verification error, 2 usage error.
 */
final class RestoreCli
{
    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(private $stdout = STDOUT, private $stderr = STDERR)
    {
    }

    /**
     * @param list<string> $argv Without the script name.
     */
    public function run(array $argv): int
    {
        $args = new ArgumentParser(
            $argv,
            ['target', 'confirm-target', 'tables', 'state-dir', 'db-host', 'db-name', 'db-user', 'db-password-file', 'confirm-database', 'overwrite', 'format', 'db-port', 'db-socket', 'key', 'key-file', 'new-prefix', 'mode', 'app-dir', 'backups', 'ttl', 'allow-target', 'storage', 'to'],
            ['path', 'pattern', 'replace', 'allow-target'],
        );
        $command = $args->positional[0] ?? 'help';
        $json = $args->string('format') === 'json';

        try {
            return match ($command) {
                'info' => $this->info($args, $json),
                'verify' => $this->verify($args, $json),
                'list' => $this->list($args),
                'restore' => $this->restore($args, $json),
                'compare' => $this->compare($args, $json),
                'web-init' => $this->webInit($args),
                'fetch' => $this->fetch($args, $json),
                'help', '--help' => $this->help(),
                default => $this->usage('Unknown command: ' . $command),
            };
        } catch (SwbiException $exception) {
            return $this->fail($exception->getMessage(), $json);
        }
    }

    private function keys(ArgumentParser $args): KeyProviderInterface
    {
        $value = $args->string('key');
        $file = $args->string('key-file');
        if ($file !== '') {
            $contents = is_file($file) ? file_get_contents($file) : false;
            $value = $contents === false ? '' : rtrim($contents, "\r\n");
        }
        if ($value === '' && is_string(getenv('SWBI_KEY')) && getenv('SWBI_KEY') !== '') {
            $value = (string) getenv('SWBI_KEY');
        }

        return $value !== '' ? new StaticKeyProvider($value) : new EnvironmentKeyProvider();
    }

    private function info(ArgumentParser $args, bool $json): int
    {
        $set = BackupSet::open($this->backupArgument($args));
        $manifest = $set->manifest();
        $summary = [
            'backup_id' => $manifest->backupId(),
            'type' => $manifest->type()->value,
            'created_at' => $manifest->createdAt(),
            'encrypted' => $manifest->isEncrypted(),
            'volumes' => count($manifest->volumes()),
            'database_parts' => count($manifest->databaseParts()),
            'files' => $manifest->section('files')['count'] ?? 0,
            'bytes' => $manifest->section('files')['bytes'] ?? 0,
            'site_url' => $manifest->section('source')['site_url'] ?? '',
            'wp_version' => $manifest->section('source')['wp_version'] ?? '',
            'table_prefix' => $manifest->section('source')['table_prefix'] ?? '',
        ];
        if ($json) {
            $this->out(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            return 0;
        }
        foreach ($summary as $key => $value) {
            $this->out(sprintf("%-15s %s\n", $key, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value));
        }

        return 0;
    }

    private function verify(ArgumentParser $args, bool $json): int
    {
        $report = (new Verifier())->verify(BackupSet::open($this->backupArgument($args), $this->keys($args)), $args->flag('deep'));
        if ($json) {
            $this->out(json_encode($report->toArray(), JSON_PRETTY_PRINT) . "\n");
        } elseif ($report->isOk()) {
            $this->out(sprintf("OK: %d volumes, %d database parts, %d files verified.\n", $report->volumesChecked, $report->databasePartsChecked, $report->filesChecked));
        } else {
            foreach ($report->errors() as $error) {
                $this->err('ERROR: ' . $error . "\n");
            }
        }

        return $report->isOk() ? 0 : 1;
    }

    private function list(ArgumentParser $args): int
    {
        $set = BackupSet::open($this->backupArgument($args), $this->keys($args));
        $selection = new Selection($args->list('path'), $args->list('pattern'));
        foreach ($set->indexReader()->read() as [$entry]) {
            if ($selection->matches((string) $entry['p'])) {
                $this->out(sprintf("%s %10s %s\n", $entry['t'], $entry['t'] === 'f' ? Bytes::format((int) $entry['s']) : '-', $entry['p']));
            }
        }

        return 0;
    }

    private function restore(ArgumentParser $args, bool $json): int
    {
        $target = $args->string('target');
        if ($target === '') {
            return $this->usage('--target is required.');
        }
        $database = null;
        $restoreDatabase = !$args->flag('no-database');
        if ($restoreDatabase && $args->string('db-name') !== '') {
            $database = $this->databaseConfig($args);
        }
        $overwrite = OverwriteMode::tryFrom($args->string('overwrite', 'if_different'));
        if ($overwrite === null) {
            return $this->usage('--overwrite must be one of: always, never, if_different.');
        }
        $request = new RestoreRequest(
            $this->backupArgument($args),
            $target,
            $args->string('confirm-target'),
            $args->string('state-dir', sys_get_temp_dir() . '/swbackupit-restore-' . substr(hash('sha256', $target), 0, 12)),
            !$args->flag('no-files'),
            $restoreDatabase && $database !== null,
            $database,
            $args->string('confirm-database'),
            !$args->flag('keep-wp-config'),
            $overwrite,
            new Selection($args->list('path'), $args->list('pattern')),
            $args->flag('dry-run'),
            !$args->flag('skip-verify'),
            keys: $this->keys($args),
            replacements: $this->replacements($args),
            newTablePrefix: $args->string('new-prefix') !== '' ? $args->string('new-prefix') : null,
            migrationDryRun: $args->flag('migration-dry-run'),
            databaseTables: $args->string('tables') === '' ? null : array_values(array_filter(array_map('trim', explode(',', $args->string('tables'))), static fn (string $t): bool => $t !== '')),
        );
        $engine = new RestoreEngine(new ConsoleLogger($this->stderr, $args->flag('verbose')));
        do {
            $outcome = $engine->run($request, new ResourceBudget(30.0), function (string $phase, array $info) use ($json): void {
                if (!$json && isset($info['path']) && ($info['files'] ?? 0) % 100 === 0) {
                    $this->err(sprintf("[%s] %s\n", $phase, (string) $info['path']));
                }
            });
        } while ($outcome->status === RestoreOutcome::CONTINUE);

        if ($json) {
            $result = ['status' => $outcome->status, 'phase' => $outcome->phase, 'messages' => $outcome->messages, 'summary' => $outcome->summary];
            $this->out(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        } elseif ($outcome->isSuccess()) {
            $this->out("Restore completed successfully.\n");
            foreach ($outcome->messages as $message) {
                $this->out('Note: ' . $message . "\n");
            }
        } else {
            $this->err('Restore FAILED in phase "' . $outcome->phase . "\":\n");
            foreach ($outcome->messages as $message) {
                $this->err(' - ' . $message . "\n");
            }
            $this->err("The restore state was kept; fix the problem and run the same command again to continue.\n");
        }

        return $outcome->isSuccess() ? 0 : 1;
    }

    /**
     * @return array<string, string>
     */
    private function replacements(ArgumentParser $args): array
    {
        $pairs = [];
        foreach ($args->list('replace') as $pair) {
            $position = strpos($pair, '=>');
            if ($position === false || $position === 0) {
                throw new \SWBackupIt\Core\Exception\ConfigurationException('--replace expects "old=>new" (e.g. --replace="https://old.example=>https://new.example/wp").');
            }
            $pairs[substr($pair, 0, $position)] = substr($pair, $position + 2);
        }

        return $pairs;
    }

    private function compare(ArgumentParser $args, bool $json): int
    {
        $target = $args->string('target');
        if ($target === '' || !is_dir($target)) {
            return $this->usage('--target must be an existing directory.');
        }
        $mode = $args->string('mode', Comparator::MODE_METADATA);
        if (!in_array($mode, [Comparator::MODE_METADATA, Comparator::MODE_SHA256], true)) {
            return $this->usage('--mode must be metadata or sha256.');
        }
        $set = BackupSet::open($this->backupArgument($args), $this->keys($args));
        try {
            $result = (new Comparator())->compare($set, $target, $mode, new Selection($args->list('path'), $args->list('pattern')));
        } finally {
            $set->close();
        }
        if ($json) {
            $this->out(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            return 0;
        }
        foreach (ComparisonResult::CATEGORIES as $category) {
            $this->out(sprintf("%-15s %d\n", $category, $result->counts[$category]));
            foreach (array_slice($result->samples[$category], 0, 10) as $path) {
                $this->out('    ' . $path . "\n");
            }
        }

        return 0;
    }

    /**
     * Downloads a backup (optionally with its whole chain) from a storage target described in a JSON file:
     * {"type":"s3|sftp|local","config":{…},"credentials":{…}} (the file should be readable only by you).
     */
    private function fetch(ArgumentParser $args, bool $json): int
    {
        $file = $args->string('storage');
        $backupId = $args->positional[1] ?? '';
        $to = $args->string('to');
        if ($file === '' || $to === '' || !is_file($file)) {
            return $this->usage('fetch needs <backup-id>, --storage=<target.json> and --to=<directory>.');
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['type'])) {
            return $this->usage('The storage file is not valid JSON with a "type".');
        }
        $target = new StorageTarget(0, 'cli', (string) $data['type'], (array) ($data['config'] ?? []), array_map('strval', (array) ($data['credentials'] ?? [])));
        $storage = (new StorageFactory())->create($target);
        $downloader = new BackupDownloader();
        $progress = function (string $relative): void {
            $this->err('downloading ' . $relative . "\n");
        };
        $ids = $args->flag('with-chain')
            ? $downloader->downloadWithAncestors($storage, $backupId, $to, $progress)
            : [basename($downloader->download($storage, $backupId, $to, $progress))];
        $this->out($json ? json_encode(['downloaded' => $ids]) . "\n" : 'Downloaded: ' . implode(', ', $ids) . "\n");

        return 0;
    }

    private function webInit(ArgumentParser $args): int
    {
        $app = $args->string('app-dir', dirname(__DIR__, 2));
        $token = WebSetup::initialize($app, $args->string('backups'), (int) $args->string('ttl', '3600'), null, $args->list('allow-target'));
        $this->out("Web restore wizard initialised in {$app}.\nOne-time token (shown only now, valid " . intdiv((int) $args->string('ttl', '3600'), 60) . " minutes):\n\n    {$token}\n\n");
        $this->out("Open the wizard in your browser (over HTTPS if possible). It locks itself after a successful restore.\n");

        return 0;
    }

    private function databaseConfig(ArgumentParser $args): ConnectionConfig
    {
        $password = getenv('SWBI_DB_PASSWORD');
        $file = $args->string('db-password-file');
        if ($file !== '') {
            $contents = is_file($file) ? file_get_contents($file) : false;
            $password = $contents === false ? '' : rtrim($contents, "\r\n");
        }

        return new ConnectionConfig(
            $args->string('db-host', 'localhost'),
            $args->string('db-name'),
            $args->string('db-user', 'root'),
            $password === false ? '' : $password,
            (int) $args->string('db-port', '3306'),
            $args->string('db-socket') !== '' ? $args->string('db-socket') : null,
        );
    }

    private function backupArgument(ArgumentParser $args): string
    {
        return $args->positional[1] ?? throw new \SWBackupIt\Core\Exception\ConfigurationException('Backup directory argument is missing.');
    }

    private function help(): int
    {
        $this->out(<<<'TEXT'
SWBackupIt standalone restore

Usage:
  swbackupit-restore info    <backup-dir> [--format=json]
  swbackupit-restore verify  <backup-dir> [--deep] [--format=json]
  swbackupit-restore list    <backup-dir> [--path=wp-content/uploads] [--pattern='**/*.jpg']
  swbackupit-restore fetch   <backup-id> --storage=target.json --to=/path/to/backups [--with-chain]   (download from a storage target)
  swbackupit-restore web-init --backups=/path/to/backups [--app-dir=DIR] [--ttl=3600] [--allow-target=/var/www]   (prepares the web wizard)
  swbackupit-restore compare <backup-dir> --target=/var/www/site [--mode=metadata|sha256] [--path=...] [--format=json]
  swbackupit-restore restore <backup-dir> --target=/var/www/site --confirm-target=/var/www/site
        [--db-host=localhost --db-name=NAME --db-user=USER --confirm-database=NAME]
        [--overwrite=always|never|if_different] [--path=...] [--pattern=...]
        [--no-files] [--no-database] [--keep-wp-config] [--skip-verify] [--dry-run] [--state-dir=DIR] [--format=json]
        [--tables=wp_posts,wp_postmeta]   (selective database restore: only these tables, no views/triggers/routines)
        [--replace='https://old.example=>https://new.example/wp' ...] [--new-prefix=blog_] [--migration-dry-run]
        [--key=PASSPHRASE|base64:KEY | --key-file=FILE]      (encrypted backups; or env SWBI_KEY / SWBACKUPIT_KEY_<KEYID>)

The database password is read from the SWBI_DB_PASSWORD environment variable or --db-password-file.
Destructive actions need --confirm-target (the canonical target path) and --confirm-database (the database name).
Exit codes: 0 success, 1 failure, 2 usage error.

TEXT);

        return 0;
    }

    private function usage(string $message): int
    {
        $this->err($message . "\nRun 'swbackupit-restore help' for usage.\n");

        return 2;
    }

    private function fail(string $message, bool $json): int
    {
        if ($json) {
            $this->out(json_encode(['status' => 'failed', 'messages' => [$message]]) . "\n");
        } else {
            $this->err('ERROR: ' . $message . "\n");
        }

        return 1;
    }

    private function out(string $text): void
    {
        fwrite($this->stdout, $text);
    }

    private function err(string $text): void
    {
        fwrite($this->stderr, $text);
    }
}
