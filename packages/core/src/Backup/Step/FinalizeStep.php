<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup\Step;

use SWBackupIt\Core\Backup\BackupContext;
use SWBackupIt\Core\Backup\BackupStepInterface;
use SWBackupIt\Core\Backup\StepOutcome;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Exception\IntegrityException;
use SWBackupIt\Core\Manifest\Manifest;

/**
 * Decides whether the backup counts as successful (PLAN.md §29): required storage targets confirmed according to the
 * profile's success policy, local manifest valid. Cleans temporary data. Never turns a failed replication into success.
 */
final class FinalizeStep implements BackupStepInterface
{
    public function step(): Step
    {
        return Step::Finalize;
    }

    public function applies(BackupProfile $profile): bool
    {
        return true;
    }

    public function run(BackupContext $context): StepOutcome
    {
        $profile = $context->profile;
        $manifest = Manifest::read($context->layout->root());
        $targets = $context->job->checkpoint(Step::VerifyRemote)['targets'] ?? [];
        $verified = 0;
        $failed = [];
        foreach ($profile->storageTargetIds as $targetId) {
            $status = (string) ($targets[(string) $targetId]['status'] ?? 'pending');
            if ($status === 'verified') {
                $verified++;
            } else {
                $failed[] = $targetId;
            }
        }
        $required = match ($profile->successPolicy) {
            'all' => count($profile->storageTargetIds),
            'any' => min(1, count($profile->storageTargetIds)),
            default => 0,
        };
        foreach ($failed as $targetId) {
            $context->logger->warning('Storage target does not hold a verified copy.', ['target' => $targetId]);
        }
        if ($verified < $required) {
            throw new IntegrityException(
                sprintf('Success policy "%s" not met: %d of %d required storage targets hold a verified copy.', $profile->successPolicy, $verified, $required),
                ['policy' => $profile->successPolicy],
            );
        }
        $context->layout->removeWork();
        if ($verified > 0 && (bool) ($profile->retention['delete_local_after_upload'] ?? false)) {
            $this->deleteLocalCopy($context, $manifest);
        }
        $context->logger->info('Backup finalized.', ['verified_targets' => $verified, 'backup_id' => $manifest->backupId()]);

        return StepOutcome::Done;
    }

    /** Keeps only the manifest locally; the data lives on the verified remote targets. */
    private function deleteLocalCopy(BackupContext $context, Manifest $manifest): void
    {
        $context->layout->removeData($manifest);
        $context->logger->notice('Local copy removed after verified upload (manifest kept).');
    }
}
