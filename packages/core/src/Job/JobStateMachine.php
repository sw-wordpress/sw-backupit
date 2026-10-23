<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Job;

use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Exception\InvalidStateTransitionException;
use SWBackupIt\Core\Support\ClockInterface;

/**
 * The only component allowed to change Job::$status.
 *
 * It validates transitions, maintains timestamps and pause/resume bookkeeping.
 * Callers must persist the job afterwards (BackupRunner saves after every transition).
 */
final class JobStateMachine
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function transition(Job $job, JobStatus $target): void
    {
        if ($job->status === $target) {
            $job->updatedAt = $this->clock->now();

            return;
        }
        if (!$job->status->canTransitionTo($target)) {
            throw new InvalidStateTransitionException(
                'Invalid job state transition.',
                ['from' => $job->status->value, 'to' => $target->value],
            );
        }
        $now = $this->clock->now();
        if ($job->startedAt === null && $target->isActive()) {
            $job->startedAt = $now;
        }
        if ($target->isTerminal()) {
            $job->finishedAt = $now;
        } elseif ($job->status->isTerminal()) {
            // Manual retry of a failed job: reopen it.
            $job->finishedAt = null;
        }
        $job->status = $target;
        $job->updatedAt = $now;
    }

    public function enterStep(Job $job, Step $step): void
    {
        $job->step = $step;
        $job->progress->stepFraction = 0.0;
        $this->transition($job, $step->status());
    }

    public function pause(Job $job): void
    {
        $job->pauseRequested = false;
        $job->resumeStatus = $job->status->isActive() || $job->status === JobStatus::Pending ? $job->status : null;
        $this->transition($job, JobStatus::Paused);
    }

    public function resume(Job $job): void
    {
        $job->pauseRequested = false;
        $target = $job->resumeStatus ?? JobStatus::Pending;
        $job->resumeStatus = null;
        $this->transition($job, $target);
    }

    public function cancel(Job $job): void
    {
        $job->cancelRequested = false;
        $this->transition($job, JobStatus::Cancelled);
    }

    /**
     * @param array{code: string, message: string, retryable: bool} $error
     */
    public function fail(Job $job, array $error): void
    {
        $job->error = $error;
        $this->transition($job, JobStatus::Failed);
    }

    public function complete(Job $job): void
    {
        $job->error = null;
        $job->progress->stepFraction = 1.0;
        $this->transition($job, JobStatus::Completed);
    }
}
