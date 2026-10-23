<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Web;

use SWBackupIt\Core\Crypto\StaticKeyProvider;
use SWBackupIt\Core\Database\ConnectionConfig;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\Bytes;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Core\Support\Uuid;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\Restore\Files\Comparator;
use SWBackupIt\Restore\Files\OverwriteMode;
use SWBackupIt\Restore\RestoreEngine;
use SWBackupIt\Restore\RestoreOutcome;
use SWBackupIt\Restore\RestoreRequest;

/**
 * Web based restore wizard. Framework free, no WordPress, every route except the login page requires a valid session.
 *
 * Flow: login (token) -> choose backup (only from the configured backups directory) -> form (target path typed twice,
 * database name typed twice, optional URL migration) -> preflight (verify + compare, nothing written) -> start ->
 * ticks driven by the browser (resumable, server side state) -> finish (app locks itself and removes its files).
 * Mutating routes are POST-only and CSRF protected; all output is escaped; a strict CSP forbids inline scripts.
 */
final class WebApp
{
    private readonly Auth $auth;
    private readonly string $state;

    /**
     * @param array<string, mixed> $config token_hash, expires_at, backups_dir, state_dir, app_dir
     */
    public function __construct(private readonly array $config)
    {
        $this->state = (string) ($config['state_dir'] ?? sys_get_temp_dir() . '/swbi-restore-' . substr(hash('sha256', (string) ($config['app_dir'] ?? __DIR__)), 0, 12));
        $this->auth = new Auth($config, $this->state);
    }

    public function handle(WebRequest $request): WebResponse
    {
        $action = $request->query['a'] ?? '';
        if ($action === 'asset') {
            return $this->asset($request->query['f'] ?? '');
        }
        if (!$this->auth->isEnabled()) {
            return new WebResponse(403, View::page('Restore unavailable', '<p>The restore application is not initialised, has expired or was locked after use.</p>', null));
        }
        $sessionId = $request->cookies[Auth::COOKIE] ?? '';
        $session = $this->auth->session($sessionId);

        if ($session === null) {
            return $action === 'login' && $request->method === 'POST' ? $this->login($request) : new WebResponse(200, View::login());
        }
        // Everything below needs a session; every POST also needs the CSRF token.
        if ($request->method === 'POST') {
            $csrf = $request->post('csrf', (string) ($request->server['HTTP_X_CSRF_TOKEN'] ?? ''));
            if (!Auth::validCsrf($session, $csrf)) {
                return new WebResponse(403, View::page('Forbidden', '<p>Invalid or missing security token. Reload the page.</p>', null));
            }
        }
        try {
            return match (true) {
                $action === 'logout' && $request->method === 'POST' => $this->logout($sessionId),
                $action === 'backup' => $this->backupPage($request, $session),
                $action === 'preflight' && $request->method === 'POST' => $this->preflight($request, $sessionId, $session),
                $action === 'start' && $request->method === 'POST' => $this->start($sessionId, $session),
                $action === 'run' => new WebResponse(200, View::run($session)),
                $action === 'tick' && $request->method === 'POST' => $this->tick($sessionId, $session),
                $action === 'finish' && $request->method === 'POST' => $this->finish($sessionId),
                default => new WebResponse(200, View::dashboard($this->backups(), $session)),
            };
        } catch (SwbiException $exception) {
            return new WebResponse(400, View::page('Error', '<p class="error">' . View::e($exception->getMessage()) . '</p><p><a href="?">Back</a></p>', $session));
        }
    }

    private function login(WebRequest $request): WebResponse
    {
        $result = $this->auth->login($request->post('token'));
        if (!$result['ok']) {
            $message = $result['retryAfter'] > 0
                ? sprintf('Too many failed attempts. Try again in %d minutes.', (int) ceil($result['retryAfter'] / 60))
                : 'The token is not valid.';

            return new WebResponse($result['retryAfter'] > 0 ? 429 : 401, View::login($message));
        }
        $response = WebResponse::redirect('?');
        $response->cookies[] = $this->auth->cookie($result['sessionId'], $request->isHttps());

        return $response;
    }

