<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Storage;

final readonly class ConnectionTestResult
{
    public function __construct(public bool $ok, public string $message, public int $latencyMs = 0)
    {
    }
}
