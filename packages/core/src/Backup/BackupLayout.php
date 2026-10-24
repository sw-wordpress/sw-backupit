<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup;

use SWBackupIt\Core\Scanner\FileScanner;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\Uuid;

/**
 * Directory layout of one backup inside the staging area:
 *
 *   <staging>/<backupId>/manifest.json   (written last; its presence marks a complete backup)
 *                       /files.ndjson    (final file index)
 *                       /database/db-0001.sql.gz ...
 *                       /volumes/backup-001.zip ...
 *                       /work/           (temporary, removed after finalisation)
 */
final class BackupLayout
{
    public function __construct(private readonly string $stagingRoot, private readonly string $backupId)
    {
    }

    public function stagingRoot(): string
    {
        return rtrim($this->stagingRoot, '/');
    }

    public function backupId(): string
    {
        return $this->backupId;
    }

    public function root(): string
    {
        return $this->stagingRoot() . '/' . $this->backupId;
    }

    public function workDirectory(): string
    {
        return $this->root() . '/work';
    }

    public function volumesDirectory(): string
    {
        return $this->root() . '/volumes';
    }

    public function databaseDirectory(): string
    {
        return $this->root() . '/database';
    }

    public function inventoryPath(): string
    {
        return $this->workDirectory() . '/inventory.ndjson';
    }

    public function indexPath(): string
    {
        return $this->root() . '/files.ndjson';
    }

    public function manifestPath(): string
    {
        return $this->root() . '/manifest.json';
    }

    public function isComplete(): bool
    {
        return is_file($this->manifestPath());
    }

    /**
     * Creates directories and web-access protection files (defence in depth; real protection must
     * come from keeping the staging directory outside the web root).
     */
    public function prepare(): void
    {
        if (!Uuid::isValid($this->backupId)) {
            throw new \InvalidArgumentException('Invalid backup id.');
        }
        AtomicFile::ensureDirectory($this->stagingRoot());
        $marker = $this->stagingRoot() . '/' . FileScanner::STAGING_MARKER;
        if (!is_file($marker)) {
            AtomicFile::write($marker, "sw-backupit staging area. Excluded from backups. Never publish this directory.\n");
        }
        if (!is_file($this->stagingRoot() . '/.htaccess')) {
            AtomicFile::write($this->stagingRoot() . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!is_file($this->stagingRoot() . '/index.php')) {
            AtomicFile::write($this->stagingRoot() . '/index.php', "<?php\n// Silence is golden.\n");
        }
        foreach ([$this->root(), $this->workDirectory(), $this->volumesDirectory(), $this->databaseDirectory()] as $directory) {
            AtomicFile::ensureDirectory($directory);
        }
    }

    public function removeWork(): void
    {
        AtomicFile::removeTree($this->workDirectory());
    }

    public function removeAll(): void
    {
        AtomicFile::removeTree($this->root());
    }
}
