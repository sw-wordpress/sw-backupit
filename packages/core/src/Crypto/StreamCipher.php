<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Crypto;

use SWBackupIt\Core\Exception\CryptoException;
use SWBackupIt\Core\Support\AtomicFile;

/**
 * Streaming authenticated encryption of files: format SWBE1 (docs/backup-format.md).
 *
 * libsodium secretstream (XChaCha20-Poly1305), 1 MiB plaintext chunks, so memory use is constant.
 * The header (including key id and KDF parameters) is bound to every chunk as additional data; the
 * final chunk carries the FINAL tag, so truncation and reordering are detected. A wrong key or any
 * modification fails authentication *before* plaintext is released for that chunk.
 *
 * Layout: "SWBE" | version(1) | kdf(1: 0 raw, 1 argon2id) | reserved(1)=0 | keyIdLen(1) | keyId
 *         | [salt(16) | opsLimit u32 | memLimitKiB u32 when kdf=1] | secretstream header(24)
 *         then repeated: u32 big endian ciphertext length | ciphertext
 */
final class StreamCipher
{
    public const MAGIC = 'SWBE';
    public const VERSION = 1;
    public const CHUNK_SIZE = 1048576;
    private const ABYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

    public static function assertAvailable(): void
    {
        if (!extension_loaded('sodium') || !function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw new CryptoException('The PHP sodium extension is required for encryption.');
        }
    }

