<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\E2e;

use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Backup\BackupRunner;
use SWBackupIt\Core\Backup\BackupRunnerFactory;
use SWBackupIt\Core\Backup\JobFactory;
use SWBackupIt\Core\Backup\SourceInfo;
use SWBackupIt\Core\Backup\StaticSourceInfoProvider;
use SWBackupIt\Core\Crypto\EnvironmentKeyProvider;
use SWBackupIt\Core\Crypto\StaticKeyProvider;
use SWBackupIt\Core\Database\ConnectionFactory;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Event\EventDispatcher;
use SWBackupIt\Core\Job\FileJobRepository;
use SWBackupIt\Core\Log\FileJobEventStore;
use SWBackupIt\Core\Retry\RetryPolicy;
use SWBackupIt\Core\Storage\ArrayStorageResolver;
use SWBackupIt\Core\Storage\BackupDownloader;
use SWBackupIt\Core\Storage\RemoteCatalog;
use SWBackupIt\Core\Storage\StorageFactory;
use SWBackupIt\Core\Storage\StorageManager;
use SWBackupIt\Core\Storage\StorageTarget;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\Restore\RestoreEngine;
use SWBackupIt\Restore\RestoreOutcome;
use SWBackupIt\Restore\RestoreRequest;
use SWBackupIt\Tests\Support\MutableClock;
use SWBackupIt\Tests\Support\SiteFixture;
use SWBackupIt\Tests\Support\TempDir;
use SWBackupIt\Tests\Support\TestDatabase;

final class EncryptedStorageBackupTest extends TestCase
{
    use SiteFixture;
    use TempDir;
    use TestDatabase;

    private string $site = '';
    private string $staging = '';
    private string $jobsDir = '';
    private string $remoteDir = '';
    private FileJobRepository $repository;
    private MutableClock $clock;
    private string $s3Bucket = '';

    /**
     * @param list<StorageTarget> $targets
     */
    private function runner(array $targets): BackupRunner
    {
        $config = $this->createDatabase();
        $pdo = ConnectionFactory::create($config);
        $this->seedSiteDatabase($pdo);
        $this->site = $this->makeTempDir('site');
        $this->buildSiteFiles($this->site);
        $work = $this->makeTempDir('work');
        $this->staging = $work . '/staging';
        $this->jobsDir = $work . '/jobs';
        $this->clock = new MutableClock();
        $this->repository = new FileJobRepository($this->jobsDir);
        $source = new SourceInfo($this->site, $this->staging, 'https://old.example.com', 'https://old.example.com', '6.8', 'wp_', $config);

        return new BackupRunner(
            $this->repository,
            new FileJobEventStore($this->jobsDir),
            new StaticSourceInfoProvider($source),
            BackupRunnerFactory::defaultSteps(),
            new EventDispatcher(),
            $this->clock,
            'info',
            new EnvironmentKeyProvider(['prod-key' => 'a very long test passphrase']),
            new StorageManager(new ArrayStorageResolver($targets), new StorageFactory(new RetryPolicy(1, 1, 1))),
        );
    }

