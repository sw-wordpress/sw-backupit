<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup;

use SWBackupIt\Core\Crypto\KeyProviderInterface;
use SWBackupIt\Core\Crypto\StreamCipher;
use SWBackupIt\Core\Exception\CryptoException;
use SWBackupIt\Core\Manifest\Manifest;
use SWBackupIt\Core\Scanner\FileIndexReader;

/** Provides a readable (decrypted if necessary) file index of the base backup of an incremental/differential backup. */
final class ParentIndex
{
    public static function open(string $parentDirectory, ?KeyProviderInterface $keys, string $workDirectory): FileIndexReader
    {
        $manifest = Manifest::read($parentDirectory);
        $stored = $parentDirectory . '/' . $manifest->indexFileName();
        if (!$manifest->isEncrypted()) {
            return new FileIndexReader($stored);
        }
        if ($keys === null) {
            throw new CryptoException('The base backup is encrypted but no key store is available.');
        }
        $plain = $workDirectory . '/parent-index.ndjson';
        if (!is_file($plain)) {
            @mkdir($workDirectory, 0700, true);
            StreamCipher::decryptFile($stored, $plain, static fn (string $keyId) => $keys->find($keyId));
        }

        return new FileIndexReader($plain);
    }
}
