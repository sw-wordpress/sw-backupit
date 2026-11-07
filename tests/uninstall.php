<?php

/** Smoke-test uninstall independently of plugin activation, including packaged releases. */

declare(strict_types=1);

define('ABSPATH', '/swbi-uninstall-test/wordpress/');
define('WP_UNINSTALL_PLUGIN', true);

function is_multisite(): bool
{
    return false;
}

function untrailingslashit(string $path): string
{
    return rtrim($path, '/');
}

function get_option(string $name, mixed $default = false): mixed
{
    return $name === 'swbi_settings' ? ['staging_dir' => '/swbi-uninstall-test/private'] : $default;
}

function sanitize_text_field(string $value): string
{
    return $value;
}

function wp_clear_scheduled_hook(string $hook): void
{
    $GLOBALS['uninstall_hooks'][] = $hook;
}

$root = $argv[1] ?? dirname(__DIR__);
require $root . '/uninstall.php';
if (count($GLOBALS['uninstall_hooks'] ?? []) !== 4) {
    throw new RuntimeException('Uninstall did not clear all scheduled hooks.');
}
echo "PASS: Uninstall loads independently and clears scheduled hooks.\n";
