<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Tests\Support\FilesBackupFixture;

/** Proves the restore distribution works as a separate PHP process without Composer, WordPress or the plugin's vendor dir. */
final class StandaloneDistributionTest extends TestCase
{
    use FilesBackupFixture;

    public function testRestoreDistributionVerifiesAndRestoresInSeparateProcess(): void
    {
        $runner = $this->setUpFilesBackup();
        $job = $this->newJob();
        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 5.0));

        $dist = $this->makeTempDir('dist');
        $script = dirname(__DIR__, 2) . '/bin/build-restore.sh';
        exec(escapeshellarg($script) . ' ' . escapeshellarg($dist . '/app') . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        self::assertDirectoryDoesNotExist($dist . '/app/vendor/league', 'distribution must not need flysystem or zipstream');

        $target = $this->makeTempDir('target');
        $state = $this->makeTempDir('state');
        $cli = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 ' . escapeshellarg($dist . '/app/bin/swbackupit-restore');
        exec($cli . ' verify ' . escapeshellarg($this->backupDir($job)) . ' --deep 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));

        exec(
            $cli . ' restore ' . escapeshellarg($this->backupDir($job)) . ' --target=' . escapeshellarg($target)
            . ' --confirm-target=' . escapeshellarg((string) realpath($target)) . ' --no-database --state-dir=' . escapeshellarg($state) . ' 2>&1',
            $restoreOutput,
            $code,
        );
        self::assertSame(0, $code, implode("\n", $restoreOutput));
        $expected = $this->snapshotTree($this->site);
        self::assertSame($expected, $this->snapshotTree($target));
    }
}
