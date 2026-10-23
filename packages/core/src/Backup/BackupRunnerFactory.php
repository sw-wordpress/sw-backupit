<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup;

use SWBackupIt\Core\Backup\Step\ArchiveStep;
use SWBackupIt\Core\Backup\Step\ExportStep;
use SWBackupIt\Core\Backup\Step\FinalizeStep;
use SWBackupIt\Core\Backup\Step\PreflightStep;
use SWBackupIt\Core\Backup\Step\ScanStep;
use SWBackupIt\Core\Backup\Step\VerifyLocalStep;
use SWBackupIt\Core\Event\EventDispatcher;
use SWBackupIt\Core\Event\EventDispatcherInterface;
use SWBackupIt\Core\Job\FileJobRepository;
use SWBackupIt\Core\Job\JobRepositoryInterface;
use SWBackupIt\Core\Log\FileJobEventStore;
use SWBackupIt\Core\Log\JobEventStoreInterface;

/** Wires the default step pipeline. Later phases register additional steps here. */
final class BackupRunnerFactory
{
    /**
     * @return list<BackupStepInterface>
     */
    public static function defaultSteps(): array
    {
        return [
            new PreflightStep(),
            new ScanStep(),
            new ExportStep(),
            new ArchiveStep(),
            new VerifyLocalStep(),
            new FinalizeStep(),
        ];
    }

    public static function create(
        JobRepositoryInterface $jobs,
        JobEventStoreInterface $events,
        SourceInfoProviderInterface $sources,
        ?EventDispatcherInterface $dispatcher = null,
    ): BackupRunner {
        return new BackupRunner($jobs, $events, $sources, self::defaultSteps(), $dispatcher ?? new EventDispatcher());
    }

    /** File based runner (CLI, tests, standalone use). */
    public static function createFileBased(string $jobsDirectory, SourceInfo $source, ?EventDispatcherInterface $dispatcher = null): BackupRunner
    {
        return self::create(
            new FileJobRepository($jobsDirectory),
            new FileJobEventStore($jobsDirectory),
            new StaticSourceInfoProvider($source),
            $dispatcher,
        );
    }
}
