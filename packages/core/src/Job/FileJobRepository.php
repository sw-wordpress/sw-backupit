<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Job;

use DateTimeImmutable;
use DateTimeZone;
use SWBackupIt\Core\Exception\JobNotFoundException;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\Uuid;

/**
 * Job repository storing one JSON document per job (atomic writes, flock based locking).
 */
final class FileJobRepository implements JobRepositoryInterface
{
    public function __construct(private readonly string $directory)
    {
        AtomicFile::ensureDirectory($directory);
    }

    public function create(Job $job): void
    {
        $this->withLock($job->id, function () use ($job): void {
            if (is_file($this->jobFile($job->id))) {
                throw new SwbiException('Job already exists.', ['job' => $job->id]);
            }
            AtomicFile::writeJson($this->jobFile($job->id), $job->toArray());
        });
    }

    public function find(string $jobId): Job
    {
        $file = $this->jobFile($jobId);
        if (!Uuid::isValid($jobId) || !is_file($file)) {
            throw new JobNotFoundException('Job not found.', ['job' => $jobId]);
        }

        return Job::fromArray(AtomicFile::readJson($file));
    }

    public function save(Job $job): void
    {
        $this->withLock($job->id, function () use ($job): void {
            $file = $this->jobFile($job->id);
            if (is_file($file)) {
                $stored = AtomicFile::readJson($file);
                $job->cancelRequested = $job->cancelRequested || (bool) ($stored['cancel_requested'] ?? false);
                $job->pauseRequested = $job->pauseRequested || (bool) ($stored['pause_requested'] ?? false);
                // The lease belongs to the repository protocol, never overwritten by a stale entity.
                $job->leaseOwner = is_string($stored['lease_owner'] ?? null) ? $stored['lease_owner'] : null;
                $job->leaseExpiresAt = is_string($stored['lease_expires_at'] ?? null)
                    ? new DateTimeImmutable($stored['lease_expires_at'], new DateTimeZone('UTC'))
                    : null;
            }
            AtomicFile::writeJson($file, $job->toArray());
        });
    }

    public function list(?array $statuses = null, int $limit = 50, int $offset = 0): array
    {
        $jobs = [];
        foreach (glob($this->directory . '/*/job.json') ?: [] as $file) {
            try {
                $job = Job::fromArray(AtomicFile::readJson($file));
            } catch (SwbiException) {
                continue;
            }
            if ($statuses === null || in_array($job->status->value, $statuses, true)) {
                $jobs[] = $job;
            }
        }
        usort($jobs, static fn (Job $a, Job $b): int => $b->createdAt <=> $a->createdAt);

        return array_slice($jobs, $offset, $limit);
    }

    public function acquireLease(string $jobId, string $owner, int $ttlSeconds, DateTimeImmutable $now): bool
    {
        return $this->withLock($jobId, function () use ($jobId, $owner, $ttlSeconds, $now): bool {
            $job = $this->find($jobId);
            if ($job->hasLeaseAt($now) && $job->leaseOwner !== $owner) {
                return false;
            }

            return $this->writeLease($job, $owner, $now->modify('+' . $ttlSeconds . ' seconds'));
        });
    }

    public function renewLease(string $jobId, string $owner, int $ttlSeconds, DateTimeImmutable $now): bool
    {
        return $this->withLock($jobId, function () use ($jobId, $owner, $ttlSeconds, $now): bool {
            $job = $this->find($jobId);
            if ($job->leaseOwner !== $owner) {
                return false;
            }

            return $this->writeLease($job, $owner, $now->modify('+' . $ttlSeconds . ' seconds'));
        });
    }

    public function releaseLease(string $jobId, string $owner): void
    {
        $this->withLock($jobId, function () use ($jobId, $owner): void {
            $job = $this->find($jobId);
            if ($job->leaseOwner === $owner) {
                $job->leaseOwner = null;
                $job->leaseExpiresAt = null;
                AtomicFile::writeJson($this->jobFile($jobId), $job->toArray());
            }
        });
    }

    public function requestCancel(string $jobId): void
    {
        $this->setFlag($jobId, 'cancel_requested');
    }

    public function requestPause(string $jobId): void
    {
        $this->setFlag($jobId, 'pause_requested');
    }

    public function delete(string $jobId): void
    {
        if (Uuid::isValid($jobId)) {
            AtomicFile::removeTree($this->directory . '/' . $jobId);
        }
    }

    public function jobDirectory(string $jobId): string
    {
        return $this->directory . '/' . $jobId;
    }

    private function writeLease(Job $job, string $owner, DateTimeImmutable $expires): bool
    {
        $job->leaseOwner = $owner;
        $job->leaseExpiresAt = $expires;
        AtomicFile::writeJson($this->jobFile($job->id), $job->toArray());

        return true;
    }

    private function setFlag(string $jobId, string $flag): void
    {
        $this->withLock($jobId, function () use ($jobId, $flag): void {
            $file = $this->jobFile($jobId);
            if (!is_file($file)) {
                throw new JobNotFoundException('Job not found.', ['job' => $jobId]);
            }
            $data = AtomicFile::readJson($file);
            $data[$flag] = true;
            AtomicFile::writeJson($file, $data);
        });
    }

    private function jobFile(string $jobId): string
    {
        return $this->directory . '/' . $jobId . '/job.json';
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(string $jobId, callable $callback): mixed
    {
        if (!Uuid::isValid($jobId)) {
            throw new JobNotFoundException('Job not found.');
        }
        $directory = $this->directory . '/' . $jobId;
        AtomicFile::ensureDirectory($directory);
        $handle = fopen($directory . '/lock', 'cb');
        if ($handle === false) {
            throw new SwbiException('Cannot open job lock.', ['job' => $jobId]);
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new SwbiException('Cannot lock job.', ['job' => $jobId]);
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
