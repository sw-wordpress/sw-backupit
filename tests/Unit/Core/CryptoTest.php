<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Crypto\EncryptionKey;
use SWBackupIt\Core\Crypto\StreamCipher;
use SWBackupIt\Core\Exception\CryptoException;
use SWBackupIt\Tests\Support\TempDir;

final class CryptoTest extends TestCase
{
    use TempDir;

    /**
     * @return array<string, array{int}>
     */
    public static function sizes(): array
    {
        return ['empty' => [0], 'tiny' => [1], 'one chunk' => [StreamCipher::CHUNK_SIZE], 'chunk+1' => [StreamCipher::CHUNK_SIZE + 1], 'multi' => [3 * StreamCipher::CHUNK_SIZE + 123]];
    }

    #[DataProvider('sizes')]
    public function testRoundtripWithPassphraseAndRawKey(int $size): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/plain', $size === 0 ? '' : random_bytes($size));
        foreach ([EncryptionKey::fromPassphrase('k1', 'correct horse battery'), EncryptionKey::generateRaw('k2')] as $key) {
            StreamCipher::encryptFile($dir . '/plain', $dir . '/enc', $key, 1, 8 * 1024 * 1024);
            self::assertNotSame(file_get_contents($dir . '/plain'), file_get_contents($dir . '/enc'));
            self::assertSame($key->keyId, StreamCipher::keyIdOf($dir . '/enc'));
            StreamCipher::decryptFile($dir . '/enc', $dir . '/dec', static fn () => $key);
            self::assertFileEquals($dir . '/plain', $dir . '/dec');
            unlink($dir . '/dec');
        }
    }

    public function testWrongKeyFailsAuthenticationAndLeavesNoOutput(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/plain', 'secret data');
        $key = EncryptionKey::fromPassphrase('k', 'right passphrase');
        StreamCipher::encryptFile($dir . '/plain', $dir . '/enc', $key, 1, 8 * 1024 * 1024);
        $wrong = EncryptionKey::fromPassphrase('k', 'wrong passphrase');
        try {
            StreamCipher::decryptFile($dir . '/enc', $dir . '/dec', static fn () => $wrong);
            self::fail('expected failure');
        } catch (CryptoException $exception) {
            self::assertStringContainsString('wrong key', $exception->getMessage());
        }
        self::assertFileDoesNotExist($dir . '/dec');
        self::assertSame([], glob($dir . '/dec*') ?: []);
    }

    public function testTamperingTruncationAndTrailingDataAreDetected(): void
    {
        $dir = $this->makeTempDir();
        $key = EncryptionKey::generateRaw('k');
        file_put_contents($dir . '/plain', random_bytes(2 * StreamCipher::CHUNK_SIZE + 5));
        StreamCipher::encryptFile($dir . '/plain', $dir . '/enc', $key);
        $good = (string) file_get_contents($dir . '/enc');

        $cases = [
            'bit flip' => substr_replace($good, chr(ord($good[200]) ^ 1), 200, 1),
            'truncated at chunk boundary' => substr($good, 0, 60 + 4 + StreamCipher::CHUNK_SIZE + 17),
            'truncated mid chunk' => substr($good, 0, strlen($good) - 10),
            'trailing data' => $good . 'x',
            'header tampered' => substr_replace($good, 'X', 4, 1),
        ];
        foreach ($cases as $name => $bytes) {
            file_put_contents($dir . '/bad', $bytes);
            try {
                StreamCipher::decryptFile($dir . '/bad', $dir . '/out', static fn () => $key);
                self::fail("$name was not detected");
            } catch (CryptoException) {
                self::assertFileDoesNotExist($dir . '/out', $name);
            }
        }
    }

    public function testReorderedChunksAreRejected(): void
    {
        $dir = $this->makeTempDir();
        $key = EncryptionKey::generateRaw('k');
        file_put_contents($dir . '/plain', random_bytes(3 * StreamCipher::CHUNK_SIZE));
        StreamCipher::encryptFile($dir . '/plain', $dir . '/enc', $key);
        $data = (string) file_get_contents($dir . '/enc');
        $headerLength = 8 + 1 + 24; // magic..keyId("k") + stream header
        $chunkLength = 4 + StreamCipher::CHUNK_SIZE + 17;
        $c1 = substr($data, $headerLength, $chunkLength);
        $c2 = substr($data, $headerLength + $chunkLength, $chunkLength);
        $swapped = substr($data, 0, $headerLength) . $c2 . $c1 . substr($data, $headerLength + 2 * $chunkLength);
        file_put_contents($dir . '/swapped', $swapped);
        $this->expectException(CryptoException::class);
        StreamCipher::decryptFile($dir . '/swapped', $dir . '/out', static fn () => $key);
    }

    public function testMaliciousKdfParametersAreBounded(): void
    {
        $dir = $this->makeTempDir();
        $key = EncryptionKey::fromPassphrase('k', 'passphrase123');
        file_put_contents($dir . '/plain', 'x');
        StreamCipher::encryptFile($dir . '/plain', $dir . '/enc', $key, 1, 8 * 1024 * 1024);
        $data = (string) file_get_contents($dir . '/enc');
        // memLimit field -> 0xFFFFFFFF KiB would allocate terabytes if trusted.
        $offset = 8 + 1 + 16 + 4;
        $data = substr_replace($data, "\xff\xff\xff\xff", $offset, 4);
        file_put_contents($dir . '/evil', $data);
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('out of the accepted range');
        StreamCipher::decryptFile($dir . '/evil', $dir . '/out', static fn () => $key);
    }

    public function testKeysNeverLeakThroughDebugOrSerialization(): void
    {
        $key = EncryptionKey::fromPassphrase('k', 'super secret passphrase');
        self::assertStringNotContainsString('super secret', print_r($key, true));
        self::assertStringNotContainsString('super secret', (string) json_encode($key));
        ob_start();
        var_dump($key);
        self::assertStringNotContainsString('super secret', (string) ob_get_clean());
        $this->expectException(CryptoException::class);
        serialize($key);
    }

    public function testKeyParsing(): void
    {
        $raw = EncryptionKey::generateRaw('a');
        $again = EncryptionKey::parse('a', $raw->export());
        self::assertFalse($again->isPassphrase);
        self::assertSame($raw->export(), $again->export());
        $this->expectException(CryptoException::class);
        EncryptionKey::parse('a', 'short');
    }
}
