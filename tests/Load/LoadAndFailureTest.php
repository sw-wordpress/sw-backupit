<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Load;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Backup\BackupRunnerFactory;
use SWBackupIt\Core\Backup\JobFactory;
use SWBackupIt\Core\Backup\SourceInfo;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Job\FileJobRepository;
use SWBackupIt\Core\Scanner\FileIndexWriter;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\Restore\RestoreEngine;
use SWBackupIt\Restore\RestoreOutcome;
use SWBackupIt\Restore\RestoreRequest;
use SWBackupIt\Tests\Support\TempDir;

/**
 * Load and failure tests (run with: vendor/bin/phpunit --testsuite load). They take a while and are not part of the default suite.
 */
final class LoadAndFailureTest extends TestCase
{
    use TempDir;

    /**
     * @return array{string, string, string, FileJobRepository}
     */
    private function environment(): array
    {
        $site = $this->makeTempDir('site');
        $work = $this->makeTempDir('work');

        return [$site, $work . '/staging', $work . '/jobs', new FileJobRepository($work . '/jobs')];
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function backup(string $site, string $staging, string $jobs, FileJobRepository $repo, array $profile): string
    {
        $runner = BackupRunnerFactory::createFileBased($jobs, new SourceInfo($site, $staging));
        $job = (new JobFactory())->create(BackupProfile::fromArray($profile + ['id' => 1, 'name' => 'Load', 'type' => 'files', 'limits' => ['time_budget_seconds' => 10]]));
        $repo->create($job);
        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 10.0), json_encode($repo->find($job->id)->error) ?: '');

        return $job->backupId;
    }

    public function testTwentyThousandSmallFilesStayWithinMemoryBounds(): void
    {
        [$site, $staging, $jobs, $repo] = $this->environment();
        for ($d = 0; $d < 100; $d++) {
            mkdir($site . "/d{$d}", 0775, true);
            for ($f = 0; $f < 200; $f++) {
                file_put_contents($site . "/d{$d}/file{$f}.txt", "content {$d} {$f}\n");
            }
        }
        $before = memory_get_peak_usage(true);
        $id = $this->backup($site, $staging, $jobs, $repo, ['volume_size_bytes' => 2097152]);
        $peak = memory_get_peak_usage(true) - $before;
        self::assertLessThan(48 * 1048576, $peak, 'memory growth while backing up 20,000 files: ' . $peak);
        $set = BackupSet::open($staging . '/' . $id);
        self::assertSame(20100, (int) $set->manifest()->section('files')['count'], '20,000 files + 100 directories + root level');
        self::assertTrue((new Verifier())->verify($set)->isOk());

        $target = $this->makeTempDir('target');
        $engine = new RestoreEngine();
        $request = new RestoreRequest($staging . '/' . $id, $target, (string) realpath($target), $this->makeTempDir('state'), restoreDatabase: false);
        do {
            $outcome = $engine->run($request, new ResourceBudget(5.0));
        } while ($outcome->status === RestoreOutcome::CONTINUE);
        self::assertSame(RestoreOutcome::COMPLETED, $outcome->status, implode(';', $outcome->messages));
        self::assertSame('content 99 199' . "\n", file_get_contents($target . '/d99/file199.txt'));
    }

    public function testSingleHugeFileIsChunkedAndRestoredWithConstantMemory(): void
    {
        [$site, $staging, $jobs, $repo] = $this->environment();
        $size = 300 * 1048576;
        $handle = fopen($site . '/huge.bin', 'wb');
        fseek($handle, $size - 5);
        fwrite($handle, 'END!!');
        fclose($handle);
        $hash = hash_file('sha256', $site . '/huge.bin');
        $before = memory_get_peak_usage(true);
        $id = $this->backup($site, $staging, $jobs, $repo, ['volume_size_bytes' => 64 * 1048576]);
        self::assertLessThan(48 * 1048576, memory_get_peak_usage(true) - $before, 'a 300 MB file must not be loaded into memory');
        $set = BackupSet::open($staging . '/' . $id);
        self::assertGreaterThanOrEqual(5, count($set->manifest()->volumes()), 'split into volumes of at most 64 MB');
        self::assertTrue((new Verifier())->verify($set, true)->isOk());
        $target = $this->makeTempDir('target');
        $request = new RestoreRequest($staging . '/' . $id, $target, (string) realpath($target), $this->makeTempDir('state'), restoreDatabase: false);
        self::assertSame(RestoreOutcome::COMPLETED, (new RestoreEngine())->run($request, ResourceBudget::unlimited())->status);
        self::assertSame($hash, hash_file('sha256', $target . '/huge.bin'));
    }

    public function testConcurrentWorkersNeverRunTheSameJobTwice(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required.');
        }
        [$site, $staging, $jobs, $repo] = $this->environment();
        for ($i = 0; $i < 300; $i++) {
            file_put_contents($site . "/f{$i}.txt", str_repeat("line {$i}\n", 200));
        }
        $runner = BackupRunnerFactory::createFileBased($jobs, new SourceInfo($site, $staging));
        $job = (new JobFactory())->create(BackupProfile::fromArray(['id' => 1, 'name' => 'Race', 'type' => 'files', 'volume_size_bytes' => 100000, 'limits' => ['time_budget_seconds' => 1]]));
        $repo->create($job);

        $children = [];
        for ($worker = 0; $worker < 4; $worker++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                // Child: hammer the job with ticks until it is finished.
                $child = BackupRunnerFactory::createFileBased($jobs, new SourceInfo($site, $staging));
                for ($i = 0; $i < 4000; $i++) {
                    $result = $child->tick($job->id, 0.05);
                    if ($result->status->isTerminal()) {
                        break;
                    }
                    usleep(1000);
                }
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        unset($runner);
        $final = $repo->find($job->id);
        self::assertSame(JobStatus::Completed, $final->status, json_encode($final->error) ?: '');
        $set = BackupSet::open($staging . '/' . $job->backupId);
        $report = (new Verifier())->verify($set, true);
        self::assertTrue($report->isOk(), implode(';', $report->errors()));
        self::assertNull($final->leaseOwner);
        self::assertSame([], glob($staging . '/' . $job->backupId . '/volumes/*.part') ?: [], 'no torn volumes left behind');
    }

    public function testFullDiskIsReportedAsRetryableError(): void
    {
        if (!is_writable('/dev/full')) {
            self::markTestSkipped('/dev/full is not available.');
        }
        $writer = new FileIndexWriter('/dev/full');
        $this->expectException(SwbiException::class);
        try {
            for ($i = 0; $i < 20000; $i++) {
                $writer->append(['p' => str_repeat('x', 200) . $i, 't' => 'f']);
            }
            $writer->commit();
        } catch (SwbiException $exception) {
            self::assertTrue($exception->isRetryable() || str_contains($exception->getMessage(), 'disk'));
            throw $exception;
        }
    }
}
