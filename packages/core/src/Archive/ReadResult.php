<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Archive;

use HashContext;

/** Collects byte count and SHA-256 of a stream while it is being consumed by the ZIP writer. */
final class ReadResult
{
    public int $bytes = 0;
    private HashContext $hash;

    public function __construct()
    {
        $this->hash = hash_init('sha256');
    }

    public function update(string $data): void
    {
        $this->bytes += strlen($data);
        hash_update($this->hash, $data);
    }

    public function sha256(): string
    {
        return hash_final(hash_copy($this->hash));
    }
}
