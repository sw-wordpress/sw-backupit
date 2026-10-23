<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Crypto;

/** Asks several providers in order (e.g. environment/constant first, stored keys second). */
final class ChainKeyProvider implements KeyProviderInterface
{
    /** @param list<KeyProviderInterface> $providers */
    public function __construct(private readonly array $providers)
    {
    }

    public function find(string $keyId): ?EncryptionKey
    {
        foreach ($this->providers as $provider) {
            $key = $provider->find($keyId);
            if ($key !== null) {
                return $key;
            }
        }

        return null;
    }
}
