<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Log;

use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\Uuid;

/**
 * NDJSON event log per job with a hard size cap.
 *
 * When the cap is reached the oldest half of the debug/info events is discarded
 * (warnings and errors are kept as long as possible) so logs never grow unbounded.
 */
final class FileJobEventStore implements JobEventStoreInterface
{
    private const SEVERITIES = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4];

    public function __construct(private readonly string $jobsDirectory, private readonly int $maxEvents = 5000)
    {
    }

    public function append(string $jobId, string $severity, string $phase, string $message, array $context = []): void
    {
        if (!Uuid::isValid($jobId)) {
            return;
        }
        $directory = $this->jobsDirectory . '/' . $jobId;
        AtomicFile::ensureDirectory($directory);
        $file = $directory . '/events.ndjson';
        $handle = fopen($file, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            return;
        }
        try {
            $metaFile = $directory . '/events.meta.json';
            $meta = is_file($metaFile) ? AtomicFile::readJson($metaFile) : [];
            $lastId = (int) ($meta['last_id'] ?? 0) + 1;
            $count = (int) ($meta['count'] ?? 0) + 1;
            $line = json_encode([
                'id' => $lastId,
                'time' => gmdate('Y-m-d\TH:i:s\Z'),
                'severity' => $severity,
                'phase' => $phase,
                'message' => $message,
                'context' => $context,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($line === false) {
                return;
            }
            fseek($handle, 0, SEEK_END);
            fwrite($handle, $line . "\n");
            fflush($handle);
            if ($count > $this->maxEvents) {
                $count = $this->compact($handle);
            }
            AtomicFile::writeJson($metaFile, ['last_id' => $lastId, 'count' => $count]);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function read(string $jobId, int $afterId = 0, int $limit = 200, ?string $minSeverity = null, ?string $phase = null): array
    {
        $file = $this->jobsDirectory . '/' . $jobId . '/events.ndjson';
        $items = [];
        $lastId = $afterId;
        $min = self::SEVERITIES[$minSeverity ?? 'debug'] ?? 0;
        if (!Uuid::isValid($jobId) || !is_file($file)) {
            return ['items' => [], 'last_id' => $lastId];
        }
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return ['items' => [], 'last_id' => $lastId];
        }
        while (($line = fgets($handle)) !== false && count($items) < $limit) {
            $event = json_decode($line, true);
            if (!is_array($event) || (int) $event['id'] <= $afterId) {
                continue;
            }
            $lastId = (int) $event['id'];
            if ((self::SEVERITIES[$event['severity']] ?? 0) < $min) {
                continue;
            }
            if ($phase !== null && $event['phase'] !== $phase) {
                continue;
            }
            $items[] = $event;
        }
        fclose($handle);

        return ['items' => $items, 'last_id' => $lastId];
    }

    /**
     * @param resource $handle Locked handle positioned anywhere.
     */
    private function compact($handle): int
    {
        rewind($handle);
        $important = [];
        $other = [];
        while (($line = fgets($handle)) !== false) {
            $event = json_decode($line, true);
            if (!is_array($event)) {
                continue;
            }
            if ((self::SEVERITIES[$event['severity']] ?? 0) >= self::SEVERITIES['warning']) {
                $important[] = $line;
            } else {
                $other[] = $line;
            }
        }
        $other = array_slice($other, (int) (count($other) / 2));
        $important = array_slice($important, -(int) ($this->maxEvents / 2));
        $merged = array_merge($important, $other);
        usort($merged, static fn (string $a, string $b): int => ((int) (json_decode($a, true)['id'] ?? 0)) <=> ((int) (json_decode($b, true)['id'] ?? 0)));
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, implode('', $merged));
        fflush($handle);

        return count($merged);
    }
}
