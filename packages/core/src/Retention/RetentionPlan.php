<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Retention;

final class RetentionPlan
{
    /** @var array<string, list<string>> backup id => reasons for keeping */
    public array $keep = [];
    /** @var array<string, string> backup id => reason for deletion */
    public array $delete = [];
    /** @var list<string> */
    public array $warnings = [];

    /**
     * @return list<string>
     */
    public function deletions(): array
    {
        return array_keys($this->delete);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['keep' => $this->keep, 'delete' => $this->delete, 'warnings' => $this->warnings];
    }
}
