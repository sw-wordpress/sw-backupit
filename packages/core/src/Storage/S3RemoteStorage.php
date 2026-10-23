<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Storage;

use Aws\Exception\AwsException;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use League\Flysystem\FilesystemOperator;
use SWBackupIt\Core\Archive\RangeStream;
use SWBackupIt\Core\Archive\ReadResult;
use SWBackupIt\Core\Exception\IntegrityException;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Exception\TransientException;
use SWBackupIt\Core\Retry\RetryPolicy;

/**
 * S3 compatible object storage. A PUT (or a completed multipart upload) is atomic, so no temporary object name is
 * needed. The SHA-256 is stored as object metadata, which makes remote verification a cheap HEAD request.
 */
final class S3RemoteStorage extends FlysystemRemoteStorage
{
    private const PART_SIZE = 8388608;

    public function __construct(
        FilesystemOperator $filesystem,
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly string $prefix,
        RetryPolicy $retry = new RetryPolicy(),
    ) {
        parent::__construct($filesystem, $retry, 's3');
    }

    public function upload(string $localPath, string $remotePath, ?callable $onProgress = null): string
    {
        if (!is_file($localPath)) {
            throw new IntegrityException('The local file to upload does not exist.', ['file' => basename($localPath)]);
        }
        $key = $this->key($remotePath);
        // Hash first (local read only): the metadata must be known before the object is created.
        $hash = hash_file('sha256', $localPath);
        if ($hash === false) {
            throw new IntegrityException('Cannot hash the local file.');
        }

        return $this->retry->run(function () use ($localPath, $key, $hash, $onProgress): string {
            $result = new ReadResult();
            $result->progress = $onProgress;
            $stream = RangeStream::open($localPath, 0, null, $result);
            try {
                $uploader = new MultipartUploader($this->client, $stream, [
                    'bucket' => $this->bucket,
                    'key' => $key,
                    'part_size' => self::PART_SIZE,
                    'params' => ['Metadata' => ['sha256' => $hash]],
                ]);
                $uploader->upload();
            } catch (\Throwable $throwable) {
                throw $this->translate($throwable, 'upload');
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (!hash_equals($hash, $result->sha256())) {
                throw new IntegrityException('The local file changed while it was being uploaded.');
            }

            return $hash;
        }, static fn (\Throwable $throwable): bool => false);
    }

    public function verify(string $remotePath, int $size, string $sha256): bool
    {
        try {
            $head = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $this->key($remotePath)]);
        } catch (AwsException $exception) {
            if ($exception->getStatusCode() === 404) {
                return false;
            }
            throw $this->translate($exception, 'verify');
        }
        $metadata = $head['Metadata'] ?? [];

        return (int) ($head['ContentLength'] ?? -1) === $size && isset($metadata['sha256']) && hash_equals($sha256, (string) $metadata['sha256']);
    }

    public function usage(): ?int
    {
        $total = 0;
        try {
            foreach ($this->client->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket, 'Prefix' => $this->prefix === '' ? '' : $this->prefix . '/']) as $page) {
                foreach ($page['Contents'] ?? [] as $object) {
                    $total += (int) $object['Size'];
                }
            }
        } catch (AwsException) {
            return null;
        }

        return $total;
    }

    public function capabilities(): array
    {
        return ['multipart', 'usage', 'checksum_metadata'];
    }

    private function key(string $remotePath): string
    {
        return ($this->prefix === '' ? '' : $this->prefix . '/') . ltrim($remotePath, '/');
    }

    protected function translate(\Throwable $exception, string $operation): SwbiException
    {
        if ($exception instanceof AwsException) {
            $status = $exception->getStatusCode() ?? 0;
            $code = (string) $exception->getAwsErrorCode();
            $retryable = $status === 0 || $status >= 500 || $status === 429 || in_array($code, ['RequestTimeout', 'SlowDown', 'RequestTimeTooSkewed'], true);
            $message = sprintf('The S3 %s failed (HTTP %d %s).', $operation, $status, $code);
            if ($retryable) {
                return new TransientException($message, [], $exception);
            }

            return new SwbiException($message, [], false, $exception);
        }

        return parent::translate($exception, $operation);
    }
}
