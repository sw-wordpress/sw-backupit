<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Restore\Cli\RestoreCli;
use SWBackupIt\Tests\Support\FilesBackupFixture;

final class RestoreCliTest extends TestCase
{
    use FilesBackupFixture;

    /**
     * @param list<string> $argv
     * @return array{int, string, string}
     */
    private function cli(array $argv): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new RestoreCli($out, $err))->run($argv);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testInfoVerifyListAndRestore(): void
    {
        $runner = $this->setUpFilesBackup();
        $job = $this->newJob();
        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 5.0));
        $dir = $this->backupDir($job);

        [$code, $out] = $this->cli(['info', $dir, '--format=json']);
        self::assertSame(0, $code);
        $info = json_decode($out, true);
        self::assertSame($job->backupId, $info['backup_id']);
        self::assertSame('files', $info['type']);

        [$code, $out] = $this->cli(['verify', $dir, '--deep']);
        self::assertSame(0, $code);
        self::assertStringStartsWith('OK:', $out);

        [$code, $out] = $this->cli(['list', $dir, '--path=wp-content/uploads/many', '--pattern=**/f1.txt']);
        self::assertSame(0, $code);
        self::assertStringContainsString('wp-content/uploads/many/f1.txt', $out);
        self::assertStringNotContainsString('wp-config.php', $out);

        $target = $this->makeTempDir('target');
        [$code, , $err] = $this->cli(['restore', $dir, '--target=' . $target, '--no-database']);
        self::assertSame(1, $code, 'missing target confirmation must fail');
        self::assertStringContainsString('not confirmed', $err);

        [$code, $out, $err] = $this->cli(['restore', $dir, '--target=' . $target, '--confirm-target=' . realpath($target), '--no-database', '--state-dir=' . $this->makeTempDir('st')]);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('Restore completed successfully', $out);
        self::assertFileExists($target . '/wp-content/uploads/binary.bin');

        [$code] = $this->cli(['bogus']);
        self::assertSame(2, $code);
        [$code] = $this->cli(['verify', $this->makeTempDir('nothing')]);
        self::assertSame(1, $code);
    }

    public function testVerifyFailsOnCorruptBackup(): void
    {
        $runner = $this->setUpFilesBackup();
        $job = $this->newJob();
        $runner->runToCompletion($job->id, 5.0);
        $dir = $this->backupDir($job);
        file_put_contents($dir . '/volumes/backup-001.zip', 'x', FILE_APPEND);
        [$code, , $err] = $this->cli(['verify', $dir]);
        self::assertSame(1, $code);
        self::assertStringContainsString('ERROR', $err);
    }
}
