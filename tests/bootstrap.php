<?php

/**
 * Smoke-test a checkout or staged release without WordPress, network or database access.
 * Usage: php tests/bootstrap.php [plugin-directory]
 */

declare(strict_types=1);

define('ABSPATH', '/test/wordpress/');

function plugin_dir_path(string $file): string
{
    return dirname($file) . '/';
}

function plugin_dir_url(string $file): string
{
    return 'https://example.test/wp-content/plugins/sw-backupit/';
}

function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['test_actions'][$hook][] = $callback;
}

function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['test_filters'][$hook][] = $callback;
}

function register_activation_hook(string $file, mixed $callback): void
{
    $GLOBALS['test_activation'] = $callback;
}

function register_deactivation_hook(string $file, mixed $callback): void
{
    $GLOBALS['test_deactivation'] = $callback;
}

function is_admin(): bool
{
    return true;
}

function is_multisite(): bool
{
    return false;
}

$root = $argv[1] ?? dirname(__DIR__);
require $root . '/sw-backupit.php';

foreach (['test_activation', 'test_deactivation'] as $callback) {
    if (!is_callable($GLOBALS[$callback] ?? null)) {
        throw new RuntimeException('Lifecycle callback could not be loaded: ' . $callback);
    }
}
foreach (['admin_menu', 'rest_api_init', 'init', 'plugins_loaded'] as $hook) {
    if (!isset($GLOBALS['test_actions'][$hook])) {
        throw new RuntimeException('Integration was not registered: ' . $hook);
    }
}
foreach ([\SWBackupIt\Core\Manifest\Manifest::class, \SWBackupIt\Restore\Cli\RestoreCli::class] as $class) {
    if (!class_exists($class)) {
        throw new RuntimeException('Monorepo class could not be loaded: ' . $class);
    }
}
if (isset($GLOBALS['test_actions']['admin_notices'])) {
    throw new RuntimeException('Plugin reported a bootstrap error.');
}

// Build names dynamically: this same external test checks original and isolated libraries.
$prefix = is_file($root . '/vendor-prefixed/autoload.php') ? 'SWBackupIt\\Dependencies\\' : '';
$cronClass = $prefix . 'Cron\\CronExpression';
$cron = new $cronClass('0 0 * * *');
if ($cron->getNextRunDate('2026-01-01 12:00:00')->format('Y-m-d H:i') !== '2026-01-02 00:00') {
    throw new RuntimeException('Cron expression evaluation failed.');
}

$s3Class = $prefix . 'Aws\\S3\\S3Client';
$s3 = new $s3Class([
    'version' => '2006-03-01',
    'region' => 'us-east-1',
    'credentials' => ['key' => 'test', 'secret' => 'test'],
    'endpoint' => 'https://example.test',
    'use_path_style_endpoint' => true,
]);
// Resolves SDK service models, endpoint rules and signing code, without an HTTP request.
$request = $s3->createPresignedRequest($s3->getCommand('GetObject', ['Bucket' => 'test', 'Key' => 'backup.zip']), '+5 minutes');
if (!str_contains((string) $request->getUri(), 'X-Amz-Signature=')) {
    throw new RuntimeException('AWS resources or signing failed.');
}

$integerClass = $prefix . 'phpseclib3\\Math\\BigInteger';
$integer = new $integerClass(7);
if ($integer->multiply(new $integerClass(6))->toString() !== '42') {
    throw new RuntimeException('phpseclib dynamic engine loading failed.');
}

$sftpClass = $prefix . 'League\\Flysystem\\PhpseclibV3\\SftpConnectionProvider';
new $sftpClass('example.test', 'test', 'test');
$s3AdapterClass = $prefix . 'League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter';
new $s3AdapterClass($s3, 'test');

$temp = sys_get_temp_dir() . '/sw-backupit-smoke-' . bin2hex(random_bytes(8));
$localClass = $prefix . 'League\\Flysystem\\Local\\LocalFilesystemAdapter';
$filesystemClass = $prefix . 'League\\Flysystem\\Filesystem';
try {
    $filesystem = new $filesystemClass(new $localClass($temp));
    $filesystem->write('probe.txt', 'isolated');
    if ($filesystem->read('probe.txt') !== 'isolated') {
        throw new RuntimeException('Flysystem local roundtrip failed.');
    }
} finally {
    if (is_file($temp . '/probe.txt')) {
        unlink($temp . '/probe.txt');
    }
    if (is_dir($temp)) {
        rmdir($temp);
    }
}

$zipClass = $prefix . 'ZipStream\\ZipStream';
$stream = fopen('php://temp', 'w+b');
try {
    $zip = new $zipClass(outputStream: $stream, sendHttpHeaders: false);
    $zip->addFile(fileName: 'probe.txt', data: 'isolated');
    $zip->finish();
    rewind($stream);
    if (fread($stream, 2) !== 'PK') {
        throw new RuntimeException('ZipStream archive creation failed.');
    }
} finally {
    fclose($stream);
}

if ($prefix !== '') {
    foreach (['Aws\\S3\\S3Client', 'League\\Flysystem\\Filesystem', 'Psr\\Log\\LoggerInterface'] as $original) {
        if (class_exists($original, false) || interface_exists($original, false)) {
            throw new RuntimeException('An unprefixed dependency leaked: ' . $original);
        }
    }
}

echo 'PASS: Plugin bootstrap, monorepo mappings, cron, AWS signing, S3/SFTP adapters, phpseclib, local storage and ZIP streaming.' . PHP_EOL;
