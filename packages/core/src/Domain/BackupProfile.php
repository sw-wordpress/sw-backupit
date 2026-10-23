<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Domain;

use SWBackupIt\Core\Exception\ConfigurationException;

/**
 * Immutable backup profile (PLAN.md §4).
 *
 * A running job stores toArray() as its configuration snapshot, so later edits
 * of the profile never influence a job that already started.
 */
final readonly class BackupProfile
{
    /**
     * @param list<string> $includePaths       Relative to the WordPress root. Empty = whole root.
     * @param list<string> $excludePaths       Relative directories/files.
     * @param list<string> $includePatterns    Glob patterns (`*`, `**`, `?`) on relative paths.
     * @param list<string> $excludePatterns
     * @param list<string> $externalPaths      Absolute paths outside the root, explicitly approved.
     * @param list<string> $dbTablesInclude    Empty = all tables with the WordPress prefix.
     * @param list<string> $dbTablesExclude
     * @param list<int>    $storageTargetIds
     * @param array<string, mixed> $retention
     * @param array<string, mixed> $notifications
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public BackupType $type = BackupType::Full,
        public string $description = '',
        public array $includePaths = [],
        public array $excludePaths = [],
        public array $includePatterns = [],
        public array $excludePatterns = [],
        public array $externalPaths = [],
        public array $dbTablesInclude = [],
        public array $dbTablesExclude = [],
        public bool $dbIncludeForeignTables = false,
        public bool $dbConsistentSnapshot = false,
        public CompressionSettings $compression = new CompressionSettings(),
        public EncryptionSettings $encryption = new EncryptionSettings(),
        public int $volumeSizeBytes = 268435456,
        public ResourceLimits $limits = new ResourceLimits(),
        public bool $checksums = true,
        public array $storageTargetIds = [],
        public string $successPolicy = 'all',
        public array $retention = [],
        public array $notifications = [],
    ) {
        if (trim($name) === '') {
            throw new ConfigurationException('Profile name must not be empty.');
        }
        if ($volumeSizeBytes < 1024) {
            throw new ConfigurationException('Volume size is too small.');
        }
        if (!in_array($successPolicy, ['all', 'any', 'local_only'], true)) {
            throw new ConfigurationException('Unsupported success policy.', ['policy' => $successPolicy]);
        }
        foreach ($includePaths as $path) {
            $this->assertRelative($path);
        }
        foreach ($excludePaths as $path) {
            $this->assertRelative($path);
        }
        foreach ($externalPaths as $path) {
            if ($path === '' || $path[0] !== '/' || str_contains($path, '..') || str_contains($path, "\0")) {
                throw new ConfigurationException('External paths must be absolute and free of traversal.');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = BackupType::tryFrom(is_string($data['type'] ?? null) ? $data['type'] : 'full');
        if ($type === null) {
            throw new ConfigurationException('Unknown backup type.');
        }
        $volumeBytes = 268435456;
        if (isset($data['volume_size_bytes']) && is_numeric($data['volume_size_bytes'])) {
            $volumeBytes = (int) $data['volume_size_bytes'];
        } elseif (isset($data['volume_size_mb']) && is_numeric($data['volume_size_mb'])) {
            $volumeBytes = (int) ((float) $data['volume_size_mb'] * 1048576);
        }

        return new self(
            isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : null,
            is_string($data['name'] ?? null) ? $data['name'] : '',
            $type,
            is_string($data['description'] ?? null) ? $data['description'] : '',
            self::stringList($data['include_paths'] ?? []),
            self::stringList($data['exclude_paths'] ?? []),
            self::stringList($data['include_patterns'] ?? []),
            self::stringList($data['exclude_patterns'] ?? []),
            self::stringList($data['external_paths'] ?? []),
            self::stringList($data['db_tables_include'] ?? []),
            self::stringList($data['db_tables_exclude'] ?? []),
            (bool) ($data['db_include_foreign_tables'] ?? false),
            (bool) ($data['db_consistent_snapshot'] ?? false),
            CompressionSettings::fromArray(self::map($data['compression'] ?? [])),
            EncryptionSettings::fromArray(self::map($data['encryption'] ?? [])),
            $volumeBytes,
            ResourceLimits::fromArray(self::map($data['limits'] ?? [])),
            (bool) ($data['checksums'] ?? true),
            array_map('intval', array_values(array_filter(
                is_array($data['storage_target_ids'] ?? null) ? $data['storage_target_ids'] : [],
                'is_numeric',
            ))),
            is_string($data['success_policy'] ?? null) ? $data['success_policy'] : 'all',
            self::map($data['retention'] ?? []),
            self::map($data['notifications'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type->value,
            'include_paths' => $this->includePaths,
            'exclude_paths' => $this->excludePaths,
            'include_patterns' => $this->includePatterns,
            'exclude_patterns' => $this->excludePatterns,
            'external_paths' => $this->externalPaths,
            'db_tables_include' => $this->dbTablesInclude,
            'db_tables_exclude' => $this->dbTablesExclude,
            'db_include_foreign_tables' => $this->dbIncludeForeignTables,
            'db_consistent_snapshot' => $this->dbConsistentSnapshot,
            'compression' => $this->compression->toArray(),
            'encryption' => $this->encryption->toArray(),
            'volume_size_bytes' => $this->volumeSizeBytes,
            'limits' => $this->limits->toArray(),
            'checksums' => $this->checksums,
            'storage_target_ids' => $this->storageTargetIds,
            'success_policy' => $this->successPolicy,
            'retention' => $this->retention,
            'notifications' => $this->notifications,
        ];
    }

    private function assertRelative(string $path): void
    {
        $path = trim($path, '/');
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new ConfigurationException('Invalid relative path in profile.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new ConfigurationException('Relative paths must not contain traversal segments.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($item) => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
