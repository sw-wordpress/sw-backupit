<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup\Step;

use SWBackupIt\Core\Archive\VolumeInfo;
use SWBackupIt\Core\Archive\VolumeReader;
use SWBackupIt\Core\Backup\BackupContext;
use SWBackupIt\Core\Backup\BackupStepInterface;
use SWBackupIt\Core\Backup\StepOutcome;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Exception\IntegrityException;
use SWBackupIt\Core\Scanner\FileIndexReader;

/**
 * Local verification before anything is uploaded or declared successful (PLAN.md §29):
 * volume checksums + ZIP structure, database part checksums and index <-> volume consistency.
 */
final class VerifyLocalStep implements BackupStepInterface
{
    public function step(): Step
    {
        return Step::VerifyLocal;
    }

    public function applies(BackupProfile $profile): bool
    {
        return true;
    }

    public function run(BackupContext $context): StepOutcome
    {
        $job = $context->job;
        $checkpoint = $job->checkpoint(Step::VerifyLocal) + ['volumes_verified' => 0, 'parts_verified' => 0, 'index_verified' => false];
        $archive = $job->checkpoint(Step::Archive);
        $export = $job->checkpoint(Step::Export);
        /** @var list<array<string, mixed>> $volumes */
        $volumes = $archive['volumes'] ?? [];
        /** @var list<array<string, mixed>> $parts */
        $parts = $export['parts'] ?? [];
        $reader = new VolumeReader();

        while ($checkpoint['volumes_verified'] < count($volumes)) {
            $info = VolumeInfo::fromArray($volumes[$checkpoint['volumes_verified']]);
            $reader->verify($context->layout->volumesDirectory() . '/' . $info->name, $info);
            $checkpoint['volumes_verified']++;
            $this->save($context, $checkpoint, count($volumes) + count($parts) + 1);
            if ($context->budget->exhausted() && $checkpoint['volumes_verified'] < count($volumes)) {
                return StepOutcome::Yield;
            }
        }
        while ($checkpoint['parts_verified'] < count($parts)) {
            $part = $parts[$checkpoint['parts_verified']];
            $path = $context->layout->databaseDirectory() . '/' . $part['name'];
            $hash = is_file($path) ? hash_file('sha256', $path) : false;
            if ($hash === false || !hash_equals((string) $part['sha256'], $hash)) {
                throw new IntegrityException('Database export part failed verification.', ['part' => (string) $part['name']]);
            }
            $checkpoint['parts_verified']++;
            $this->save($context, $checkpoint, count($volumes) + count($parts) + 1);
        }
        if ($checkpoint['index_verified'] !== true) {
            if ($context->profile->type->includesFiles()) {
                $this->verifyIndex($context, $volumes);
            }
            $checkpoint['index_verified'] = true;
            $this->save($context, $checkpoint, count($volumes) + count($parts) + 1);
        }
        $context->logger->info('Local verification succeeded.', ['volumes' => count($volumes), 'database_parts' => count($parts)]);

        return StepOutcome::Done;
    }

    /**
     * @param list<array<string, mixed>> $volumes
     */
    private function verifyIndex(BackupContext $context, array $volumes): void
    {
        $expectedEntries = array_sum(array_map(static fn (array $volume): int => (int) $volume['entries'], $volumes));
        $seen = 0;
        $maxVolume = count($volumes);
        foreach ((new FileIndexReader($context->layout->indexPath()))->read() as [$entry]) {
            if ($entry['t'] !== 'f' || ($entry['st'] ?? 'ok') === 'unreadable' || ($entry['st'] ?? 'ok') === 'vanished') {
                continue;
            }
            $references = isset($entry['c']) && is_array($entry['c']) ? $entry['c'] : [['v' => $entry['v'] ?? 0]];
            foreach ($references as $reference) {
                $volume = (int) ($reference['v'] ?? 0);
                if ($volume < 1 || $volume > $maxVolume) {
                    throw new IntegrityException('File index references a missing volume.', ['path' => (string) $entry['p']]);
                }
                $seen++;
            }
        }
        if ($seen !== $expectedEntries) {
            throw new IntegrityException('File index and archive volumes are inconsistent.', ['index_entries' => $seen, 'volume_entries' => $expectedEntries]);
        }
    }

    /**
     * @param array<string, mixed> $checkpoint
     */
    private function save(BackupContext $context, array $checkpoint, int $total): void
    {
        $context->job->setCheckpoint(Step::VerifyLocal, $checkpoint);
        $done = (int) $checkpoint['volumes_verified'] + (int) $checkpoint['parts_verified'] + ($checkpoint['index_verified'] ? 1 : 0);
        $context->job->progress->stepFraction = min(1.0, $done / max(1, $total));
        $context->commit();
    }
}
