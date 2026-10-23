<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Backup;

use SWBackupIt\Core\Archive\VolumeInfo;
use SWBackupIt\Core\Exception\ManifestException;
use SWBackupIt\Core\Manifest\Manifest;
use SWBackupIt\Core\Scanner\FileIndexReader;

/**
 * A backup directory opened for reading (restore, verification, browsing).
 * Needs nothing but the files of the backup: no WordPress, no database, no catalog.
 */
final class BackupSet
{
    private function __construct(private readonly string $directory, private readonly Manifest $manifest)
    {
    }

    /**
     * @throws ManifestException
     */
    public static function open(string $directory): self
    {
        $real = realpath($directory);
        if ($real === false || !is_dir($real)) {
            throw new ManifestException('Backup directory does not exist.');
        }

        return new self($real, Manifest::read($real));
    }

    public function manifest(): Manifest
    {
        return $this->manifest;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function indexReader(): FileIndexReader
    {
        return new FileIndexReader($this->directory . '/files.ndjson');
    }

    public function indexPath(): string
    {
        return $this->directory . '/files.ndjson';
    }

    public function volumePath(string $name): string
    {
        return $this->directory . '/volumes/' . $name;
    }

    public function databasePartPath(string $name): string
    {
        return $this->directory . '/database/' . $name;
    }

    public function volumeFileName(int $index): string
    {
        foreach ($this->manifest->volumes() as $volume) {
            if ($volume->index === $index) {
                return $volume->name;
            }
        }

        return VolumeInfo::nameFor($index);
    }
}
