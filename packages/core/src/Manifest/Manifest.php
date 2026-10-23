<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Manifest;

use SWBackupIt\Core\Archive\VolumeInfo;
use SWBackupIt\Core\Domain\BackupType;
use SWBackupIt\Core\Exception\ManifestException;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\Uuid;

/**
 * Versioned global manifest of a backup (see docs/backup-format.md).
 *
 * The manifest is self-describing and independent of the WordPress database. Parsing is strict:
 * everything that later becomes a filesystem path is validated here already.
 */
final class Manifest
{
    public const FORMAT = 'swbackupit';
    public const FORMAT_VERSION = 1;
    public const FILE_NAME = 'manifest.json';

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @throws ManifestException
     */
    public static function fromArray(array $data): self
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new ManifestException('Not a SWBackupIt manifest.');
        }
        $version = $data['format_version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new ManifestException('Manifest has no valid format version.');
        }
        if ($version > self::FORMAT_VERSION) {
            throw new ManifestException('Backup was created by a newer, unsupported format version.', ['version' => $version]);
        }
        if (!is_string($data['backup_id'] ?? null) || !Uuid::isValid($data['backup_id'])) {
            throw new ManifestException('Manifest has an invalid backup id.');
        }
        if (BackupType::tryFrom((string) ($data['type'] ?? '')) === null) {
            throw new ManifestException('Manifest has an invalid backup type.');
        }
        if (!is_string($data['created_at'] ?? null) || strtotime($data['created_at']) === false) {
            throw new ManifestException('Manifest has an invalid creation date.');
        }
        $files = $data['files'] ?? null;
        if ($files !== null) {
            if (!is_array($files) || !isset($files['index'], $files['sha256']) || $files['index'] !== 'files.ndjson') {
                throw new ManifestException('Manifest file index description is invalid.');
            }
        }
        foreach (self::listOf($data, 'volumes') as $volume) {
            if (!is_array($volume) || preg_match('/^backup-\d{3,}\.zip(\.swbe)?$/', (string) ($volume['name'] ?? '')) !== 1 || !self::isSha256($volume['sha256'] ?? null)) {
                throw new ManifestException('Manifest contains an invalid volume entry.');
            }
        }
        $database = $data['database'] ?? null;
        if ($database !== null) {
            if (!is_array($database)) {
                throw new ManifestException('Manifest database section is invalid.');
            }
            foreach (self::listOf($database, 'parts') as $part) {
                if (!is_array($part) || preg_match('/^db-\d{4,}\.sql\.gz(\.swbe)?$/', (string) ($part['name'] ?? '')) !== 1 || !self::isSha256($part['sha256'] ?? null)) {
                    throw new ManifestException('Manifest contains an invalid database part entry.');
                }
            }
        }

        return new self($data);
    }

    /**
     * @throws ManifestException
     */
    public static function read(string $directory): self
    {
        $path = rtrim($directory, '/') . '/' . self::FILE_NAME;
        if (!is_file($path)) {
            throw new ManifestException('Backup has no manifest (incomplete backup?).', ['path' => $path]);
        }

        try {
            return self::fromArray(AtomicFile::readJson($path));
        } catch (ManifestException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new ManifestException('Manifest is unreadable.', [], false, $throwable);
        }
    }

    public function backupId(): string
    {
        return (string) $this->data['backup_id'];
    }

    public function type(): BackupType
    {
        return BackupType::from((string) $this->data['type']);
    }

    public function createdAt(): string
    {
        return (string) $this->data['created_at'];
    }

    public function isEncrypted(): bool
    {
        return ($this->data['encryption'] ?? null) !== null;
    }

    /**
     * @return list<VolumeInfo>
     */
    public function volumes(): array
    {
        return array_map(static fn (array $volume): VolumeInfo => VolumeInfo::fromArray($volume), self::listOf($this->data, 'volumes'));
    }

    /**
     * @return list<array{name: string, size: int, sha256: string, raw_bytes: int}>
     */
    public function databaseParts(): array
    {
        $database = $this->data['database'] ?? null;

        return is_array($database) ? self::listOf($database, 'parts') : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function section(string $name): array
    {
        $value = $this->data[$name] ?? [];

        return is_array($value) ? $value : [];
    }

    public function filesIndexSha256(): ?string
    {
        $files = $this->data['files'] ?? null;

        return is_array($files) ? (string) $files['sha256'] : null;
    }

    public function hasFiles(): bool
    {
        return ($this->data['files'] ?? null) !== null;
    }

    public function hasDatabase(): bool
    {
        return ($this->data['database'] ?? null) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private static function listOf(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            throw new ManifestException("Manifest section '{$key}' must be a list.");
        }

        return array_values($value);
    }

    private static function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }
}
