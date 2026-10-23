<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Event;

enum FailurePolicy
{
    /** A listener exception aborts the operation (only allowed for "before_*" events). */
    case Blocking;
    /** A listener exception is logged and ignored; the backup state is never affected. */
    case Isolated;
}
