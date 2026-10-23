<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Crypto;

/**
 * Reads keys from the environment variable SWBACKUPIT_KEY_<ID> (id upper-cased, non-alphanumerics become "_")
 * or from a map. Value: passphrase or "base64:<32 bytes>".
 */
final class EnvironmentKeyProvider implements KeyProviderInterface
{
    /** @param array<string, string> $map keyId => value (optional, checked first) */
    public function __construct(private readonly array $map = [])
    {
    }

    public function find(string $keyId): ?EncryptionKey
    {
        $value = $this->map[$keyId] ?? getenv('SWBACKUPIT_KEY_' . strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '_', $keyId)));

        return is_string($value) && $value !== '' ? EncryptionKey::parse($keyId, $value) : null;
    }
}
