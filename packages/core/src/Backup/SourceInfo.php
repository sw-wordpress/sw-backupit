<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup;

use SWBackupIt\Core\Database\ConnectionConfig;

/**
 * Everything the backup core needs to know about the system being backed up.
 * Built by the WordPress adapter (or the CLI/tests); the core never reads WordPress itself.
 */
final readonly class SourceInfo
{
    /**
     * @param array<string, scalar|null> $extra Additional descriptive metadata stored in the manifest.
     */
    public function __construct(
        public string $rootPath,
        public string $stagingPath,
        public string $siteUrl = '',
        public string $homeUrl = '',
        public string $wpVersion = '',
        public string $tablePrefix = 'wp_',
        public ?ConnectionConfig $database = null,
        public bool $multisite = false,
        public array $extra = [],
    ) {
    }
}
