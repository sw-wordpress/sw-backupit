<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Job;

use DateTimeImmutable;

/**
 * Persistence of jobs. Implementations: FileJobRepository (core), wpdb based (WordPress adapter).
 *
 * Contract for all implementations:
 * - save() is atomic and preserves the cancel/pause flags that were set through requestCancel()/requestPause()
 *   after the job was loaded (the worker must never overwrite a user's cancel request).
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

    public function delete(string $jobId): void;
}
