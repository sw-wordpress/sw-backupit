<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup;

enum StepOutcome
{
    /** The step is complete; the runner proceeds with the next one. */
    case Done;
    /** The time budget is used up; the step continues in the next tick. */
    case Yield;
}
