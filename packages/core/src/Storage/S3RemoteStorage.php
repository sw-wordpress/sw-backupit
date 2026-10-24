<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Storage;

use Aws\Exception\AwsException;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use League\Flysystem\FilesystemOperator;
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
        /** @var array{mode: string, days: int}|null S3 Object Lock applied to every uploaded object (bucket must have Object Lock enabled). */
        private readonly ?array $objectLock = null,
    ) {
        parent::__construct($filesystem, $retry, 's3');
    }

    public function upload(string $localPath, string $remotePath, ?callable $onProgress = null, ?string $resumeStatePath = null): string
    {
        if (!is_file($localPath)) {
            throw new IntegrityException('The local file to upload does not exist.', ['file' => basename($localPath)]);
        }
        $key = $this->key($remotePath);
        // Hash first (local read only): the metadata must be known before the object is created.
        $hash = hash_file('sha256', $localPath);
        $size = filesize($localPath);
        $mtime = filemtime($localPath);
        if ($hash === false || $size === false) {
            throw new IntegrityException('Cannot hash the local file.');
        }

        return $this->retry->run(function () use ($localPath, $key, $hash, $size, $mtime, $onProgress, $resumeStatePath): string {
            $options = [
                'bucket' => $this->bucket,
                'key' => $key,
                'part_size' => self::PART_SIZE,
                'concurrency' => 1, // bounded memory (parts are read sequentially); resumable at part granularity
                'params' => ['Metadata' => ['sha256' => $hash]] + $this->lockParameters(),
                'before_upload' => static function (\Aws\CommandInterface $command) use ($onProgress): void {
                    if ($onProgress !== null) {
                        $body = $command['Body'] ?? null;
                        $onProgress($body instanceof \Psr\Http\Message\StreamInterface ? (int) $body->getSize() : 0);
                    }
                },
            ];
            $state = $this->loadState($resumeStatePath, $hash, $size);
            if ($state !== null) {
                $options['state'] = $state;
            }
            if ($size < self::PART_SIZE) {
                return $this->putSmallObject($localPath, $key, $hash, $size, $mtime, $onProgress);
            }
            $uploader = new MultipartUploader($this->client, $localPath, $options);
            try {
                $uploader->upload();
            } catch (\Aws\Exception\MultipartUploadException $exception) {
                $this->saveState($resumeStatePath, $exception->getState(), $hash, $size);
                throw $this->translate($exception->getPrevious() ?? $exception, 'upload');
            } catch (\Throwable $throwable) {
                // Whatever interrupted the transfer (callback, network, process signal): keep the completed parts.
                $this->saveState($resumeStatePath, $uploader->getState(), $hash, $size);
                throw $this->translate($throwable, 'upload');
            }
            clearstatcache(true, $localPath);
            if (filesize($localPath) !== $size || filemtime($localPath) !== $mtime) {
                throw new IntegrityException('The local file changed while it was being uploaded.');
            }
            if ($resumeStatePath !== null) {
                @unlink($resumeStatePath);
            }

            return $hash;
        }, static fn (\Throwable $throwable): bool => false);
    }

    /**
     * Objects below the multipart part size go up with a single PutObject. The SHA-256 is sent as a checksum header, so the
     * provider verifies the data on arrival (and Object Lock uploads, which require a checksum, are accepted).
     */
    private function putSmallObject(string $localPath, string $key, string $hash, int $size, int|false $mtime, ?callable $onProgress): string
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new IntegrityException('Cannot read the local file.');
        }
        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $stream,
                'Metadata' => ['sha256' => $hash],
                'ChecksumAlgorithm' => 'SHA256',
                'ChecksumSHA256' => base64_encode((string) hex2bin($hash)),
            ] + $this->lockParameters());
        } catch (\Throwable $throwable) {
            throw $this->translate($throwable, 'upload');
        } finally {
            fclose($stream);
        }
        if ($onProgress !== null) {
            $onProgress($size);
        }
        clearstatcache(true, $localPath);
        if (filesize($localPath) !== $size || filemtime($localPath) !== $mtime) {
            throw new IntegrityException('The local file changed while it was being uploaded.');
        }

        return $hash;
    }

    /**
     * @return array<string, mixed>
     */
    private function lockParameters(): array
    {
        if ($this->objectLock === null) {
            return [];
        }

        return [
            'ObjectLockMode' => $this->objectLock['mode'],
            'ObjectLockRetainUntilDate' => new \DateTimeImmutable('+' . $this->objectLock['days'] . ' days'),
        ];
    }

    private function loadState(?string $path, string $hash, int $size): ?\Aws\Multipart\UploadState
    {
        if ($path === null || !is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (
            !is_array($data) || ($data['sha256'] ?? '') !== $hash || (int) ($data['size'] ?? -1) !== $size
            || !is_array($data['id'] ?? null) || !is_array($data['parts'] ?? null) || !is_numeric($data['part_size'] ?? null)
        ) {
            @unlink($path);

            return null;
        }
        // Plain JSON, no unserialize(): rebuild the SDK state from upload id, part size and completed parts.
        $state = new \Aws\Multipart\UploadState(array_map('strval', $data['id']));
        $state->setPartSize((int) $data['part_size']);
        $state->setStatus(\Aws\Multipart\UploadState::INITIATED);
        if (isset($data['upload_id']) && is_string($data['upload_id'])) {
            $state->setUploadId('UploadId', $data['upload_id']);
        }
        foreach ($data['parts'] as $number => $part) {
            if (is_array($part) && isset($part['PartNumber'], $part['ETag'])) {
                $state->markPartAsUploaded((int) $number, ['PartNumber' => (int) $part['PartNumber'], 'ETag' => (string) $part['ETag']]);
            }
        }

        return $state;
    }

    private function saveState(?string $path, \Aws\Multipart\UploadState $state, string $hash, int $size): void
    {
        if ($path === null) {
            return;
        }
        $id = $state->getId();
        file_put_contents($path, (string) json_encode([
            'sha256' => $hash,
            'size' => $size,
            'id' => array_diff_key($id, ['UploadId' => true]),
            'upload_id' => $id['UploadId'] ?? null,
            'part_size' => $state->getPartSize(),
            'parts' => $state->getUploadedParts(),
        ]));
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

    /** Retention end of an object when Object Lock is active (null = not locked/unknown). */
    public function retainUntil(string $remotePath): ?\DateTimeImmutable
    {
        try {
            $head = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $this->key($remotePath)]);
        } catch (AwsException) {
            return null;
        }
        $until = $head['ObjectLockRetainUntilDate'] ?? null;

        return $until instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($until) : null;
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
        return $this->objectLock === null ? ['multipart', 'usage', 'checksum_metadata'] : ['multipart', 'usage', 'checksum_metadata', 'object_lock'];
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
