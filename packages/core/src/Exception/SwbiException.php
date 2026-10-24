<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Exception;

use RuntimeException;
use Throwable;

/**
 * Base class of all sw-backupit errors.
 *
 * The message is always safe to show to users (no secrets, no stack traces).
 */
class SwbiException extends RuntimeException
{
    /**
     * @param array<string, scalar|null> $context Safe technical details for logs.
     */
    public function __construct(
        string $message,
        private readonly array $context = [],
        private readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }

    /** Whether repeating the failed operation may succeed (transient failure). */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