    /**
     * @return array{StorageTarget, StorageTarget}
     */
    private function targets(): array
    {
        $this->remoteDir = $this->makeTempDir('remote');
        $local = new StorageTarget(1, 'Local replica', 'local', ['path' => $this->remoteDir]);
        $port = getenv('SWBI_TEST_S3_PORT') ?: '33099';
        if (@fsockopen('127.0.0.1', (int) $port, $errno, $error, 0.3) === false) {
            return [$local, new StorageTarget(2, 'Second local', 'local', ['path' => $this->makeTempDir('remote2')])];
        }
        $this->s3Bucket = 'swbi-e2e-' . bin2hex(random_bytes(3));
        $endpoint = "http://127.0.0.1:{$port}";
        (new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $endpoint, 'use_path_style_endpoint' => true, 'credentials' => ['key' => 'k', 'secret' => 's']]))
            ->createBucket(['Bucket' => $this->s3Bucket]);

        return [$local, new StorageTarget(2, 'S3', 's3', ['endpoint' => $endpoint, 'bucket' => $this->s3Bucket, 'prefix' => 'wp', 'path_style' => true, 'allow_private_network' => true], ['access_key' => 'k', 'secret_key' => 's'])];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function profile(array $overrides = []): BackupProfile
    {
        return BackupProfile::fromArray($overrides + [
            'id' => 1, 'name' => 'Encrypted', 'type' => 'full', 'volume_size_bytes' => 250000,
            'limits' => ['time_budget_seconds' => 5, 'batch_rows' => 50, 'database_part_bytes' => 8192],
            'encryption' => ['enabled' => true, 'key_id' => 'prod-key'], 'storage_target_ids' => [1, 2], 'success_policy' => 'all',
        ]);
    }

    public function testEncryptedBackupReplicatedToTwoTargetsAndRestoredFromEachSource(): void
    {
        [$a, $b] = $this->targets();
        $runner = $this->runner([$a, $b]);
        $job = (new JobFactory($this->clock))->create($this->profile());
        $this->repository->create($job);
        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 5.0), json_encode($this->repository->find($job->id)->error) ?: '');

        $dir = $this->staging . '/' . $job->backupId;
        self::assertSame([], glob($dir . '/volumes/*.zip') ?: [], 'no plain volume remains');
        self::assertNotEmpty(glob($dir . '/volumes/*.zip.swbe'));
        self::assertNotEmpty(glob($dir . '/database/*.sql.gz.swbe'));
        self::assertFileExists($dir . '/files.ndjson.swbe');
        self::assertFileDoesNotExist($dir . '/files.ndjson');
        $manifestJson = (string) file_get_contents($dir . '/manifest.json');
        self::assertStringContainsString('"key_id": "prod-key"', $manifestJson);
        self::assertStringNotContainsString('a very long test passphrase', $manifestJson);
        self::assertStringNotContainsString('wp-config.php', (string) file_get_contents($dir . '/files.ndjson.swbe'), 'file names are not readable');

        // Integrity can be verified without any key; the deep check needs the right key.
        self::assertTrue((new Verifier())->verify(BackupSet::open($dir))->isOk());
        $deep = (new Verifier())->verify(BackupSet::open($dir, new StaticKeyProvider('a very long test passphrase'), $this->makeTempDir('dec')), true);
        self::assertTrue($deep->isOk(), implode('; ', $deep->errors()));
        $wrong = (new Verifier())->verify(BackupSet::open($dir, new StaticKeyProvider('this is the wrong passphrase'), $this->makeTempDir('dec')), true);
        self::assertFalse($wrong->isOk());
        self::assertStringContainsString('wrong key', implode(' ', $wrong->errors()));

        // Both replicas hold the verified copy.
        $state = $this->repository->find($job->id)->checkpoint(\SWBackupIt\Core\Domain\Step::VerifyRemote);
        self::assertSame('verified', $state['targets']['1']['status']);
        self::assertSame('verified', $state['targets']['2']['status']);
        $manager = new StorageManager(new ArrayStorageResolver([$a, $b]));
        $discovered = (new RemoteCatalog())->discover($manager->remote(2), $this->makeTempDir('disc'));
        self::assertSame($job->backupId, $discovered[0]['backup_id']);
        self::assertNotNull($discovered[0]['manifest']);

        // Disaster: production is gone, local staging is gone. Restore from the remote replica with the key.
        $expectedTree = $this->snapshotTree($this->site);
        foreach ([1, 2] as $targetId) {
            $downloaded = (new BackupDownloader())->download($manager->remote($targetId), $job->backupId, $this->makeTempDir('dl'));
            $targetDb = $this->createDatabase();
            $target = $this->makeTempDir('target');
            $request = new RestoreRequest(
                $downloaded,
                $target,
                (string) realpath($target),
                $this->makeTempDir('state'),
                database: $targetDb,
                confirmedDatabase: $targetDb->name,
                keys: new StaticKeyProvider('a very long test passphrase'),
            );
            $outcome = (new RestoreEngine())->run($request, ResourceBudget::unlimited());
            self::assertSame(RestoreOutcome::COMPLETED, $outcome->status, implode('; ', $outcome->messages));
            $restored = $this->snapshotTree($target);
            unset($expectedTree['wp-config.php'], $restored['wp-config.php']);
            self::assertSame($expectedTree, $restored, "restore from target $targetId");
            self::assertSame('Post 0 😀', (string) ConnectionFactory::create($targetDb)->query('SELECT post_title FROM wp_posts ORDER BY ID LIMIT 1')->fetchColumn());
            $expectedTree = $this->snapshotTree($this->site);
        }
    }

    public function testWrongOrMissingKeyNeverWritesFiles(): void
    {
        [$a, $b] = $this->targets();
        $runner = $this->runner([$a, $b]);
        $job = (new JobFactory($this->clock))->create($this->profile(['storage_target_ids' => []]));
        $this->repository->create($job);
        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 5.0));
        $dir = $this->staging . '/' . $job->backupId;
        foreach (['wrong' => new StaticKeyProvider('definitely the wrong passphrase'), 'none' => null] as $name => $keys) {
            $target = $this->makeTempDir('target');
            $request = new RestoreRequest($dir, $target, (string) realpath($target), $this->makeTempDir('state'), restoreDatabase: false, keys: $keys);
            $outcome = (new RestoreEngine())->run($request, ResourceBudget::unlimited());
            self::assertSame(RestoreOutcome::FAILED, $outcome->status, $name);
            self::assertSame([], array_values(array_diff(scandir($target) ?: [], ['.', '..'])), "$name: nothing written");
        }
    }

    public function testMissingEncryptionKeyFailsPreflightOfEncryptStepWithoutLeakingPlainData(): void
    {
        $runner = $this->runner([]);
        $job = (new JobFactory($this->clock))->create($this->profile(['storage_target_ids' => [], 'encryption' => ['enabled' => true, 'key_id' => 'unknown-key']]));
        $this->repository->create($job);
        self::assertSame(JobStatus::Failed, $runner->runToCompletion($job->id, 5.0));
        self::assertStringContainsString('unknown-key', (string) $this->repository->find($job->id)->error['message']);
    }

    public function testFailingTargetDoesNotHarmOtherReplicaAndPolicyDecidesOutcome(): void
    {
        $this->remoteDir = $this->makeTempDir('remote');
        $good = new StorageTarget(1, 'good', 'local', ['path' => $this->remoteDir]);
        $bad = new StorageTarget(2, 'bad', 'local', ['path' => '/proc/swbi-not-writable']);

        // Policy "all": the job fails, but the verified replica on the good target stays intact.
        $runner = $this->runner([$good, $bad]);
        $job = (new JobFactory($this->clock))->create($this->profile(['encryption' => ['enabled' => false], 'storage_target_ids' => [1, 2], 'success_policy' => 'all', 'type' => 'files']));
        $this->repository->create($job);
        self::assertSame(JobStatus::Failed, $runner->runToCompletion($job->id, 5.0));
        self::assertStringContainsString('Success policy', (string) $this->repository->find($job->id)->error['message']);
        self::assertFileExists($this->remoteDir . '/' . $job->backupId . '/manifest.json');
        self::assertTrue((new Verifier())->verify(BackupSet::open($this->remoteDir . '/' . $job->backupId))->isOk());

        // Policy "any": same situation is a success with a warning.
        $runner = $this->runner([$good, $bad]);
        $job = (new JobFactory($this->clock))->create($this->profile(['encryption' => ['enabled' => false], 'storage_target_ids' => [1, 2], 'success_policy' => 'any', 'type' => 'files']));
        $this->repository->create($job);
        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 5.0));
        self::assertGreaterThan(0, $this->repository->find($job->id)->warningsCount);
    }

    public function testTransientUploadFailureResumesAndSucceedsAfterRetry(): void
    {
        $this->remoteDir = $this->makeTempDir('remote');
        $target = new StorageTarget(1, 'flaky', 'local', ['path' => $this->remoteDir]);
        $runner = $this->runner([$target]);
        $job = (new JobFactory($this->clock))->create($this->profile(['encryption' => ['enabled' => false], 'storage_target_ids' => [1], 'type' => 'files']));
        $this->repository->create($job);
        // Make the storage root read-only so the first attempts fail transiently, then repair it.
        chmod($this->remoteDir, 0500);
        $status = JobStatus::Pending;
        for ($i = 0; $i < 200; $i++) {
            $status = $runner->tick($job->id, 5.0)->status;
            if ($status === JobStatus::RetryWaiting) {
                break;
            }
        }
        if ($status !== JobStatus::RetryWaiting && posix_geteuid() === 0) {
            chmod($this->remoteDir, 0700);
            self::markTestSkipped('Running as root: permission based failure cannot be simulated.');
        }
        self::assertSame(JobStatus::RetryWaiting, $status);
        chmod($this->remoteDir, 0700);
        $this->clock->advance(7200);
        $final = $runner->runToCompletion($job->id, 5.0);
        self::assertSame(JobStatus::Completed, $final, json_encode($this->repository->find($job->id)->error) ?: '');
        self::assertFileExists($this->remoteDir . '/' . $job->backupId . '/manifest.json');
    }
}
