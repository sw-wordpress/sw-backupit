<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Scanner;

use Generator;
use SWBackupIt\Core\Exception\ManifestException;

/** Streams NDJSON file index entries with their byte offsets. */
final class FileIndexReader
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Yields [entry, offsetAfterEntry] pairs starting at $offset.
     *
     * @return Generator<int, array{0: array<string, mixed>, 1: int}>
     */
    public function read(int $offset = 0): Generator
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new ManifestException('Cannot open file index.', ['path' => basename($this->path)]);
        }
        try {
            fseek($handle, $offset);
            $position = $offset;
            while (($line = fgets($handle)) !== false) {
                $position += strlen($line);
                if (!str_ends_with($line, "\n")) {
                    // A torn last line (crash during write) is never valid data.
                    return;
                }
                $entry = json_decode($line, true);
                if (!is_array($entry) || !isset($entry['p'], $entry['t'])) {
                    throw new ManifestException('Corrupt file index line.', ['offset' => $position]);
                }
                yield [$entry, $position];
            }
        } finally {
            fclose($handle);
        }
    }
}
