<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup\Step;

use SWBackupIt\Core\Backup\BackupContext;
use SWBackupIt\Core\Backup\BackupStepInterface;
use SWBackupIt\Core\Backup\StepOutcome;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Exception\TransientException;
use SWBackupIt\Core\Manifest\Manifest;

/**
 * Verifies the uploaded objects on each target (size + SHA-256, see RemoteStorageInterface::verify()).
 * A mismatch discards the upload record of that file and sends the job back to the upload step (retry).
 */
final class VerifyRemoteStep implements BackupStepInterface
{
    public function step(): Step
    {
        return Step::VerifyRemote;
    }

    public function applies(BackupProfile $profile): bool
    {
        return $profile->storageTargetIds !== [];
    }

    public function run(BackupContext $context): StepOutcome
    {
        $job = $context->job;
        $manifest = Manifest::read($context->layout->root());
        $files = (new UploadStep())->files($context, $manifest);
        $upload = $job->checkpoint(Step::Upload);
        $state = $job->checkpoint(Step::VerifyRemote) + ['targets' => []];
        $mismatch = false;

        foreach ($context->profile->storageTargetIds as $targetId) {
            $key = (string) $targetId;
            $uploadTarget = $upload['targets'][$key] ?? ['status' => 'pending', 'uploaded' => []];
            $current = $state['targets'][$key] ?? ['status' => 'pending', 'error' => null];
            if ($uploadTarget['status'] !== 'uploaded' || $current['status'] === 'verified') {
                if ($uploadTarget['status'] !== 'uploaded') {
                    $current = ['status' => 'failed', 'error' => $uploadTarget['error'] ?? 'not uploaded'];
                    $state['targets'][$key] = $current;
                }
                continue;
            }
            try {
                $storage = $context->storage?->remote($targetId) ?? throw new SwbiException('No storage manager is configured.');
                foreach ($files as $relative => $file) {
                    if (!$storage->verify($manifest->backupId() . '/' . $relative, $file['size'], $file['sha256'])) {
                        $context->logger->warning('Remote verification failed; the file will be uploaded again.', ['target' => $targetId, 'file' => $relative]);
                        unset($upload['targets'][$key]['uploaded'][$relative]);
                        $upload['targets'][$key]['status'] = 'retry';
                        $upload['targets'][$key]['attempts'] = (int) ($upload['targets'][$key]['attempts'] ?? 0) + 1;
                        if ($upload['targets'][$key]['attempts'] >= UploadStep::MAX_TARGET_ATTEMPTS) {
                            $upload['targets'][$key]['status'] = 'failed_final';
                            $upload['targets'][$key]['error'] = 'Remote verification failed repeatedly.';
                        }
                        $mismatch = $mismatch || $upload['targets'][$key]['status'] === 'retry';
                        $current = ['status' => 'failed', 'error' => 'Remote verification failed for ' . $relative];
                        continue 2;
                    }
                }
                $current = ['status' => 'verified', 'error' => null];
                $context->logger->info('Remote copy verified.', ['target' => $targetId]);
            } catch (TransientException $exception) {
                throw $exception;
            } catch (SwbiException $exception) {
                $current = ['status' => 'failed', 'error' => $exception->getMessage()];
                $context->logger->error('Remote verification failed.', ['target' => $targetId, 'error' => $exception->getMessage()]);
            }
            $state['targets'][$key] = $current;
        }
        $job->setCheckpoint(Step::Upload, $upload);
        $job->setCheckpoint(Step::VerifyRemote, $state);
        $this->syncProgress($context, $state);
        if ($mismatch) {
            $job->completedSteps = array_values(array_diff($job->completedSteps, [Step::Upload->value]));
            $state['targets'] = array_filter($state['targets'], static fn (array $target): bool => $target['status'] === 'verified');
            $job->setCheckpoint(Step::VerifyRemote, $state);
            $context->commit();
            throw new TransientException('Remote verification failed; uploading again.');
        }
        $context->commit();

        return StepOutcome::Done;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function syncProgress(BackupContext $context, array $state): void
    {
        $targets = [];
        foreach ($state['targets'] as $id => $target) {
            $targets[] = ['target_id' => (int) $id, 'status' => $target['status'], 'error' => $target['error']];
        }
        $context->job->progress->targets = $targets;
    }
}
