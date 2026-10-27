<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\E2e;

use PDO;
use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Backup\BackupRunnerFactory;
use SWBackupIt\Core\Backup\JobFactory;
use SWBackupIt\Core\Backup\SourceInfo;
use SWBackupIt\Core\Database\ConnectionFactory;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\BackupType;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Job\FileJobRepository;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\Restore\RestoreEngine;
use SWBackupIt\Restore\RestoreOutcome;
use SWBackupIt\Restore\RestoreRequest;
use SWBackupIt\Tests\Support\SiteFixture;
use SWBackupIt\Tests\Support\TempDir;
use SWBackupIt\Tests\Support\TestDatabase;

final class BackupRestoreTest extends TestCase
{
    use SiteFixture;
    use TempDir;
    use TestDatabase;

    /**
     * @return array{profile: BackupProfile, source: SourceInfo, site: string, jobs: string, files: array<string, string>, pdo: PDO}
     */
    private function setUpSite(BackupType $type = BackupType::Full, bool $stagingInside = false): array
    {
        $site = $this->makeTempDir('site');
        $files = $this->buildSiteFiles($site);
        $config = $this->createDatabase();
        $pdo = ConnectionFactory::create($config);
        $this->seedSiteDatabase($pdo);
        $work = $this->makeTempDir('work');
        $staging = $stagingInside ? $site . '/wp-content/sw-backupit-staging' : $work . '/staging';
        $profile = BackupProfile::fromArray([
            'id' => 1, 'name' => 'Full', 'type' => $type->value,
            'volume_size_bytes' => 250000,
            'limits' => ['time_budget_seconds' => 1, 'batch_rows' => 25, 'database_part_bytes' => 4096],
            'exclude_patterns' => ['**/*.log'],
        ]);
        $source = new SourceInfo($site, $staging, 'https://old.example.com', 'https://old.example.com', '6.8', 'wp_', $config);

        return ['profile' => $profile, 'source' => $source, 'site' => $site, 'jobs' => $work . '/jobs', 'files' => $files, 'pdo' => $pdo];
    }

    private function runBackup(BackupProfile $profile, SourceInfo $source, string $jobsDir, float $budget = 0.0): array
    {
        $runner = BackupRunnerFactory::createFileBased($jobsDir, $source);
        $repository = new FileJobRepository($jobsDir);
        $job = (new JobFactory())->create($profile);
        $repository->create($job);
        $status = $runner->runToCompletion($job->id, $budget);

        return [$status, $repository->find($job->id), $job->backupId];
    }

    public function testFullBackupAndRestoreIntoFreshTargets(): void
    {
        $env = $this->setUpSite();
        [$status, $job, $backupId] = $this->runBackup($env['profile'], $env['source'], $env['jobs']);
        self::assertSame(JobStatus::Completed, $status, json_encode($job->error) ?: '');
        self::assertSame(100.0, $job->progress->percent(null, true));

        $backupDir = $env['source']->stagingPath . '/' . $backupId;
        self::assertFileExists($backupDir . '/manifest.json');
        self::assertDirectoryDoesNotExist($backupDir . '/work');
        $set = BackupSet::open($backupDir);
        self::assertGreaterThan(3, count($set->manifest()->volumes()), 'small volume size must produce several volumes');
        self::assertTrue((new Verifier())->verify($set)->isOk());
        $deep = (new Verifier())->verify($set, true);
        self::assertTrue($deep->isOk(), implode('; ', $deep->errors()));

        // Wipe the "production" system completely.
        $expectedTree = $this->snapshotTree($env['site']);
        $expectedDb = $this->dumpDatabase($env['pdo']);
        \SWBackupIt\Core\Support\AtomicFile::removeTree($env['site']);
        mkdir($env['site']);
        $env['pdo']->exec('DROP TABLE wp_options, wp_posts');

        // Restore with the standalone engine (no WordPress, no job repository).
        $targetDb = $this->createDatabase();
        $target = $this->makeTempDir('target');
        $request = new RestoreRequest(
            $backupDir,
            $target,
            (string) realpath($target),
            $this->makeTempDir('state'),
            database: $targetDb,
            confirmedDatabase: $targetDb->name,
        );
        $outcome = (new RestoreEngine())->run($request, ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::COMPLETED, $outcome->status, implode('; ', $outcome->messages));

        // wp-config.php is intentionally rewritten with the new database settings.
        $restoredTree = $this->snapshotTree($target);
        unset($expectedTree['wp-config.php'], $restoredTree['wp-config.php']);
        self::assertSame($expectedTree, $restoredTree);
        self::assertSame($expectedDb, $this->dumpDatabase(ConnectionFactory::create($targetDb)));
        $config = (string) file_get_contents($target . '/wp-config.php');
        self::assertStringContainsString("'" . $targetDb->name . "'", $config);
        self::assertTrue(
            str_contains($config, "define( 'DB_PASSWORD', " . var_export($targetDb->password, true) . " )"),
            'Restored wp-config.php must contain the configured target database password.',
        );
        self::assertSame(hash('sha256', $env['files']['wp-content/uploads/big.dat']), hash_file('sha256', $target . '/wp-content/uploads/big.dat'));
    }

    public function testBackupWithStagingInsideSiteDoesNotRecurse(): void
    {
        $env = $this->setUpSite(BackupType::Files, true);
        [$status, , $backupId] = $this->runBackup($env['profile'], $env['source'], $env['jobs'], 5.0);
        self::assertSame(JobStatus::Completed, $status);
        $set = BackupSet::open($env['source']->stagingPath . '/' . $backupId);
        foreach ($set->indexReader()->read() as [$entry]) {
            self::assertStringNotContainsString('sw-backupit-staging', $entry['p']);
        }
        self::assertFalse($set->manifest()->hasDatabase());
    }

    public function testDatabaseOnlyBackup(): void
    {
        $env = $this->setUpSite(BackupType::Database);
        [$status, , $backupId] = $this->runBackup($env['profile'], $env['source'], $env['jobs']);
        self::assertSame(JobStatus::Completed, $status);
        $set = BackupSet::open($env['source']->stagingPath . '/' . $backupId);
        self::assertFalse($set->manifest()->hasFiles());
        self::assertSame([], $set->manifest()->volumes());
        self::assertNotEmpty($set->manifest()->databaseParts());
    }

    /**
     * @return array<string, string>
     */
    private function dumpDatabase(PDO $pdo): array
    {
        $out = [];
        foreach (['wp_options' => 'option_id', 'wp_posts' => 'ID'] as $table => $order) {
            $out[$table] = md5(serialize($pdo->query("SELECT * FROM {$table} ORDER BY {$order}")->fetchAll(PDO::FETCH_NUM)));
        }

        return $out;
    }
}
