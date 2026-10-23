<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Archive;

use DateTimeImmutable;
use SWBackupIt\Core\Exception\ArchiveException;
use SWBackupIt\Core\Support\AtomicFile;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * Writes one self-contained ZIP volume (ZIP64 capable) via ZipStream.
 *
 * The volume is written to "<name>.part" and only renamed to its final name after the central
 * directory was written and fsynced, so a final volume is always complete and never modified again.
 */
final class VolumeWriter
{
    /** @var resource */
    private $output;
    private ZipStream $zip;
    private int $entries = 0;
    private bool $closed = false;

    public function __construct(
        private readonly string $directory,
        private readonly int $index,
        private readonly CompressionPolicy $policy,
    ) {
        AtomicFile::ensureDirectory($directory);
        RangeStream::register();
        $output = fopen($this->partPath(), 'wb');
        if ($output === false) {
            throw new ArchiveException('Cannot create archive volume.', ['volume' => $index]);
        }
        $this->output = $output;
        $this->zip = new ZipStream(
            sendHttpHeaders: false,
            outputStream: $this->output,
            enableZip64: true,
            defaultEnableZeroHeader: true,
            defaultCompressionMethod: CompressionMethod::DEFLATE,
            defaultDeflateLevel: $policy->level(),
        );
    }

    /** Bytes written to the volume so far (without the central directory). */
    public function size(): int
    {
        $position = ftell($this->output);

        return $position === false ? 0 : $position;
    }

    public function entryCount(): int
    {
        return $this->entries;
    }

    /**
     * Adds a byte range of a local file as one archive entry and returns size/hash of what was read.
     */
    public function addFileRange(string $entryName, string $absolutePath, int $offset, ?int $length, int $mtime, int $fullSize): ReadResult
    {
        $result = new ReadResult();
        try {
            $stream = RangeStream::open($absolutePath, $offset, $length, $result);
            try {
                $this->zip->addFileFromStream(
                    fileName: $entryName,
                    stream: $stream,
                    compressionMethod: $this->policy->methodFor($entryName, $length ?? $fullSize),
                    deflateLevel: $this->policy->level(),
                    lastModificationDateTime: (new DateTimeImmutable())->setTimestamp(max(0, $mtime)),
                    maxSize: $length,
                );
            } finally {
                fclose($stream);
            }
        } catch (\Throwable $throwable) {
            $this->abort();
            throw new ArchiveException('Writing the archive entry failed.', ['entry' => $entryName], false, $throwable);
        }
        $this->entries++;

        return $result;
    }

    public function close(): VolumeInfo
    {
        if ($this->closed) {
            throw new ArchiveException('Volume already closed.');
        }
        $this->closed = true;
        try {
            $this->zip->finish();
            fflush($this->output);
            AtomicFile::sync($this->output);
        } catch (\Throwable $throwable) {
            $this->abort();
            throw new ArchiveException('Finishing the archive volume failed.', ['volume' => $this->index], false, $throwable);
        }
        fclose($this->output);

        $part = $this->partPath();
        $final = $this->finalPath();
        $hash = hash_file('sha256', $part);
        $size = filesize($part);
        if ($hash === false || $size === false || !rename($part, $final)) {
            throw new ArchiveException('Cannot finalize archive volume.', ['volume' => $this->index]);
        }

        return new VolumeInfo($this->index, VolumeInfo::nameFor($this->index), $size, $hash, $this->entries);
    }

    /** Discards the partial volume. */
    public function abort(): void
    {
        $this->closed = true;
        if (is_resource($this->output)) {
            fclose($this->output);
        }
        @unlink($this->partPath());
    }

    private function partPath(): string
    {
        return $this->directory . '/' . VolumeInfo::nameFor($this->index) . '.part';
    }

    private function finalPath(): string
    {
        return $this->directory . '/' . VolumeInfo::nameFor($this->index);
    }
}
