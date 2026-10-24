<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Storage;

use SWBackupIt\Core\Exception\IntegrityException;
use SWBackupIt\Core\Manifest\Manifest;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\PathGuard;
use SWBackupIt\Core\Support\ResourceBudget;

/**
 * Copies a stored backup to another storage target (copy/move between targets, PLAN.md §10.3).
 *
 * The source is the local staging copy or another storage target. Files are copied as stored (encrypted backups stay
 * encrypted, no key is needed), every file is checked against the manifest checksums before and after the upload and
 * the manifest is uploaded LAST, so the destination only holds a recognisable backup once every data file is verified.
 * Resumable: the state array records the finished files; files already verified on the destination are not sent again.
 * Remote sources are fetched one file at a time into the work directory (constant disk use of one file).
 */
final class BackupTransfer
{
    public function __construct(private readonly string $workDirectory)
    {
    }

    /**
     * @param string|null $localDirectory Local backup directory (manifest + data) or null when $source is used.
     * @param array<string, mixed> $state Resume state of an earlier call (empty for a new transfer).
     * @param callable(array<string, mixed>): void|null $checkpoint Called with the state after every finished file.
     * @param callable(int): void|null $onProgress Called with the number of bytes sent.
     * @return array<string, mixed> The new state; `finished` is true once the destination holds the verified backup.
     */
    public function run(
        string $backupId,
        ?string $localDirectory,
        ?RemoteStorageInterface $source,
        RemoteStorageInterface $destination,
        array $state = [],
        ?ResourceBudget $budget = null,
        ?callable $checkpoint = null,
        ?callable $onProgress = null,
    ): array {
        if ($localDirectory === null && $source === null) {
            throw new IntegrityException('The backup has no source to copy from.');
        }
        $budget ??= ResourceBudget::unlimited();
        AtomicFile::ensureDirectory($this->workDirectory);
        $manifestPath = $localDirectory !== null ? $localDirectory . '/' . Manifest::FILE_NAME : $this->fetchManifest($backupId, $source);
        $manifest = Manifest::fromArray(AtomicFile::readJson($manifestPath));
        if ($manifest->backupId() !== $backupId) {
            throw new IntegrityException('The manifest does not belong to the backup.');
        }
        $files = self::files($manifest);
        $state += ['done' => [], 'finished' => false, 'bytes_done' => 0];
        $state['bytes_total'] = array_sum(array_map(static fn (array $file): int => (int) $file['size'], $files));
        $state['files_total'] = count($files) + 1;

        foreach ($files as $relative => $file) {
            if (isset($state['done'][$relative])) {
                continue;
            }
            PathGuard::assertSafeRelative($relative);
            $remotePath = $backupId . '/' . $relative;
            if ($file['size'] === null || !$destination->verify($remotePath, $file['size'], $file['sha256'])) {
                $this->copyFile($backupId, $relative, $file, $localDirectory, $source, $destination, $onProgress);
            }
            $state['done'][$relative] = $file['sha256'];
            $state['bytes_done'] += (int) $file['size'];
            if ($checkpoint !== null) {
                $checkpoint($state);
            }
            if ($budget->exhausted()) {
                $this->cleanup($localDirectory, $manifestPath);

                return $state;
            }
        }

        $manifestHash = (string) hash_file('sha256', $manifestPath);
        $sent = $destination->upload($manifestPath, $backupId . '/' . Manifest::FILE_NAME);
        if (!hash_equals($manifestHash, $sent) || !$destination->verify($backupId . '/' . Manifest::FILE_NAME, (int) filesize($manifestPath), $manifestHash)) {
            throw new IntegrityException('The manifest could not be verified on the destination.');
        }
        $this->cleanup($localDirectory, $manifestPath);
        $state['done'][Manifest::FILE_NAME] = $manifestHash;
        $state['finished'] = true;
        if ($checkpoint !== null) {
            $checkpoint($state);
        }

        return $state;
    }

    /**
     * Stored data files of a backup (as on the storage targets), manifest excluded.
     *
     * @return array<string, array{size: int|null, sha256: string}> relative path => expected size (null = unknown) and checksum
     */
    public static function files(Manifest $manifest): array
    {
        $files = [];
        foreach ($manifest->volumes() as $volume) {
            $files['volumes/' . $volume->name] = ['size' => $volume->size, 'sha256' => $volume->sha256];
        }
        foreach ($manifest->databaseParts() as $part) {
            $files['database/' . $part['name']] = ['size' => (int) $part['size'], 'sha256' => (string) $part['sha256']];
        }
        if ($manifest->hasFiles()) {
            $files[$manifest->indexFileName()] = ['size' => null, 'sha256' => (string) $manifest->filesIndexSha256()];
        }

        return $files;
    }

    /**
     * @param array{size: int|null, sha256: string} $file
     * @param callable(int): void|null $onProgress
     */
    private function copyFile(
        string $backupId,
        string $relative,
        array $file,
        ?string $localDirectory,
        ?RemoteStorageInterface $source,
        RemoteStorageInterface $destination,
        ?callable $onProgress,
    ): void {
        $temporary = null;
        if ($localDirectory !== null) {
            $path = $localDirectory . '/' . $relative;
        } else {
            $temporary = $this->workDirectory . '/' . substr(hash('sha256', $relative), 0, 24) . '.part';
            $source?->download($backupId . '/' . $relative, $temporary);
            $path = $temporary;
        }
        try {
            $hash = is_file($path) ? hash_file('sha256', $path) : false;
            $size = is_file($path) ? (int) filesize($path) : -1;
            if ($hash === false || !hash_equals($file['sha256'], $hash) || ($file['size'] !== null && $size !== $file['size'])) {
                throw new IntegrityException('A file of the source copy failed verification.', ['file' => $relative]);
            }
            $statePath = $this->workDirectory . '/upload-' . substr(hash('sha256', $backupId . '|' . $relative), 0, 24) . '.state';
            $sent = $destination->upload($path, $backupId . '/' . $relative, $onProgress, $statePath);
            if (!hash_equals($file['sha256'], $sent) || !$destination->verify($backupId . '/' . $relative, $size, $file['sha256'])) {
                throw new IntegrityException('A copied file failed verification on the destination.', ['file' => $relative]);
            }
            @unlink($statePath);
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }
    }

    private function fetchManifest(string $backupId, ?RemoteStorageInterface $source): string
    {
        $path = $this->workDirectory . '/' . $backupId . '.manifest.json';
        if (!is_file($path)) {
            $source?->download($backupId . '/' . Manifest::FILE_NAME, $path);
        }

        return $path;
    }

    private function cleanup(?string $localDirectory, string $manifestPath): void
    {
        if ($localDirectory === null) {
            @unlink($manifestPath);
        }
    }
}
