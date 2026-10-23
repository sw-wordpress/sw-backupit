<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Exception;

use Throwable;

/** A failure that is worth retrying (network hiccup, timeout, busy resource). */
final class TransientException extends SwbiException
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(string $message, array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, $context, true, $previous);
    }
}
