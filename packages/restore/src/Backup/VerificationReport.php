<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Backup;

final class VerificationReport
{
    /** @var list<string> */
    private array $errors = [];
    public int $volumesChecked = 0;
    public int $databasePartsChecked = 0;
    public int $filesChecked = 0;

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function isOk(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->isOk(),
            'checked_volumes' => $this->volumesChecked,
            'checked_database_parts' => $this->databasePartsChecked,
            'checked_files' => $this->filesChecked,
            'errors' => $this->errors,
        ];
    }
}
