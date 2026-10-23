<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup\Step;

use SWBackupIt\Core\Backup\BackupContext;
use SWBackupIt\Core\Backup\BackupStepInterface;
use SWBackupIt\Core\Backup\StepOutcome;
use SWBackupIt\Core\Database\DatabaseExporter;
use SWBackupIt\Core\Database\TableSelector;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Event\Events;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\ResourceBudget;

/** Streams the database into gzip parts. Every closed part is checkpointed. */
final class ExportStep implements BackupStepInterface
{
    public function step(): Step
    {
        return Step::Export;
    }

    public function applies(BackupProfile $profile): bool
    {
        return $profile->type->includesDatabase();
    }

    public function run(BackupContext $context): StepOutcome
    {
        $job = $context->job;
        $profile = $context->profile;
        $cursor = $job->checkpoint(Step::Export);
        if (($cursor['phase'] ?? '') === 'done') {
            return StepOutcome::Done;
        }
        $consistent = $profile->dbConsistentSnapshot;
        if ($consistent && $cursor !== []) {
            // A snapshot cannot survive a process restart: start over to keep the guarantee.
            $context->logger->warning('Consistent snapshot export was interrupted and restarts from scratch.');
            AtomicFile::removeTree($context->layout->databaseDirectory());
            AtomicFile::ensureDirectory($context->layout->databaseDirectory());
            $cursor = [];
        }
        $pdo = $context->pdo();
        $tables = [];
        if ($cursor === []) {
            $context->events->dispatch(Events::BEFORE_DATABASE_EXPORT, ['job_id' => $job->id, 'backup_id' => $job->backupId]);
            $tables = (new TableSelector())->select(
                $pdo,
                $context->source->tablePrefix,
                $profile->dbTablesInclude,
                $profile->dbTablesExclude,
                $profile->dbIncludeForeignTables,
            );
            if ($consistent) {
                foreach ($tables as $table) {
                    if (!$table->isView && strcasecmp($table->engine, 'InnoDB') !== 0) {
                        $context->logger->warning('Table is not InnoDB; it is not part of the transactional snapshot.', ['table' => $table->name, 'engine' => $table->engine]);
                    }
                }
            } else {
                $context->logger->notice('Database export runs in batches without a global snapshot; see docs/backup-format.md for consistency notes.');
            }
            $context->logger->info('Exporting database tables.', ['tables' => count($tables)]);
        }
        $exporter = new DatabaseExporter($pdo, $context->logger);
        $budget = $consistent ? ResourceBudget::unlimited() : $context->budget;
        $onPart = function (array $committed) use ($context): void {
            $context->job->setCheckpoint(Step::Export, $committed);
            $total = max(1, count($committed['tables']));
            $context->job->progress->stepFraction = min(1.0, (int) $committed['ti'] / $total);
            $context->job->progress->currentItem = (string) ($committed['tables'][min((int) $committed['ti'], $total - 1)]['name'] ?? '');
            $context->commit();
        };
        $result = $exporter->export(
            $context->layout->databaseDirectory(),
            $tables,
            $profile->dbIncludeForeignTables,
            $profile->limits->batchRows,
            $profile->limits->databasePartBytes,
            $cursor,
            $budget,
            $onPart,
            $consistent,
        );
        $job->setCheckpoint(Step::Export, $result->cursor);
        $context->commit();
        if (!$result->finished) {
            return StepOutcome::Yield;
        }
        $context->logger->info('Database export complete.', ['parts' => count($result->cursor['parts'])]);
        $context->events->dispatch(Events::AFTER_DATABASE_EXPORT, ['job_id' => $job->id, 'backup_id' => $job->backupId]);

        return StepOutcome::Done;
    }
}
