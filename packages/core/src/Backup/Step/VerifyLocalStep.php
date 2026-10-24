<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup\Step;

use SWBackupIt\Core\Archive\VolumeInfo;
use SWBackupIt\Core\Archive\VolumeReader;
use SWBackupIt\Core\Backup\Artifacts;
use SWBackupIt\Core\Backup\BackupContext;
use SWBackupIt\Core\Backup\BackupStepInterface;
use SWBackupIt\Core\Backup\StepOutcome;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Exception\IntegrityException;
use SWBackupIt\Core\Scanner\FileIndexReader;

/**
 * Local verification before anything is uploaded or declared successful (PLAN.md §29).
 *
 * Plain backups: volume checksums + ZIP structure, database part checksums, index <-> volume consistency.
 * Encrypted backups: the structural checks run in EncryptStep *before* encryption (verifyPlain); this step then verifies the
 * stored (encrypted) artifacts by size and checksum.
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
        $state = $context->job->checkpoint(Step::VerifyLocal);
        $save = function (array $state) use ($context): void {
            $context->job->setCheckpoint(Step::VerifyLocal, $state);
            $context->commit();
        };

        if (Artifacts::isEncrypted($context->job)) {
            return $this->verifyStored($context, $state, $save);
        }
        $outcome = $this->verifyPlain($context, $state, $save);
        if ($outcome === StepOutcome::Done) {
            $context->logger->info('Local verification succeeded.');
        }

        return $outcome;
    }

    /**
     * Structural verification of plain artifacts. Reusable with a caller owned state array.
     *
     * @param array<string, mixed> $state
     * @param callable(array<string, mixed>): void $save Persists the state.
     */
    public function verifyPlain(BackupContext $context, array $state, callable $save): StepOutcome
    {
        $state += ['volumes_verified' => 0, 'parts_verified' => 0, 'index_verified' => false];
        $volumes = Artifacts::volumes($context->job);
        $parts = Artifacts::databaseParts($context->job);
        $total = count($volumes) + count($parts) + 1;
        $reader = new VolumeReader();

        while ($state['volumes_verified'] < count($volumes)) {
            $info = VolumeInfo::fromArray($volumes[$state['volumes_verified']]);
            $reader->verify($context->layout->volumesDirectory() . '/' . $info->name, $info);
            $state['volumes_verified']++;
            $this->progress($context, $state, $total);
            $save($state);
            if ($context->budget->exhausted() && $state['volumes_verified'] < count($volumes)) {
                return StepOutcome::Yield;
            }
        }
        while ($state['parts_verified'] < count($parts)) {
            $part = $parts[$state['parts_verified']];
            $this->verifyFile($context->layout->databaseDirectory() . '/' . $part['name'], $part['size'], $part['sha256'], $part['name']);
            $state['parts_verified']++;
            $this->progress($context, $state, $total);
            $save($state);
        }
        if ($state['index_verified'] !== true) {
            if ($context->profile->type->includesFiles()) {
                $this->verifyIndex($context, $volumes);
            }
            $state['index_verified'] = true;
            $this->progress($context, $state, $total);
            $save($state);
        }

        return StepOutcome::Done;
    }

    /**
     * @param array<string, mixed> $state
     * @param callable(array<string, mixed>): void $save
     */
    private function verifyStored(BackupContext $context, array $state, callable $save): StepOutcome
    {
        $state += ['stored_verified' => false];
        if ($state['stored_verified'] === true) {
            return StepOutcome::Done;
        }
        foreach (Artifacts::volumes($context->job) as $volume) {
            $this->verifyFile($context->layout->volumesDirectory() . '/' . $volume['name'], $volume['size'], $volume['sha256'], $volume['name']);
        }
        foreach (Artifacts::databaseParts($context->job) as $part) {
            $this->verifyFile($context->layout->databaseDirectory() . '/' . $part['name'], $part['size'], $part['sha256'], $part['name']);
        }
        if ($context->profile->type->includesFiles()) {
            $name = Artifacts::indexName($context->job);
            $stored = $context->job->checkpoint(Step::Encrypt)['index'] ?? [];
            $this->verifyFile($context->layout->root() . '/' . $name, (int) ($stored['size'] ?? -1), (string) ($stored['sha256'] ?? ''), $name);
        }
        $state['stored_verified'] = true;
        $save($state);
        $context->logger->info('Local verification of encrypted artifacts succeeded.');

        return StepOutcome::Done;
    }

    private function verifyFile(string $path, int $size, string $sha256, string $name): void
    {
        $hash = is_file($path) && filesize($path) === $size ? hash_file('sha256', $path) : false;
        if ($hash === false || !hash_equals($sha256, $hash)) {
            throw new IntegrityException('A backup file failed verification.', ['file' => $name]);
        }
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
            if ($entry['t'] !== 'f' || isset($entry['b']) || in_array($entry['st'] ?? 'ok', ['unreadable', 'vanished'], true)) {
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
     * @param array<string, mixed> $state
     */
    private function progress(BackupContext $context, array $state, int $total): void
    {
        $done = (int) $state['volumes_verified'] + (int) $state['parts_verified'] + ($state['index_verified'] ? 1 : 0);
        $context->job->progress->stepFraction = min(1.0, $done / max(1, $total));
    }
}