    private function logout(string $sessionId): WebResponse
    {
        $this->auth->destroy($sessionId);

        return new WebResponse(200, View::login('You were signed out.'));
    }

    /**
     * @param array<string, mixed> $session
     */
    private function backupPage(WebRequest $request, array $session): WebResponse
    {
        $set = $this->openBackup($request->query['id'] ?? '', null);
        $manifest = $set->manifest();

        return new WebResponse(200, View::backup($manifest->toArray(), $session, $this->suggestTarget(), (string) ($this->config['db_name'] ?? '')));
    }

    /**
     * @param array<string, mixed> $session
     */
    private function preflight(WebRequest $request, string $sessionId, array $session): WebResponse
    {
        $backupId = $request->post('backup_id');
        $plan = $this->plan($request, $backupId);
        $set = $this->openBackup($backupId, $plan['key'] === '' ? null : new StaticKeyProvider($plan['key']));
        try {
            $report = (new Verifier())->verify($set);
            $comparison = null;
            if ($report->isOk() && $plan['restore_files'] && is_dir($plan['target']) && (!$set->isEncrypted() || $set->hasKeys())) {
                $comparison = (new Comparator())->compare($set, $plan['target'], Comparator::MODE_METADATA, null, $this->state)->toArray();
            }
        } finally {
            $set->close();
        }
        $session['data'] = ['plan' => $plan, 'preflight' => ['ok' => $report->isOk(), 'errors' => $report->errors(), 'comparison' => $comparison], 'run' => null];
        $this->auth->save($sessionId, $session);

        return new WebResponse(200, View::preflight($session['data']['preflight'], $plan, $session));
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(WebRequest $request, string $backupId): array
    {
        $target = rtrim($request->post('target'), '/');
        $confirm = rtrim($request->post('confirm_target'), '/');
        $real = $target !== '' && $target[0] === '/' ? realpath($target) : false;
        if ($real === false || !is_dir($real)) {
            throw new \SWBackupIt\Core\Exception\ConfigurationException('The target directory must be an existing absolute path.');
        }
        if ($confirm !== $real) {
            throw new \SWBackupIt\Core\Exception\ConfigurationException('Type the target path again exactly as shown to confirm it: ' . $real);
        }
        $roots = $this->config['allowed_target_roots'] ?? [];
        if (is_array($roots) && $roots !== []) {
            $allowed = false;
            foreach ($roots as $root) {
                $rootReal = is_string($root) ? realpath($root) : false;
                $allowed = $allowed || ($rootReal !== false && ($real === $rootReal || str_starts_with($real, $rootReal . '/')));
            }
            if (!$allowed) {
                throw new \SWBackupIt\Core\Exception\ConfigurationException('The target is outside the permitted restore locations.');
            }
        }
        $replacements = [];
        $old = trim($request->post('old_url'));
        $new = trim($request->post('new_url'));
        if ($old !== '' || $new !== '') {
            foreach ([$old, $new] as $url) {
                if (preg_match('#^https?://[^\s]+$#i', $url) !== 1) {
                    throw new \SWBackupIt\Core\Exception\ConfigurationException('URL migration needs a valid old and new URL (http:// or https://).');
                }
            }
            $replacements[rtrim($old, '/')] = rtrim($new, '/');
        }
        $oldPath = trim($request->post('old_path'));
        $newPath = trim($request->post('new_path'));
        if ($oldPath !== '' && $newPath !== '') {
            $replacements[rtrim($oldPath, '/')] = rtrim($newPath, '/');
        }
        $overwrite = OverwriteMode::tryFrom($request->post('overwrite', 'if_different')) ?? OverwriteMode::IfDifferent;
        $restoreDb = $request->post('restore_database') === '1';
        $db = null;
        if ($restoreDb) {
            $db = [
                'host' => $request->post('db_host', 'localhost'), 'name' => $request->post('db_name'), 'user' => $request->post('db_user'),
                'password' => $request->post('db_password'), 'confirm' => $request->post('confirm_db'), 'port' => (int) $request->post('db_port', '3306'),
            ];
            if ($db['name'] === '' || $db['confirm'] !== $db['name']) {
                throw new \SWBackupIt\Core\Exception\ConfigurationException('Type the database name twice to confirm the database restore.');
            }
            new ConnectionConfig($db['host'], $db['name'], $db['user'], $db['password'], $db['port']);
        }
        $prefix = trim($request->post('new_prefix'));

        return [
            'backup_id' => $backupId, 'target' => $real, 'restore_files' => $request->post('restore_files', '1') === '1', 'restore_database' => $restoreDb, 'db' => $db,
            'overwrite' => $overwrite->value, 'replacements' => $replacements, 'new_prefix' => $prefix, 'key' => $request->post('key'),
            'path' => array_values(array_filter(array_map('trim', explode("\n", $request->post('paths'))), static fn (string $p): bool => $p !== '')),
        ];
    }

    /**
     * @param array<string, mixed> $session
     */
    private function start(string $sessionId, array $session): WebResponse
    {
        $data = $session['data'] ?? [];
        if (!is_array($data) || !isset($data['plan']) || ($data['preflight']['ok'] ?? false) !== true) {
            throw new \SWBackupIt\Core\Exception\ConfigurationException('Run the preflight check first; the backup must verify successfully.');
        }
        $data['run'] = ['status' => 'running', 'started' => time()];
        $session['data'] = $data;
        $this->auth->save($sessionId, $session);

        return WebResponse::redirect('?a=run');
    }

    /**
     * @param array<string, mixed> $session
     */
    private function tick(string $sessionId, array $session): WebResponse
    {
        $data = $session['data'] ?? [];
        $plan = is_array($data) ? ($data['plan'] ?? null) : null;
        if (!is_array($plan) || ($data['run']['status'] ?? '') !== 'running') {
            return WebResponse::json(['status' => 'failed', 'messages' => ['No restore is running.']], 409);
        }
        $db = $plan['db'];
        $config = $db === null ? null : new ConnectionConfig((string) $db['host'], (string) $db['name'], (string) $db['user'], (string) $db['password'], (int) $db['port']);
        $request = new RestoreRequest(
            $this->backupDir((string) $plan['backup_id']),
            (string) $plan['target'],
            (string) $plan['target'],
            $this->state . '/restore/' . $plan['backup_id'],
            (bool) $plan['restore_files'],
            (bool) $plan['restore_database'],
            $config,
            $db === null ? '' : (string) $db['name'],
            true,
            OverwriteMode::from((string) $plan['overwrite']),
            new \SWBackupIt\Restore\Files\Selection($plan['path']),
            false,
            false,
            keys: $plan['key'] === '' ? null : new StaticKeyProvider((string) $plan['key']),
            replacements: $plan['replacements'],
            newTablePrefix: $plan['new_prefix'] === '' ? null : (string) $plan['new_prefix'],
        );
        $outcome = (new RestoreEngine())->run($request, new ResourceBudget(8.0));
        $files = $outcome->summary['files'] ?? [];
        $payload = [
            'status' => $outcome->status, 'phase' => $outcome->phase, 'messages' => $outcome->messages,
            'files' => (int) ($files['files'] ?? 0), 'bytes' => Bytes::format((int) ($files['bytes'] ?? 0)), 'skipped' => (int) ($files['skipped'] ?? 0),
        ];
        if ($outcome->status !== RestoreOutcome::CONTINUE) {
            // Credentials and keys are removed from the session as soon as the run ended (success or failure).
            $data['plan']['db']['password'] = '';
            $data['plan']['key'] = '';
            $data['run'] = ['status' => $outcome->status, 'finished' => time()];
            $session['data'] = $data;
            $this->auth->save($sessionId, $session);
        }

        return WebResponse::json($payload);
    }

    private function finish(string $sessionId): WebResponse
    {
        $session = $this->auth->session($sessionId);
        if ($session === null || ($session['data']['run']['status'] ?? '') !== RestoreOutcome::COMPLETED) {
            throw new \SWBackupIt\Core\Exception\ConfigurationException('The restore has not completed successfully; the application stays available.');
        }
        $this->auth->destroyAll();
        $this->auth->lock();
        $removed = $this->removeApplication();

        return new WebResponse(200, View::page('Restore finished', '<p class="ok">The restore completed. The restore application is now locked' . ($removed ? ' and its files were removed.' : '. Please delete the restore directory from your server manually.') . '</p>', null));
    }

    /** Best effort self removal; the lock file already makes the app inert if this fails. */
    private function removeApplication(): bool
    {
        $dir = (string) ($this->config['app_dir'] ?? '');
        if ($dir === '' || !is_dir($dir) || !is_file($dir . '/index.php')) {
            return false;
        }
        $ok = true;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..' || $item === 'index.php') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                AtomicFile::removeTree($path);
                clearstatcache(true, $path);
                $ok = (is_dir($path) ? false : true) && $ok;
            } else {
                $ok = @unlink($path) && $ok;
            }
        }
        file_put_contents($dir . '/index.php', "<?php\nhttp_response_code(410);\nexit('This restore application has been removed.');\n");

