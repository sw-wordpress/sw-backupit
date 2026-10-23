<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Files;

final class FileRestoreResult
{
    public bool $finished = false;
    /** @var array<string, mixed> */
    public array $state = [];
    /** @var list<string> */
    public array $failures = [];
}
