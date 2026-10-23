<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Domain;

enum JobStatus: string
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    case Scanning = 'scanning';
    case Exporting = 'exporting';
    case Archiving = 'archiving';
    case Encrypting = 'encrypting';
    case Uploading = 'uploading';
    case Verifying = 'verifying';
    case Completed = 'completed';
    case Paused = 'paused';
    case RetryWaiting = 'retry_waiting';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    /** Statuses in which a worker is (or may be) processing a step. */
    public function isActive(): bool
    {
        return in_array($this, [
            self::Preparing,
            self::Scanning,
            self::Exporting,
            self::Archiving,
            self::Encrypting,
            self::Uploading,
            self::Verifying,
        ], true);
    }

    /**
     * @return list<JobStatus>
     */
    public function allowedTransitions(): array
    {
        $active = [
            self::Preparing,
            self::Scanning,
            self::Exporting,
            self::Archiving,
            self::Encrypting,
            self::Uploading,
            self::Verifying,
        ];

        return match (true) {
            $this === self::Pending => [...$active, self::Paused, self::Failed, self::Cancelled],
            $this->isActive() => [
                ...$active,
                self::Completed,
                self::Paused,
                self::RetryWaiting,
                self::Failed,
                self::Cancelled,
            ],
            $this === self::Paused => [...$active, self::Pending, self::Failed, self::Cancelled],
            $this === self::RetryWaiting => [...$active, self::Pending, self::Failed, self::Cancelled],
            // A failed job may be retried manually; it resumes from its checkpoints.
            $this === self::Failed => [self::RetryWaiting, self::Pending],
            default => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
