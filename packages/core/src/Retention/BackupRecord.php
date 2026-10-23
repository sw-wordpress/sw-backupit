<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Retention;

use DateTimeImmutable;
use SWBackupIt\Core\Domain\BackupType;

/** What retention needs to know about a backup (independent of where it is stored). */
final readonly class BackupRecord
{
    /**
     * @param bool $reliable False when metadata is incomplete/inconsistent: such backups are never deleted automatically.
     */
    public function __construct(
        public string $id,
        public BackupType $type,
        public DateTimeImmutable $createdAt,
        public int $sizeBytes,
        public ?string $parentId = null,
        public bool $protected = false,
        public bool $reliable = true,
        public ?int $profileId = null,
    ) {
    }
}
