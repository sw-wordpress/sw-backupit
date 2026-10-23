<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup\Step;

use SWBackupIt\Core\Backup\BackupContext;
use SWBackupIt\Core\Backup\BackupStepInterface;
use SWBackupIt\Core\Backup\StepOutcome;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Scanner\FileScanner;
use SWBackupIt\Core\Scanner\RuleSet;
use SWBackupIt\Core\Support\ResourceBudget;

/** Builds the file inventory (resumable, streamed to work/inventory.ndjson). */
final class ScanStep implements BackupStepInterface
{
    public function step(): Step
    {
        return Step::Scan;
    }

    public function applies(BackupProfile $profile): bool
    {
        return $profile->type->includesFiles();
    }

    public function run(BackupContext $context): StepOutcome
    {
        $profile = $context->profile;
        $rules = new RuleSet($profile->includePaths, $this->excludes($context), $profile->includePatterns, $profile->excludePatterns);
        $scanner = new FileScanner($context->source->rootPath, $rules, $context->logger);
        $cursor = $context->job->checkpoint(Step::Scan);
        if (($cursor['finished'] ?? false) === true) {
            return StepOutcome::Done;
        }
        // Scan in slices so the lease/checkpoint is refreshed regularly.
        while (true) {
            $slice = new ResourceBudget(min(5.0, $context->budget->remainingSeconds()));
            $result = $scanner->scan($context->layout->inventoryPath(), $cursor, $slice);
            $cursor = $result->cursor;
            $cursor['finished'] = $result->finished;
            $context->job->setCheckpoint(Step::Scan, $cursor);
            $context->job->progress->filesTotal = (int) $cursor['count'];
            $context->job->progress->bytesTotal = (int) $cursor['bytes'];
            $context->job->progress->currentItem = '';
            $context->commit();
            if ($result->finished) {
                $context->logger->info('File inventory complete.', ['entries' => $cursor['count'], 'bytes' => $cursor['bytes']]);

                return StepOutcome::Done;
            }
            if ($context->budget->exhausted()) {
                return StepOutcome::Yield;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function excludes(BackupContext $context): array
    {
        $excludes = $context->profile->excludePaths;
        $root = realpath($context->source->rootPath);
        $staging = realpath($context->layout->stagingRoot());
        if ($root !== false && $staging !== false && str_starts_with($staging . '/', $root . '/') && $staging !== $root) {
            $excludes[] = ltrim(substr($staging, strlen($root)), '/');
        }

        return $excludes;
    }
}
