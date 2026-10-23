<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Retention;

/** Retention rules; a backup is kept when ANY active rule keeps it. All zero = keep everything. */
final readonly class RetentionPolicy
{
    public function __construct(
        public int $maxCount = 0,
        public int $maxAgeDays = 0,
        public int $maxStorageBytes = 0,
        public int $daily = 0,
        public int $weekly = 0,
        public int $monthly = 0,
        public int $yearly = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $data profile/settings retention array
     */
    public static function fromArray(array $data): self
    {
        $gfs = is_array($data['gfs'] ?? null) ? $data['gfs'] : [];
        $int = static fn (mixed $value): int => is_numeric($value) ? max(0, (int) $value) : 0;

        return new self(
            $int($data['max_count'] ?? 0),
            $int($data['max_age_days'] ?? 0),
            $int($data['max_storage_mb'] ?? 0) * 1048576,
            $int($gfs['daily'] ?? 0),
            $int($gfs['weekly'] ?? 0),
            $int($gfs['monthly'] ?? 0),
            $int($gfs['yearly'] ?? 0),
        );
    }

    public function isActive(): bool
    {
        return $this->maxCount > 0 || $this->maxAgeDays > 0 || $this->maxStorageBytes > 0
            || $this->daily > 0 || $this->weekly > 0 || $this->monthly > 0 || $this->yearly > 0;
    }
}
