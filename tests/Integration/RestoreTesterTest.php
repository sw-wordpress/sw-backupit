<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Backup\BackupRunnerFactory;
use SWBackupIt\Core\Backup\JobFactory;
use SWBackupIt\Core\Backup\SourceInfo;
use SWBackupIt\Core\Database\ConnectionConfig;
use SWBackupIt\Core\Database\ConnectionFactory;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Job\FileJobRepository;
use SWBackupIt\Restore\Testing\RestoreTester;
use SWBackupIt\Restore\Testing\RestoreTestResult;
use SWBackupIt\Tests\Support\SiteFixture;
use SWBackupIt\Tests\Support\TempDir;
use SWBackupIt\Tests\Support\TestDatabase;

final class RestoreTesterTest extends TestCase
{
    use SiteFixture;
    use TempDir;
    use TestDatabase;

    private ConnectionConfig $config;
    private string $backupDir = '';

    protected function setUp(): void
    {
        $site = $this->makeTempDir('site');
        $this->buildSiteFiles($site);
        $this->config = $this->createDatabase();
        $this->seedSiteDatabase(ConnectionFactory::create($this->config));
        $work = $this->makeTempDir('work');
        $runner = BackupRunnerFactory::createFileBased($work . '/jobs', new SourceInfo($site, $work . '/staging', 'https://old.test', 'https://old.test', '6.8', 'wp_', $this->config));
        $job = (new JobFactory())->create(BackupProfile::fromArray(['id' => 1, 'name' => 'T', 'type' => 'full', 'volume_size_bytes' => 400000, 'limits' => ['time_budget_seconds' => 5]]));
        (new FileJobRepository($work . '/jobs'))->create($job);
        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 5.0));
        $this->backupDir = $work . '/staging/' . $job->backupId;
    }

    private function scratchDatabases(): int
    {
        return (int) $this->adminPdo()->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'swbi\\_rt\\_%'")->fetchColumn();
    }

    public function testIsolatedRestoreTestPassesAndLeavesNothingBehind(): void
    {
        $productionRows = (string) ConnectionFactory::create($this->config)->query('SELECT COUNT(*) FROM wp_posts')->fetchColumn();
        $result = (new RestoreTester())->run($this->backupDir, null, $this->makeTempDir('rt'), $this->config);
        self::assertSame(RestoreTestResult::PASSED, $result->status(), json_encode($result->toArray()) ?: '');
        $names = array_column($result->checks, 'name');
        self::assertContains('files', $names);
        self::assertContains('database', $names);
        self::assertContains('wordpress', $names);
        self::assertSame(0, $this->scratchDatabases(), 'scratch database dropped');
        self::assertSame($productionRows, (string) ConnectionFactory::create($this->config)->query('SELECT COUNT(*) FROM wp_posts')->fetchColumn(), 'production database untouched');
    }

    public function testWithoutScratchPrivilegeTheResultIsPartialNotPassed(): void
    {
        $result = (new RestoreTester())->run($this->backupDir, null, $this->makeTempDir('rt'), null);
        self::assertSame(RestoreTestResult::PARTIAL, $result->status());
    }

    public function testCorruptBackupFailsTheTest(): void
    {
        file_put_contents($this->backupDir . '/volumes/backup-001.zip', 'broken', FILE_APPEND);
        $result = (new RestoreTester())->run($this->backupDir, null, $this->makeTempDir('rt'), $this->config);
        self::assertSame(RestoreTestResult::FAILED, $result->status());
        self::assertSame(0, $this->scratchDatabases());
    }
}
