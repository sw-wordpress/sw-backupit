<?php

declare(strict_types=1);

/**
 * Build-only workaround: Mozart 1.2.3 moves packages that have a PSR-4 map but does not register their Composer
 * "autoload.files" (global helper functions) in the generated autoloader, so e.g. Aws\manifest() was undefined at runtime.
 * Registers exactly the helper files the shipped dependencies need and fails when one is missing.
 *
 * Second workaround: class names assembled at runtime from string literals ('phpseclib3\Math\...' for BigInteger engines,
 * EC curves, hashes; "Aws\{$service}\..." for service clients and exceptions) are not prefixed by Mozart. They are
 * rewritten here; a library that no longer contains such a literal fails the build so the list is reviewed.
 *
 * Usage: php bin/mozart-fixups.php <plugin-staging-dir>   (remove when upstream handles both cases)
 */

$root = rtrim($argv[1] ?? '', '/');
$prefixed = $root . '/vendor-prefixed';
$files = [
    'Aws/functions.php',
    'JmesPath/JmesPath.php',
];
$static = $prefixed . '/composer/autoload_static.php';
$list = $prefixed . '/composer/autoload_files.php';
foreach ([$static, $list] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Mozart autoloader missing: $file\n");
        exit(1);
    }
}
$staticCode = (string) file_get_contents($static);
$listCode = (string) file_get_contents($list);
$staticEntries = '';
$listEntries = '';
foreach ($files as $relative) {
    if (!is_file($prefixed . '/' . $relative)) {
        fwrite(STDERR, "Helper file missing after Mozart: $relative\n");
        exit(1);
    }
    $id = md5('swbackupit:' . $relative);
    $staticEntries .= "        '$id' => __DIR__ . '/../$relative',\n";
    $listEntries .= "    '$id' => \$vendorDir . '/$relative',\n";
}
$staticCode = preg_replace('/(public static \$files = array \(\n)/', '$1' . $staticEntries, $staticCode, 1, $count1);
$listCode = preg_replace('/(return array\(\n)/', '$1' . $listEntries, $listCode, 1, $count2);
if (1 !== $count1 || 1 !== $count2) {
    fwrite(STDERR, "Unexpected Mozart autoloader format.\n");
    exit(1);
}
file_put_contents($static, $staticCode);
file_put_contents($list, $listCode);
echo 'registered ' . count($files) . " helper files\n";

// Case-colliding leftovers: Mozart keeps the original lower-case vendor directories next to its prefixed ones
// (Aws/ vs aws/, Symfony/ vs symfony/). Such a package breaks on case-insensitive file systems and is rejected by the
// update verifier. aws/ only holds the optional aws-crt-php wrapper (needs the awscrt extension, never used here);
// the one global helper of symfony/ moves into the prefixed Symfony/ tree.
$crt = $prefixed . '/aws';
if (is_dir($crt)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($crt, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($crt);
}
$legacy = $prefixed . '/symfony/deprecation-contracts/function.php';
if (!is_file($legacy)) {
    fwrite(STDERR, "symfony/deprecation-contracts helper missing.\n");
    exit(1);
}
$target = $prefixed . '/Symfony/DeprecationContracts/function.php';
mkdir(dirname($target), 0777, true);
rename($legacy, $target);
foreach ([$static, $list] as $file) {
    file_put_contents($file, str_replace('/symfony/deprecation-contracts/function.php', '/Symfony/DeprecationContracts/function.php', (string) file_get_contents($file)));
}
$legacyRoot = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($prefixed . '/symfony', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($legacyRoot as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($prefixed . '/symfony');
$seen = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($prefixed, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
    $key = strtolower($entry->getPathname());
    if (isset($seen[$key])) {
        fwrite(STDERR, 'Case-colliding paths remain: ' . $entry->getPathname() . "\n");
        exit(1);
    }
    $seen[$key] = true;
}

$rewrites = [
    // library directory => [pattern, replacement]
    'phpseclib3' => ['/([\'"])(\\\\?)phpseclib3\\\\/', '$1$2SWBackupIt\\\\Dependencies\\\\phpseclib3\\\\'],
    'Aws' => ['/"Aws\\\\\\\\\\{/', '"SWBackupIt\\\\\\\\Dependencies\\\\\\\\Aws\\\\\\\\{'],
];
foreach ($rewrites as $directory => [$pattern, $replacement]) {
    $changed = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($prefixed . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ('php' !== $file->getExtension()) {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        $new = preg_replace($pattern, $replacement, $code, -1, $count);
        if ($count > 0 && null !== $new) {
            file_put_contents($file->getPathname(), $new);
            $changed += $count;
        }
    }
    if (0 === $changed) {
        fwrite(STDERR, "No dynamic class-name literals found in $directory; review bin/mozart-fixups.php.\n");
        exit(1);
    }
    echo "rewrote $changed dynamic class-name literals in $directory\n";
}
