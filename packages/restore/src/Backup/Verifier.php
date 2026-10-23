<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Backup;

use SWBackupIt\Core\Archive\VolumeReader;
use SWBackupIt\Core\Exception\IntegrityException;
use ZipArchive;

/**
 * Verifies a backup against its manifest. Reports every problem instead of stopping at the first one.
 *
 * Levels: quick = checksums of index/volumes/database parts + ZIP structure + index consistency;
 * deep = additionally reads every archived file and compares its SHA-256 with the index.
 */
final class Verifier
{
    public function verify(BackupSet $set, bool $deep = false): VerificationReport
    {
        $report = new VerificationReport();
        $manifest = $set->manifest();

        if ($manifest->hasFiles()) {
            $indexHash = is_file($set->indexPath()) ? hash_file('sha256', $set->indexPath()) : false;
            if ($indexHash === false || !hash_equals((string) $manifest->filesIndexSha256(), $indexHash)) {
                $report->addError('The file index is missing or does not match the manifest checksum.');
            }
        }
        $reader = new VolumeReader();
        $volumeEntries = 0;
        foreach ($manifest->volumes() as $volume) {
            $volumeEntries += $volume->entries;
            try {
                $reader->verify($set->volumePath($volume->name), $volume);
                $report->volumesChecked++;
            } catch (IntegrityException $exception) {
                $report->addError($exception->getMessage() . ' (' . $volume->name . ')');
            }
        }
        foreach ($manifest->databaseParts() as $part) {
            $path = $set->databasePartPath((string) $part['name']);
            $hash = is_file($path) ? hash_file('sha256', $path) : false;
            if ($hash === false || !hash_equals((string) $part['sha256'], $hash)) {
                $report->addError('Database part is missing or corrupt: ' . $part['name']);
            } else {
                $report->databasePartsChecked++;
            }
        }
        if ($manifest->hasFiles() && is_file($set->indexPath())) {
            $this->verifyIndex($set, $report, $deep, $volumeEntries);
        }

        return $report;
    }

    private function verifyIndex(BackupSet $set, VerificationReport $report, bool $deep, int $volumeEntries): void
    {
        $maxVolume = count($set->manifest()->volumes());
        $references = 0;
        /** @var array<int, ZipArchive> $zips */
        $zips = [];
        try {
            foreach ($set->indexReader()->read() as [$entry]) {
                if ($entry['t'] !== 'f' || in_array($entry['st'] ?? 'ok', ['unreadable', 'vanished'], true)) {
                    continue;
                }
                $report->filesChecked++;
                $chunks = isset($entry['c']) && is_array($entry['c']) ? $entry['c'] : [['v' => $entry['v'] ?? 0, 'a' => $entry['a'] ?? '', 's' => $entry['s'] ?? 0, 'h' => $entry['h'] ?? null]];
                $total = hash_init('sha256');
                foreach ($chunks as $chunk) {
                    $references++;
                    $volume = (int) ($chunk['v'] ?? 0);
                    if ($volume < 1 || $volume > $maxVolume) {
                        $report->addError('Index entry references a missing volume: ' . $entry['p']);
                        continue 2;
                    }
                    if (!$deep) {
                        continue;
                    }
                    $zip = $zips[$volume] ??= $this->openZip($set, $volume);
                    $stream = $zip->getStream((string) $chunk['a']);
                    if ($stream === false) {
                        $report->addError('Archive entry is missing: ' . $chunk['a']);
                        continue 2;
                    }
                    $segment = hash_init('sha256');
                    $bytes = 0;
                    while (!feof($stream)) {
                        $data = fread($stream, 1048576);
                        if ($data === false) {
                            break;
                        }
                        hash_update($segment, $data);
                        hash_update($total, $data);
                        $bytes += strlen($data);
                    }
                    fclose($stream);
                    if ($bytes !== (int) ($chunk['s'] ?? -1) || (isset($chunk['h']) && !hash_equals((string) $chunk['h'], hash_final($segment)))) {
                        $report->addError('Archived content does not match the index: ' . $entry['p']);
                        continue 2;
                    }
                }
                if ($deep && isset($entry['h']) && isset($entry['c']) && !hash_equals((string) $entry['h'], hash_final($total))) {
                    $report->addError('Reassembled file does not match its checksum: ' . $entry['p']);
                }
            }
        } finally {
            foreach ($zips as $zip) {
                $zip->close();
            }
        }
        if ($references !== $volumeEntries) {
            $report->addError(sprintf('Index (%d archive entries) and volumes (%d entries) are inconsistent.', $references, $volumeEntries));
        }
    }

    private function openZip(BackupSet $set, int $volume): ZipArchive
    {
        return (new VolumeReader())->open($set->volumePath($set->volumeFileName($volume)));
    }
}
