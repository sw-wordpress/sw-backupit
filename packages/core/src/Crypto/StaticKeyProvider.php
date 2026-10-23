<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Crypto;

/** Supplies the same secret for whatever key id a file asks for (restore with a user supplied key). */
final class StaticKeyProvider implements KeyProviderInterface
{
    public function __construct(#[\SensitiveParameter] private readonly string $value)
    {
    }

    public function find(string $keyId): EncryptionKey
    {
        return EncryptionKey::parse($keyId, $this->value);
    }
}
