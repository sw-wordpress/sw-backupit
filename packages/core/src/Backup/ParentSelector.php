<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup;

use SWBackupIt\Core\Domain\BackupType;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Manifest\Manifest;

/**
 * Chooses the base backup of an incremental or differential backup (PLAN.md §14).
 *
 * - Incremental: the newest complete backup of the same profile that contains files (any type).
 * - Differential: the newest complete NON-delta backup that contains files (the last full backup).
 *
 * The base must still have its file index locally (needed for the comparison). Without a usable base the caller creates a
 * full backup instead (documented fallback, logged as a notice).
 */
final class ParentSelector
{
    /**
     * @return array{backup_id: string, manifest_sha256: string}|null
     */
    public function select(BackupContext $context): ?array
    {
        if (!$context->profile->type->isDelta() || $context->catalog === null) {
            return null;
        }
        $differential = $context->profile->type === BackupType::Differential;
        $records = $context->catalog->records($context->profile->id);
        usort($records, static fn ($a, $b): int => $b->createdAt <=> $a->createdAt);
        foreach ($records as $record) {
            if (!$record->reliable || $record->type === BackupType::Database || ($differential && $record->type->isDelta())) {
                continue;
            }
            $directory = $context->layout->stagingRoot() . '/' . $record->id;
            try {
                $manifest = Manifest::read($directory);
            } catch (SwbiException) {
                continue;
            }
            if (!$manifest->hasFiles() || !is_file($directory . '/' . $manifest->indexFileName())) {
                continue;
            }
            $hash = hash_file('sha256', $directory . '/manifest.json');

            return ['backup_id' => $record->id, 'manifest_sha256' => $hash === false ? '' : $hash];
        }

        return null;
    }
}
