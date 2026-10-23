<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup;

use SWBackupIt\Core\Domain\JobStatus;

final readonly class TickResult
{
    public function __construct(
        public JobStatus $status,
        /** Whether calling tick() again can make progress right away. */
        public bool $shouldContinue,
        public string $note = '',
    ) {
    }
}
