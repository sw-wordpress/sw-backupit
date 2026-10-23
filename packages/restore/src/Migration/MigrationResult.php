<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Migration;

final class MigrationResult
{
    public bool $finished = false;
    public int $rowsScanned = 0;
    public int $rowsChanged = 0;
    public int $replacements = 0;
    /** Values that look serialized but could not be parsed and were therefore not touched. */
    public int $skippedValues = 0;
    /** @var list<string> */
    public array $skippedTables = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'finished' => $this->finished,
            'rows_scanned' => $this->rowsScanned,
            'rows_changed' => $this->rowsChanged,
            'replacements' => $this->replacements,
            'skipped_values' => $this->skippedValues,
            'skipped_tables' => $this->skippedTables,
        ];
    }
}
