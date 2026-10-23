<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Domain;

/** Processing steps of a backup job in execution order (PLAN.md §6.1). */
enum Step: string
{
    case Preflight = 'preflight';
    case Scan = 'scan';
    case Export = 'export';
    case Archive = 'archive';
    case Encrypt = 'encrypt';
    case VerifyLocal = 'verify_local';
    case Manifest = 'manifest';
    case Upload = 'upload';
    case VerifyRemote = 'verify_remote';
    case Finalize = 'finalize';
    case Retention = 'retention';
    case Notify = 'notify';

    public function status(): JobStatus
    {
        return match ($this) {
            self::Preflight, self::Finalize, self::Manifest => JobStatus::Preparing,
            self::Scan => JobStatus::Scanning,
            self::Export => JobStatus::Exporting,
            self::Archive => JobStatus::Archiving,
            self::Encrypt => JobStatus::Encrypting,
            self::Upload => JobStatus::Uploading,
            self::VerifyLocal, self::VerifyRemote, self::Retention, self::Notify => JobStatus::Verifying,
        };
    }

    /**
     * Lower/upper bound (percent) of this step within the whole job.
     *
     * @return array{int, int}
     */
    public function weight(): array
    {
        return match ($this) {
            self::Preflight => [0, 2],
            self::Scan => [2, 8],
            self::Export => [8, 25],
            self::Archive => [25, 60],
            self::Encrypt => [60, 68],
            self::VerifyLocal => [68, 76],
            self::Manifest => [76, 78],
            self::Upload => [78, 90],
            self::VerifyRemote => [90, 95],
            self::Finalize => [95, 97],
            self::Retention => [97, 99],
            self::Notify => [99, 100],
        };
    }
}
