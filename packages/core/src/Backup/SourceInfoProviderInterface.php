<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup;

use SWBackupIt\Core\Job\Job;

/** Resolves the system description for a job at execution time (credentials are never stored in jobs). */
interface SourceInfoProviderInterface
{
    public function forJob(Job $job): SourceInfo;
}
