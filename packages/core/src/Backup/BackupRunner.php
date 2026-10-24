<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup;

use Psr\Log\LogLevel;
use SWBackupIt\Core\Crypto\KeyProviderInterface;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Event\EventDispatcher;
use SWBackupIt\Core\Event\EventDispatcherInterface;
use SWBackupIt\Core\Event\Events;
use SWBackupIt\Core\Event\ListenerVetoException;
use SWBackupIt\Core\Exception\JobNotFoundException;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Job\Job;
use SWBackupIt\Core\Job\JobRepositoryInterface;
use SWBackupIt\Core\Job\JobStateMachine;
use SWBackupIt\Core\Log\JobEventStoreInterface;
use SWBackupIt\Core\Log\JobLogger;
use SWBackupIt\Core\Retention\BackupCatalogInterface;
use SWBackupIt\Core\Storage\StorageManager;
use SWBackupIt\Core\Support\ClockInterface;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Core\Support\SystemClock;
use Throwable;

/**
 * Executes backup jobs in short, resumable ticks.
 *
 * One tick: acquire lease -> run steps until the time budget is used up or the job ends ->
 * persist -> release lease. No process has to stay alive between ticks, so this works with
 * WP-Cron, browser polling or a CLI loop alike. A crashed worker only blocks the job until its lease expires.
 */
final class BackupRunner
{
    public const MAX_RETRIES = 3;

    /** @var list<BackupStepInterface> Steps in execution order. */
    private array $steps;
    private readonly JobStateMachine $machine;

    /**
     * @param list<BackupStepInterface> $steps
     */
    public function __construct(
        private readonly JobRepositoryInterface $jobs,
        private readonly JobEventStoreInterface $events,
        private readonly SourceInfoProviderInterface $sources,
        array $steps,
        private readonly EventDispatcherInterface $dispatcher = new EventDispatcher(),
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly string $logLevel = LogLevel::INFO,
        private readonly ?KeyProviderInterface $keys = null,
        private readonly ?StorageManager $storage = null,
        private readonly ?BackupCatalogInterface $catalog = null,
    ) {
        $order = array_map(static fn (Step $step): string => $step->value, Step::cases());
        usort($steps, static fn (BackupStepInterface $a, BackupStepInterface $b): int => array_search($a->step()->value, $order, true) <=> array_search($b->step()->value, $order, true));
        $this->steps = $steps;
        $this->machine = new JobStateMachine($clock);
    }

    public function stateMachine(): JobStateMachine
    {
        return $this->machine;
    }

    public function tick(string $jobId, ?float $budgetSeconds = null): TickResult
    {
        $job = $this->jobs->find($jobId);
        $profile = BackupProfile::fromArray($job->profileSnapshot);
        $budgetSeconds ??= (float) $profile->limits->timeBudgetSeconds;
        $owner = bin2hex(random_bytes(8));
        $leaseTtl = (int) ceil($budgetSeconds * 3) + 60;

        if ($job->status->isTerminal()) {
            return new TickResult($job->status, false, 'job is finished');
        }
        if (!$this->jobs->acquireLease($jobId, $owner, $leaseTtl, $this->clock->now())) {
            return new TickResult($job->status, false, 'job is locked by another worker');
        }

        $logger = new JobLogger($this->events, $jobId, $this->logLevel);
        try {
            $job = $this->jobs->find($jobId);
            $result = $this->process($job, $profile, $logger, $owner, $budgetSeconds, $leaseTtl);
        } finally {
            $this->jobs->releaseLease($jobId, $owner);
        }

        return $result;
    }

    private function process(Job $job, BackupProfile $profile, JobLogger $logger, string $owner, float $budgetSeconds, int $leaseTtl): TickResult
    {
        $budget = new ResourceBudget($budgetSeconds, $profile->limits->memoryMb * 1048576);
        $commit = function () use ($job, $owner, $leaseTtl): void {
            $job->updatedAt = $this->clock->now();
            $this->jobs->save($job);
            $this->jobs->renewLease($job->id, $owner, $leaseTtl, $this->clock->now());
        };

        if ($job->status === JobStatus::Paused) {
            return new TickResult($job->status, false, 'job is paused');
        }
        if ($job->status === JobStatus::RetryWaiting && $job->nextRetryAt !== null && $job->nextRetryAt > $this->clock->now()) {
            return new TickResult($job->status, false, 'waiting for retry');
        }
        if ($job->status === JobStatus::RetryWaiting || $job->status === JobStatus::Failed) {
            $logger->info('Retrying job.', ['retry' => $job->retryCount]);
            $job->nextRetryAt = null;
        }

        $source = $this->sources->forJob($job);
        $context = new BackupContext($job, $profile, new BackupLayout($source->stagingPath, $job->backupId), $source, $logger, $this->dispatcher, $this->clock, $budget, $commit, $this->keys, $this->storage, $this->catalog);

        try {
            while (true) {
                $flag = $this->applyControlFlags($job, $context, $logger);
                if ($flag !== null) {
                    return $flag;
                }
                $step = $this->nextStep($job, $profile);
                if ($step === null) {
                    $this->machine->complete($job);
                    $commit();
                    $logger->info('Backup completed.', ['backup_id' => $job->backupId]);
                    $job->warningsCount = max($job->warningsCount, $logger->warningCount());
                    $commit();
                    $this->dispatcher->dispatch(Events::AFTER_BACKUP, ['job_id' => $job->id, 'backup_id' => $job->backupId, 'status' => 'completed', 'warnings' => $job->warningsCount]);

                    return new TickResult(JobStatus::Completed, false);
                }
                $logger->setPhase($step->step()->value);
                $this->machine->enterStep($job, $step->step());
                $commit();
                $stepPayload = ['job_id' => $job->id, 'backup_id' => $job->backupId, 'step' => $step->step()->value, 'status' => $job->status->value];
                $this->dispatcher->dispatch(Events::BEFORE_STEP, $stepPayload);
                try {
                    $outcome = $step->run($context);
                } catch (Throwable $stepError) {
                    $this->dispatcher->dispatch(Events::STEP_FAILED, $stepPayload + ['error' => $stepError instanceof SwbiException ? $stepError->getMessage() : $stepError::class]);
                    throw $stepError;
                }
                $this->dispatcher->dispatch(Events::AFTER_STEP, $stepPayload + ['outcome' => $outcome === StepOutcome::Done ? 'done' : 'yield']);
                $job->warningsCount = max($job->warningsCount, $logger->warningCount());
                if ($outcome === StepOutcome::Done) {
                    $job->completedSteps[] = $step->step()->value;
                    $commit();
                    if ($budget->exhausted()) {
                        return new TickResult($job->status, true, 'time budget used up');
                    }
                    continue;
                }
                $commit();

                return new TickResult($job->status, true, 'step continues in next tick');
            }
        } catch (ListenerVetoException $exception) {
            return $this->fail($job, $logger, $commit, 'swbi_vetoed', $exception->getMessage(), false, $exception);
        } catch (Throwable $throwable) {
            return $this->handleFailure($job, $logger, $commit, $throwable);
        }
    }

