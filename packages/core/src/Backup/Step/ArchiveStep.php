<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup\Step;

use SWBackupIt\Core\Archive\CompressionPolicy;
use SWBackupIt\Core\Archive\EntryName;
use SWBackupIt\Core\Archive\ReadResult;
use SWBackupIt\Core\Archive\VolumeInfo;
use SWBackupIt\Core\Archive\VolumeWriter;
use SWBackupIt\Core\Backup\BackupContext;
use SWBackupIt\Core\Backup\BackupStepInterface;
use SWBackupIt\Core\Backup\StepOutcome;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Scanner\FileIndexReader;
use SWBackupIt\Core\Scanner\FileIndexWriter;

/**
 * Turns the inventory into independent ZIP volumes and the final file index.
 *
 * Checkpoint model: a volume is the unit of durability. The checkpoint only advances (list of
 * finished volumes, inventory offset, final index offset, chunk state) after a volume was closed,
 * fsynced and renamed. A crash discards at most the volume in progress; finished volumes are reused.
 * Files larger than the volume size are split into `<path>.swbpartNNNN` chunks, one chunk per volume.
 */
final class ArchiveStep implements BackupStepInterface
{
    public function step(): Step
    {
        return Step::Archive;
    }

    public function applies(BackupProfile $profile): bool
    {
        return $profile->type->includesFiles();
    }

