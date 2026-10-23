<?php

declare(strict_types=1);

namespace SWBackupIt\Restore;

use SWBackupIt\Core\Support\AtomicFile;

/** Persists restore progress so an interrupted restore can continue (PLAN.md §13.2). */
final class RestoreStateStore
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $file = $this->file();

        return is_file($file) ? AtomicFile::readJson($file) : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function save(array $state): void
    {
        AtomicFile::writeJson($this->file(), $state);
    }

    public function clear(): void
    {
        @unlink($this->file());
    }

    private function file(): string
    {
        return rtrim($this->directory, '/') . '/restore-state.json';
    }
}
