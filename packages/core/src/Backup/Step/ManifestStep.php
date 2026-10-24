<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup\Step;

use SWBackupIt\Core\Backup\Artifacts;
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
 * Writes the global manifest after all artifacts exist (and are verified). A backup without manifest.json is
 * by definition incomplete; the presence of a valid manifest marks it complete.
 */
final class ManifestStep implements BackupStepInterface
{
    public const GENERATOR_VERSION = '0.1.0';

    /**
     * @return array{backup_id: string, manifest_sha256: string}|null
     */
    private function parent(\SWBackupIt\Core\Job\Job $job): ?array
    {
        $preflight = $job->checkpoint(Step::Preflight);
        $parent = $preflight['parent'] ?? null;
        if (!is_array($parent) || !in_array($preflight['effective_type'] ?? '', ['incremental', 'differential'], true)) {
            return null;
        }

        return ['backup_id' => (string) $parent['backup_id'], 'manifest_sha256' => (string) $parent['manifest_sha256']];
    }

    public function step(): Step
    {
        return Step::Manifest;
    }

    public function applies(BackupProfile $profile): bool
    {
        return true;
    }

    public function run(BackupContext $context): StepOutcome
    {
        $job = $context->job;
        $profile = $context->profile;
        $export = $job->checkpoint(Step::Export);
        $encrypted = Artifacts::isEncrypted($job);
        $encryptState = $job->checkpoint(Step::Encrypt);

        $files = null;
        if ($profile->type->includesFiles()) {
            $entries = 0;
            $bytes = 0;
            if ($encrypted) {
                $stats = $encryptState['index_stats'] ?? ['count' => 0, 'bytes' => 0];
                $entries = (int) $stats['count'];
                $bytes = (int) $stats['bytes'];
            } else {
                foreach ((new FileIndexReader($context->layout->indexPath()))->read() as [$entry]) {
                    $entries++;
                    $bytes += $entry['t'] === 'f' ? (int) $entry['s'] : 0;
                }
            }
            $scan = $job->checkpoint(Step::Scan);
            if ($entries !== (int) ($scan['count'] ?? -1)) {
                throw new IntegrityException('File index entry count differs from the inventory.', ['index' => $entries, 'inventory' => (int) ($scan['count'] ?? 0)]);
            }
            $indexName = Artifacts::indexName($job);
            $indexHash = hash_file('sha256', $context->layout->root() . '/' . $indexName);
            if ($indexHash === false) {
                throw new IntegrityException('Cannot hash the file index.');
            }
            $files = ['index' => $indexName, 'sha256' => $indexHash, 'count' => $entries, 'bytes' => $bytes];
        }

        $database = null;
        if ($profile->type->includesDatabase()) {
            $database = [
                'parts' => Artifacts::databaseParts($job),
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
            'type' => (string) ($job->checkpoint(Step::Preflight)['effective_type'] ?? $profile->type->value),
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
            'parent' => $this->parent($job),
            'chain' => ['requires' => array_values(array_map('strval', array_keys((array) ($job->checkpoint(Step::Archive)['requires'] ?? []))))],
            'files' => $files,
            'volumes' => Artifacts::volumes($job),
            'database' => $database,
            'encryption' => $encrypted ? [
                'format' => 'SWBE1',
                'cipher' => 'xchacha20poly1305-secretstream',
                'kdf' => $encryptState['kdf'] ?? 'argon2id',
                'key_id' => $profile->encryption->keyId,
            ] : null,
            'checksums' => $profile->checksums,
            'volume_size_bytes' => $profile->volumeSizeBytes,
            'warnings_count' => $job->warningsCount,
        ];
        // Validate before publishing: never write a manifest we could not read ourselves.
        Manifest::fromArray($data);
        AtomicFile::writeJson($context->layout->manifestPath(), $data);
        $hash = hash_file('sha256', $context->layout->manifestPath());
        $job->setCheckpoint(Step::Manifest, ['manifest_sha256' => $hash]);
        $context->commit();
        $context->logger->info('Manifest written.', ['backup_id' => $job->backupId]);

        return StepOutcome::Done;
    }
}
