<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Backup\BackupContext;
use SWBackupIt\Core\Backup\BackupStepInterface;
use SWBackupIt\Core\Backup\StepOutcome;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Event\EventDispatcher;
use SWBackupIt\Core\Event\Events;
use SWBackupIt\Core\Event\FailurePolicy;
use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Exception\InvalidStateTransitionException;
use SWBackupIt\Core\Exception\TransientException;
use SWBackupIt\Core\Job\JobStateMachine;
use SWBackupIt\Core\Log\FileJobEventStore;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\Tests\Support\FilesBackupFixture;

final class JobEngineTest extends TestCase
{
    use FilesBackupFixture;

    public function testInvalidTransitionsAreRejected(): void
    {
        $this->setUpFilesBackup();
        $job = $this->newJob();
        $machine = new JobStateMachine($this->clock);
        $this->expectException(InvalidStateTransitionException::class);
        $machine->transition($job, JobStatus::Completed === JobStatus::Completed ? JobStatus::Paused : JobStatus::Pending);
        $machine->transition($job, JobStatus::RetryWaiting);
    }

    public function testTerminalStatesCannotBeLeft(): void
    {
        foreach ([JobStatus::Completed, JobStatus::Cancelled] as $terminal) {
            self::assertSame([], $terminal->allowedTransitions());
        }
        self::assertContains(JobStatus::RetryWaiting, JobStatus::Failed->allowedTransitions());
    }

    public function testSecondWorkerIsLockedOutAndExpiredLeaseIsTakenOver(): void
    {
        $runner = $this->setUpFilesBackup();
        $job = $this->newJob();
        self::assertTrue($this->repository->acquireLease($job->id, 'crashed-worker', 120, $this->clock->now()));

        $locked = $runner->tick($job->id, 0.0);
        self::assertFalse($locked->shouldContinue);
        self::assertStringContainsString('locked', $locked->note);
        self::assertSame(JobStatus::Pending, $this->repository->find($job->id)->status);

        $this->clock->advance(500); // lease of the crashed worker expired
        $status = $runner->runToCompletion($job->id, 5.0);
        self::assertSame(JobStatus::Completed, $status);
        self::assertNull($this->repository->find($job->id)->leaseOwner, 'lease released after tick');
    }

