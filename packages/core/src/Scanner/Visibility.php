<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Scanner;

enum Visibility
{
    /** Excluded: neither emitted nor descended into. */
    case Skip;
    /** Not emitted itself (e.g. an ancestor of an include path) but its children are visited. */
    case Traverse;
    /** Emitted (and descended into when a directory). */
    case Emit;
}
