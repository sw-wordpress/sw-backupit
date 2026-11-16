<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Exception\PathViolationException;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Restore\Web\Auth;
use SWBackupIt\Restore\Web\WebSetup;
use SWBackupIt\Tests\Support\TempDir;

final class RestoreWebStateTest extends TestCase
{
    use TempDir;

    public function testDefaultInitializationNeverAdoptsThePredictableOrPreviousState(): void
    {
        $app = $this->makeTempDir('app');
        $backups = $this->makeTempDir('backups');
        $states = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                WebSetup::initialize($app, $backups);
                $config = require $app . '/config.php';
                $states[] = $config['state_dir'];
                self::assertMatchesRegularExpression('/\.swbi-restore-state-[a-f0-9]{32}$/', $config['state_dir']);
                self::assertDirectoryExists($config['state_dir']);
                self::assertSame(fileowner($app), fileowner($config['state_dir']));
                if (PHP_OS_FAMILY !== 'Windows') {
                    self::assertSame(0700, fileperms($config['state_dir']) & 0777);
                }
            }
            self::assertNotSame($states[0], $states[1]);
        } finally {
            foreach ($states as $state) {
                AtomicFile::removeTree($state);
            }
        }
    }

    public function testPrecreatedWritableStateCannotAuthenticateAForgedSession(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permissions are required.');
        }
        $root = $this->makeTempDir('untrusted');
        mkdir($root . '/sessions', 0700);
        $id = str_repeat('a', 64);
        $session = json_encode(['last' => time(), 'csrf' => 'attacker', 'data' => []], JSON_THROW_ON_ERROR);
        file_put_contents($root . '/sessions/' . $id . '.json', $session);
        chmod($root, 0777);
        try {
            new Auth(['token_hash' => hash('sha256', 'secret'), 'expires_at' => time() + 3600], $root);
            self::fail('Untrusted state must be refused before loading a session.');
        } catch (ConfigurationException) {
            self::assertFileDoesNotExist($root . '/.htaccess');
            self::assertSame($session, file_get_contents($root . '/sessions/' . $id . '.json'));
            self::assertSame(0777, fileperms($root) & 0777, 'refusal does not adopt or modify untrusted state');
        }
    }

    public function testWritableSessionsDirectoryIsRejectedBeforeAuthentication(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permissions are required.');
        }
        $root = $this->makeTempDir('state');
        mkdir($root . '/sessions', 0700);
        chmod($root . '/sessions', 0777);
        $this->expectException(ConfigurationException::class);
        new Auth([], $root);
    }

    public function testSymlinkStateNeverChangesTheTarget(): void
    {
        $parent = $this->makeTempDir('parent');
        $outside = $this->makeTempDir('outside');
        $permissions = fileperms($outside);
        symlink($outside, $parent . '/state');
        try {
            WebSetup::protectStateDirectory($parent . '/state');
            self::fail('Symlink state must be refused.');
        } catch (PathViolationException) {
            self::assertSame($permissions, fileperms($outside));
            self::assertSame(['.', '..'], scandir($outside));
        }
    }

    public function testSymlinkSessionsNeverChangeTheTarget(): void
    {
        $root = $this->makeTempDir('state');
        $outside = $this->makeTempDir('outside');
        symlink($outside, $root . '/sessions');
        $this->expectException(PathViolationException::class);
        new Auth([], $root);
    }

    public function testProtectionRulesCannotOverwriteSymlinkTargets(): void
    {
        foreach (['.htaccess', 'web.config'] as $name) {
            $root = $this->makeTempDir('state');
            $outside = $this->makeTempDir('outside') . '/sentinel';
            file_put_contents($outside, 'unchanged');
            symlink($outside, $root . '/' . $name);
            try {
                WebSetup::protectStateDirectory($root);
                self::fail('Symlink rules must be refused.');
            } catch (PathViolationException) {
                self::assertSame('unchanged', file_get_contents($outside));
            }
        }
    }

    public function testOwnedStateIsProtectedAndSessionsStillWorkAfterRestart(): void
    {
        $root = $this->makeTempDir('state');
        $config = ['token_hash' => hash('sha256', 'secret'), 'expires_at' => time() + 3600];
        $auth = new Auth($config, $root);
        $login = $auth->login('secret');
        self::assertTrue($login['ok']);
        $session = (new Auth($config, $root))->session($login['sessionId']);
        self::assertNotNull($session);
        self::assertNotEmpty($session['csrf']);
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0700, fileperms($root . '/sessions') & 0777);
        }
        self::assertFileExists($root . '/.htaccess');
        self::assertFileExists($root . '/web.config');
    }

    public function testStateMustBeADirectory(): void
    {
        $root = $this->makeTempDir('state');
        file_put_contents($root . '/file', 'unchanged');
        $this->expectException(ConfigurationException::class);
        WebSetup::protectStateDirectory($root . '/file');
    }
}
