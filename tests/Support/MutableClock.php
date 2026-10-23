<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use SWBackupIt\Core\Support\ClockInterface;

final class MutableClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $time = '2026-01-01T00:00:00Z')
    {
        $this->now = new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify('+' . $seconds . ' seconds');
    }
}
