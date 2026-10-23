<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Domain;

/**
 * Encryption configuration of a profile. Never contains the passphrase or key material:
 * only an identifier under which the key is resolved at runtime.
 */
final readonly class EncryptionSettings
{
    public function __construct(public bool $enabled = false, public string $keyId = '')
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['enabled'] ?? false),
            is_string($data['key_id'] ?? null) ? $data['key_id'] : '',
        );
    }

    /**
     * @return array{enabled: bool, key_id: string}
     */
    public function toArray(): array
    {
        return ['enabled' => $this->enabled, 'key_id' => $this->keyId];
    }
}
