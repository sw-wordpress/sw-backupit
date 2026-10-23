<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Domain;

enum BackupType: string
{
    case Full = 'full';
    case Files = 'files';
    case Database = 'database';
    case Incremental = 'incremental';
    case Differential = 'differential';

    public function includesFiles(): bool
    {
        return $this !== self::Database;
    }

    public function includesDatabase(): bool
    {
        return $this !== self::Files;
    }

    /** Whether the backup is stored as delta against a parent backup. */
    public function isDelta(): bool
    {
        return $this === self::Incremental || $this === self::Differential;
    }
}
