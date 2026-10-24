<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Web;

/**
 * HTML rendering for the restore wizard. Every dynamic value goes through e(); no inline scripts or styles (strict CSP).
 * All user-facing text goes through t() (see Translator).
 */
final class View
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function t(string $message, string|int ...$arguments): string
    {
        return Translator::t($message, ...$arguments);
    }

    /**
     * @param array<string, mixed>|null $session
     */
    public static function page(string $title, string $body, ?array $session, string $bodyAttributes = ''): string
    {
        $csrf = $session === null ? '' : ' data-csrf="' . self::e($session['csrf'] ?? '') . '"';
        $logout = $session === null ? '' : '<form method="post" action="?a=logout" class="logout"><input type="hidden" name="csrf" value="' . self::e($session['csrf'] ?? '') . '"><button class="link">' . self::e(self::t('Sign out')) . '</button></form>';

        return '<!doctype html><html lang="' . self::e(Translator::language()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">'
            . '<title>' . self::e($title) . ' – SWBackupIt Restore</title><link rel="stylesheet" href="?a=asset&amp;f=css"></head>'
            . '<body' . $csrf . $bodyAttributes . '><header><div class="brand"><span class="logo" aria-hidden="true"></span><strong>SWBackupIt</strong> Restore</div>' . $logout . '</header>'
            . '<main><h1>' . self::e($title) . '</h1>' . $body . '</main><script src="?a=asset&amp;f=js" defer></script></body></html>';
    }

    public static function unavailable(): string
    {
        return self::page(self::t('Restore unavailable'), '<p>' . self::e(self::t('The restore application is not initialised, has expired or was locked after use.')) . '</p>', null);
    }

    /**
     * @param array<string, mixed>|null $session
     */
    public static function error(string $message, ?array $session): string
    {
        return self::page(self::t('Error'), '<p class="error">' . self::e($message) . '</p><p><a href="?">' . self::e(self::t('Back')) . '</a></p>', $session);
    }

    public static function login(string $message = ''): string
    {
        $note = $message === '' ? '' : '<p class="error" role="alert">' . self::e($message) . '</p>';

        return self::page(self::t('Sign in'), $note . '<p>' . self::e(self::t('Enter the one-time restore token that was displayed when the restore was prepared.')) . '</p>'
            . '<form method="post" action="?a=login" autocomplete="off"><label>' . self::e(self::t('Restore token')) . '<input type="password" name="token" required autofocus autocomplete="off"></label>'
            . '<button class="primary">' . self::e(self::t('Sign in')) . '</button></form>', null);
    }

    /**
     * @param list<array<string, mixed>> $backups
     * @param array<string, mixed> $session
     */
    public static function dashboard(array $backups, array $session): string
    {
        if ($backups === []) {
            return self::page(self::t('Choose a backup'), '<p>' . self::e(self::t('No backups were found in the configured backups directory.')) . '</p>', $session);
        }
        $rows = '';
        foreach ($backups as $backup) {
            $rows .= '<tr><td><a href="?a=backup&amp;id=' . self::e($backup['id']) . '">' . self::e(substr((string) $backup['id'], 0, 8)) . '</a></td><td>' . self::e($backup['created_at'])
                . '</td><td>' . self::e($backup['type']) . ($backup['encrypted'] ? ' 🔒' : '') . '</td><td>' . self::e($backup['files']) . '</td><td>' . self::e($backup['size']) . '</td><td>' . self::e($backup['site']) . '</td></tr>';
        }
        $head = '<tr><th>' . self::e(self::t('Backup')) . '</th><th>' . self::e(self::t('Created (UTC)')) . '</th><th>' . self::e(self::t('Type')) . '</th><th>' . self::e(self::t('Files')) . '</th><th>' . self::e(self::t('Size')) . '</th><th>' . self::e(self::t('Source site')) . '</th></tr>';

        return self::page(self::t('Choose a backup'), '<table><thead>' . $head . '</thead><tbody>' . $rows . '</tbody></table>', $session);
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
        $field = static fn (string $label, string $input): string => '<label>' . self::e($label) . $input . '</label>';
        $html = '<dl><dt>' . self::e(self::t('Backup')) . '</dt><dd>' . self::e($manifest['backup_id']) . '</dd><dt>' . self::e(self::t('Created')) . '</dt><dd>' . self::e($manifest['created_at']) . '</dd><dt>' . self::e(self::t('Type'))
            . '</dt><dd>' . self::e($manifest['type']) . '</dd><dt>' . self::e(self::t('Source site')) . '</dt><dd>' . self::e($source['site_url'] ?? '') . '</dd><dt>' . self::e(self::t('Source path')) . '</dt><dd>' . self::e($source['root_path'] ?? '') . '</dd></dl>'
            . '<form method="post" action="?a=preflight"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="backup_id" value="' . self::e($manifest['backup_id']) . '">';
        if ($encrypted) {
            $html .= '<fieldset><legend>' . self::e(self::t('Encryption')) . '</legend>' . $field(self::t('Passphrase or key (base64:…)'), '<input type="password" name="key" autocomplete="off" required>') . '</fieldset>';
        }
        if ($hasFiles) {
            $html .= '<fieldset><legend>' . self::e(self::t('Files')) . '</legend><label class="check"><input type="checkbox" name="restore_files" value="1" checked> ' . self::e(self::t('Restore files')) . '</label>'
                . $field(self::t('Target directory (absolute path of the WordPress installation)'), '<input name="target" value="' . self::e($suggestedTarget) . '" required>')
                . $field(self::t('Type the target directory again to confirm'), '<input name="confirm_target" required>')
                . $field(self::t('Existing files'), '<select name="overwrite"><option value="if_different">' . self::e(self::t('Overwrite only if different')) . '</option><option value="always">' . self::e(self::t('Always overwrite')) . '</option><option value="never">' . self::e(self::t('Never overwrite')) . '</option></select>')
                . $field(self::t('Only these paths (optional, one per line)'), '<textarea name="paths" rows="3" placeholder="wp-content/uploads"></textarea>') . '</fieldset>';
        } else {
            $html .= '<input type="hidden" name="target" value="' . self::e($suggestedTarget) . '">';
        }
        if ($hasDb) {
            $html .= '<fieldset><legend>' . self::e(self::t('Database')) . '</legend><label class="check"><input type="checkbox" name="restore_database" value="1" checked> ' . self::e(self::t('Restore the database (existing tables with the same names are dropped)')) . '</label>'
                . $field(self::t('Host'), '<input name="db_host" value="localhost">') . $field(self::t('Port'), '<input name="db_port" value="3306">')
                . $field(self::t('Database name'), '<input name="db_name" value="' . self::e($suggestedDb) . '">') . $field(self::t('Type the database name again to confirm'), '<input name="confirm_db">')
                . $field(self::t('User'), '<input name="db_user">') . $field(self::t('Password'), '<input type="password" name="db_password" autocomplete="off">') . '</fieldset>'
                . '<fieldset><legend>' . self::e(self::t('Migration (optional)')) . '</legend>' . $field(self::t('Old URL'), '<input name="old_url" value="' . self::e($source['site_url'] ?? '') . '">') . $field(self::t('New URL'), '<input name="new_url" placeholder="https://new-domain.example/wordpress">')
                . $field(self::t('Old path'), '<input name="old_path" value="' . self::e($source['root_path'] ?? '') . '">') . $field(self::t('New path'), '<input name="new_path">')
                . $field(self::t('New table prefix'), '<input name="new_prefix" placeholder="' . self::e($source['table_prefix'] ?? 'wp_') . '">') . '</fieldset>';
        }

        return self::page(self::t('Restore settings'), $html . '<button class="primary">' . self::e(self::t('Check backup & compare')) . '</button></form>', $session);
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

            return self::page(self::t('Backup check failed'), '<p class="error">' . self::e(self::t('The backup did not pass verification. Nothing was changed.')) . '</p><ul>' . $errors . '</ul><p><a href="?">' . self::e(self::t('Back')) . '</a></p>', $session);
        }
        $html = '<p class="ok">' . self::e(self::t('The backup passed verification.')) . '</p><dl><dt>' . self::e(self::t('Target')) . '</dt><dd>' . self::e($plan['target']) . '</dd><dt>' . self::e(self::t('Database')) . '</dt><dd>'
            . ($plan['restore_database'] ? self::e($plan['db']['name']) : self::e(self::t('not restored'))) . '</dd></dl>';
        if (is_array($preflight['comparison'])) {
            $c = $preflight['comparison'];
            $row = static fn (string $label, string $key): string => '<tr><th>' . self::e($label) . '</th><td>' . (int) $c[$key] . '</td></tr>';
            $html .= '<h2>' . self::e(self::t('Comparison with the target directory')) . '</h2><table><tbody>' . $row(self::t('Only in backup'), 'only_in_backup') . $row(self::t('Modified locally'), 'modified')
                . $row(self::t('Only local'), 'only_local') . $row(self::t('Identical'), 'identical') . $row(self::t('Not comparable'), 'incomparable') . '</tbody></table>';
        }
        $html .= '<form method="post" action="?a=start"><input type="hidden" name="csrf" value="' . $csrf . '"><button class="danger">' . self::e(self::t('Start restore')) . '</button></form><p><a href="?">' . self::e(self::t('Cancel')) . '</a></p>';

        return self::page(self::t('Ready to restore'), $html, $session);
    }

    /**
     * @param array<string, mixed> $session
     */
    public static function run(array $session): string
    {
        $body = '<p id="status" role="status" aria-live="polite">' . self::e(self::t('Starting…')) . '</p><progress id="bar" max="100"></progress><pre id="log" aria-live="off"></pre>'
            . '<form id="finish" method="post" action="?a=finish" hidden><input type="hidden" name="csrf" value="' . self::e($session['csrf'] ?? '') . '"><button class="primary">' . self::e(self::t('Lock and remove the restore application')) . '</button></form>';

        return self::page(self::t('Restoring…'), $body, $session, ' data-run="1" data-t-phase="' . self::e(self::t('Phase')) . '" data-t-files="' . self::e(self::t('files restored')) . '" data-t-done="'
            . self::e(self::t('The restore completed successfully.')) . '" data-t-failed="' . self::e(self::t('The restore FAILED. It can be resumed: reload this page to continue.')) . '" data-t-lost="'
            . self::e(self::t('Connection lost. Reload this page to resume the restore.')) . '"');
    }
}
