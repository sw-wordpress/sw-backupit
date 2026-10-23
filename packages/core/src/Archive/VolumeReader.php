<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Archive;

use SWBackupIt\Core\Exception\ArchiveException;
use SWBackupIt\Core\Exception\IntegrityException;
use ZipArchive;

/** Reads archive volumes (verification, streaming single entries). */
final class VolumeReader
{
    /**
     * Verifies the whole-file SHA-256 and the ZIP structure of a volume.
     *
     * @throws IntegrityException
     */
    public function verify(string $path, VolumeInfo $info): void
    {
        if (!is_file($path)) {
            throw new IntegrityException('Archive volume is missing.', ['volume' => $info->name]);
        }
        if (filesize($path) !== $info->size) {
            throw new IntegrityException('Archive volume has an unexpected size.', ['volume' => $info->name]);
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false || !hash_equals($info->sha256, $hash)) {
            throw new IntegrityException('Archive volume checksum mismatch.', ['volume' => $info->name]);
        }
        $zip = new ZipArchive();
        $status = $zip->open($path, ZipArchive::CHECKCONS);
        if ($status !== true) {
            throw new IntegrityException('Archive volume is not a valid ZIP file.', ['volume' => $info->name, 'code' => (int) $status]);
        }
        $count = $zip->numFiles;
        $zip->close();
        if ($count !== $info->entries) {
            throw new IntegrityException('Archive volume has an unexpected entry count.', ['volume' => $info->name]);
        }
    }

    /**
     * Opens a volume for reading entries.
     */
    public function open(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        $status = $zip->open($path, ZipArchive::RDONLY);
        if ($status !== true) {
            throw new ArchiveException('Cannot open archive volume.', ['volume' => basename($path), 'code' => (int) $status]);
        }

        return $zip;
    }
}
