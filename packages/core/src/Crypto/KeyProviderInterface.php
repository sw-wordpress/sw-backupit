<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Crypto;

/** Resolves encryption keys by id at runtime. Keys are never stored in profiles, jobs, logs or manifests. */
interface KeyProviderInterface
{
    public function find(string $keyId): ?EncryptionKey;
}
