<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Backup\BackupRunner;
use SWBackupIt\Core\Backup\BackupRunnerFactory;
use SWBackupIt\Core\Backup\JobFactory;
use SWBackupIt\Core\Backup\SourceInfo;
use SWBackupIt\Core\Backup\StaticSourceInfoProvider;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Event\EventDispatcher;
use SWBackupIt\Core\Job\FileJobRepository;
use SWBackupIt\Core\Log\FileJobEventStore;
use SWBackupIt\Core\Retention\DirectoryBackupCatalog;
use SWBackupIt\Core\Storage\ArrayStorageResolver;
use SWBackupIt\Core\Storage\StorageManager;
use SWBackupIt\Core\Storage\StorageTarget;
use SWBackupIt\Tests\Support\SiteFixture;
use SWBackupIt\Tests\Support\TempDir;

final class RetentionStepTest extends TestCase
{
    use SiteFixture;
    use TempDir;

    public function testRetentionDeletesOldBackupsLocallyAndOnRemoteButKeepsProtected(): void
    {
        $site = $this->makeTempDir('site');
        $this->createTree($site, ['index.php' => '<?php', 'wp-content/a.txt' => 'a']);
        $work = $this->makeTempDir('work');
        $staging = $work . '/staging';
        $remote = $this->makeTempDir('remote');
        $storage = new StorageManager(new ArrayStorageResolver([new StorageTarget(1, 'r', 'local', ['path' => $remote])]));
        $catalog = new DirectoryBackupCatalog($staging, [1 => $storage->remote(1)]);
        $repository = new FileJobRepository($work . '/jobs');
        $runner = new BackupRunner(
            $repository,
            new FileJobEventStore($work . '/jobs'),
            new StaticSourceInfoProvider(new SourceInfo($site, $staging)),
            BackupRunnerFactory::defaultSteps(),
            new EventDispatcher(),
            new \SWBackupIt\Core\Support\SystemClock(),
            'info',
            null,
            $storage,
            $catalog,
        );
        $profile = static fn (array $retention): BackupProfile => BackupProfile::fromArray([
            'id' => 7, 'name' => 'P', 'type' => 'files', 'storage_target_ids' => [1],
            'retention' => $retention, 'limits' => ['time_budget_seconds' => 5],
        ]);

        // Three backups without retention rule, aged 3, 2 and 1 days; the oldest one is protected.
        $ids = [];
        foreach ([3, 2, 1] as $daysAgo) {
            $job = (new JobFactory())->create($profile([]));
            $repository->create($job);
            self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 5.0));
            $ids[] = $job->backupId;
            $manifestFile = $staging . '/' . $job->backupId . '/manifest.json';
            $data = json_decode((string) file_get_contents($manifestFile), true);
            $data['created_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - $daysAgo * 86400);
            file_put_contents($manifestFile, json_encode($data));
        }
        touch($staging . '/' . $ids[0] . '/.protected');

        // The fourth backup applies "keep the newest 2".
        $job = (new JobFactory())->create($profile(['max_count' => 2]));
        $repository->create($job);
        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 5.0));
        $ids[] = $job->backupId;

        $left = array_map(static fn ($r) => $r->id, $catalog->records(7));
        sort($left);
        $expected = [$ids[0], $ids[2], $ids[3]]; // #1 protected, #3 and #4 newest two
        sort($expected);
        self::assertSame($expected, $left, 'backup #2 deleted; #1 protected; #3 and #4 are the newest two');
        self::assertDirectoryDoesNotExist($staging . '/' . $ids[1]);
        self::assertDirectoryDoesNotExist($remote . '/' . $ids[1], 'remote replica removed too');
        self::assertDirectoryExists($remote . '/' . $ids[0]);
    }
}
