<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup;

use SWBackupIt\Core\Backup\Step\ArchiveStep;
use SWBackupIt\Core\Backup\Step\EncryptStep;
use SWBackupIt\Core\Backup\Step\ExportStep;
use SWBackupIt\Core\Backup\Step\FinalizeStep;
use SWBackupIt\Core\Backup\Step\ManifestStep;
use SWBackupIt\Core\Backup\Step\PreflightStep;
use SWBackupIt\Core\Backup\Step\ScanStep;
use SWBackupIt\Core\Backup\Step\UploadStep;
use SWBackupIt\Core\Backup\Step\VerifyLocalStep;
use SWBackupIt\Core\Backup\Step\VerifyRemoteStep;
use SWBackupIt\Core\Crypto\KeyProviderInterface;
use SWBackupIt\Core\Event\EventDispatcher;
use SWBackupIt\Core\Event\EventDispatcherInterface;
use SWBackupIt\Core\Job\FileJobRepository;
use SWBackupIt\Core\Job\JobRepositoryInterface;
use SWBackupIt\Core\Log\FileJobEventStore;
use SWBackupIt\Core\Log\JobEventStoreInterface;
use SWBackupIt\Core\Storage\StorageManager;
use SWBackupIt\Core\Support\SystemClock;

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
            new EncryptStep(),
            new VerifyLocalStep(),
            new ManifestStep(),
            new UploadStep(),
            new VerifyRemoteStep(),
            new FinalizeStep(),
        ];
    }

    public static function create(
        JobRepositoryInterface $jobs,
        JobEventStoreInterface $events,
        SourceInfoProviderInterface $sources,
        ?EventDispatcherInterface $dispatcher = null,
        ?KeyProviderInterface $keys = null,
        ?StorageManager $storage = null,
    ): BackupRunner {
        return new BackupRunner($jobs, $events, $sources, self::defaultSteps(), $dispatcher ?? new EventDispatcher(), new SystemClock(), 'info', $keys, $storage);
    }

    /** File based runner (CLI, tests, standalone use). */
    public static function createFileBased(
        string $jobsDirectory,
        SourceInfo $source,
        ?EventDispatcherInterface $dispatcher = null,
        ?KeyProviderInterface $keys = null,
        ?StorageManager $storage = null,
    ): BackupRunner {
        return self::create(
            new FileJobRepository($jobsDirectory),
            new FileJobEventStore($jobsDirectory),
            new StaticSourceInfoProvider($source),
            $dispatcher,
            $keys,
            $storage,
        );
    }
}
