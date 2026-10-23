<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Log;

interface JobEventStoreInterface
{
    /**
     * @param array<string, mixed> $context Already redacted technical details.
     */
    public function append(string $jobId, string $severity, string $phase, string $message, array $context = []): void;

    /**
     * @return array{items: list<array<string, mixed>>, last_id: int}
     */
    public function read(string $jobId, int $afterId = 0, int $limit = 200, ?string $minSeverity = null, ?string $phase = null): array;
}
