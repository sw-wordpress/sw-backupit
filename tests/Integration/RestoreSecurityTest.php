<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\Restore\Files\OverwriteMode;
use SWBackupIt\Restore\Files\RestoreLimits;
use SWBackupIt\Restore\Files\Selection;
use SWBackupIt\Restore\RestoreEngine;
use SWBackupIt\Restore\RestoreOutcome;
use SWBackupIt\Restore\RestoreRequest;
use SWBackupIt\Tests\Support\FilesBackupFixture;

final class RestoreSecurityTest extends TestCase
{
    use FilesBackupFixture;

    private string $backupDir = '';

    private function makeBackup(): void
    {
        $runner = $this->setUpFilesBackup();
        $job = $this->newJob();
        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 5.0));
        $this->backupDir = $this->backupDir($job);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function request(string $target, array $overrides = []): RestoreRequest
    {
        return new RestoreRequest(
            $this->backupDir,
            $target,
            $overrides['confirmed'] ?? (string) realpath($target),
            $this->makeTempDir('state'),
            restoreDatabase: false,
            overwrite: $overrides['overwrite'] ?? OverwriteMode::IfDifferent,
            selection: $overrides['selection'] ?? new Selection(),
            dryRun: $overrides['dryRun'] ?? false,
            verifyFirst: $overrides['verifyFirst'] ?? true,
            limits: $overrides['limits'] ?? new RestoreLimits(),
        );
    }

    /**
     * Rewrites the index with a modified entry list and fixes the manifest checksum so tampering
     * is not caught by the checksum check, but must still be stopped by the path guard.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    private function tamperIndex(callable $mutate): void
    {
        $lines = [];
        foreach ((new \SWBackupIt\Core\Scanner\FileIndexReader($this->backupDir . '/files.ndjson'))->read() as [$entry]) {
            $lines[] = json_encode($mutate($entry), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        file_put_contents($this->backupDir . '/files.ndjson', implode("\n", $lines) . "\n");
        $manifest = AtomicFile::readJson($this->backupDir . '/manifest.json');
        $manifest['files']['sha256'] = hash_file('sha256', $this->backupDir . '/files.ndjson');
        AtomicFile::writeJson($this->backupDir . '/manifest.json', $manifest);
    }

    public function testRestoreRefusesUnconfirmedTargetPath(): void
    {
        $this->makeBackup();
        $target = $this->makeTempDir('target');
        $outcome = (new RestoreEngine())->run($this->request($target, ['confirmed' => '/tmp/something-else']), ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::FAILED, $outcome->status);
        self::assertStringContainsString('not confirmed', $outcome->messages[0]);
        self::assertSame([], scandir($target) ? array_values(array_diff(scandir($target), ['.', '..'])) : []);
    }

    public function testTargetMayNotContainTheBackup(): void
    {
        $this->makeBackup();
        $outcome = (new RestoreEngine())->run($this->request($this->staging), ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::FAILED, $outcome->status);
    }

    public function testTamperedIndexIsCaughtByVerification(): void
    {
        $this->makeBackup();
        file_put_contents($this->backupDir . '/files.ndjson', "\n", FILE_APPEND);
        $outcome = (new RestoreEngine())->run($this->request($this->makeTempDir('t')), ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::FAILED, $outcome->status);
        self::assertStringContainsString('verification failed', $outcome->messages[0]);
    }

    public function testZipSlipPathsInIndexNeverEscapeTheTarget(): void
    {
        $this->makeBackup();
        $this->tamperIndex(static function (array $entry): array {
            if ($entry['p'] === 'index.php') {
                $entry['p'] = '../escaped.php';
            }

            return $entry;
        });
        $parent = $this->makeTempDir('parent');
        mkdir($parent . '/target');
        $outcome = (new RestoreEngine())->run($this->request($parent . '/target', ['verifyFirst' => false]), ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::FAILED, $outcome->status);
        self::assertFileDoesNotExist($parent . '/escaped.php');
        self::assertFileDoesNotExist($parent . '/target/../escaped.php');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function maliciousPaths(): array
    {
        return ['absolute' => ['/tmp/swbi-pwned.php'], 'backslash' => ['..\\..\\pwned.php'], 'nul' => ["a\0.php"], 'dotdot mid' => ['a/../../pwned.php']];
    }

    /**
     * @dataProvider maliciousPaths
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('maliciousPaths')]
    public function testMaliciousIndexPathsAreRejected(string $evil): void
    {
        $this->makeBackup();
        $this->tamperIndex(static function (array $entry) use ($evil): array {
            if ($entry['p'] === 'index.php') {
                $entry['p'] = $evil;
            }

            return $entry;
        });
        $target = $this->makeTempDir('t');
        $outcome = (new RestoreEngine())->run($this->request($target, ['verifyFirst' => false]), ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::FAILED, $outcome->status);
        self::assertFileDoesNotExist('/tmp/swbi-pwned.php');
    }

    public function testExistingSymlinkInTargetIsNeverFollowed(): void
    {
        $this->makeBackup();
        $target = $this->makeTempDir('target');
        $outside = $this->makeTempDir('outside');
        symlink($outside, $target . '/wp-content');

        $outcome = (new RestoreEngine())->run($this->request($target), ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::FAILED, $outcome->status);
        self::assertSame([], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])), 'nothing was written through the symlink');
    }

    public function testSymlinksFromBackupAreNotRecreated(): void
    {
        $runner = $this->setUpFilesBackup();
        symlink('/etc/passwd', $this->site . '/evil-link');
        $job = $this->newJob();
        $runner->runToCompletion($job->id, 5.0);
        $this->backupDir = $this->backupDir($job);
        $target = $this->makeTempDir('target');
        $outcome = (new RestoreEngine())->run($this->request($target), ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::COMPLETED, $outcome->status, implode(';', $outcome->messages));
        self::assertFalse(is_link($target . '/evil-link'));
        self::assertFileDoesNotExist($target . '/evil-link');
    }

    public function testZipBombRatioIsRefused(): void
    {
        $runner = $this->setUpFilesBackup();
        file_put_contents($this->site . '/zeros.bin', str_repeat("\0", 4 * 1048576));
        $job = $this->newJob();
        $runner->runToCompletion($job->id, 5.0);
        $this->backupDir = $this->backupDir($job);
        $target = $this->makeTempDir('target');
        $outcome = (new RestoreEngine())->run($this->request($target, ['limits' => new RestoreLimits(maxCompressionRatio: 50.0)]), ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::FAILED, $outcome->status);
        self::assertStringContainsString('compression ratio', implode(' ', $outcome->messages));
        self::assertFileDoesNotExist($target . '/zeros.bin');
        self::assertSame([], glob($target . '/.swbi-*') ?: [], 'no temp files left behind');
    }

    public function testSizeLimitsAreEnforced(): void
    {
        $this->makeBackup();
        $outcome = (new RestoreEngine())->run($this->request($this->makeTempDir('t'), ['limits' => new RestoreLimits(maxTotalBytes: 1000)]), ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::FAILED, $outcome->status);
    }

    public function testOverwriteModesAndSelectiveRestore(): void
    {
        $this->makeBackup();
        $target = $this->makeTempDir('target');
        mkdir($target . '/wp-content/uploads', 0775, true);
        file_put_contents($target . '/index.php', 'LOCAL CHANGE');
        file_put_contents($target . '/wp-content/uploads/binary.bin', 'LOCAL');

        $never = (new RestoreEngine())->run($this->request($target, ['overwrite' => OverwriteMode::Never, 'selection' => new Selection(['index.php', 'wp-content/uploads/many'])]), ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::COMPLETED, $never->status, implode(';', $never->messages));
        self::assertSame('LOCAL CHANGE', file_get_contents($target . '/index.php'));
        self::assertFileExists($target . '/wp-content/uploads/many/f1.txt');
        self::assertFileDoesNotExist($target . '/wp-config.php', 'selective restore only touches the selection');

        $always = (new RestoreEngine())->run($this->request($target, ['overwrite' => OverwriteMode::Always, 'selection' => new Selection(['index.php'])]), ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::COMPLETED, $always->status);
        self::assertSame("<?php\n// front controller\n", file_get_contents($target . '/index.php'));

        $dry = (new RestoreEngine())->run($this->request($target, ['dryRun' => true, 'selection' => new Selection(['wp-content/uploads/binary.bin']), 'overwrite' => OverwriteMode::Always]), ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::COMPLETED, $dry->status);
        self::assertSame('LOCAL', file_get_contents($target . '/wp-content/uploads/binary.bin'), 'dry run changes nothing');
    }

    public function testRestoreResumesAfterBudgetExhaustion(): void
    {
        $this->makeBackup();
        $target = $this->makeTempDir('target');
        $request = $this->request($target);
        $engine = new RestoreEngine();
        $rounds = 0;
        do {
            $outcome = $engine->run($request, new ResourceBudget(0.0));
            $rounds++;
        } while ($outcome->status === RestoreOutcome::CONTINUE && $rounds < 100);
        self::assertSame(RestoreOutcome::COMPLETED, $outcome->status, implode(';', $outcome->messages));
        self::assertGreaterThan(1, $rounds);
        $expected = $this->snapshotTree($this->site);
        self::assertSame($expected, $this->snapshotTree($target));
    }

    public function testMissingVolumeAndWrongKeyScenariosFailLoudly(): void
    {
        $this->makeBackup();
        unlink($this->backupDir . '/volumes/backup-002.zip');
        $report = (new Verifier())->verify(BackupSet::open($this->backupDir));
        self::assertFalse($report->isOk());
        $outcome = (new RestoreEngine())->run($this->request($this->makeTempDir('t')), ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::FAILED, $outcome->status);
    }
}
