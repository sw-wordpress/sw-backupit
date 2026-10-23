<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Files;

/** Options of a file restore run. The target path must have been confirmed by the caller. */
final readonly class RestoreOptions
{
    public function __construct(
        public string $targetPath,
        public OverwriteMode $overwrite = OverwriteMode::IfDifferent,
        public Selection $selection = new Selection(),
        public bool $dryRun = false,
        public bool $verifyHashes = true,
        public RestoreLimits $limits = new RestoreLimits(),
    ) {
    }
}