        return $ok;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function backups(): array
    {
        $result = [];
        $root = (string) ($this->config['backups_dir'] ?? '');
        foreach (glob(rtrim($root, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!Uuid::isValid(basename($dir))) {
                continue;
            }
            try {
                $manifest = BackupSet::open($dir)->manifest();
                $files = $manifest->section('files');
                $size = array_sum(array_map(static fn ($v): int => $v->size, $manifest->volumes()));
                $result[] = ['id' => $manifest->backupId(), 'created_at' => $manifest->createdAt(), 'type' => $manifest->type()->value, 'encrypted' => $manifest->isEncrypted(), 'files' => $files['count'] ?? 0, 'size' => Bytes::format((int) $size), 'site' => (string) ($manifest->section('source')['site_url'] ?? '')];
            } catch (SwbiException) {
                continue;
            }
        }
        usort($result, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $result;
    }

    private function backupDir(string $id): string
    {
        if (!Uuid::isValid($id)) {
            throw new \SWBackupIt\Core\Exception\ConfigurationException('Invalid backup id.');
        }
        $dir = rtrim((string) ($this->config['backups_dir'] ?? ''), '/') . '/' . $id;
        if (!is_dir($dir)) {
            throw new \SWBackupIt\Core\Exception\ConfigurationException('The backup was not found in the configured backups directory.');
        }

        return $dir;
    }

    private function openBackup(string $id, ?StaticKeyProvider $keys): BackupSet
    {
        return BackupSet::open($this->backupDir($id), $keys, $this->state . '/decrypted-' . bin2hex(random_bytes(4)));
    }

    private function suggestTarget(): string
    {
        return (string) ($this->config['suggested_target'] ?? '');
    }

    private function asset(string $name): WebResponse
    {
        $dir = dirname(__DIR__, 2) . '/public';
        $map = ['css' => ['restore.css', 'text/css; charset=utf-8'], 'js' => ['restore.js', 'application/javascript; charset=utf-8']];
        if (!isset($map[$name])) {
            return new WebResponse(404, 'Not found');
        }
        $file = $dir . '/' . $map[$name][0];
        if (!is_file($file)) {
            $file = ($this->config['app_dir'] ?? $dir) . '/' . $map[$name][0];
        }

        return new WebResponse(is_file($file) ? 200 : 404, is_file($file) ? (string) file_get_contents($file) : '', ['Content-Type' => $map[$name][1], 'Cache-Control' => 'public, max-age=300']);
    }
}
