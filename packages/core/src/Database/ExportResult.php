<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Database;

final readonly class ExportResult
{
    /**
     * @param array<string, mixed> $cursor
     */
    public function __construct(public bool $finished, public array $cursor)
    {
    }
}
