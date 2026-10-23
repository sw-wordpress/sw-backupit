<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup;

use SWBackupIt\Core\Job\Job;

final class StaticSourceInfoProvider implements SourceInfoProviderInterface
{
    public function __construct(private readonly SourceInfo $source)
    {
    }

    public function forJob(Job $job): SourceInfo
    {
        return $this->source;
    }
}
