<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Storage;

use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Manifest\Manifest;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\Uuid;

/**
 * Discovers backups on a storage target by reading their manifests (disaster recovery: the catalog can be rebuilt
 * from storage alone). Directories without a valid manifest are reported as incomplete, never as backups.
 */
final class RemoteCatalog
{
    /**
     * @return list<array{backup_id: string, manifest: Manifest|null, error: string|null}>
     */
    public function discover(RemoteStorageInterface $storage, string $tempDirectory): array
    {
        AtomicFile::ensureDirectory($tempDirectory);
        $found = [];
        foreach ($storage->listDirectories('') as $name) {
            if (!Uuid::isValid($name)) {
                continue;
            }
            $found[] = $this->read($storage, $name, $tempDirectory);
        }

        return $found;
    }

    /**
     * @return array{backup_id: string, manifest: Manifest|null, error: string|null}
     */
    public function read(RemoteStorageInterface $storage, string $backupId, string $tempDirectory): array
    {
        $local = $tempDirectory . '/' . $backupId . '.manifest.json';
        try {
            if (!$storage->exists($backupId . '/manifest.json')) {
                return ['backup_id' => $backupId, 'manifest' => null, 'error' => 'no manifest (incomplete upload)'];
            }
            $storage->download($backupId . '/manifest.json', $local);
            $manifest = Manifest::fromArray(AtomicFile::readJson($local));
            if ($manifest->backupId() !== $backupId) {
                return ['backup_id' => $backupId, 'manifest' => null, 'error' => 'manifest id does not match the directory'];
            }

            return ['backup_id' => $backupId, 'manifest' => $manifest, 'error' => null];
        } catch (SwbiException $exception) {
            return ['backup_id' => $backupId, 'manifest' => null, 'error' => $exception->getMessage()];
        } finally {
            @unlink($local);
        }
    }
}
