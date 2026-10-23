<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Support;

use DateTimeImmutable;

interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
