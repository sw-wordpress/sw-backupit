<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Crypto;

use SWBackupIt\Core\Exception\CryptoException;

/**
 * Key material for backup encryption. Either a 32 byte raw key or a passphrase (stretched with Argon2id).
 * __debugInfo and serialization never expose the secret.
 */
final class EncryptionKey
{
    public const RAW_BYTES = 32;

    private function __construct(
        public readonly string $keyId,
        #[\SensitiveParameter]
        private readonly string $secret,
        public readonly bool $isPassphrase,
    ) {
        if ($keyId === '' || strlen($keyId) > 64 || preg_match('/^[A-Za-z0-9._-]+$/', $keyId) !== 1) {
            throw new CryptoException('The key id must be 1-64 characters of A-Z a-z 0-9 . _ -');
        }
    }

    public static function fromPassphrase(string $keyId, #[\SensitiveParameter] string $passphrase): self
    {
        if (strlen($passphrase) < 8) {
            throw new CryptoException('The passphrase must be at least 8 characters long.');
        }

        return new self($keyId, $passphrase, true);
    }

    public static function fromRaw(string $keyId, #[\SensitiveParameter] string $raw): self
    {
        if (strlen($raw) !== self::RAW_BYTES) {
            throw new CryptoException('A raw key must be exactly 32 bytes.');
        }

        return new self($keyId, $raw, false);
    }

    /** Parses "base64:<32 bytes base64>" as a raw key, anything else as a passphrase. */
    public static function parse(string $keyId, #[\SensitiveParameter] string $value): self
    {
        if (str_starts_with($value, 'base64:')) {
            $raw = base64_decode(substr($value, 7), true);

            return self::fromRaw($keyId, $raw === false ? '' : $raw);
        }

        return self::fromPassphrase($keyId, $value);
    }

    public static function generateRaw(string $keyId): self
    {
        return new self($keyId, random_bytes(self::RAW_BYTES), false);
    }

    /** Exportable representation for the user to store safely (shown once). */
    public function export(): string
    {
        return $this->isPassphrase ? $this->secret : 'base64:' . base64_encode($this->secret);
    }

    /**
     * @internal Used by the stream encryptor only.
     */
    public function derive(string $salt, int $opsLimit, int $memLimitBytes): string
    {
        if (!$this->isPassphrase) {
            return $this->secret;
        }

        return sodium_crypto_pwhash(
            self::RAW_BYTES,
            $this->secret,
            $salt,
            $opsLimit,
            $memLimitBytes,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['keyId' => $this->keyId, 'isPassphrase' => $this->isPassphrase];
    }

    public function __serialize(): array
    {
        throw new CryptoException('Encryption keys must not be serialized.');
    }
}
