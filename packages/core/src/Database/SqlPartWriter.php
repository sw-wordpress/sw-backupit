<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Database;

use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Support\AtomicFile;

/**
 * Writes one gzip compressed SQL part. Like archive volumes, a part only gets its final name after
 * it was completely written and fsynced.
 */
final class SqlPartWriter
{
    /** @var resource */
    private $gz;
    private int $rawBytes = 0;

    public function __construct(private readonly string $directory, private readonly int $index, int $level = 6)
    {
        AtomicFile::ensureDirectory($directory);
        $handle = gzopen($this->partPath(), 'wb' . max(1, min(9, $level)));
        if ($handle === false) {
            throw new SwbiException('Cannot create database export part.', ['part' => $index], true);
        }
        $this->gz = $handle;
    }

    public static function nameFor(int $index): string
    {
        return sprintf('db-%04d.sql.gz', $index);
    }

    public function write(string $sql): void
    {
        if ($sql === '') {
            return;
        }
        if (gzwrite($this->gz, $sql) === false) {
            throw new SwbiException('Cannot write database export (disk full?).', ['part' => $this->index], true);
        }
        $this->rawBytes += strlen($sql);
    }

    public function rawBytes(): int
    {
        return $this->rawBytes;
    }

    /**
     * @return array{name: string, size: int, sha256: string, raw_bytes: int}
     */
    public function close(): array
    {
        gzclose($this->gz);
        $part = $this->partPath();
        $sync = fopen($part, 'rb');
        if ($sync !== false) {
            AtomicFile::sync($sync);
            fclose($sync);
        }
        $hash = hash_file('sha256', $part);
        $size = filesize($part);
        $final = $this->directory . '/' . self::nameFor($this->index);
        if ($hash === false || $size === false || !rename($part, $final)) {
            throw new SwbiException('Cannot finalize database export part.', ['part' => $this->index]);
        }

        return ['name' => self::nameFor($this->index), 'size' => $size, 'sha256' => $hash, 'raw_bytes' => $this->rawBytes];
    }

    public function abort(): void
    {
        if (is_resource($this->gz)) {
            gzclose($this->gz);
        }
        @unlink($this->partPath());
    }

    private function partPath(): string
    {
        return $this->directory . '/' . self::nameFor($this->index) . '.part';
    }
}
