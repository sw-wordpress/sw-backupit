<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Integration;

use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Exception\TransientException;
use SWBackupIt\Core\Retry\RetryPolicy;
use SWBackupIt\Core\Storage\EndpointPolicy;
use SWBackupIt\Core\Storage\RemoteStorageInterface;
use SWBackupIt\Core\Storage\StorageFactory;
use SWBackupIt\Core\Storage\StorageTarget;
use SWBackupIt\Tests\Support\TempDir;

final class StorageTest extends TestCase
{
    use TempDir;

    /**
     * @return list<array{string, callable(): RemoteStorageInterface}>
     */
    private function storages(): array
    {
        $factory = new StorageFactory(new RetryPolicy(2, 1, 2));
        $local = $this->makeTempDir('remote');
        $list = [['local', fn () => $factory->create(new StorageTarget(1, 'L', 'local', ['path' => $local]))]];

        $port = getenv('SWBI_TEST_S3_PORT') ?: '33099';
        $endpoint = "http://127.0.0.1:{$port}";
        if (@fsockopen('127.0.0.1', (int) $port, $errno, $error, 0.3) !== false) {
            $bucket = 'swbi-test-' . bin2hex(random_bytes(3));
            $client = new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $endpoint, 'use_path_style_endpoint' => true, 'credentials' => ['key' => 'k', 'secret' => 's']]);
            $client->createBucket(['Bucket' => $bucket]);
            $list[] = ['s3', fn () => $factory->create(new StorageTarget(2, 'S3', 's3', [
                'endpoint' => $endpoint, 'bucket' => $bucket, 'prefix' => 'backups', 'path_style' => true, 'allow_private_network' => true,
            ], ['access_key' => 'k', 'secret_key' => 's']))];
        }

        return $list;
    }

    public function testStorageContractForAllAvailableBackends(): void
    {
        $dir = $this->makeTempDir('src');
        file_put_contents($dir . '/a.bin', random_bytes(300000));
        $hash = (string) hash_file('sha256', $dir . '/a.bin');
        foreach ($this->storages() as [$name, $make]) {
            $storage = $make();
            $test = $storage->test();
            self::assertTrue($test->ok, "$name: " . $test->message);

            $progress = 0;
            $sent = $storage->upload($dir . '/a.bin', 'bk1/volumes/backup-001.zip', function (int $bytes) use (&$progress): void {
                $progress += $bytes;
            });
            self::assertSame($hash, $sent, "$name: hash computed while sending");
            self::assertSame(300000, $progress, "$name: progress callback");
            self::assertTrue($storage->exists('bk1/volumes/backup-001.zip'));
            self::assertSame(300000, $storage->size('bk1/volumes/backup-001.zip'));
            self::assertTrue($storage->verify('bk1/volumes/backup-001.zip', 300000, $hash), $name);
            self::assertFalse($storage->verify('bk1/volumes/backup-001.zip', 300000, str_repeat('0', 64)), "$name: wrong hash");
            self::assertFalse($storage->verify('bk1/volumes/backup-001.zip', 1, $hash), "$name: wrong size");
            self::assertFalse($storage->verify('bk1/missing.zip', 1, $hash), "$name: missing object");
            self::assertSame([], array_filter($storage->listFiles('bk1/volumes'), static fn (string $f): bool => str_contains($f, '.swbi-part')), "$name: no temp objects left");

            // Overwrite is safe and idempotent (retry of an upload).
            $storage->upload($dir . '/a.bin', 'bk1/volumes/backup-001.zip');
            self::assertSame(['bk1'], $storage->listDirectories(''));
            self::assertSame(['backup-001.zip'], $storage->listFiles('bk1/volumes'));

            $storage->download('bk1/volumes/backup-001.zip', $dir . '/down.bin');
            self::assertFileEquals($dir . '/a.bin', $dir . '/down.bin');
            unlink($dir . '/down.bin');

            $storage->deleteDirectory('bk1');
            self::assertFalse($storage->exists('bk1/volumes/backup-001.zip'));
        }
    }

    public function testS3WithWrongCredentialsFailsWithoutLeakingSecrets(): void
    {
        $port = getenv('SWBI_TEST_S3_PORT') ?: '33099';
        if (@fsockopen('127.0.0.1', (int) $port, $errno, $error, 0.3) === false) {
            self::markTestSkipped('No S3 mock available (bin/test-s3.sh start).');
        }
        $storage = (new StorageFactory(new RetryPolicy(1)))->create(new StorageTarget(3, 'S3', 's3', [
            'endpoint' => "http://127.0.0.1:{$port}", 'bucket' => 'does-not-exist-bucket', 'path_style' => true, 'allow_private_network' => true,
        ], ['access_key' => 'AKIASECRETACCESSKEY', 'secret_key' => 'SECRETSECRETSECRET']));
        $result = $storage->test();
        self::assertFalse($result->ok);
        self::assertStringNotContainsString('SECRETSECRET', $result->message);
        self::assertStringNotContainsString('AKIASECRET', $result->message);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blockedEndpoints(): array
    {
        return [
            'loopback' => ['https://127.0.0.1/'], 'metadata' => ['https://169.254.169.254/latest'], 'private 10' => ['https://10.0.0.5:9000'],
            'private 192' => ['https://192.168.1.1'], 'ipv6 loopback' => ['https://[::1]/'], 'userinfo' => ['https://user:pass@example.com/'],
            'file scheme' => ['file:///etc/passwd'], 'plain http' => ['http://example.com/'], 'garbage' => ['not a url'],
        ];
    }

    /**
     * @dataProvider blockedEndpoints
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('blockedEndpoints')]
    public function testSsrfPolicyBlocksDangerousEndpoints(string $url): void
    {
        $this->expectException(ConfigurationException::class);
        EndpointPolicy::assertAllowedUrl($url, false);
    }

    public function testPrivateNetworkOptInAndLocalFactoryValidation(): void
    {
        EndpointPolicy::assertAllowedUrl('http://127.0.0.1:9000', true);
        $this->expectException(ConfigurationException::class);
        (new StorageFactory())->create(new StorageTarget(1, 'bad', 'local', ['path' => '/tmp/../etc']));
    }

    public function testSftpRequiresHostKeyAndSafeRoot(): void
    {
        $factory = new StorageFactory();
        foreach (
            [
            ['host' => 'example.com', 'username' => 'u', 'root' => '/data'],
            ['host' => '169.254.169.254', 'username' => 'u', 'root' => '/data', 'host_fingerprint' => 'aa'],
            ['host' => 'example.com', 'username' => 'u', 'root' => '/data/../etc', 'host_fingerprint' => 'aa'],
            ] as $config
        ) {
            try {
                $factory->create(new StorageTarget(1, 's', 'sftp', $config, ['password' => 'x']));
                self::fail('expected ConfigurationException for ' . json_encode($config));
            } catch (ConfigurationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRetryPolicyBacksOffOnTransientErrorsOnly(): void
    {
        $delays = [];
        $policy = new RetryPolicy(4, 100, 1000, static function (int $ms) use (&$delays): void {
            $delays[] = $ms;
        });
        $calls = 0;
        $value = $policy->run(function () use (&$calls): string {
            if (++$calls < 3) {
                throw new TransientException('flaky');
            }

            return 'ok';
        }, static fn (): bool => false);
        self::assertSame('ok', $value);
        self::assertCount(2, $delays);
        self::assertGreaterThanOrEqual(100, $delays[0]);
        self::assertGreaterThanOrEqual(200, $delays[1]);

        $calls = 0;
        try {
            $policy->run(function () use (&$calls): void {
                $calls++;
                throw new \LogicException('permanent');
            }, static fn (): bool => false);
        } catch (\LogicException) {
            self::assertSame(1, $calls, 'non transient errors are not retried');
        }
    }
}
