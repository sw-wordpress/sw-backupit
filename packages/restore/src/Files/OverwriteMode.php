<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Files;

enum OverwriteMode: string
{
    case Always = 'always';
    case Never = 'never';
    case IfDifferent = 'if_different';
}
