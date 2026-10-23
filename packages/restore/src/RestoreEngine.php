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
        $phase = (string) ($state['phase'] ?? 'start');
        $set = null;
        try {
            $set = BackupSet::open($request->backupDirectory, $request->keys, rtrim($request->stateDirectory, '/') . '/decrypted');
            $target = $this->confirmTarget($request, $set);
            if ($phase === 'start') {
                $this->events->dispatch(Events::BEFORE_RESTORE, ['backup_id' => $set->manifest()->backupId(), 'target' => $target]);
                $state = ['phase' => $request->verifyFirst ? 'verify' : 'files', 'started' => time()];
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
                    $state['phase'] = 'config';
                    $store->save($state);
                } elseif ($phase === 'config') {
                    $messages = $this->updateConfig($request, $set, $target);
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
        $importer = new SqlImporter($pdo, $this->logger);
        $parts = $set->manifest()->databaseParts();
        $db = is_array($state['database'] ?? null) ? $state['database'] : ['part' => 0, 'statements' => 0];
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
        $prefix = (string) ($set->manifest()->section('source')['table_prefix'] ?? '');
        $changed = (new WpConfigEditor($path))->update([
            'DB_NAME' => $db->name,
            'DB_USER' => $db->user,
            'DB_PASSWORD' => $db->password,
            'DB_HOST' => $host,
        ], $prefix !== '' ? $prefix : null);
        $this->logger->info('wp-config.php updated.', ['changed' => $changed]);

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
