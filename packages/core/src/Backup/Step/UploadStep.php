<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup\Step;

use SWBackupIt\Core\Backup\BackupContext;
use SWBackupIt\Core\Backup\BackupStepInterface;
use SWBackupIt\Core\Backup\StepOutcome;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Event\Events;
use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Exception\TransientException;
use SWBackupIt\Core\Manifest\Manifest;

/**
 * Replicates the local backup to every configured storage target (PLAN.md §10.3).
 *
 * Targets are independent: a failure on one target never touches the verified replicas of another and is recorded
 * per target. The manifest is uploaded LAST, so a remote backup without manifest is recognisably incomplete.
 * Resume granularity: one finished file on one target.
 */
final class UploadStep implements BackupStepInterface
{
    public const MAX_TARGET_ATTEMPTS = 3;

    public function step(): Step
    {
        return Step::Upload;
    }

    public function applies(BackupProfile $profile): bool
    {
        return $profile->storageTargetIds !== [];
    }

    public function run(BackupContext $context): StepOutcome
    {
        if ($context->storage === null) {
            throw new ConfigurationException('No storage manager is configured.');
        }
        $job = $context->job;
        $manifest = Manifest::read($context->layout->root());
        $files = $this->files($context, $manifest);
        $state = $job->checkpoint(Step::Upload) + ['targets' => []];
        $progress = $job->progress;
        $progress->uploadBytesTotal = array_sum(array_column($files, 'size')) * count($context->profile->storageTargetIds);
        $started = microtime(true);
        $bytesThisTick = 0;
        $anyRetry = false;

        foreach ($context->profile->storageTargetIds as $targetId) {
            $key = (string) $targetId;
            $target = $state['targets'][$key] ?? ['status' => 'pending', 'uploaded' => [], 'attempts' => 0, 'error' => null];
            if (in_array($target['status'], ['uploaded', 'failed_final'], true)) {
                $progress->uploadBytesDone += 0;
                continue;
            }
            $context->events->dispatch(Events::BEFORE_UPLOAD, ['job_id' => $job->id, 'backup_id' => $job->backupId, 'target_id' => $targetId]);
            try {
                $storage = $context->storage->remote($targetId);
                foreach ($files as $relative => $file) {
                    if (isset($target['uploaded'][$relative])) {
                        continue;
                    }
                    $target['status'] = 'uploading';
                    $progress->currentItem = $relative;
                    $sha256 = $storage->upload($file['path'], $manifest->backupId() . '/' . $relative, function (int $bytes) use ($progress, &$bytesThisTick, $started): void {
                        $progress->uploadBytesDone += $bytes;
                        $bytesThisTick += $bytes;
                        $progress->uploadRateBps = round($bytesThisTick / max(0.001, microtime(true) - $started), 1);
                    });
                    if (!hash_equals($file['sha256'], $sha256)) {
                        throw new SwbiException('The file changed while it was being uploaded.', ['file' => $relative]);
                    }
                    $target['uploaded'][$relative] = $sha256;
                    $state['targets'][$key] = $target;
                    $this->save($context, $state, $files);
                    if ($context->budget->exhausted()) {
                        return StepOutcome::Yield;
                    }
                }
                $target['status'] = 'uploaded';
                $target['error'] = null;
                $context->logger->info('Upload to storage target completed.', ['target' => $targetId, 'files' => count($files)]);
            } catch (TransientException $exception) {
                $target['attempts']++;
                $target['error'] = $exception->getMessage();
                $target['status'] = $target['attempts'] >= self::MAX_TARGET_ATTEMPTS ? 'failed_final' : 'retry';
                $anyRetry = $anyRetry || $target['status'] === 'retry';
                $context->logger->warning('Upload to storage target failed.', ['target' => $targetId, 'attempt' => $target['attempts'], 'error' => $exception->getMessage()]);
            } catch (SwbiException $exception) {
                $target['status'] = 'failed_final';
                $target['error'] = $exception->getMessage();
                $context->logger->error('Upload to storage target failed permanently.', ['target' => $targetId, 'error' => $exception->getMessage()]);
            }
            $state['targets'][$key] = $target;
            $this->save($context, $state, $files);
        }
        $context->events->dispatch(Events::AFTER_UPLOAD, ['job_id' => $job->id, 'backup_id' => $job->backupId]);
        if ($anyRetry) {
            throw new TransientException('Upload to one or more storage targets failed temporarily and will be retried.');
        }

        return StepOutcome::Done;
    }

    /**
     * @return array<string, array{path: string, size: int, sha256: string}> relative remote path => local file (manifest last)
     */
    public function files(BackupContext $context, Manifest $manifest): array
    {
        $layout = $context->layout;
        $files = [];
        foreach ($manifest->volumes() as $volume) {
            $files['volumes/' . $volume->name] = ['path' => $layout->volumesDirectory() . '/' . $volume->name, 'size' => $volume->size, 'sha256' => $volume->sha256];
        }
        foreach ($manifest->databaseParts() as $part) {
            $files['database/' . $part['name']] = ['path' => $layout->databaseDirectory() . '/' . $part['name'], 'size' => $part['size'], 'sha256' => $part['sha256']];
        }
        if ($manifest->hasFiles()) {
            $index = $layout->root() . '/' . $manifest->indexFileName();
            $files[$manifest->indexFileName()] = ['path' => $index, 'size' => (int) filesize($index), 'sha256' => (string) $manifest->filesIndexSha256()];
        }
        $manifestPath = $layout->manifestPath();
        $files['manifest.json'] = ['path' => $manifestPath, 'size' => (int) filesize($manifestPath), 'sha256' => (string) hash_file('sha256', $manifestPath)];

        return $files;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, array{path: string, size: int, sha256: string}> $files
     */
    private function save(BackupContext $context, array $state, array $files): void
    {
        $targets = [];
        foreach ($state['targets'] as $id => $target) {
            $done = count($target['uploaded']);
            $targets[] = [
                'target_id' => (int) $id,
                'status' => match ($target['status']) {
                    'uploaded' => 'uploaded',
                    'uploading' => 'uploading',
                    'failed_final', 'retry' => 'failed',
                    default => 'pending',
                },
                'error' => $target['error'],
                'files_done' => $done,
                'files_total' => count($files),
            ];
        }
        $context->job->progress->targets = $targets;
        $context->job->setCheckpoint(Step::Upload, $state);
        $context->commit();
    }
}
