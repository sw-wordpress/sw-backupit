<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup;

use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Job\Job;
use SWBackupIt\Core\Support\ClockInterface;
use SWBackupIt\Core\Support\SystemClock;
use SWBackupIt\Core\Support\Uuid;

final class JobFactory
{
    public function __construct(private readonly ClockInterface $clock = new SystemClock())
    {
    }

    /** Creates a pending job holding an immutable snapshot of the profile. */
    public function create(BackupProfile $profile, string $trigger = 'manual', ?int $scheduleId = null): Job
    {
        return new Job(
            Uuid::v4(),
            Uuid::v4(),
            $profile->id,
            $profile->toArray(),
            $profile->type,
            $this->clock->now(),
            $scheduleId,
            $trigger,
        );
    }
}
