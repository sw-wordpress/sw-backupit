<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup\Step;

use SWBackupIt\Core\Archive\CompressionPolicy;
use SWBackupIt\Core\Archive\EntryName;
use SWBackupIt\Core\Archive\ReadResult;
use SWBackupIt\Core\Archive\VolumeInfo;
use SWBackupIt\Core\Archive\VolumeWriter;
use SWBackupIt\Core\Backup\BackupContext;
use SWBackupIt\Core\Backup\ParentIndex;
use SWBackupIt\Core\Backup\BackupStepInterface;
use SWBackupIt\Core\Backup\StepOutcome;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Scanner\FileIndexReader;
use SWBackupIt\Core\Scanner\FileIndexWriter;
use SWBackupIt\Core\Support\PathOrder;

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
            'parent_offset' => 0, 'requires' => [], 'unchanged' => 0,
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
        $preflight = $job->checkpoint(Step::Preflight);
        $parentInfo = is_array($preflight['parent'] ?? null) && in_array($preflight['effective_type'] ?? '', ['incremental', 'differential'], true) ? $preflight['parent'] : null;
        $parentReader = $parentInfo === null ? null : ParentIndex::open(
            $context->layout->stagingRoot() . '/' . $parentInfo['backup_id'],
            $context->keys,
            $context->layout->workDirectory() . '/parent',
        );
        $compressedBefore = array_sum(array_map(static fn (array $volume): int => (int) $volume['size'], $checkpoint['volumes']));

        while (true) {
            $volumeIndex = count($checkpoint['volumes']) + 1;
            $final = new FileIndexWriter($context->layout->indexPath(), (int) $checkpoint['final_offset']);
            $writer = null;
            $consumed = (int) $checkpoint['index_offset'];
            $chunk = $checkpoint['chunk'];
            $more = false;
            $parentBefore = (int) $checkpoint['parent_offset'];
            $parentGen = $parentReader?->read($parentBefore);
            $parentPeek = $parentGen !== null && $parentGen->valid() ? $parentGen->current() : null;

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

                    if ($parentGen !== null && !(is_array($chunk) && $chunk['path'] === $path)) {
                        // Merge-join with the base index (both are in canonical path order).
                        while ($parentPeek !== null && PathOrder::compare((string) $parentPeek[0]['p'], $path) < 0) {
                            $parentBefore = $parentPeek[1];
                            $parentGen->next();
                            $parentPeek = $parentGen->valid() ? $parentGen->current() : null;
                        }
                        if ($parentPeek !== null && $this->isUnchanged($parentPeek[0], $path, $size, (int) $stat['mtime'])) {
                            $base = (string) ($parentPeek[0]['b'] ?? $parentInfo['backup_id']);
                            $entry = array_intersect_key($entry, array_flip(['p', 't', 'mode'])) + ['s' => $size, 'm' => (int) $stat['mtime'], 'st' => 'ok'];
                            foreach (['h', 'v', 'a', 'c'] as $key) {
                                if (isset($parentPeek[0][$key])) {
                                    $entry[$key] = $parentPeek[0][$key];
                                }
                            }
                            $entry['b'] = $base;
                            $checkpoint['requires'][$base] = true;
                            $final->append($entry);
                            $consumed = $nextOffset;
                            $checkpoint['entries']++;
                            $checkpoint['unchanged']++;
                            $progress->filesDone = (int) $checkpoint['entries'];
                            continue;
                        }
                    }

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
                $checkpoint['parent_offset'] = $parentBefore;
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
     * A file is unchanged when the base backup holds its data with identical size and modification time.
     *
     * @param array<string, mixed> $parent
     */
    private function isUnchanged(array $parent, string $path, int $size, int $mtime): bool
    {
        return (string) $parent['p'] === $path
            && $parent['t'] === 'f'
            && ($parent['st'] ?? 'ok') === 'ok'
            && (int) $parent['s'] === $size
            && (int) $parent['m'] === $mtime
            && (isset($parent['a']) || isset($parent['c']));
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