    public static function encryptFile(string $source, string $destination, EncryptionKey $key, int $opsLimit = 3, int $memLimitBytes = 67108864): void
    {
        self::assertAvailable();
        $in = fopen($source, 'rb');
        if ($in === false) {
            throw new CryptoException('Cannot read the file to encrypt.', ['file' => basename($source)]);
        }
        $temporary = $destination . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $out = fopen($temporary, 'wb');
        if ($out === false) {
            fclose($in);
            throw new CryptoException('Cannot write the encrypted file.', ['file' => basename($destination)], true);
        }
        try {
            $salt = $key->isPassphrase ? random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES) : '';
            $derived = $key->derive($salt, $opsLimit, $memLimitBytes);
            [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($derived);
            sodium_memzero($derived);

            $header = self::MAGIC . chr(self::VERSION) . chr($key->isPassphrase ? 1 : 0) . "\0" . chr(strlen($key->keyId)) . $key->keyId;
            if ($key->isPassphrase) {
                $header .= $salt . pack('N', $opsLimit) . pack('N', intdiv($memLimitBytes, 1024));
            }
            $header .= $streamHeader;
            self::write($out, $header);

            $buffer = (string) fread($in, self::CHUNK_SIZE);
            while (true) {
                $next = feof($in) ? '' : (string) fread($in, self::CHUNK_SIZE);
                $final = $next === '' && feof($in);
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $buffer,
                    $header,
                    $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                );
                self::write($out, pack('N', strlen($cipher)) . $cipher);
                if ($final) {
                    break;
                }
                $buffer = $next;
            }
            fflush($out);
            AtomicFile::sync($out);
            fclose($out);
            fclose($in);
            if (!rename($temporary, $destination)) {
                throw new CryptoException('Cannot finalize the encrypted file.');
            }
        } catch (\Throwable $throwable) {
            if (is_resource($out)) {
                fclose($out);
            }
            if (is_resource($in)) {
                fclose($in);
            }
            @unlink($temporary);
            throw $throwable;
        }
    }

    /**
     * Decrypts a file. Output is written to a temp file and only moved into place after the FINAL tag authenticated.
     *
     * @param callable(string $keyId): ?EncryptionKey $keyResolver
     */
    public static function decryptFile(string $source, string $destination, callable $keyResolver): void
    {
        self::assertAvailable();
        $in = fopen($source, 'rb');
        if ($in === false) {
            throw new CryptoException('Cannot read the encrypted file.', ['file' => basename($source)]);
        }
        $temporary = $destination . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $out = fopen($temporary, 'wb');
        if ($out === false) {
            fclose($in);
            throw new CryptoException('Cannot write the decrypted file.', ['file' => basename($destination)], true);
        }
        try {
            [$keyId, $header, $kdf] = self::readHeader($in);
            $key = $keyResolver($keyId);
            if ($key === null) {
                throw new CryptoException('No key available for key id "' . $keyId . '".');
            }
            $derived = $key->isPassphrase ? self::deriveFromHeader($key, $kdf) : $key->derive('', 0, 0);
            if ($kdf === null && $key->isPassphrase) {
                throw new CryptoException('The file was encrypted with a raw key but a passphrase was supplied.');
            }
            $streamHeader = substr($header, -SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $derived);
            sodium_memzero($derived);

            $finished = false;
            while (!$finished) {
                $lengthBytes = self::read($in, 4, true);
                if ($lengthBytes === '') {
                    throw new CryptoException('The encrypted file is truncated.');
                }
                $length = (int) (unpack('N', $lengthBytes)[1] ?? 0);
                if ($length < self::ABYTES || $length > self::CHUNK_SIZE + self::ABYTES) {
                    throw new CryptoException('The encrypted file is corrupt (invalid chunk length).');
                }
                $cipher = self::read($in, $length);
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher, $header);
                if ($result === false) {
                    throw new CryptoException('Decryption failed: wrong key or the data was modified.');
                }
                [$plain, $tag] = $result;
                self::write($out, $plain);
                $finished = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            $extra = fread($in, 1);
            if ($extra !== '' && $extra !== false) {
                throw new CryptoException('Unexpected data after the end of the encrypted stream.');
            }
            fflush($out);
            AtomicFile::sync($out);
            fclose($out);
            fclose($in);
            if (!rename($temporary, $destination)) {
                throw new CryptoException('Cannot finalize the decrypted file.');
            }
        } catch (\Throwable $throwable) {
            if (is_resource($out)) {
                fclose($out);
            }
            if (is_resource($in)) {
                fclose($in);
            }
            @unlink($temporary);
            throw $throwable;
        }
    }

    /** Reads the key id from a file header without any key. */
    public static function keyIdOf(string $file): string
    {
        $in = fopen($file, 'rb');
        if ($in === false) {
            throw new CryptoException('Cannot read the encrypted file.');
        }
        try {
            return self::readHeader($in)[0];
        } finally {
            fclose($in);
        }
    }

    /**
     * @param resource $in
     * @return array{string, string, array{string, int, int}|null} key id, full header bytes, kdf params
     */
    private static function readHeader($in): array
    {
        $fixed = self::read($in, 8, true);
        if (strlen($fixed) < 8 || !str_starts_with($fixed, self::MAGIC)) {
            throw new CryptoException('Not an SWBackupIt encrypted file.');
        }
        if (ord($fixed[4]) !== self::VERSION) {
            throw new CryptoException('Unsupported encryption format version.');
        }
        $kdfType = ord($fixed[5]);
        if (!in_array($kdfType, [0, 1], true) || ord($fixed[6]) !== 0) {
            throw new CryptoException('Unsupported encryption parameters.');
        }
        $idLength = ord($fixed[7]);
        $keyId = $idLength > 0 ? self::read($in, $idLength) : '';
        $header = $fixed . $keyId;
        $kdf = null;
        if ($kdfType === 1) {
            $params = self::read($in, SODIUM_CRYPTO_PWHASH_SALTBYTES + 8);
            $header .= $params;
            $salt = substr($params, 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $ops = (int) (unpack('N', substr($params, SODIUM_CRYPTO_PWHASH_SALTBYTES, 4))[1] ?? 0);
            $memKiB = (int) (unpack('N', substr($params, SODIUM_CRYPTO_PWHASH_SALTBYTES + 4, 4))[1] ?? 0);
            // Bound attacker-controlled KDF cost (a malicious file must not exhaust memory/CPU).
            if ($ops < 1 || $ops > 12 || $memKiB < 8192 || $memKiB > 1048576) {
                throw new CryptoException('The key derivation parameters are out of the accepted range.');
            }
            $kdf = [$salt, $ops, $memKiB * 1024];
        }
        $header .= self::read($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

        return [$keyId, $header, $kdf];
    }

    /**
     * @param array{string, int, int}|null $kdf
     */
    private static function deriveFromHeader(EncryptionKey $key, ?array $kdf): string
    {
        if ($kdf === null) {
            throw new CryptoException('The file was encrypted with a raw key but a passphrase was supplied.');
        }

        return $key->derive($kdf[0], $kdf[1], $kdf[2]);
    }

    /**
     * @param resource $stream
     */
    private static function read($stream, int $length, bool $allowEmpty = false): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $part = fread($stream, max(1, $length - strlen($data)));
            if ($part === false || $part === '') {
                break;
            }
            $data .= $part;
        }
        if (strlen($data) !== $length && !($allowEmpty && $data === '')) {
            throw new CryptoException('The encrypted file is truncated.');
        }

        return $data;
    }

    /**
     * @param resource $stream
     */
    private static function write($stream, string $data): void
    {
        if (fwrite($stream, $data) !== strlen($data)) {
            throw new CryptoException('Writing failed (disk full?).', [], true);
        }
    }
}
