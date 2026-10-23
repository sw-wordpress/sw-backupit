<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Support;

use SWBackupIt\Core\Backup\BackupRunner;
use SWBackupIt\Core\Backup\BackupRunnerFactory;
use SWBackupIt\Core\Backup\JobFactory;
use SWBackupIt\Core\Backup\SourceInfo;
use SWBackupIt\Core\Backup\StaticSourceInfoProvider;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Event\EventDispatcher;
use SWBackupIt\Core\Job\FileJobRepository;
use SWBackupIt\Core\Job\Job;
use SWBackupIt\Core\Log\FileJobEventStore;

/** Files-only backups (no database needed) for engine and restore tests. */
trait FilesBackupFixture
{
    use SiteFixture;
    use TempDir;

    protected string $site = '';
    protected string $staging = '';
    protected string $jobsDir = '';
    protected FileJobRepository $repository;
    protected MutableClock $clock;
    protected EventDispatcher $dispatcher;
    protected SourceInfo $source;

    /**
     * @param array<string, mixed> $profileOverrides
     * @param list<\SWBackupIt\Core\Backup\BackupStepInterface> $extraSteps
     */
    protected function setUpFilesBackup(array $profileOverrides = [], array $extraSteps = []): BackupRunner
    {
        $this->site = $this->makeTempDir('site');
        $this->buildSiteFiles($this->site);
        $work = $this->makeTempDir('work');
        $this->staging = $work . '/staging';
        $this->jobsDir = $work . '/jobs';
        $this->clock = new MutableClock();
        $this->dispatcher = new EventDispatcher();
        $this->repository = new FileJobRepository($this->jobsDir);
        $this->source = new SourceInfo($this->site, $this->staging, 'https://old.example.com', 'https://old.example.com', '6.8', 'wp_');
        $this->profile = BackupProfile::fromArray($profileOverrides + [
            'id' => 1, 'name' => 'Files', 'type' => 'files', 'volume_size_bytes' => 250000,
            'limits' => ['time_budget_seconds' => 1],
        ]);

        return new BackupRunner(
            $this->repository,
            new FileJobEventStore($this->jobsDir),
            new StaticSourceInfoProvider($this->source),
            array_merge(BackupRunnerFactory::defaultSteps(), $extraSteps),
            $this->dispatcher,
            $this->clock,
        );
    }

    protected BackupProfile $profile;

    protected function newJob(): Job
    {
        $job = (new JobFactory($this->clock))->create($this->profile);
        $this->repository->create($job);

        return $job;
    }

    protected function backupDir(Job $job): string
    {
        return $this->staging . '/' . $job->backupId;
    }
}
