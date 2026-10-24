<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Storage;

use SWBackupIt\Core\Exception\IntegrityException;
use SWBackupIt\Core\Manifest\Manifest;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\PathGuard;

/**
 * Downloads a complete backup from a storage target into a local directory (restore from external storage).
 * Every file is verified against the checksums of the manifest; the manifest is written last; existing verified files
 * are reused, so an interrupted download continues.
 */
final class BackupDownloader
{
    /**
     * Downloads a backup together with every base backup its index references (incremental/differential chains).
     *
     * @param callable(string): void|null $onFile
     * @return list<string> Downloaded backup ids (requested backup first).
     */
    public function downloadWithAncestors(RemoteStorageInterface $storage, string $backupId, string $destinationRoot, ?callable $onFile = null): array
    {
        $done = [];
        $queue = [$backupId];
        while ($queue !== []) {
            $id = array_shift($queue);
            if (in_array($id, $done, true)) {
                continue;
            }
            $directory = $this->download($storage, $id, $destinationRoot, $onFile);
            $done[] = $id;
            foreach (Manifest::read($directory)->requires() as $required) {
                $queue[] = $required;
            }
        }

        return $done;
    }

    /**
     * @param callable(string): void|null $onFile Called with the relative path before each file is fetched.
     * @return string The local backup directory.
     */
    public function download(RemoteStorageInterface $storage, string $backupId, string $destinationRoot, ?callable $onFile = null): string
    {
        $target = rtrim($destinationRoot, '/') . '/' . $backupId;
        AtomicFile::ensureDirectory($target);
        $catalog = (new RemoteCatalog())->read($storage, $backupId, $target);
        $manifest = $catalog['manifest'];
        if (!$manifest instanceof Manifest) {
            throw new IntegrityException('The remote backup is incomplete or invalid: ' . ($catalog['error'] ?? 'unknown'));
        }
        $files = [];
        foreach ($manifest->volumes() as $volume) {
            $files['volumes/' . $volume->name] = [$volume->size, $volume->sha256];
        }
        foreach ($manifest->databaseParts() as $part) {
            $files['database/' . $part['name']] = [$part['size'], $part['sha256']];
        }
        if ($manifest->hasFiles()) {
            $files[$manifest->indexFileName()] = [null, (string) $manifest->filesIndexSha256()];
        }
        foreach ($files as $relative => [$size, $sha256]) {
            PathGuard::assertSafeRelative($relative);
            $local = $target . '/' . $relative;
            if (is_file($local) && hash_file('sha256', $local) === $sha256) {
                continue;
            }
            if ($onFile !== null) {
                $onFile($relative);
            }
            AtomicFile::ensureDirectory(dirname($local));
            $storage->download($backupId . '/' . $relative, $local);
            $hash = hash_file('sha256', $local);
            if ($hash === false || !hash_equals($sha256, $hash) || ($size !== null && filesize($local) !== $size)) {
                @unlink($local);
                throw new IntegrityException('A downloaded file failed verification.', ['file' => $relative]);
            }
        }
        $storage->download($backupId . '/manifest.json', $target . '/manifest.json');

        return $target;
    }
}
