<?php

declare(strict_types=1);

namespace SWBackupIt\Restore;

final readonly class RestoreOutcome
{
    public const COMPLETED = 'completed';
    public const CONTINUE = 'continue';
    public const FAILED = 'failed';

    /**
     * @param list<string> $messages Errors (failed) or warnings (completed).
     * @param array<string, mixed> $summary
     */
    public function __construct(
        public string $status,
        public string $phase,
        public array $messages = [],
        public array $summary = [],
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === self::COMPLETED;
    }
}
