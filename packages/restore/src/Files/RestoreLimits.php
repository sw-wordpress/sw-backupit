<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Files;

/** Resource limits protecting against ZIP bombs and manipulated archives. */
final readonly class RestoreLimits
{
    public function __construct(
        public int $maxTotalBytes = 1099511627776,
        public int $maxEntryBytes = 107374182400,
        public int $maxEntries = 10000000,
        public float $maxCompressionRatio = 2000.0,
    ) {
    }
}
