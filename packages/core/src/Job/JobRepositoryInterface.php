<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Job;

use DateTimeImmutable;

/**
 * Persistence of jobs. Implementations: FileJobRepository (core), wpdb based (WordPress adapter).
 *
 * Contract for all implementations:
 * - save() is atomic and never touches the cancel/pause flags: requestCancel()/requestPause() store them
 *   independently and find() reports them, so a worker saving a stale entity cannot lose a user's request.
 * - Leases are exclusive; an expired lease may be taken over (crashed worker recovery).
 */
interface JobRepositoryInterface
{
    public function create(Job $job): void;

    /**
     * @throws \SWBackupIt\Core\Exception\JobNotFoundException
     */
    public function find(string $jobId): Job;

    public function save(Job $job): void;

    /**
     * @param list<string>|null $statuses Status values to filter by, null = all.
     * @return list<Job> Newest first.
     */
    public function list(?array $statuses = null, int $limit = 50, int $offset = 0): array;

    public function acquireLease(string $jobId, string $owner, int $ttlSeconds, DateTimeImmutable $now): bool;

    public function renewLease(string $jobId, string $owner, int $ttlSeconds, DateTimeImmutable $now): bool;

    public function releaseLease(string $jobId, string $owner): void;

    public function requestCancel(string $jobId): void;

    public function requestPause(string $jobId): void;

    /** Clears pending cancel/pause requests (called by the worker after it acted on them). */
    public function clearFlags(string $jobId): void;

    public function delete(string $jobId): void;
}
