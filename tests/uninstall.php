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

function apply_filters(string $hook, mixed $value): mixed
{
    return $value;
}

function remove_filter(string $hook, mixed $callback): bool
{
    return true;
}

function get_site_option(string $name, mixed $default = false): mixed
{
    return $name === 'auto_update_plugins' ? ['sw-backupit/sw-backupit.php', 'other/other.php'] : $default;
}

function update_site_option(string $name, mixed $value): bool
{
    $GLOBALS['uninstall_site_options'][$name] = $value;
    return true;
}

function delete_site_option(string $name): bool
{
    $GLOBALS['uninstall_deleted'][] = $name;
    return true;
}

function get_site_transient(string $name): mixed
{
    return false;
}

function delete_option(string $name): bool
{
    $GLOBALS['uninstall_deleted'][] = $name;
    return true;
}

$root = $argv[1] ?? dirname(__DIR__);
require $root . '/uninstall.php';
if (count($GLOBALS['uninstall_hooks'] ?? []) !== 4) {
    throw new RuntimeException('Uninstall did not clear all scheduled hooks.');
}
if (($GLOBALS['uninstall_site_options']['auto_update_plugins'] ?? null) !== ['other/other.php']) {
    throw new RuntimeException('Uninstall did not remove only its own automatic-update selection.');
}
foreach (['swbi_verified_github_updates_v1', 'swbi_verified_github_updates_lock_v1', 'swbi_verified_updates_migrated'] as $option) {
    if (!in_array($option, $GLOBALS['uninstall_deleted'] ?? [], true)) {
        throw new RuntimeException('Uninstall did not delete ' . $option);
    }
}
echo "PASS: Uninstall loads independently and clears scheduled hooks.\n";
