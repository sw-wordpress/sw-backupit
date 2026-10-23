<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Scanner;

final readonly class ScanResult
{
    /**
     * @param array<string, mixed> $cursor Serializable resume state.
     */
    public function __construct(public bool $finished, public array $cursor)
    {
    }
}
