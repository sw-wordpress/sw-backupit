<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Storage;

/**
 * What the backup engine needs from a storage destination. Implementations wrap Flysystem and add the
 * capabilities Flysystem does not offer uniformly (verification, safe finalisation, usage).
 *
 * Paths are relative to the target root (and its configured prefix).
 */
interface RemoteStorageInterface
{
    /**
     * Uploads a local file so that it only appears under its final name when complete (temporary object
     * name + rename, or an inherently atomic PUT). Throws TransientException for retryable failures.
     *
     * @param callable(int): void|null $onProgress Receives the bytes read since the last call.
     * @return string SHA-256 of the data that was actually sent.
     */
    public function upload(string $localPath, string $remotePath, ?callable $onProgress = null): string;

    public function exists(string $remotePath): bool;

    public function size(string $remotePath): ?int;

    /** Verifies that the remote object has the expected size and SHA-256 (how depends on the provider). */
    public function verify(string $remotePath, int $size, string $sha256): bool;

    /**
     * @return resource
     */
    public function readStream(string $remotePath);

    /** Downloads an object to a local file (atomic via temp file). */
    public function download(string $remotePath, string $localPath): void;

    public function delete(string $remotePath): void;

    public function deleteDirectory(string $remotePath): void;

    /**
     * Immediate child directory names below a path.
     *
     * @return list<string>
     */
    public function listDirectories(string $remotePath): array;

    /**
     * Immediate child file names below a path.
     *
     * @return list<string>
     */
    public function listFiles(string $remotePath): array;

    public function test(): ConnectionTestResult;

    /** Bytes used below the root when the provider can tell (null = unknown). */
    public function usage(): ?int;

    /**
     * Optional capabilities: 'multipart', 'server_side_copy', 'object_lock', 'usage', 'checksum_metadata'.
     *
     * @return list<string>
     */
    public function capabilities(): array;
}
