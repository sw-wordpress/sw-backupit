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
    case Upload = 'upload';
    case VerifyRemote = 'verify_remote';
    case Finalize = 'finalize';
    case Retention = 'retention';
    case Notify = 'notify';

    public function status(): JobStatus
    {
        return match ($this) {
            self::Preflight, self::Finalize => JobStatus::Preparing,
            self::Scan => JobStatus::Scanning,
            self::Export => JobStatus::Exporting,
            self::Archive => JobStatus::Archiving,
            self::Encrypt => JobStatus::Encrypting,
            self::Upload => JobStatus::Uploading,
            self::VerifyLocal, self::VerifyRemote, self::Retention, self::Notify => JobStatus::Verifying,
        };
    }

    /** Lower/upper bound (percent) of this step within the whole job. */
    public function weight(): array
    {
        return match ($this) {
            self::Preflight => [0, 2],
            self::Scan => [2, 10],
            self::Export => [10, 30],
            self::Archive => [30, 70],
            self::Encrypt => [70, 75],
            self::VerifyLocal => [75, 85],
            self::Upload => [85, 93],
            self::VerifyRemote => [93, 96],
            self::Finalize => [96, 98],
            self::Retention => [98, 99],
            self::Notify => [99, 100],
        };
    }
}
