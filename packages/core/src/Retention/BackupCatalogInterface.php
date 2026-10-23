<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Retention;

/** Source of backup records and the only way retention deletes backups (all locations of one backup). */
interface BackupCatalogInterface
{
    /**
     * @return list<BackupRecord>
     */
    public function records(?int $profileId = null): array;

    /**
     * Deletes a backup from every location. Must throw if any location could not be cleaned, so the caller keeps
     * the catalog entry and can retry (never "forget" a backup that still exists somewhere).
     *
     * @throws \SWBackupIt\Core\Exception\SwbiException
     */
    public function delete(string $backupId): void;
}
