<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\E2e;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Backup\BackupRunner;
use SWBackupIt\Core\Backup\BackupRunnerFactory;
use SWBackupIt\Core\Backup\JobFactory;
use SWBackupIt\Core\Backup\SourceInfo;
use SWBackupIt\Core\Backup\StaticSourceInfoProvider;
use SWBackupIt\Core\Crypto\EnvironmentKeyProvider;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Event\EventDispatcher;
use SWBackupIt\Core\Job\FileJobRepository;
use SWBackupIt\Core\Log\FileJobEventStore;
use SWBackupIt\Core\Retention\DirectoryBackupCatalog;
use SWBackupIt\Core\Retention\RetentionPlanner;
use SWBackupIt\Core\Retention\RetentionPolicy;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Core\Support\SystemClock;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\Restore\RestoreEngine;
use SWBackupIt\Restore\RestoreOutcome;
use SWBackupIt\Restore\RestoreRequest;
use SWBackupIt\Tests\Support\SiteFixture;
use SWBackupIt\Tests\Support\TempDir;

final class IncrementalChainTest extends TestCase
{
    use SiteFixture;
    use TempDir;
    use \SWBackupIt\Tests\Support\TestDatabase;

    private string $site = '';
    private string $staging = '';
    private FileJobRepository $repository;
    private BackupRunner $runner;

