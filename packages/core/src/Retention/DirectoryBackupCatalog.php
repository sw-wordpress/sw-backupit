<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Retention;

use DateTimeImmutable;
use DateTimeZone;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Manifest\Manifest;
use SWBackupIt\Core\Storage\RemoteStorageInterface;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\Uuid;

/**
 * Catalog backed by a staging directory (manifests are the source of truth) plus optional remote replicas that are
 * cleaned together with the local copy. Protection flags live in `<backup>/.protected`.
 */
final class DirectoryBackupCatalog implements BackupCatalogInterface
{
    /** @param array<int, RemoteStorageInterface> $remotes target id => storage that holds replicas */
    public function __construct(private readonly string $stagingRoot, private readonly array $remotes = [])
    {
    }

    public function records(?int $profileId = null): array
    {
        $records = [];
        foreach (glob(rtrim($this->stagingRoot, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (!Uuid::isValid($id)) {
                continue;
            }
            try {
                $manifest = Manifest::read($dir);
                $profile = $manifest->section('profile');
                $recordProfileId = isset($profile['id']) ? (int) $profile['id'] : null;
                if ($profileId !== null && $recordProfileId !== $profileId) {
                    continue;
                }
                $size = 0;
                foreach ($manifest->volumes() as $volume) {
                    $size += $volume->size;
                }
                foreach ($manifest->databaseParts() as $part) {
                    $size += $part['size'];
                }
                $parent = $manifest->section('parent');
                $records[] = new BackupRecord(
                    $id,
                    $manifest->type(),
                    new DateTimeImmutable($manifest->createdAt(), new DateTimeZone('UTC')),
                    $size,
                    isset($parent['backup_id']) ? (string) $parent['backup_id'] : null,
                    is_file($dir . '/.protected'),
                    true,
                    $recordProfileId,
                );
            } catch (\Throwable) {
                // Incomplete or damaged: report as unreliable instead of hiding it (never auto deleted).
                if ($profileId === null) {
                    $records[] = new BackupRecord($id, \SWBackupIt\Core\Domain\BackupType::Full, new DateTimeImmutable('@' . (int) filemtime($dir)), 0, null, false, false);
                }
            }
        }

        return $records;
    }

    public function delete(string $backupId): void
    {
        if (!Uuid::isValid($backupId)) {
            throw new SwbiException('Invalid backup id.');
        }
        foreach ($this->remotes as $targetId => $remote) {
            try {
                $remote->deleteDirectory($backupId);
            } catch (\Throwable $throwable) {
                throw new SwbiException('Storage target ' . $targetId . ' could not be cleaned; the backup is kept.', [], false, $throwable);
            }
        }
        AtomicFile::removeTree(rtrim($this->stagingRoot, '/') . '/' . $backupId);
    }
}
