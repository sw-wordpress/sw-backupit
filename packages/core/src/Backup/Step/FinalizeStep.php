<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup\Step;

use SWBackupIt\Core\Backup\BackupContext;
use SWBackupIt\Core\Backup\BackupStepInterface;
use SWBackupIt\Core\Backup\StepOutcome;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Exception\IntegrityException;
use SWBackupIt\Core\Manifest\Manifest;
use SWBackupIt\Core\Scanner\FileIndexReader;
use SWBackupIt\Core\Support\AtomicFile;

/**
 * Writes the global manifest as the very last artifact. A backup without manifest.json is
 * by definition incomplete; the presence of a valid manifest marks it complete.
 */
final class FinalizeStep implements BackupStepInterface
{
    public const GENERATOR_VERSION = '0.1.0';

    public function step(): Step
    {
        return Step::Finalize;
    }

    public function applies(BackupProfile $profile): bool
    {
        return true;
    }

    public function run(BackupContext $context): StepOutcome
    {
        $job = $context->job;
        $profile = $context->profile;
        $archive = $job->checkpoint(Step::Archive);
        $export = $job->checkpoint(Step::Export);

        $files = null;
        if ($profile->type->includesFiles()) {
            $entries = 0;
            $bytes = 0;
            foreach ((new FileIndexReader($context->layout->indexPath()))->read() as [$entry]) {
                $entries++;
                $bytes += $entry['t'] === 'f' ? (int) $entry['s'] : 0;
            }
            $scan = $job->checkpoint(Step::Scan);
            if ($entries !== (int) ($scan['count'] ?? -1)) {
                throw new IntegrityException('File index entry count differs from the inventory.', ['index' => $entries, 'inventory' => (int) ($scan['count'] ?? 0)]);
            }
            $indexHash = hash_file('sha256', $context->layout->indexPath());
            if ($indexHash === false) {
                throw new IntegrityException('Cannot hash the file index.');
            }
            $files = ['index' => 'files.ndjson', 'sha256' => $indexHash, 'count' => $entries, 'bytes' => $bytes];
        }

        $database = null;
        if ($profile->type->includesDatabase()) {
            $database = [
                'parts' => $export['parts'] ?? [],
                'tables' => array_map(static fn (array $table): array => $table + ['exported_rows' => $export['table_rows'][$table['name']] ?? null], $export['tables'] ?? []),
                'consistent_snapshot' => $profile->dbConsistentSnapshot,
                'table_prefix' => $context->source->tablePrefix,
                'include_foreign_tables' => $profile->dbIncludeForeignTables,
            ];
        }

        $snapshot = $profile->toArray();
        $data = [
            'format' => Manifest::FORMAT,
            'format_version' => Manifest::FORMAT_VERSION,
            'backup_id' => $job->backupId,
            'job_id' => $job->id,
            'created_at' => ($job->startedAt ?? $job->createdAt)->format('Y-m-d\TH:i:s\Z'),
            'finished_at' => $context->clock->now()->format('Y-m-d\TH:i:s\Z'),
            'type' => $profile->type->value,
            'status' => 'complete',
            'generator' => ['name' => 'swbackupit', 'version' => self::GENERATOR_VERSION, 'php' => PHP_VERSION],
            'source' => [
                'site_url' => $context->source->siteUrl,
                'home_url' => $context->source->homeUrl,
                'wp_version' => $context->source->wpVersion,
                'table_prefix' => $context->source->tablePrefix,
                'root_path' => $context->source->rootPath,
                'multisite' => $context->source->multisite,
                'extra' => $context->source->extra,
            ],
            'profile' => $snapshot,
            'parent' => null,
            'files' => $files,
            'volumes' => $archive['volumes'] ?? [],
            'database' => $database,
            'encryption' => null,
            'checksums' => $profile->checksums,
            'volume_size_bytes' => $profile->volumeSizeBytes,
            'warnings_count' => $job->warningsCount,
        ];
        // Validate before publishing: never write a manifest we could not read ourselves.
        Manifest::fromArray($data);
        AtomicFile::writeJson($context->layout->manifestPath(), $data);
        $hash = hash_file('sha256', $context->layout->manifestPath());
        $job->setCheckpoint(Step::Finalize, ['manifest_sha256' => $hash]);
        $context->commit();
        $context->layout->removeWork();
        $context->logger->info('Backup finalized.', ['backup_id' => $job->backupId]);

        return StepOutcome::Done;
    }
}
