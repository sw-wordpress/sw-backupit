<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Storage;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use SWBackupIt\Core\Archive\RangeStream;
use SWBackupIt\Core\Archive\ReadResult;
use SWBackupIt\Core\Exception\IntegrityException;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Exception\TransientException;
use SWBackupIt\Core\Retry\RetryPolicy;

/**
 * Generic Flysystem 3 based storage (local filesystem, SFTP, ...).
 *
 * Upload: stream to "<name>.swbi-part", then move to the final name. The SHA-256 is computed while the
 * data is sent. Verification re-reads the remote object (hash) - exact but costs a download; providers
 * with cheaper integrity metadata override verify().
 */
class FlysystemRemoteStorage implements RemoteStorageInterface
{
    public function __construct(
        protected readonly FilesystemOperator $filesystem,
        protected readonly RetryPolicy $retry = new RetryPolicy(),
        private readonly string $label = 'storage',
    ) {
    }

    public function upload(string $localPath, string $remotePath, ?callable $onProgress = null): string
    {
        if (!is_file($localPath)) {
            throw new IntegrityException('The local file to upload does not exist.', ['file' => basename($localPath)]);
        }
        $temporary = $remotePath . '.swbi-part';

        return $this->retry->run(function () use ($localPath, $remotePath, $temporary, $onProgress): string {
            $result = new ReadResult();
            $result->progress = $onProgress;
            $stream = RangeStream::open($localPath, 0, null, $result);
            try {
                $this->filesystem->writeStream($temporary, $stream);
            } catch (FilesystemException $exception) {
                throw $this->translate($exception, 'upload');
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            try {
                if ($this->filesystem->fileExists($remotePath)) {
                    $this->filesystem->delete($remotePath);
                }
                $this->filesystem->move($temporary, $remotePath);
            } catch (FilesystemException $exception) {
                throw $this->translate($exception, 'finalize');
            }

            return $result->sha256();
        }, static fn (\Throwable $throwable): bool => false);
    }

    public function exists(string $remotePath): bool
    {
        try {
            return $this->filesystem->fileExists($remotePath);
        } catch (FilesystemException $exception) {
            throw $this->translate($exception, 'check');
        }
    }

    public function size(string $remotePath): ?int
    {
        try {
            return $this->filesystem->fileExists($remotePath) ? $this->filesystem->fileSize($remotePath) : null;
        } catch (FilesystemException $exception) {
            throw $this->translate($exception, 'stat');
        }
    }

    public function verify(string $remotePath, int $size, string $sha256): bool
    {
        if ($this->size($remotePath) !== $size) {
            return false;
        }
        $stream = $this->readStream($remotePath);
        $hash = hash_init('sha256');
        while (!feof($stream)) {
            $data = fread($stream, 1048576);
            if ($data === false) {
                break;
            }
            hash_update($hash, $data);
        }
        fclose($stream);

        return hash_equals($sha256, hash_final($hash));
    }

    public function readStream(string $remotePath)
    {
        try {
            return $this->filesystem->readStream($remotePath);
        } catch (FilesystemException $exception) {
            throw $this->translate($exception, 'read');
        }
    }

    public function download(string $remotePath, string $localPath): void
    {
        $temporary = $localPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $out = fopen($temporary, 'wb');
        if ($out === false) {
            throw new SwbiException('Cannot write the downloaded file.');
        }
        try {
            $in = $this->readStream($remotePath);
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            if (!rename($temporary, $localPath)) {
                throw new SwbiException('Cannot finalize the downloaded file.');
            }
        } catch (\Throwable $throwable) {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($temporary);
            throw $throwable;
        }
    }

    public function delete(string $remotePath): void
    {
        try {
            if ($this->filesystem->fileExists($remotePath)) {
                $this->filesystem->delete($remotePath);
            }
        } catch (FilesystemException $exception) {
            throw $this->translate($exception, 'delete');
        }
    }

    public function deleteDirectory(string $remotePath): void
    {
        try {
            $this->filesystem->deleteDirectory($remotePath);
        } catch (FilesystemException $exception) {
            throw $this->translate($exception, 'delete');
        }
    }

    public function listDirectories(string $remotePath): array
    {
        return $this->listChildren($remotePath, true);
    }

    public function listFiles(string $remotePath): array
    {
        return $this->listChildren($remotePath, false);
    }

    public function test(): ConnectionTestResult
    {
        $start = microtime(true);
        $name = '.swbi-connection-test-' . bin2hex(random_bytes(4));
        try {
            $this->filesystem->write($name, 'swbackupit');
            $ok = $this->filesystem->read($name) === 'swbackupit';
            $this->filesystem->delete($name);
        } catch (FilesystemException $exception) {
            return new ConnectionTestResult(false, 'The storage test failed: ' . $this->describe($exception));
        }

        return new ConnectionTestResult($ok, $ok ? 'Write, read and delete succeeded.' : 'Read back mismatch.', (int) ((microtime(true) - $start) * 1000));
    }

    public function usage(): ?int
    {
        return null;
    }

    public function capabilities(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    private function listChildren(string $remotePath, bool $directories): array
    {
        $names = [];
        try {
            foreach ($this->filesystem->listContents($remotePath, false) as $item) {
                if ($item->isDir() === $directories) {
                    $names[] = basename($item->path());
                }
            }
        } catch (FilesystemException $exception) {
            throw $this->translate($exception, 'list');
        }
        sort($names);

        return $names;
    }

    protected function translate(\Throwable $exception, string $operation): SwbiException
    {
        // Provider messages may contain paths/hosts; callers get a safe message, details only via $previous.
        return new TransientException(sprintf('The %s operation on %s failed (%s).', $operation, $this->label, $this->describe($exception)), [], $exception);
    }

    protected function describe(\Throwable $exception): string
    {
        return (new \ReflectionClass($exception))->getShortName();
    }
}
