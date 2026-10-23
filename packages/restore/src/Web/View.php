<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Web;

/** HTML rendering for the restore wizard. Every dynamic value goes through e(); no inline scripts or styles (strict CSP). */
final class View
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed>|null $session
     */
    public static function page(string $title, string $body, ?array $session, string $bodyAttributes = ''): string
    {
        $csrf = $session === null ? '' : ' data-csrf="' . self::e($session['csrf'] ?? '') . '"';
        $logout = $session === null ? '' : '<form method="post" action="?a=logout" class="logout"><input type="hidden" name="csrf" value="' . self::e($session['csrf'] ?? '') . '"><button class="link">Sign out</button></form>';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">'
            . '<title>' . self::e($title) . ' – SWBackupIt Restore</title><link rel="stylesheet" href="?a=asset&amp;f=css"></head>'
            . '<body' . $csrf . $bodyAttributes . '><header><div class="brand"><span class="logo" aria-hidden="true"></span><strong>SWBackupIt</strong> Restore</div>' . $logout . '</header>'
            . '<main><h1>' . self::e($title) . '</h1>' . $body . '</main><script src="?a=asset&amp;f=js" defer></script></body></html>';
    }

    public static function login(string $message = ''): string
    {
        $note = $message === '' ? '' : '<p class="error" role="alert">' . self::e($message) . '</p>';

        return self::page('Sign in', $note . '<p>Enter the one-time restore token that was displayed when the restore was prepared.</p>'
            . '<form method="post" action="?a=login" autocomplete="off"><label>Restore token<input type="password" name="token" required autofocus autocomplete="off"></label>'
            . '<button class="primary">Sign in</button></form>', null);
    }

    /**
     * @param list<array<string, mixed>> $backups
     * @param array<string, mixed> $session
     */
    public static function dashboard(array $backups, array $session): string
    {
        if ($backups === []) {
            return self::page('Choose a backup', '<p>No backups were found in the configured backups directory.</p>', $session);
        }
        $rows = '';
        foreach ($backups as $backup) {
            $rows .= '<tr><td><a href="?a=backup&amp;id=' . self::e($backup['id']) . '">' . self::e(substr((string) $backup['id'], 0, 8)) . '</a></td><td>' . self::e($backup['created_at'])
                . '</td><td>' . self::e($backup['type']) . ($backup['encrypted'] ? ' 🔒' : '') . '</td><td>' . self::e($backup['files']) . '</td><td>' . self::e($backup['size']) . '</td><td>' . self::e($backup['site']) . '</td></tr>';
        }

        return self::page('Choose a backup', '<table><thead><tr><th>Backup</th><th>Created (UTC)</th><th>Type</th><th>Files</th><th>Size</th><th>Source site</th></tr></thead><tbody>' . $rows . '</tbody></table>', $session);
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $session
     */
    public static function backup(array $manifest, array $session, string $suggestedTarget, string $suggestedDb): string
    {
        $source = is_array($manifest['source'] ?? null) ? $manifest['source'] : [];
        $hasDb = ($manifest['database'] ?? null) !== null;
        $hasFiles = ($manifest['files'] ?? null) !== null;
        $csrf = self::e($session['csrf'] ?? '');
        $encrypted = ($manifest['encryption'] ?? null) !== null;
        $html = '<dl><dt>Backup</dt><dd>' . self::e($manifest['backup_id']) . '</dd><dt>Created</dt><dd>' . self::e($manifest['created_at']) . '</dd><dt>Type</dt><dd>' . self::e($manifest['type'])
            . '</dd><dt>Source site</dt><dd>' . self::e($source['site_url'] ?? '') . '</dd><dt>Source path</dt><dd>' . self::e($source['root_path'] ?? '') . '</dd></dl>'
            . '<form method="post" action="?a=preflight"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="backup_id" value="' . self::e($manifest['backup_id']) . '">';
        if ($encrypted) {
            $html .= '<fieldset><legend>Encryption</legend><label>Passphrase or key (base64:…)<input type="password" name="key" autocomplete="off" required></label></fieldset>';
        }
        if ($hasFiles) {
            $html .= '<fieldset><legend>Files</legend><label class="check"><input type="checkbox" name="restore_files" value="1" checked> Restore files</label>'
                . '<label>Target directory (absolute path of the WordPress installation)<input name="target" value="' . self::e($suggestedTarget) . '" required></label>'
                . '<label>Type the target directory again to confirm<input name="confirm_target" required></label>'
                . '<label>Existing files<select name="overwrite"><option value="if_different">Overwrite only if different</option><option value="always">Always overwrite</option><option value="never">Never overwrite</option></select></label>'
                . '<label>Only these paths (optional, one per line)<textarea name="paths" rows="3" placeholder="wp-content/uploads"></textarea></label></fieldset>';
        } else {
            $html .= '<input type="hidden" name="target" value="' . self::e($suggestedTarget) . '">';
        }
        if ($hasDb) {
            $html .= '<fieldset><legend>Database</legend><label class="check"><input type="checkbox" name="restore_database" value="1" checked> Restore the database (existing tables with the same names are dropped)</label>'
                . '<label>Host<input name="db_host" value="localhost"></label><label>Port<input name="db_port" value="3306"></label>'
                . '<label>Database name<input name="db_name" value="' . self::e($suggestedDb) . '"></label><label>Type the database name again to confirm<input name="confirm_db"></label>'
                . '<label>User<input name="db_user"></label><label>Password<input type="password" name="db_password" autocomplete="off"></label></fieldset>'
                . '<fieldset><legend>Migration (optional)</legend><label>Old URL<input name="old_url" value="' . self::e($source['site_url'] ?? '') . '"></label><label>New URL<input name="new_url" placeholder="https://new-domain.example/wordpress"></label>'
                . '<label>Old path<input name="old_path" value="' . self::e($source['root_path'] ?? '') . '"></label><label>New path<input name="new_path"></label>'
                . '<label>New table prefix<input name="new_prefix" placeholder="' . self::e($source['table_prefix'] ?? 'wp_') . '"></label></fieldset>';
        }

        return self::page('Restore settings', $html . '<button class="primary">Check backup &amp; compare</button></form>', $session);
    }

    /**
     * @param array<string, mixed> $preflight
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $session
     */
    public static function preflight(array $preflight, array $plan, array $session): string
    {
        $csrf = self::e($session['csrf'] ?? '');
        if (!$preflight['ok']) {
            $errors = '';
            foreach ((array) $preflight['errors'] as $error) {
                $errors .= '<li>' . self::e($error) . '</li>';
            }

            return self::page('Backup check failed', '<p class="error">The backup did not pass verification. Nothing was changed.</p><ul>' . $errors . '</ul><p><a href="?">Back</a></p>', $session);
        }
        $html = '<p class="ok">The backup passed verification.</p><dl><dt>Target</dt><dd>' . self::e($plan['target']) . '</dd><dt>Database</dt><dd>' . ($plan['restore_database'] ? self::e($plan['db']['name']) : 'not restored') . '</dd></dl>';
        if (is_array($preflight['comparison'])) {
            $c = $preflight['comparison'];
            $html .= '<h2>Comparison with the target directory</h2><table><tbody><tr><th>Only in backup</th><td>' . (int) $c['only_in_backup'] . '</td></tr><tr><th>Modified locally</th><td>' . (int) $c['modified']
                . '</td></tr><tr><th>Only local</th><td>' . (int) $c['only_local'] . '</td></tr><tr><th>Identical</th><td>' . (int) $c['identical'] . '</td></tr><tr><th>Not comparable</th><td>' . (int) $c['incomparable'] . '</td></tr></tbody></table>';
        }
        $html .= '<form method="post" action="?a=start"><input type="hidden" name="csrf" value="' . $csrf . '"><button class="danger">Start restore</button></form><p><a href="?">Cancel</a></p>';

        return self::page('Ready to restore', $html, $session);
    }

    /**
     * @param array<string, mixed> $session
     */
    public static function run(array $session): string
    {
        $body = '<p id="status" role="status" aria-live="polite">Starting…</p><progress id="bar" max="100"></progress><pre id="log" aria-live="off"></pre>'
            . '<form id="finish" method="post" action="?a=finish" hidden><input type="hidden" name="csrf" value="' . self::e($session['csrf'] ?? '') . '"><button class="primary">Lock and remove the restore application</button></form>';

        return self::page('Restoring…', $body, $session, ' data-run="1"');
    }
}
