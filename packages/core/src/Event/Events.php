<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Event;

/** Names of framework independent core events (PLAN.md §17). */
final class Events
{
    public const BEFORE_BACKUP = 'before_backup';
    public const AFTER_BACKUP = 'after_backup';
    public const BACKUP_FAILED = 'backup_failed';
    public const BEFORE_DATABASE_EXPORT = 'before_database_export';
    public const AFTER_DATABASE_EXPORT = 'after_database_export';
    public const BEFORE_UPLOAD = 'before_upload';
    public const AFTER_UPLOAD = 'after_upload';
    public const BEFORE_RESTORE = 'before_restore';
    public const AFTER_RESTORE = 'after_restore';
    public const RESTORE_FAILED = 'restore_failed';

    // Fine grained, always isolated (never vetoable) observation points around every job step.
    public const BEFORE_STEP = 'before_step';
    public const AFTER_STEP = 'after_step';
    public const STEP_FAILED = 'step_failed';
    public const JOB_STATE_CHANGED = 'job_state_changed';

    /** Events whose listeners may veto the operation by throwing. */
    public const VETOABLE = [
        self::BEFORE_BACKUP,
        self::BEFORE_DATABASE_EXPORT,
        self::BEFORE_UPLOAD,
        self::BEFORE_RESTORE,
    ];
}
