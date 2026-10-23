<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Cli;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use SWBackupIt\Core\Log\Redactor;
use Stringable;

final class ConsoleLogger extends AbstractLogger
{
    /** @param resource $stream */
    public function __construct(private $stream, private readonly bool $verbose = false)
    {
    }

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!$this->verbose && in_array($level, [LogLevel::DEBUG, LogLevel::INFO], true)) {
            return;
        }
        $details = $context === [] ? '' : ' ' . json_encode(Redactor::redact($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        fwrite($this->stream, sprintf("[%s] %s%s\n", is_string($level) ? $level : 'log', (string) $message, $details));
    }
}