    public function run(BackupContext $context): StepOutcome
    {
        $job = $context->job;
        $profile = $context->profile;
        $checkpoint = $job->checkpoint(Step::Archive) + [
            'volumes' => [], 'index_offset' => 0, 'final_offset' => 0, 'chunk' => null,
            'entries' => 0, 'files_done' => 0, 'bytes_done' => 0, 'finished' => false,
        ];
        if ($checkpoint['finished'] === true) {
            return StepOutcome::Done;
        }
        $root = rtrim($context->source->rootPath, '/');
        $maxVolume = $profile->volumeSizeBytes;
        $policy = new CompressionPolicy($profile->compression);
        $reader = new FileIndexReader($context->layout->inventoryPath());
        $progress = $job->progress;
        $progress->filesDone = (int) $checkpoint['entries'];
        $progress->bytesDone = (int) $checkpoint['bytes_done'];
        $compressedBefore = array_sum(array_map(static fn (array $volume): int => (int) $volume['size'], $checkpoint['volumes']));

        while (true) {
            $volumeIndex = count($checkpoint['volumes']) + 1;
            $final = new FileIndexWriter($context->layout->indexPath(), (int) $checkpoint['final_offset']);
            $writer = null;
            $consumed = (int) $checkpoint['index_offset'];
            $chunk = $checkpoint['chunk'];
            $more = false;

            try {
                foreach ($reader->read($consumed) as [$entry, $nextOffset]) {
                    $path = (string) $entry['p'];
                    if ($entry['t'] !== 'f' || ($entry['st'] ?? 'ok') !== 'ok') {
                        $final->append($entry);
                        $consumed = $nextOffset;
                        $checkpoint['entries']++;
                        $progress->filesDone = (int) $checkpoint['entries'];
                        continue;
                    }
                    $absolute = $root . '/' . $path;
                    clearstatcache(true, $absolute);
                    $stat = is_file($absolute) && !is_link($absolute) ? @stat($absolute) : false;
                    if ($stat === false) {
                        $context->logger->warning('File vanished during backup.', ['path' => $path]);
                        $entry['st'] = 'vanished';
                        $final->append($entry);
                        $consumed = $nextOffset;
                        $checkpoint['entries']++;
                        $progress->filesDone = (int) $checkpoint['entries'];
                        continue;
                    }
                    $size = (int) $stat['size'];
                    $progress->currentItem = $path;
                    $archiveName = EntryName::for($path);

                    if ($size <= $maxVolume) {
                        if ($writer !== null && $writer->entryCount() > 0 && $writer->size() + $size > $maxVolume) {
                            $more = true;
                            break;
                        }
                        $writer ??= new VolumeWriter($context->layout->volumesDirectory(), $volumeIndex, $policy);
                        $result = $writer->addFileRange($archiveName, $absolute, 0, $size, (int) $stat['mtime'], $size);
                        $entry = $this->describe($context, $entry, $absolute, $stat, $result, $volumeIndex);
                        $entry['a'] = $archiveName;
                        $final->append($entry);
                        $checkpoint['bytes_done'] += (int) $entry['s'];
                    } else {
                        if ($writer !== null && $writer->entryCount() > 0) {
                            $more = true;
                            break;
                        }
                        if (!is_array($chunk) || $chunk['path'] !== $path) {
                            $chunk = ['path' => $path, 'next' => 0, 'size' => $size, 'hash' => null, 'chunks' => []];
                        }
                        $offset = (int) $chunk['next'] * $maxVolume;
                        $length = min($maxVolume, (int) $chunk['size'] - $offset);
                        $entryName = $archiveName . '.swbpart' . sprintf('%04d', (int) $chunk['next'] + 1);
                        $result = ReadResult::fromState(is_string($chunk['hash']) ? $chunk['hash'] : null);
                        $writer = new VolumeWriter($context->layout->volumesDirectory(), $volumeIndex, $policy);
                        $writer->addFileRange($entryName, $absolute, $offset, $length, (int) $stat['mtime'], $length, $result);
                        $chunk['chunks'][] = ['v' => $volumeIndex, 'a' => $entryName, 's' => $result->bytes, 'h' => $result->segmentSha256()];
                        $chunk['next'] = (int) $chunk['next'] + 1;
                        $chunk['hash'] = $result->state();
                        $checkpoint['bytes_done'] += $length;
                        if ($chunk['next'] * $maxVolume >= (int) $chunk['size']) {
                            $entry = $this->describe($context, $entry, $absolute, $stat, $result, (int) $chunk['chunks'][0]['v'], $chunk['chunks']);
                            $entry['s'] = (int) $chunk['size'];
                            $final->append($entry);
                            $chunk = null;
                            $consumed = $nextOffset;
                            $checkpoint['entries']++;
                        }
                        $more = true;
                        break;
                    }
                    $consumed = $nextOffset;
                    $checkpoint['entries']++;
                    $progress->bytesDone = (int) $checkpoint['bytes_done'];
                    $progress->filesDone = (int) $checkpoint['entries'];
                    if ($context->budget->exhausted()) {
                        $more = true;
                        break;
                    }
                }

                if ($writer !== null && $writer->entryCount() > 0) {
                    $volume = $writer->close();
                    $writer = null;
                    $checkpoint['volumes'][] = $volume->toArray();
                    $compressedBefore += $volume->size;
                    $context->logger->info('Archive volume completed.', ['volume' => $volume->name, 'size' => $volume->size, 'entries' => $volume->entries]);
                } else {
                    $writer?->abort();
                    $writer = null;
                }
                $checkpoint['index_offset'] = $consumed;
                $checkpoint['final_offset'] = $final->commit();
                $checkpoint['chunk'] = $chunk;
                $checkpoint['finished'] = !$more;
                $progress->bytesDone = (int) $checkpoint['bytes_done'];
                $progress->filesDone = (int) $checkpoint['entries'];
                $progress->bytesCompressed = $compressedBefore;
                $progress->volumesDone = count($checkpoint['volumes']);
                $job->setCheckpoint(Step::Archive, $checkpoint);
                $context->commit();
            } catch (\Throwable $throwable) {
                $writer?->abort();
                throw $throwable;
            } finally {
                $final->close();
            }

            if ($checkpoint['finished'] === true) {
                $context->logger->info('Archiving complete.', ['volumes' => count($checkpoint['volumes']), 'entries' => $checkpoint['entries']]);

                return StepOutcome::Done;
            }
            if ($context->budget->exhausted()) {
                return StepOutcome::Yield;
            }
        }
    }

    /**
     * Completes an index entry with archive coordinates and checks that the file did not change while it was read.
     *
     * @param array<string, mixed> $entry
     * @param array<int|string, int> $stat
     * @param list<array<string, mixed>>|null $chunks
     * @return array<string, mixed>
     */
    private function describe(BackupContext $context, array $entry, string $absolute, array $stat, ReadResult $result, int $volume, ?array $chunks = null): array
    {
        clearstatcache(true, $absolute);
        $after = @stat($absolute);
        $expected = $chunks === null ? (int) $stat['size'] : (int) $entry['s'];
        $changed = $after === false || (int) $after['size'] !== (int) $stat['size'] || (int) $after['mtime'] !== (int) $stat['mtime'];
        if ($chunks === null && $result->bytes !== $expected) {
            $changed = true;
        }
        if ($changed) {
            $context->logger->warning('File changed while it was being backed up.', ['path' => $entry['p']]);
            $entry['st'] = 'changed';
        }
        $entry['s'] = $chunks === null ? $result->bytes : (int) $stat['size'];
        $entry['m'] = (int) $stat['mtime'];
        if ($context->profile->checksums) {
            $entry['h'] = $result->sha256();
        }
        $entry['v'] = $volume;
        if ($chunks !== null) {
            $entry['c'] = $chunks;
        }

        return $entry;
    }
}
