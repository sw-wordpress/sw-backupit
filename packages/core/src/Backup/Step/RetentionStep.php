<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup\Step;

use SWBackupIt\Core\Backup\BackupContext;
use SWBackupIt\Core\Backup\BackupStepInterface;
use SWBackupIt\Core\Backup\StepOutcome;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Retention\RetentionExecutor;
use SWBackupIt\Core\Retention\RetentionPlanner;
use SWBackupIt\Core\Retention\RetentionPolicy;

/** Applies the profile's retention after a successful backup. Failures here never fail the (already successful) backup. */
final class RetentionStep implements BackupStepInterface
{
    public function step(): Step
    {
        return Step::Retention;
    }

    public function applies(BackupProfile $profile): bool
    {
        return RetentionPolicy::fromArray($profile->retention)->isActive();
    }

    public function run(BackupContext $context): StepOutcome
    {
        if ($context->catalog === null) {
            $context->logger->notice('Retention skipped: no backup catalog configured.');

            return StepOutcome::Done;
        }
        try {
            $policy = RetentionPolicy::fromArray($context->profile->retention);
            $records = $context->catalog->records($context->profile->id);
            $plan = (new RetentionPlanner())->plan($records, $policy, $context->clock->now());
            foreach ($plan->warnings as $warning) {
                $context->logger->warning($warning);
            }
            $result = (new RetentionExecutor($context->logger))->execute($plan, $context->catalog);
            $context->logger->info('Retention applied.', ['deleted' => count($result['deleted']), 'failed' => count($result['failed'])]);
        } catch (\Throwable $throwable) {
            $context->logger->warning('Retention failed and was skipped.', ['error' => $throwable::class]);
        }

        return StepOutcome::Done;
    }
}