    private function nextStep(Job $job, BackupProfile $profile): ?BackupStepInterface
    {
        $done = $job->completedSteps;
        foreach ($this->steps as $step) {
            if (in_array($step->step()->value, $done, true) || !$step->applies($profile)) {
                continue;
            }

            return $step;
        }

        return null;
    }

    /** Honors cancel/pause requests between work units. */
    private function applyControlFlags(Job $job, BackupContext $context, JobLogger $logger): ?TickResult
    {
        $fresh = $this->jobs->find($job->id);
        $job->cancelRequested = $fresh->cancelRequested;
        $job->pauseRequested = $fresh->pauseRequested;
        if ($job->cancelRequested) {
            $this->machine->cancel($job);
            $this->jobs->save($job);
            $this->jobs->clearFlags($job->id);
            $logger->warning('Job cancelled by user.');
            $context->layout->removeAll();

            return new TickResult(JobStatus::Cancelled, false);
        }
        if ($job->pauseRequested) {
            $this->machine->pause($job);
            $this->jobs->save($job);
            $this->jobs->clearFlags($job->id);
            $logger->info('Job paused by user.');

            return new TickResult(JobStatus::Paused, false);
        }

        return null;
    }

    private function handleFailure(Job $job, JobLogger $logger, \Closure $commit, Throwable $throwable): TickResult
    {
        $retryable = $throwable instanceof SwbiException && $throwable->isRetryable();
        $message = $throwable instanceof SwbiException ? $throwable->getMessage() : 'An unexpected error occurred.';
        $context = $throwable instanceof SwbiException ? $throwable->context() : [];
        $logger->error($message, $context + ['exception' => $throwable::class]);

        if ($retryable && $job->retryCount < self::MAX_RETRIES) {
            $job->retryCount++;
            $delay = (int) min(900, 30 * (2 ** ($job->retryCount - 1)));
            $delay += random_int(0, (int) ($delay / 4));
            $job->nextRetryAt = $this->clock->now()->modify('+' . $delay . ' seconds');
            $job->error = ['code' => 'swbi_transient', 'message' => $message, 'retryable' => true];
            if (!$job->status->isActive()) {
                $job->status = JobStatus::Preparing;
            }
            $this->machine->transition($job, JobStatus::RetryWaiting);
            $commit();
            $logger->warning('Transient error, job will be retried.', ['retry' => $job->retryCount, 'in_seconds' => $delay]);

            return new TickResult(JobStatus::RetryWaiting, false, 'retry scheduled');
        }

        return $this->fail($job, $logger, $commit, $throwable instanceof SwbiException ? 'swbi_' . $this->codeFor($throwable) : 'swbi_unexpected', $message, $retryable, $throwable);
    }

    private function fail(Job $job, JobLogger $logger, \Closure $commit, string $code, string $message, bool $retryable, Throwable $cause): TickResult
    {
        if (!$job->status->canTransitionTo(JobStatus::Failed)) {
            $job->status = JobStatus::Preparing;
        }
        $this->machine->fail($job, ['code' => $code, 'message' => $message, 'retryable' => $retryable]);
        $commit();
        $this->dispatcher->dispatch(Events::BACKUP_FAILED, ['job_id' => $job->id, 'backup_id' => $job->backupId, 'error' => $message, 'code' => $code]);

        return new TickResult(JobStatus::Failed, false, $cause::class);
    }

    private function codeFor(SwbiException $exception): string
    {
        $name = (new \ReflectionClass($exception))->getShortName();

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', str_replace('Exception', '', $name)));
    }

    /** Convenience for CLI/tests: runs ticks until the job reaches a resting state. */
    public function runToCompletion(string $jobId, ?float $budgetSeconds = null, int $maxTicks = 100000): JobStatus
    {
        $status = null;
        for ($i = 0; $i < $maxTicks; $i++) {
            try {
                $result = $this->tick($jobId, $budgetSeconds);
            } catch (JobNotFoundException $exception) {
                throw $exception;
            }
            $status = $result->status;
            if (!$result->shouldContinue) {
                break;
            }
        }

        return $status ?? JobStatus::Pending;
    }
}
