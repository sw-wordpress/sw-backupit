<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Domain;

use SWBackupIt\Core\Exception\ConfigurationException;

final readonly class ResourceLimits
{
    public function __construct(
        public int $timeBudgetSeconds = 20,
        public int $memoryMb = 128,
        public int $batchRows = 1000,
        public int $databasePartBytes = 33554432,
    ) {
        if ($timeBudgetSeconds < 1 || $timeBudgetSeconds > 3600) {
            throw new ConfigurationException('Time budget must be between 1 and 3600 seconds.');
        }
        if ($memoryMb < 16) {
            throw new ConfigurationException('Memory limit must be at least 16 MB.');
        }
        if ($batchRows < 1 || $batchRows > 100000) {
            throw new ConfigurationException('Batch rows must be between 1 and 100000.');
        }
        if ($databasePartBytes < 1024) {
            throw new ConfigurationException('Database part size is too small.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $defaults = new self();

        return new self(
            is_numeric($data['time_budget_seconds'] ?? null) ? (int) $data['time_budget_seconds'] : $defaults->timeBudgetSeconds,
            is_numeric($data['memory_mb'] ?? null) ? (int) $data['memory_mb'] : $defaults->memoryMb,
            is_numeric($data['batch_rows'] ?? null) ? (int) $data['batch_rows'] : $defaults->batchRows,
            is_numeric($data['database_part_bytes'] ?? null) ? (int) $data['database_part_bytes'] : $defaults->databasePartBytes,
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'time_budget_seconds' => $this->timeBudgetSeconds,
            'memory_mb' => $this->memoryMb,
            'batch_rows' => $this->batchRows,
            'database_part_bytes' => $this->databasePartBytes,
        ];
    }
}
