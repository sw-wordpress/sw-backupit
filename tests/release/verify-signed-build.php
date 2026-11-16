<?php

/**
 * Signs the REAL build ZIP(s) with a disposable key and verifies them with the runtime verifier.
 * Also checks package completeness and that signing aborts without the signing secret.
 *
 * Usage: php tests/release/verify-signed-build.php <directory-with-zips>
 * No WordPress, no network, no production key.
 */

declare(strict_types=1);

define('ABSPATH', '/swbi-release-check/');
define('DAY_IN_SECONDS', 86400);

// phpcs:disable
class WP_Error
{
    public function __construct(public string $code = '', public string $message = '', public mixed $data = null)
    {
    }
    public function get_error_code(): string
    {
        return $this->code;
    }
    public function get_error_message(): string
    {
        return $this->message;
    }
}
// phpcs:enable
// phpcs:enable
function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}
function __(string $text, string $domain = 'default'): string
{
    return $text;
}
function wp_parse_url(string $url): array|false
{
    return parse_url($url);
}

$root = dirname(__DIR__, 2);
foreach (array( 'Manifest', 'Package' ) as $class) {
    require_once $root . '/packages/wordpress/src/Update/' . $class . '.php';
}

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

$dir = realpath($argv[1] ?? '');
if (false === $dir) {
    fail('ZIP directory missing.');
}
$zips = glob($dir . '/sw-backupit-*.zip') ?: array();
if (1 !== count($zips)) {
    fail('Expected exactly one sw-backupit ZIP.');
}
$zip_file = $zips[0];

// Completeness of the installable package (the update must ship its own verifier and keyring).
$zip = new ZipArchive();
$zip->open($zip_file);
$names = array();
for ($i = 0; $i < $zip->count(); $i++) {
    $names[] = (string) $zip->getNameIndex($i);
}
$required = array(
    'sw-backupit/sw-backupit.php',
    'sw-backupit/uninstall.php',
    'sw-backupit/vendor-prefixed/autoload.php',
    'sw-backupit/assets/admin/app.js',
    'sw-backupit/assets/js/swbackupit-updates.js',
    'sw-backupit/restore-app/vendor/autoload.php',
    'sw-backupit/languages/sw-backupit-de_DE.mo',
);
foreach (array( 'Manifest', 'Package', 'Provider', 'Service', 'Store', 'Transport' ) as $class) {
    $required[] = "sw-backupit/packages/wordpress/src/Update/$class.php";
}
foreach (array( 'source', 'descriptors', 'trusted-keys' ) as $config) {
    $required[] = "sw-backupit/packages/wordpress/src/Update/Config/$config.php";
}
foreach ($required as $path) {
    if (! in_array($path, $names, true)) {
        fail("Package incomplete, missing $path");
    }
}
foreach ($names as $name) {
    if (preg_match('#^sw-backupit/(vendor|tests|apps|bin|node_modules|\.git|\.github)/#', $name) || preg_match('#signing-key|\.base64$|\.pem$|secret[-_]key#i', $name)) {
        fail("Unexpected development or key file in package: $name");
    }
}
$main = (string) $zip->getFromName('sw-backupit/sw-backupit.php');
$zip->close();
if (! preg_match('/^ \* Update URI:\s+https:\/\/github\.com\/sw-wordpress\/sw-backupit$/m', $main)) {
    fail('Update URI header missing or wrong.');
}
$ring = require $root . '/packages/wordpress/src/Update/Config/trusted-keys.php';
echo 'Shipped key ring entries: ' . count($ring) . "\n";

// Disposable key, work on a copy so the build output is untouched.
$work = sys_get_temp_dir() . '/swbi-release-check-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
$copy = $work . '/' . basename($zip_file);
copy($zip_file, $copy);
$pair   = sodium_crypto_sign_keypair();
$secret = base64_encode(sodium_crypto_sign_secretkey($pair));
$public = array( 'throwaway' => base64_encode(sodium_crypto_sign_publickey($pair)) );
$release = array(
    'id'         => 7,
    'tag_name'   => 'v' . preg_replace('/^sw-backupit-(.+)\.zip$/', '$1', basename($zip_file)),
    'draft'      => false,
    'prerelease' => false,
    'assets'     => array( array( 'id' => 11, 'name' => basename($zip_file), 'size' => filesize($copy) ) ),
);
file_put_contents($work . '/release.json', json_encode($release));

$sign = static function (array $env) use ($root, $work): array {
    $p = proc_open(array( PHP_BINARY, $root . '/bin/release-manifest.php', 'sign', $work . '/release.json', $work, str_repeat('b', 40) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return array( proc_close($p), $out );
};
[ $exit ] = $sign(array());
if (0 === $exit || file_exists($work . '/sw-plugin-release.json')) {
    fail('Signing must abort without the signing secret.');
}
[ $exit, $out ] = $sign(array( 'SW_PLUGIN_RELEASE_SIGNING_KEY' => $secret, 'SW_PLUGIN_RELEASE_KEY_ID' => 'throwaway' ));
if (0 !== $exit || str_contains($out, $secret)) {
    fail('Signing failed or leaked the key: ' . $out);
}
$manifest = \SWBackupIt\WordPress\Update\Manifest::verify((string) file_get_contents($work . '/sw-plugin-release.json'), (string) file_get_contents($work . '/sw-plugin-release.json.sig'), $public, $release);
if (is_wp_error($manifest)) {
    fail('Runtime verifier rejected the signed manifest: ' . json_encode($manifest->data));
}
$package = \SWBackupIt\WordPress\Update\Manifest::package($manifest, 'sw-backupit');
if (null === $package) {
    fail('Package entry missing.');
}
$result = \SWBackupIt\WordPress\Update\Package::verify($copy, $package);
if (is_wp_error($result)) {
    fail('Runtime package check rejected the real build ZIP: ' . json_encode($result->data));
}
// The same signature must fail with a different key.
$wrong = array( 'throwaway' => base64_encode(random_bytes(32)) );
if (! is_wp_error(\SWBackupIt\WordPress\Update\Manifest::verify((string) file_get_contents($work . '/sw-plugin-release.json'), (string) file_get_contents($work . '/sw-plugin-release.json.sig'), $wrong, $release))) {
    fail('Signature accepted with a foreign key.');
}
foreach (glob($work . '/*') ?: array() as $file) {
    unlink($file);
}
rmdir($work);
echo 'PASS: real build ZIP ' . basename($zip_file) . ' is complete, signed with a disposable key and accepted by the runtime verifier.' . "\n";
