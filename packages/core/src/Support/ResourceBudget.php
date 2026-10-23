<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Support;

/**
 * Soft time budget for one worker invocation (tick).
 *
 * Steps check exhausted() between work units and yield; the job continues in the next tick.
 */
final class ResourceBudget
{
    private float $deadline;

    public function __construct(private readonly float $seconds, private readonly int $memoryLimitBytes = 0)
    {
        $this->deadline = microtime(true) + $seconds;
    }

    public static function unlimited(): self
    {
        return new self(86400.0 * 365);
    }

    public function exhausted(): bool
    {
        if (microtime(true) >= $this->deadline) {
            return true;
        }

        return $this->memoryLimitBytes > 0 && memory_get_usage(true) >= $this->memoryLimitBytes;
    }

    public function remainingSeconds(): float
    {
        return max(0.0, $this->deadline - microtime(true));
    }

    public function seconds(): float
    {
        return $this->seconds;
    }
}
