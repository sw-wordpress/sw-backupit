<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Retry;

use SWBackupIt\Core\Exception\TransientException;
use Throwable;

/** Retry with exponential backoff, upper bound and jitter for transient failures only. */
final class RetryPolicy
{
    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param (callable(int): void)|null $sleeper Receives milliseconds; injectable for tests.
     */
    public function __construct(
        private readonly int $attempts = 3,
        private readonly int $baseDelayMs = 500,
        private readonly int $maxDelayMs = 8000,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @param callable(Throwable): bool $isTransient
     * @return T
     * @throws Throwable the last error when it is not transient or attempts are exhausted
     */
    public function run(callable $operation, callable $isTransient): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $operation();
            } catch (Throwable $throwable) {
                $attempt++;
                if ($attempt >= $this->attempts || !($throwable instanceof TransientException || $isTransient($throwable))) {
                    throw $throwable;
                }
                ($this->sleeper)($this->delay($attempt));
            }
        }
    }

    public function delay(int $attempt): int
    {
        $delay = min($this->maxDelayMs, $this->baseDelayMs * (2 ** ($attempt - 1)));

        return $delay + random_int(0, (int) ($delay / 4));
    }
}