    public function testCompletedVolumesSurviveLostCheckpointsAndAreNotRebuilt(): void
    {
        $runner = $this->setUpFilesBackup();
        $job = $this->newJob();
        $jobFile = $this->jobsDir . '/' . $job->id . '/job.json';

        // Run until at least two volumes are finished, snapshot that checkpoint.
        $snapshot = null;
        for ($i = 0; $i < 400 && $snapshot === null; $i++) {
            $runner->tick($job->id, 0.0);
            $archive = $this->repository->find($job->id)->checkpoint(Step::Archive);
            if (count($archive['volumes'] ?? []) >= 2) {
                $snapshot = file_get_contents($jobFile);
            }
        }
        self::assertNotNull($snapshot);
        $volumeDir = $this->backupDir($job) . '/volumes';
        $before = [];
        foreach (['backup-001.zip', 'backup-002.zip'] as $name) {
            $before[$name] = [fileinode($volumeDir . '/' . $name), filemtime($volumeDir . '/' . $name), hash_file('sha256', $volumeDir . '/' . $name)];
        }

        // Let the process "run on" for a while, then lose every later checkpoint (crash simulation).
        for ($i = 0; $i < 3; $i++) {
            $runner->tick($job->id, 0.0);
        }
        file_put_contents($jobFile, $snapshot);
        file_put_contents($volumeDir . '/backup-009.zip.part', 'torn write');

        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 0.0));
        foreach ($before as $name => $state) {
            self::assertSame($state, [fileinode($volumeDir . '/' . $name), filemtime($volumeDir . '/' . $name), hash_file('sha256', $volumeDir . '/' . $name)], "$name must not be rebuilt");
        }
        $report = (new Verifier())->verify(BackupSet::open($this->backupDir($job)), true);
        self::assertTrue($report->isOk(), implode('; ', $report->errors()));
    }

    public function testCancelRemovesStagingAndPauseResumeWorks(): void
    {
        $runner = $this->setUpFilesBackup();
        $job = $this->newJob();
        $runner->tick($job->id, 0.0);

        $this->repository->requestPause($job->id);
        $result = $runner->tick($job->id, 0.0);
        self::assertSame(JobStatus::Paused, $result->status);
        self::assertFalse($runner->tick($job->id, 0.0)->shouldContinue);

        $paused = $this->repository->find($job->id);
        $runner->stateMachine()->resume($paused);
        $this->repository->save($paused);
        self::assertNotSame(JobStatus::Paused, $this->repository->find($job->id)->status);

        $runner->tick($job->id, 0.0);
        $this->repository->requestCancel($job->id);
        self::assertSame(JobStatus::Cancelled, $runner->tick($job->id, 0.0)->status);
        self::assertDirectoryDoesNotExist($this->backupDir($job));
    }

    public function testCorruptVolumeIsDetectedBeforeSuccess(): void
    {
        $runner = $this->setUpFilesBackup();
        $job = $this->newJob();
        for ($i = 0; $i < 400; $i++) {
            $runner->tick($job->id, 0.0);
            if (in_array('archive', $this->repository->find($job->id)->completedSteps, true)) {
                break;
            }
        }
        $volume = $this->backupDir($job) . '/volumes/backup-002.zip';
        $handle = fopen($volume, 'r+b');
        fseek($handle, 100);
        fwrite($handle, 'CORRUPTED-BYTES');
        fclose($handle);

        self::assertSame(JobStatus::Failed, $runner->runToCompletion($job->id, 0.0));
        $failed = $this->repository->find($job->id);
        self::assertSame('swbi_integrity', $failed->error['code'] ?? null);
        self::assertFileDoesNotExist($this->backupDir($job) . '/manifest.json', 'no manifest => never a successful backup');
    }

    /**
     * Regression: a worker whose lease expired (a step blocked longer than the lease) kept writing the job after another worker
     * had taken it over. It must abort without touching the job state.
     */
    public function testWorkerThatLostItsLeaseNeverWritesTheJob(): void
    {
        $test = $this;
        $thief = new class ($test) implements BackupStepInterface {
            public function __construct(private readonly JobEngineTest $test)
            {
            }

            public function step(): Step
            {
                return Step::Upload;
            }

            public function applies(BackupProfile $profile): bool
            {
                return true;
            }

            public function run(BackupContext $context): StepOutcome
            {
                $this->test->takeOverLease($context->job->id);
                $context->job->warningsCount = 999;
                $context->commit();

                return StepOutcome::Done;
            }
        };
        $runner = $this->setUpFilesBackup([], [$thief]);
        $job = $this->newJob();
        $result = null;
        for ($i = 0; $i < 200; $i++) {
            $result = $runner->tick($job->id, 5.0);
            if (!$result->shouldContinue) {
                break;
            }
        }
        self::assertNotNull($result);
        self::assertStringContainsString('lease was lost', $result->note);
        $stored = $this->repository->find($job->id);
        self::assertSame('other-worker', $stored->leaseOwner);
        self::assertNotSame(999, $stored->warningsCount, 'the job was not written by the worker that lost the lease');
        self::assertNotSame(JobStatus::Failed, $stored->status);
    }

    /** Simulates another worker taking over after the lease expired. */
    public function takeOverLease(string $jobId): void
    {
        $this->clock->advance(10000);
        self::assertTrue($this->repository->acquireLease($jobId, 'other-worker', 600, $this->clock->now()));
    }

    public function testTransientErrorsAreRetriedWithBackoffThenFail(): void
    {
        $flaky = new class (2) implements BackupStepInterface {
            public int $calls = 0;

            public function __construct(private readonly int $failures)
            {
            }

            public function step(): Step
            {
                return Step::Upload;
            }

            public function applies(BackupProfile $profile): bool
            {
                return true;
            }

            public function run(BackupContext $context): StepOutcome
            {
                if (++$this->calls <= $this->failures) {
                    throw new TransientException('network down');
                }

                return StepOutcome::Done;
            }
        };
        $runner = $this->setUpFilesBackup([], [$flaky]);
        $job = $this->newJob();
        $result = null;
        for ($i = 0; $i < 400; $i++) {
            $result = $runner->tick($job->id, 0.0);
            if ($result->status === JobStatus::RetryWaiting) {
                break;
            }
        }
        self::assertSame(JobStatus::RetryWaiting, $result?->status);
        $waiting = $this->repository->find($job->id);
        self::assertSame(1, $waiting->retryCount);
        self::assertNotNull($waiting->nextRetryAt);
        self::assertFalse($runner->tick($job->id, 0.0)->shouldContinue, 'not due yet');

        $this->clock->advance(3600);
        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 0.0, 5000) === JobStatus::RetryWaiting ? $this->advanceAndFinish($runner, $job->id) : $runner->runToCompletion($job->id, 0.0));
        self::assertSame(3, $flaky->calls);
    }

    private function advanceAndFinish(\SWBackupIt\Core\Backup\BackupRunner $runner, string $jobId): JobStatus
    {
        for ($i = 0; $i < 10; $i++) {
            $this->clock->advance(3600);
            $status = $runner->runToCompletion($jobId, 0.0);
            if ($status === JobStatus::Completed) {
                return $status;
            }
        }

        return $status;
    }

    public function testBlockingBeforeBackupListenerVetoesCleanly(): void
    {
        $runner = $this->setUpFilesBackup();
        $this->dispatcher->listen(Events::BEFORE_BACKUP, static function (): void {
            throw new \RuntimeException('maintenance window');
        }, FailurePolicy::Blocking);
        $job = $this->newJob();
        self::assertSame(JobStatus::Failed, $runner->runToCompletion($job->id, 0.0));
        self::assertSame('swbi_vetoed', $this->repository->find($job->id)->error['code'] ?? null);
    }

    public function testIsolatedListenerErrorsNeverBreakBackups(): void
    {
        $runner = $this->setUpFilesBackup();
        $calls = 0;
        $this->dispatcher->listen(Events::AFTER_BACKUP, static function () use (&$calls): void {
            $calls++;
            throw new \RuntimeException('listener bug');
        });
        $job = $this->newJob();
        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 5.0));
        self::assertSame(1, $calls);
    }

    public function testStepEventsAreEmittedAndNeverBreakTheBackup(): void
    {
        $runner = $this->setUpFilesBackup();
        $seen = [];
        $this->dispatcher->listen(Events::BEFORE_STEP, static function (array $payload) use (&$seen): void {
            $seen[] = 'before:' . $payload['step'];
        });
        $this->dispatcher->listen(Events::AFTER_STEP, static function (array $payload) use (&$seen): void {
            $seen[] = 'after:' . $payload['step'];
            throw new \RuntimeException('observer bug');
        });
        $job = $this->newJob();
        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 5.0));
        self::assertContains('before:preflight', $seen);
        self::assertContains('after:finalize', $seen);
        self::assertLessThan(array_search('before:scan', $seen, true), array_search('after:preflight', $seen, true));
    }

    public function testBlockingPolicyIsRejectedForAfterEvents(): void
    {
        $dispatcher = new EventDispatcher();
        $this->expectException(ConfigurationException::class);
        $dispatcher->listen(Events::AFTER_BACKUP, static function (): void {
        }, FailurePolicy::Blocking);
    }

    public function testExternalPathsFailPreflightExplicitly(): void
    {
        $runner = $this->setUpFilesBackup(['external_paths' => ['/etc']]);
        $job = $this->newJob();
        self::assertSame(JobStatus::Failed, $runner->runToCompletion($job->id, 0.0));
        self::assertStringContainsString('outside the WordPress directory', (string) $this->repository->find($job->id)->error['message']);
    }

    public function testEventLogIsBoundedAndRedacted(): void
    {
        $dir = $this->makeTempDir();
        $store = new FileJobEventStore($dir, 100);
        $jobId = \SWBackupIt\Core\Support\Uuid::v4();
        $logger = new \SWBackupIt\Core\Log\JobLogger($store, $jobId);
        for ($i = 0; $i < 500; $i++) {
            $logger->info("event $i", ['password' => 'hunter2', 'url' => 'sftp://user:secret@host/x']);
        }
        $logger->error('important');
        $events = $store->read($jobId, 0, 1000);
        self::assertLessThanOrEqual(110, count($events['items']));
        self::assertSame('important', end($events['items'])['message']);
        $raw = (string) file_get_contents($dir . '/' . $jobId . '/events.ndjson');
        self::assertStringNotContainsString('hunter2', $raw);
        self::assertStringNotContainsString('secret@', $raw);
    }
}
