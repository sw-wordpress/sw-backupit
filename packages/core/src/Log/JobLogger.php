<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * PSR-3 logger bound to a job. Persists structured, redacted events.
 */
final class JobLogger extends AbstractLogger
{
    private const ORDER = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 4,
        LogLevel::ALERT => 4,
        LogLevel::EMERGENCY => 4,
    ];

    private string $phase = 'init';
    private int $warnings = 0;

    public function __construct(
        private readonly JobEventStoreInterface $store,
        private readonly string $jobId,
        private readonly string $minLevel = LogLevel::INFO,
    ) {
    }

    public function setPhase(string $phase): void
    {
        $this->phase = $phase;
    }

    public function warningCount(): int
    {
        return $this->warnings;
    }

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $level = is_string($level) ? $level : LogLevel::INFO;
        $rank = self::ORDER[$level] ?? 1;
        if ($rank >= self::ORDER[LogLevel::WARNING]) {
            $this->warnings++;
        }
        if ($rank < (self::ORDER[$this->minLevel] ?? 1)) {
            return;
        }
        $severity = match (true) {
            $rank >= 4 => 'error',
            $rank === 3 => 'warning',
            $rank === 2 => 'notice',
            $rank === 1 => 'info',
            default => 'debug',
        };
        $this->store->append(
            $this->jobId,
            $severity,
            $this->phase,
            Redactor::redactString((string) $message),
            Redactor::redact($context),
        );
    }
}
