<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\Restore\RestoreEngine;
use SWBackupIt\Restore\RestoreOutcome;
use SWBackupIt\Restore\RestoreRequest;
use SWBackupIt\Tests\Support\FilesBackupFixture;

/**
 * Regression: a large (chunked) file that changes between two chunks used to be stored torn with status "ok", so the backup
 * reported no warning and incremental backups kept reusing the torn data; a file that shrank could not be restored at all.
 */
final class ChunkConsistencyTest extends TestCase
{
    use FilesBackupFixture;

    /** @return array<string, array{string}> */
    public static function modifications(): array
    {
        return ['rewritten in place' => ['modify'], 'truncated' => ['shrink']];
    }

    #[DataProvider('modifications')]
    public function testFileChangingBetweenChunksIsFlaggedAndStaysRestorable(string $mode): void
    {
        $runner = $this->setUpFilesBackup(['volume_size_bytes' => 100000, 'compression' => 'none']);
        $big = $this->site . '/big.dat';
        file_put_contents($big, str_repeat('A', 300000));
        touch($big, time() - 100);
        $job = $this->newJob();

        $modified = false;
        for ($i = 0; $i < 500; $i++) {
            $result = $runner->tick($job->id, 0.0);
            $chunk = $this->repository->find($job->id)?->checkpoint(Step::Archive)['chunk'] ?? null;
            if (!$modified && is_array($chunk) && $chunk['next'] === 1) {
                if ($mode === 'modify') {
                    $handle = fopen($big, 'r+');
                    self::assertIsResource($handle);
                    fwrite($handle, str_repeat('B', 300000));
                    fclose($handle);
                } else {
                    file_put_contents($big, str_repeat('B', 150000));
                }
                touch($big, time());
                $modified = true;
            }
            if (!$result->shouldContinue) {
                break;
            }
        }
        self::assertTrue($modified);
        $job = $this->repository->find($job->id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Completed, $job->status);
        self::assertGreaterThan(0, $job->warningsCount, 'the torn file is reported as a warning');

        $dir = $this->backupDir($job);
        $entry = null;
        foreach (BackupSet::open($dir)->indexReader()->read() as [$candidate]) {
            if ($candidate['p'] === 'big.dat') {
                $entry = $candidate;
            }
        }
        self::assertNotNull($entry);
        self::assertSame('changed', $entry['st'], 'never reused by incremental backups');
        self::assertTrue((new Verifier())->verify(BackupSet::open($dir), true)->isOk());

        $target = $this->makeTempDir('target');
        $outcome = (new RestoreEngine())->run(new RestoreRequest($dir, $target, (string) realpath($target), $this->makeTempDir('state'), restoreDatabase: false), ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::COMPLETED, $outcome->status, implode('; ', $outcome->messages));
        self::assertFileExists($target . '/big.dat');
        self::assertContains('  big.dat', $outcome->messages, 'the user is told that the file may be inconsistent');
    }
}
