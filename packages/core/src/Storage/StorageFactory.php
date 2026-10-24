<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Storage;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Retry\RetryPolicy;
use SWBackupIt\Core\Support\PathGuard;

/** Builds storage implementations for configured targets and validates their (untrusted) configuration. */
final class StorageFactory
{
    public function __construct(private readonly RetryPolicy $retry = new RetryPolicy())
    {
    }

    public function create(StorageTarget $target): RemoteStorageInterface
    {
        return match ($target->type) {
            'local' => $this->local($target),
            'sftp' => $this->sftp($target),
            's3' => $this->s3($target),
            default => throw new ConfigurationException('Unsupported storage type.'),
        };
    }

    private function local(StorageTarget $target): RemoteStorageInterface
    {
        $path = (string) ($target->config['path'] ?? '');
        if ($path === '' || $path[0] !== '/' || str_contains($path, '..') || str_contains($path, "\0")) {
            throw new ConfigurationException('A local storage target needs an absolute path without "..".');
        }
        if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
            throw new ConfigurationException('The local storage directory cannot be created.');
        }
        $adapter = new LocalFilesystemAdapter($path, PortableVisibilityConverter::fromArray(['file' => ['public' => 0640, 'private' => 0600], 'dir' => ['public' => 0750, 'private' => 0700]]));

        return new FlysystemRemoteStorage(new Filesystem($adapter), $this->retry, 'local storage');
    }

    private function sftp(StorageTarget $target): RemoteStorageInterface
    {
        $config = $target->config;
        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 22);
        $allowPrivate = (bool) ($config['allow_private_network'] ?? false);
        EndpointPolicy::assertAllowedHost($host, $allowPrivate, $port);
        $root = (string) ($config['root'] ?? '/');
        if ($root === '' || $root[0] !== '/' || str_contains($root, '..')) {
            throw new ConfigurationException('The SFTP root must be an absolute path without "..".');
        }
        $fingerprint = (string) ($config['host_fingerprint'] ?? '');
        if ($fingerprint === '' && !(bool) ($config['trust_unknown_host'] ?? false)) {
            throw new ConfigurationException('Set the SFTP host key fingerprint (or explicitly accept the risk of an unverified host).');
        }
        $credentials = $target->credentials;
        $provider = new SftpConnectionProvider(
            $host,
            (string) ($config['username'] ?? ''),
            $credentials['password'] ?? null,
            $credentials['private_key'] ?? null,
            $credentials['passphrase'] ?? null,
            $port,
            false,
            (int) ($config['timeout'] ?? 20),
            2,
            $fingerprint === '' ? null : $fingerprint,
        );

        return new FlysystemRemoteStorage(new Filesystem(new SftpAdapter($provider, rtrim($root, '/') ?: '/')), $this->retry, 'SFTP storage');
    }

    private function s3(StorageTarget $target): RemoteStorageInterface
    {
        $config = $target->config;
        $allowPrivate = (bool) ($config['allow_private_network'] ?? false);
        $endpoint = (string) ($config['endpoint'] ?? '');
        if ($endpoint !== '') {
            EndpointPolicy::assertAllowedUrl($endpoint, $allowPrivate);
        }
        $bucket = (string) ($config['bucket'] ?? '');
        if (preg_match('/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/', $bucket) !== 1) {
            throw new ConfigurationException('The bucket name is invalid.');
        }
        $prefix = trim((string) ($config['prefix'] ?? ''), '/');
        if ($prefix !== '') {
            PathGuard::assertSafeRelative($prefix);
        }
        $credentials = $target->credentials;
        $options = [
            'version' => 'latest',
            'region' => (string) ($config['region'] ?? 'us-east-1'),
            'credentials' => ['key' => (string) ($credentials['access_key'] ?? ''), 'secret' => (string) ($credentials['secret_key'] ?? '')],
            'use_path_style_endpoint' => (bool) ($config['path_style'] ?? false),
            'http' => ['allow_redirects' => false, 'verify' => true, 'connect_timeout' => 10, 'timeout' => 300],
            'retries' => 0,
        ];
        if ($endpoint !== '') {
            $options['endpoint'] = $endpoint;
        }
        $lock = null;
        if (is_array($config['object_lock'] ?? null)) {
            $mode = strtoupper((string) ($config['object_lock']['mode'] ?? ''));
            $days = (int) ($config['object_lock']['days'] ?? 0);
            if (!in_array($mode, ['GOVERNANCE', 'COMPLIANCE'], true) || $days < 1 || $days > 3650) {
                throw new ConfigurationException('Object lock needs mode GOVERNANCE or COMPLIANCE and 1-3650 retention days.');
            }
            $lock = ['mode' => $mode, 'days' => $days];
        }
        $client = new S3Client($options);
        $filesystem = new Filesystem(new AwsS3V3Adapter($client, $bucket, $prefix));

        return new S3RemoteStorage($filesystem, $client, $bucket, $prefix, $this->retry, $lock);
    }
}
