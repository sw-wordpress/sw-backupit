<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Archive;

use HashContext;

/** Collects byte count and SHA-256 of a stream while it is being consumed by the ZIP writer. */
final class ReadResult
{
    public int $bytes = 0;
    private HashContext $hash;
    private HashContext $segment;

    public function __construct(?HashContext $hash = null)
    {
        $this->hash = $hash ?? hash_init('sha256');
        $this->segment = hash_init('sha256');
    }

    /** Restores the running hash of a partially archived (chunked) file. */
    public static function fromState(?string $state): self
    {
        if ($state === null || $state === '') {
            return new self();
        }
        $context = unserialize(base64_decode($state, true) ?: '', ['allowed_classes' => [HashContext::class]]);

        return new self($context instanceof HashContext ? $context : null);
    }

    /** Serialises the running hash so a later tick can continue the same file. */
    public function state(): string
    {
        return base64_encode(serialize(hash_copy($this->hash)));
    }

    public function update(string $data): void
    {
        $this->bytes += strlen($data);
        hash_update($this->hash, $data);
        hash_update($this->segment, $data);
    }

    /** SHA-256 of the bytes read through this instance only (one chunk of a chunked file). */
    public function segmentSha256(): string
    {
        return hash_final(hash_copy($this->segment));
    }

    public function sha256(): string
    {
        return hash_final(hash_copy($this->hash));
    }
}
