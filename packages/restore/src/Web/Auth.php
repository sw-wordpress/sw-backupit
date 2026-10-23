<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Web;

use SWBackupIt\Core\Support\AtomicFile;

/**
 * Authentication for the web based restore wizard (PLAN.md §13.1/§13.6, §24).
 *
 * - No token configured / expired / app locked => nothing is possible, nothing about backups is revealed.
 * - A single random token (shown once to the operator) is stored only as SHA-256; compared in constant time.
 * - Failed logins are rate limited globally (not per IP: proxy headers can be spoofed): 5 failures in 15 minutes lock logins
 *   for the remaining window, successful tokens do not reset the counter.
 * - Sessions: random id in an HttpOnly, SameSite=Strict (Secure on https) cookie, server side state, idle timeout, CSRF token per session.
 * - Session state (which may contain database credentials for the running restore) lives in a 0600 file and is wiped on logout/finish.
 */
final class Auth
{
    public const COOKIE = 'swbi_restore';
    private const MAX_FAILURES = 5;
    private const WINDOW = 900;
    private const IDLE = 1800;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config, private readonly string $stateDirectory)
    {
        AtomicFile::ensureDirectory($stateDirectory . '/sessions');
        @chmod($stateDirectory, 0700);
    }

    public function isEnabled(): bool
    {
        $hash = $this->config['token_hash'] ?? '';
        $expires = (int) ($this->config['expires_at'] ?? 0);

        return is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) === 1 && $expires > time() && !is_file($this->stateDirectory . '/locked');
    }

    public function isLocked(): bool
    {
        return is_file($this->stateDirectory . '/locked');
    }

    public function lock(): void
    {
        AtomicFile::write($this->stateDirectory . '/locked', gmdate('c') . "\n");
    }

    /**
     * @return array{ok: bool, retryAfter: int, sessionId: string}
     */
    public function login(string $token): array
    {
        $now = time();
        $file = $this->stateDirectory . '/ratelimit.json';
        $failures = array_values(array_filter(
            is_file($file) ? (array) (AtomicFile::readJson($file)['failures'] ?? []) : [],
            static fn (mixed $time): bool => is_int($time) && $time > $now - self::WINDOW,
        ));
        if (count($failures) >= self::MAX_FAILURES) {
            return ['ok' => false, 'retryAfter' => max(1, $failures[0] + self::WINDOW - $now), 'sessionId' => ''];
        }
        $expected = (string) ($this->config['token_hash'] ?? '');
        if (!$this->isEnabled() || !hash_equals($expected, hash('sha256', trim($token)))) {
            $failures[] = $now;
            AtomicFile::writeJson($file, ['failures' => $failures]);
            usleep(250000);

            return ['ok' => false, 'retryAfter' => 0, 'sessionId' => ''];
        }
        $id = bin2hex(random_bytes(32));
        $this->writeSession($id, ['created' => $now, 'last' => $now, 'csrf' => bin2hex(random_bytes(24)), 'data' => []]);

        return ['ok' => true, 'retryAfter' => 0, 'sessionId' => $id];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function session(string $id): ?array
    {
        if (!$this->isEnabled() || preg_match('/^[0-9a-f]{64}$/', $id) !== 1) {
            return null;
        }
        $file = $this->sessionFile($id);
        if (!is_file($file)) {
            return null;
        }
        $session = AtomicFile::readJson($file);
        if (((int) ($session['last'] ?? 0)) < time() - self::IDLE) {
            @unlink($file);

            return null;
        }
        $session['last'] = time();
        $this->writeSession($id, $session);

        return $session;
    }

    /**
     * @param array<string, mixed> $session
     */
    public function save(string $id, array $session): void
    {
        $this->writeSession($id, $session);
    }

    public function destroy(string $id): void
    {
        if (preg_match('/^[0-9a-f]{64}$/', $id) === 1) {
            @unlink($this->sessionFile($id));
        }
    }

    public function destroyAll(): void
    {
        foreach (glob($this->stateDirectory . '/sessions/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function cookie(string $sessionId, bool $secure): string
    {
        return sprintf('%s=%s; Path=/; HttpOnly; SameSite=Strict%s', self::COOKIE, $sessionId, $secure ? '; Secure' : '');
    }

    /**
     * @param array<string, mixed> $session
     */
    public static function validCsrf(array $session, string $token): bool
    {
        return isset($session['csrf']) && is_string($session['csrf']) && hash_equals($session['csrf'], $token);
    }

    private function sessionFile(string $id): string
    {
        return $this->stateDirectory . '/sessions/' . $id . '.json';
    }

    /**
     * @param array<string, mixed> $session
     */
    private function writeSession(string $id, array $session): void
    {
        $file = $this->sessionFile($id);
        AtomicFile::writeJson($file, $session);
        @chmod($file, 0600);
    }
}
