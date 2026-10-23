<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Job;

use DateTimeImmutable;
use DateTimeZone;
use SWBackupIt\Core\Domain\BackupType;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Domain\Step;

/**
 * Persistent backup job. State changes go through JobStateMachine so they are
 * validated and always followed by a repository save.
 */
final class Job
{
    public JobStatus $status = JobStatus::Pending;
    public ?Step $step = null;
    /** Status to return to after pause. */
    public ?JobStatus $resumeStatus = null;
    public ?DateTimeImmutable $startedAt = null;
    public DateTimeImmutable $updatedAt;
    public ?DateTimeImmutable $finishedAt = null;
    public JobProgress $progress;
    /** @var array{code: string, message: string, retryable: bool}|null */
    public ?array $error = null;
    /** @var array<string, array<string, mixed>> Checkpoint data keyed by step id. */
    public array $checkpoints = [];
    public int $retryCount = 0;
    public ?DateTimeImmutable $nextRetryAt = null;
    public bool $cancelRequested = false;
    public bool $pauseRequested = false;
    public int $warningsCount = 0;
    /** @var list<string> Step ids that finished completely (resume skips them). */
    public array $completedSteps = [];
    public ?string $leaseOwner = null;
    public ?DateTimeImmutable $leaseExpiresAt = null;

    /**
     * @param array<string, mixed> $profileSnapshot Immutable copy of the profile at job start.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $backupId,
        public readonly ?int $profileId,
        public readonly array $profileSnapshot,
        public readonly BackupType $type,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?int $scheduleId = null,
        public readonly string $trigger = 'manual',
    ) {
        $this->updatedAt = $createdAt;
        $this->progress = new JobProgress();
    }

    /**
     * @return array<string, mixed>
     */
    public function checkpoint(Step $step): array
    {
        return $this->checkpoints[$step->value] ?? [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setCheckpoint(Step $step, array $data): void
    {
        $this->checkpoints[$step->value] = $data;
    }

    public function hasLeaseAt(DateTimeImmutable $now): bool
    {
        return $this->leaseOwner !== null && $this->leaseExpiresAt !== null && $this->leaseExpiresAt > $now;
    }

    public function profileName(): string
    {
        $name = $this->profileSnapshot['name'] ?? '';

        return is_string($name) ? $name : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $format = static fn (?DateTimeImmutable $date): ?string => $date?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

        return [
            'id' => $this->id,
            'backup_id' => $this->backupId,
            'profile_id' => $this->profileId,
            'schedule_id' => $this->scheduleId,
            'trigger' => $this->trigger,
            'profile_snapshot' => $this->profileSnapshot,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'step' => $this->step?->value,
            'resume_status' => $this->resumeStatus?->value,
            'created_at' => $format($this->createdAt),
            'started_at' => $format($this->startedAt),
            'updated_at' => $format($this->updatedAt),
            'finished_at' => $format($this->finishedAt),
            'progress' => $this->progress->toArray(),
            'error' => $this->error,
            'checkpoints' => $this->checkpoints,
            'completed_steps' => $this->completedSteps,
            'retry_count' => $this->retryCount,
            'next_retry_at' => $format($this->nextRetryAt),
            'cancel_requested' => $this->cancelRequested,
            'pause_requested' => $this->pauseRequested,
            'warnings_count' => $this->warningsCount,
            'lease_owner' => $this->leaseOwner,
            'lease_expires_at' => $format($this->leaseExpiresAt),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $parse = static fn (mixed $value): ?DateTimeImmutable => is_string($value) && $value !== ''
            ? new DateTimeImmutable($value, new DateTimeZone('UTC'))
            : null;
        $snapshot = is_array($data['profile_snapshot'] ?? null) ? $data['profile_snapshot'] : [];
        $job = new self(
            (string) $data['id'],
            (string) $data['backup_id'],
            isset($data['profile_id']) ? (int) $data['profile_id'] : null,
            $snapshot,
            BackupType::from((string) $data['type']),
            $parse($data['created_at'] ?? null) ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
            isset($data['schedule_id']) ? (int) $data['schedule_id'] : null,
            is_string($data['trigger'] ?? null) ? $data['trigger'] : 'manual',
        );
        $job->status = JobStatus::from((string) $data['status']);
        $job->step = is_string($data['step'] ?? null) ? Step::from($data['step']) : null;
        $job->resumeStatus = is_string($data['resume_status'] ?? null) ? JobStatus::from($data['resume_status']) : null;
        $job->startedAt = $parse($data['started_at'] ?? null);
        $job->updatedAt = $parse($data['updated_at'] ?? null) ?? $job->createdAt;
        $job->finishedAt = $parse($data['finished_at'] ?? null);
        $job->progress = JobProgress::fromArray(is_array($data['progress'] ?? null) ? $data['progress'] : []);
        $error = $data['error'] ?? null;
        if (is_array($error) && isset($error['code'], $error['message'])) {
            $job->error = [
                'code' => (string) $error['code'],
                'message' => (string) $error['message'],
                'retryable' => (bool) ($error['retryable'] ?? false),
            ];
        }
        $job->checkpoints = is_array($data['checkpoints'] ?? null) ? $data['checkpoints'] : [];
        $job->completedSteps = array_values(array_filter(
            is_array($data['completed_steps'] ?? null) ? $data['completed_steps'] : [],
            'is_string',
        ));
        $job->retryCount = (int) ($data['retry_count'] ?? 0);
        $job->nextRetryAt = $parse($data['next_retry_at'] ?? null);
        $job->cancelRequested = (bool) ($data['cancel_requested'] ?? false);
        $job->pauseRequested = (bool) ($data['pause_requested'] ?? false);
        $job->warningsCount = (int) ($data['warnings_count'] ?? 0);
        $job->leaseOwner = is_string($data['lease_owner'] ?? null) ? $data['lease_owner'] : null;
        $job->leaseExpiresAt = $parse($data['lease_expires_at'] ?? null);

        return $job;
    }
}
