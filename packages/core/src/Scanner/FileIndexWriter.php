<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Scanner;

use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Support\AtomicFile;

/**
 * Appends NDJSON lines to a file index. Resumable: open() truncates to a checkpointed offset.
 *
 * Index line schema (version 1), keys are short to keep large indexes small:
 *  p path, t type (f|d|l), s size, m mtime, mode permissions, lt link target,
 *  st status (ok|unreadable|skipped|changed), h sha256, v volume, a archive entry, c chunks.
 */
final class FileIndexWriter
{
    /** @var resource */
    private $handle;
    private int $offset;

    public function __construct(private readonly string $path, int $resumeOffset = 0)
    {
        AtomicFile::ensureDirectory(dirname($path));
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new SwbiException('Cannot open file index.', ['path' => basename($path)]);
        }
        $this->handle = $handle;
        ftruncate($this->handle, max(0, $resumeOffset));
        fseek($this->handle, $resumeOffset);
        $this->offset = $resumeOffset;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function append(array $entry): void
    {
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $written = fwrite($this->handle, $line);
        if ($written !== strlen($line)) {
            throw new SwbiException('Cannot write file index (disk full?).', ['path' => basename($this->path)], true);
        }
        $this->offset += $written;
    }

    /** Makes everything written so far durable and returns the offset that is safe to checkpoint. */
    public function commit(): int
    {
        fflush($this->handle);
        AtomicFile::sync($this->handle);

        return $this->offset;
    }

    public function close(): int
    {
        $offset = $this->commit();
        fclose($this->handle);

        return $offset;
    }
}
