<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup\Step;

use SWBackupIt\Core\Backup\BackupContext;
use SWBackupIt\Core\Backup\BackupStepInterface;
use SWBackupIt\Core\Backup\ParentSelector;
use SWBackupIt\Core\Backup\StepOutcome;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Event\Events;
use SWBackupIt\Core\Exception\PreflightException;
use SWBackupIt\Core\Support\Bytes;

/** Validates the environment before any data is touched and prepares the staging area. */
final class PreflightStep implements BackupStepInterface
{
    private const MIN_FREE_BYTES = 67108864;

    public function step(): Step
    {
        return Step::Preflight;
    }

    public function applies(BackupProfile $profile): bool
    {
        return true;
    }

    public function run(BackupContext $context): StepOutcome
    {
        $profile = $context->profile;
        $source = $context->source;

        foreach (['zlib', 'hash', 'json'] as $extension) {
            if (!extension_loaded($extension)) {
                throw new PreflightException("Required PHP extension '{$extension}' is missing.");
            }
        }
        if ($profile->type->includesFiles() && !extension_loaded('zip')) {
            throw new PreflightException("Required PHP extension 'zip' is missing.");
        }
        if ($profile->encryption->enabled && !extension_loaded('sodium')) {
            throw new PreflightException("Encryption requires the PHP 'sodium' extension.");
        }
        if ($profile->externalPaths !== []) {
            throw new PreflightException('Paths outside the WordPress directory are not supported yet (planned for a later release).');
        }
        if ($profile->type->includesFiles() && (!is_dir($source->rootPath) || !is_readable($source->rootPath))) {
            throw new PreflightException('The WordPress directory is not readable.');
        }
        if ($profile->type->includesDatabase()) {
            if ($source->database === null) {
                throw new PreflightException('A database export was requested but no database connection is configured.');
            }
            if (!extension_loaded('pdo_mysql')) {
                throw new PreflightException("Required PHP extension 'pdo_mysql' is missing.");
            }
            $context->pdo()->query('SELECT 1');
        }

        $context->layout->prepare();
        if (!is_writable($context->layout->root())) {
            throw new PreflightException('The staging directory is not writable.');
        }
        $free = @disk_free_space($context->layout->root());
        if ($free !== false && $free < self::MIN_FREE_BYTES) {
            throw new PreflightException('Not enough free disk space in the staging directory.', ['free' => Bytes::format((int) $free)]);
        }
        $root = realpath($source->rootPath);
        $staging = realpath($context->layout->stagingRoot());
        if ($root !== false && $staging !== false && ($staging === $root || str_starts_with($staging, $root . '/'))) {
            $context->logger->warning('The staging directory is located inside the WordPress directory. Make sure the web server denies access to it, or move it outside the web root.');
        }

        $parent = (new ParentSelector())->select($context);
        $effective = $profile->type;
        if ($profile->type->isDelta()) {
            if ($parent === null) {
                $effective = \SWBackupIt\Core\Domain\BackupType::Full;
                $context->logger->notice('No usable base backup with a local file index was found; creating a full backup instead.');
            } else {
                $context->logger->info('Base backup selected.', ['parent' => $parent['backup_id']]);
            }
        }
        $context->job->setCheckpoint(Step::Preflight, ['parent' => $parent, 'effective_type' => $effective->value]);

        // May veto (blocking listeners) before any state is changed.
        $context->events->dispatch(Events::BEFORE_BACKUP, $this->payload($context));
        $context->logger->info('Preflight checks passed.', ['free_bytes' => $free === false ? null : (int) $free]);

        return StepOutcome::Done;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(BackupContext $context): array
    {
        return [
            'job_id' => $context->job->id,
            'backup_id' => $context->job->backupId,
            'profile' => $context->profile->name,
            'type' => $context->profile->type->value,
        ];
    }
}