    protected function setUp(): void
    {
        $config = $this->createDatabase();
        $this->seedSiteDatabase(\SWBackupIt\Core\Database\ConnectionFactory::create($config));
        $this->site = $this->makeTempDir('site');
        $this->buildSiteFiles($this->site);
        $work = $this->makeTempDir('work');
        $this->staging = $work . '/staging';
        $this->repository = new FileJobRepository($work . '/jobs');
        $this->runner = new BackupRunner(
            $this->repository,
            new FileJobEventStore($work . '/jobs'),
            new StaticSourceInfoProvider(new SourceInfo($this->site, $this->staging, 'https://x.test', 'https://x.test', '6.8', 'wp_', $config)),
            BackupRunnerFactory::defaultSteps(),
            new EventDispatcher(),
            new SystemClock(),
            'info',
            new EnvironmentKeyProvider(['chain-key' => 'chain passphrase long enough']),
            null,
            new DirectoryBackupCatalog($this->staging),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function backup(string $type, array $overrides = []): string
    {
        $profile = BackupProfile::fromArray($overrides + ['id' => 5, 'name' => 'Chain', 'type' => $type, 'volume_size_bytes' => 250000, 'limits' => ['time_budget_seconds' => 5]]);
        $job = (new JobFactory())->create($profile);
        $this->repository->create($job);
        self::assertSame(JobStatus::Completed, $this->runner->runToCompletion($job->id, 5.0), json_encode($this->repository->find($job->id)->error) ?: '');
        // Make manifests distinguishable in time (retention and base selection order by creation time).
        $manifestFile = $this->staging . '/' . $job->backupId . '/manifest.json';
        $data = json_decode((string) file_get_contents($manifestFile), true);
        $data['created_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - (10 - count(glob($this->staging . '/*', GLOB_ONLYDIR) ?: [])) * 3600);
        file_put_contents($manifestFile, json_encode($data));

        return $job->backupId;
    }

    private function restoreTo(string $backupId, ?\SWBackupIt\Core\Crypto\KeyProviderInterface $keys = null): string
    {
        $target = $this->makeTempDir('target');
        $request = new RestoreRequest($this->staging . '/' . $backupId, $target, (string) realpath($target), $this->makeTempDir('state'), restoreDatabase: false, keys: $keys);
        $outcome = (new RestoreEngine())->run($request, ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::COMPLETED, $outcome->status, implode('; ', $outcome->messages));

        return $target;
    }

    public function testIncrementalAndDifferentialChainsRestoreEveryPointInTime(): void
    {
        $full = $this->backup('full');
        $stateFull = $this->snapshotTree($this->site);

        // Change set 1: modify, add, delete.
        file_put_contents($this->site . '/wp-content/uploads/many/f3.txt', 'changed content 3');
        file_put_contents($this->site . '/wp-content/uploads/new-1.txt', 'new one');
        unlink($this->site . '/wp-content/uploads/many/f4.txt');
        $inc1 = $this->backup('incremental');
        $stateInc1 = $this->snapshotTree($this->site);

        // Change set 2: modify the big (chunked) file, rename one file.
        file_put_contents($this->site . '/wp-content/uploads/big.dat', random_bytes(500000) . 'tail');
        rename($this->site . '/wp-content/uploads/many/f5.txt', $this->site . '/wp-content/uploads/many/f5-renamed.txt');
        $inc2 = $this->backup('incremental');
        $stateInc2 = $this->snapshotTree($this->site);

        $diff = $this->backup('differential');
        $stateDiff = $this->snapshotTree($this->site);

        $manifest = static fn (string $id): BackupSet => BackupSet::open($GLOBALS['__staging'] . '/' . $id);
        $GLOBALS['__staging'] = $this->staging;
        $m1 = $manifest($inc1)->manifest();
        $m2 = $manifest($inc2)->manifest();
        $md = $manifest($diff)->manifest();
        self::assertSame('incremental', $m1->type()->value);
        self::assertSame($full, $m1->toArray()['parent']['backup_id']);
        self::assertSame($inc1, $m2->toArray()['parent']['backup_id']);
        self::assertSame($full, $md->toArray()['parent']['backup_id'], 'differential is based on the last FULL backup');
        self::assertContains($full, $m2->requires());
        $volumeBytes = static fn (\SWBackupIt\Core\Manifest\Manifest $m): int => array_sum(array_map(static fn ($v): int => $v->size, $m->volumes()));
        self::assertLessThan($volumeBytes($manifest($full)->manifest()) / 2, $volumeBytes($m1), 'incremental stores only changed files');

        foreach ([[$full, $stateFull], [$inc1, $stateInc1], [$inc2, $stateInc2], [$diff, $stateDiff]] as [$id, $expected]) {
            $report = (new Verifier())->verify(BackupSet::open($this->staging . '/' . $id), true);
            self::assertTrue($report->isOk(), implode('; ', $report->errors()));
            self::assertSame($expected, $this->snapshotTree($this->restoreTo($id)), "restore of $id reproduces its point in time (incl. deletions)");
        }
        self::assertArrayNotHasKey('wp-content/uploads/many/f4.txt', $this->snapshotTree($this->restoreTo($inc1)));
    }

    public function testRetentionNeverDeletesTheBaseOfKeptBackups(): void
    {
        $full = $this->backup('full');
        file_put_contents($this->site . '/wp-content/uploads/a.txt', 'a');
        $inc1 = $this->backup('incremental');
        file_put_contents($this->site . '/wp-content/uploads/b.txt', 'b');
        $inc2 = $this->backup('incremental');
        $catalog = new DirectoryBackupCatalog($this->staging);
        $plan = (new RetentionPlanner())->plan($catalog->records(5), new RetentionPolicy(maxCount: 1), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        self::assertSame([], $plan->deletions(), 'inc2 is kept by count and needs inc1 and the full backup');
        self::assertContains($full, array_keys($plan->keep));
        self::assertContains($inc1, array_keys($plan->keep));
        self::assertContains($inc2, array_keys($plan->keep));
    }

    public function testMissingBaseBackupIsDetectedAndNothingIsWrittenOnRestore(): void
    {
        $full = $this->backup('full');
        file_put_contents($this->site . '/wp-content/uploads/a.txt', 'a');
        $inc = $this->backup('incremental');
        \SWBackupIt\Core\Support\AtomicFile::removeTree($this->staging . '/' . $full);
        $report = (new Verifier())->verify(BackupSet::open($this->staging . '/' . $inc));
        self::assertFalse($report->isOk());
        self::assertStringContainsString('base backup', strtolower(implode(' ', $report->errors())));

        $target = $this->makeTempDir('target');
        $request = new RestoreRequest($this->staging . '/' . $inc, $target, (string) realpath($target), $this->makeTempDir('state'), restoreDatabase: false);
        $outcome = (new RestoreEngine())->run($request, ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::FAILED, $outcome->status);
        self::assertSame([], array_values(array_diff(scandir($target) ?: [], ['.', '..'])));
    }

    public function testIncrementalWithoutBaseFallsBackToFullAndEncryptedChainWorks(): void
    {
        $first = $this->backup('incremental', ['encryption' => ['enabled' => true, 'key_id' => 'chain-key']]);
        self::assertSame('full', BackupSet::open($this->staging . '/' . $first)->manifest()->type()->value, 'no base => full backup');
        file_put_contents($this->site . '/wp-content/uploads/enc-change.txt', 'changed');
        $second = $this->backup('incremental', ['encryption' => ['enabled' => true, 'key_id' => 'chain-key']]);
        $manifest = BackupSet::open($this->staging . '/' . $second)->manifest();
        self::assertSame('incremental', $manifest->type()->value);
        self::assertSame([$first], $manifest->requires());
        $keys = new EnvironmentKeyProvider(['chain-key' => 'chain passphrase long enough']);
        self::assertSame($this->snapshotTree($this->site), $this->snapshotTree($this->restoreTo($second, $keys)));
    }

    public function testUnchangedRunProducesNoNewVolumes(): void
    {
        $this->backup('full');
        $inc = $this->backup('incremental');
        $manifest = BackupSet::open($this->staging . '/' . $inc)->manifest();
        self::assertSame([], $manifest->volumes());
        self::assertSame($this->snapshotTree($this->site), $this->snapshotTree($this->restoreTo($inc)));
    }
}
