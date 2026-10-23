<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup;

use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\Step;

/**
 * One idempotent, resumable unit of a backup (PLAN.md §6.1).
 *
 * Contract: run() may be called repeatedly (after yields, crashes, retries). It must continue from
 * the checkpoint stored in the job and must never mark a checkpoint valid before the
 * data it refers to is durably written.
 */
interface BackupStepInterface
{
    public function step(): Step;

    public function applies(BackupProfile $profile): bool;

    public function run(BackupContext $context): StepOutcome;
}
