<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Restore\Web\WebApp;
use SWBackupIt\Restore\Web\WebRequest;
use SWBackupIt\Restore\Web\WebResponse;
use SWBackupIt\Restore\Web\WebSetup;
use SWBackupIt\Tests\Support\FilesBackupFixture;

final class WebWizardTest extends TestCase
{
    use FilesBackupFixture;

    private string $app = '';
    private string $token = '';
    private WebApp $web;

    private function setUpWizard(int $ttl = 3600): void
    {
        $runner = $this->setUpFilesBackup();
        $job = $this->newJob();
        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 5.0));
        $this->backupId = $job->backupId;
        $this->app = $this->makeTempDir('app');
        file_put_contents($this->app . '/index.php', '<?php // entry');
        file_put_contents($this->app . '/restore.js', '// js');
        $this->token = WebSetup::initialize($this->app, $this->staging, $ttl, $this->makeTempDir('wstate'));
        $config = require $this->app . '/config.php';
        $config['app_dir'] = $this->app;
        $this->web = new WebApp($config);
    }

    private string $backupId = '';

    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $post
     */
    private function call(string $method, array $query = [], array $post = [], string $cookie = '', string $csrfHeader = ''): WebResponse
    {
        $cookies = $cookie === '' ? [] : ['swbi_restore' => $cookie];
        $server = $csrfHeader === '' ? [] : ['HTTP_X_CSRF_TOKEN' => $csrfHeader];

        return $this->web->handle(new WebRequest($method, $query, $post, $cookies, $server));
    }

    /**
     * @return array{string, string} session id, csrf
     */
    private function login(): array
    {
        $response = $this->call('POST', ['a' => 'login'], ['token' => $this->token]);
        self::assertSame(303, $response->status);
        preg_match('/swbi_restore=([0-9a-f]{64}); Path=\/; HttpOnly; SameSite=Strict/', $response->cookies[0], $m);
        $session = (new \ReflectionClass($this->web))->getProperty('auth');
        $auth = $session->getValue($this->web);
        $data = $auth->session($m[1]);

        return [$m[1], (string) $data['csrf']];
    }

    public function testUnauthenticatedAccessRevealsNothing(): void
    {
        $this->setUpWizard();
        foreach ([['GET', []], ['GET', ['a' => 'backup', 'id' => $this->backupId]], ['POST', ['a' => 'tick']], ['GET', ['a' => 'run']]] as [$method, $query]) {
            $response = $this->call($method, $query);
            self::assertStringNotContainsString($this->backupId, $response->body);
            self::assertStringContainsString('Sign in', $response->body);
        }
        $wrong = $this->call('POST', ['a' => 'login'], ['token' => 'wrong']);
        self::assertSame(401, $wrong->status);
        self::assertSame([], $wrong->cookies);
    }

    public function testLoginIsRateLimitedEvenWithTheCorrectTokenAfterTooManyFailures(): void
    {
        $this->setUpWizard();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $this->call('POST', ['a' => 'login'], ['token' => 'nope' . $i])->status);
        }
        $locked = $this->call('POST', ['a' => 'login'], ['token' => $this->token]);
        self::assertSame(429, $locked->status);
        self::assertSame([], $locked->cookies);
    }

    public function testExpiredTokenAndUninitialisedAppAreInert(): void
    {
        $this->setUpWizard(60);
        $config = require $this->app . '/config.php';
        $config['expires_at'] = time() - 1;
        $expired = new WebApp($config + ['app_dir' => $this->app]);
        $response = $expired->handle(new WebRequest('POST', ['a' => 'login'], ['token' => $this->token]));
        self::assertSame(403, $response->status);
        $none = (new WebApp(['app_dir' => $this->app]))->handle(new WebRequest('GET'));
        self::assertSame(403, $none->status);
    }

    public function testCsrfIsEnforcedAndPagesAreEscaped(): void
    {
        $this->setUpWizard();
        [$session, $csrf] = $this->login();
        $noCsrf = $this->call('POST', ['a' => 'preflight'], ['backup_id' => $this->backupId], $session);
        self::assertSame(403, $noCsrf->status);
        $dashboard = $this->call('GET', [], [], $session);
        self::assertStringContainsString(substr($this->backupId, 0, 8), $dashboard->body);
        $xss = $this->call('POST', ['a' => 'preflight'], ['csrf' => $csrf, 'backup_id' => $this->backupId, 'target' => '<script>alert(1)</script>', 'confirm_target' => 'x'], $session);
        self::assertSame(400, $xss->status);
        self::assertStringNotContainsString('<script>alert', $xss->body);
        $traversal = $this->call('GET', ['a' => 'backup', 'id' => '../../etc'], [], $session);
        self::assertSame(400, $traversal->status);
    }

    public function testTargetMustBeConfirmedAndFullRestoreFlowLocksTheApplication(): void
    {
        $this->setUpWizard();
        [$session, $csrf] = $this->login();
        $target = $this->makeTempDir('target');
        $base = ['csrf' => $csrf, 'backup_id' => $this->backupId, 'restore_files' => '1', 'target' => $target];

        $unconfirmed = $this->call('POST', ['a' => 'preflight'], $base + ['confirm_target' => $target . '/other'], $session);
        self::assertSame(400, $unconfirmed->status);
        self::assertStringContainsString('Type the target path again', $unconfirmed->body);
        $startWithoutPreflight = $this->call('POST', ['a' => 'start'], ['csrf' => $csrf], $session);
        self::assertSame(400, $startWithoutPreflight->status, 'start requires a successful preflight');

        $pre = $this->call('POST', ['a' => 'preflight'], $base + ['confirm_target' => (string) realpath($target)], $session);
        self::assertSame(200, $pre->status, $pre->body);
        self::assertStringContainsString('passed verification', $pre->body);
        self::assertSame(303, $this->call('POST', ['a' => 'start'], ['csrf' => $csrf], $session)->status);

        $status = '';
        for ($i = 0; $i < 50 && $status !== 'completed'; $i++) {
            $tick = json_decode($this->call('POST', ['a' => 'tick'], [], $session, $csrf)->body, true);
            $status = $tick['status'];
            self::assertNotSame('failed', $status, json_encode($tick) ?: '');
        }
        self::assertSame('completed', $status);
        self::assertSame($this->snapshotTree($this->site), $this->snapshotTree($target));

        $finish = $this->call('POST', ['a' => 'finish'], ['csrf' => $csrf], $session);
        self::assertStringContainsString('locked', $finish->body);
        self::assertFileDoesNotExist($this->app . '/restore.js');
        self::assertFileDoesNotExist($this->app . '/config.php');
        self::assertStringContainsString('410', (string) file_get_contents($this->app . '/index.php'));
        // The token is dead: even a correct login attempt is refused afterwards.
        self::assertSame(403, $this->call('POST', ['a' => 'login'], ['token' => $this->token])->status);
    }

    public function testFinishIsRefusedUnlessTheRestoreSucceeded(): void
    {
        $this->setUpWizard();
        [$session, $csrf] = $this->login();
        $finish = $this->call('POST', ['a' => 'finish'], ['csrf' => $csrf], $session);
        self::assertSame(400, $finish->status);
        self::assertFileExists($this->app . '/restore.js');
    }

    public function testResponsesCarryStrictSecurityHeadersViaSendContract(): void
    {
        $this->setUpWizard();
        $response = $this->call('GET');
        self::assertStringNotContainsString('<script>', $response->body, 'no inline scripts (CSP script-src self)');
        self::assertStringNotContainsString(' style=', $response->body, 'no inline styles (CSP style-src self)');
    }
}
