<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Backup\BackupRunnerFactory;
use SWBackupIt\Core\Backup\JobFactory;
use SWBackupIt\Core\Backup\SourceInfo;
use SWBackupIt\Core\Database\ConnectionFactory;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Job\FileJobRepository;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Restore\Migration\DatabaseMigrator;
use SWBackupIt\Restore\Migration\TablePrefixMigrator;
use SWBackupIt\Restore\RestoreEngine;
use SWBackupIt\Restore\RestoreOutcome;
use SWBackupIt\Restore\RestoreRequest;
use SWBackupIt\Tests\Support\SiteFixture;
use SWBackupIt\Tests\Support\TempDir;
use SWBackupIt\Tests\Support\TestDatabase;

final class MigrationTest extends TestCase
{
    use SiteFixture;
    use TempDir;
    use TestDatabase;

    private function seedMigrationTables(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE wp_usermeta (umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT, meta_key VARCHAR(255), meta_value LONGTEXT)');
        $pdo->exec("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'wp_capabilities', 'a:1:{s:13:\"administrator\";b:1;}'), (1, 'wp_user_level', '10'), (1, 'nickname', 'x')");
        $pdo->exec("INSERT INTO wp_options (option_name, option_value) VALUES ('wp_user_roles', 'a:0:{}'), ('home', 'https://alte-domain.de'), ('widget', '" . str_replace("'", "''", serialize(['title' => 'Link https://alte-domain.de/a', 'items' => [1, 2], 'path' => '/var/www/old/wp-content'])) . "')");
        $pdo->exec("INSERT INTO wp_posts (post_title, post_content) VALUES ('Link', '<a href=\"https://alte-domain.de/x\">x</a> and https://alte-domain.de/y'), ('Untouched', 'nothing')");
        $pdo->exec("UPDATE wp_options SET option_value = 'https://alte-domain.de' WHERE option_name = 'siteurl'");
    }

    public function testFullMigrationToNewDomainSubdirectoryPathAndPrefix(): void
    {
        $site = $this->makeTempDir('site');
        $this->buildSiteFiles($site);
        $config = $this->createDatabase();
        $pdo = ConnectionFactory::create($config);
        $this->seedSiteDatabase($pdo);
        $this->seedMigrationTables($pdo);
        $work = $this->makeTempDir('work');
        $profile = BackupProfile::fromArray(['id' => 1, 'name' => 'm', 'type' => 'full', 'volume_size_bytes' => 400000, 'limits' => ['time_budget_seconds' => 5]]);
        $runner = BackupRunnerFactory::createFileBased($work . '/jobs', new SourceInfo($site, $work . '/staging', 'https://alte-domain.de', 'https://alte-domain.de', '6.8', 'wp_', $config));
        $job = (new JobFactory())->create($profile);
        (new FileJobRepository($work . '/jobs'))->create($job);
        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 5.0));

        $targetDb = $this->createDatabase();
        $target = $this->makeTempDir('target');
        $replacements = ['https://alte-domain.de' => 'https://neue-domain.de/wordpress', '/var/www/old' => '/var/www/new/wordpress'];

        // Dry run first: reports changes, writes nothing.
        $dry = new RestoreRequest($work . '/staging/' . $job->backupId, $target, (string) realpath($target), $this->makeTempDir('state'), database: $targetDb, confirmedDatabase: $targetDb->name, replacements: $replacements, migrationDryRun: true);
        $outcome = (new RestoreEngine())->run($dry, ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::COMPLETED, $outcome->status, implode(';', $outcome->messages));
        self::assertGreaterThan(0, $outcome->summary['migration']['rows_changed']);
        $dbPdo = ConnectionFactory::create($targetDb);
        self::assertSame('https://alte-domain.de', (string) $dbPdo->query("SELECT option_value FROM wp_options WHERE option_name='siteurl'")->fetchColumn(), 'dry run did not modify data');

        // Real migration incl. table prefix change.
        $targetDb2 = $this->createDatabase();
        $target2 = $this->makeTempDir('target');
        $request = new RestoreRequest($work . '/staging/' . $job->backupId, $target2, (string) realpath($target2), $this->makeTempDir('state'), database: $targetDb2, confirmedDatabase: $targetDb2->name, replacements: $replacements, newTablePrefix: 'blog_');
        $outcome = (new RestoreEngine())->run($request, ResourceBudget::unlimited());
        self::assertSame(RestoreOutcome::COMPLETED, $outcome->status, implode(';', $outcome->messages));
        $migrated = ConnectionFactory::create($targetDb2);
        $option = static fn (string $name): string => (string) $migrated->query("SELECT option_value FROM blog_options WHERE option_name='{$name}'")->fetchColumn();
        self::assertSame('https://neue-domain.de/wordpress', $option('siteurl'));
        self::assertSame('https://neue-domain.de/wordpress', $option('home'));
        $widget = unserialize($option('widget'));
        self::assertSame('Link https://neue-domain.de/wordpress/a', $widget['title']);
        self::assertSame('/var/www/new/wordpress/wp-content', $widget['path']);
        self::assertSame([1, 2], $widget['items']);
        self::assertSame('https://neue-domain.de/wordpress/x', (string) preg_replace('/.*href="([^"]+)".*/s', '$1', (string) $migrated->query("SELECT post_content FROM blog_posts WHERE post_title='Link'")->fetchColumn()));
        self::assertSame('nothing', (string) $migrated->query("SELECT post_content FROM blog_posts WHERE post_title='Untouched'")->fetchColumn());
        self::assertSame(['blog_capabilities', 'blog_user_level', 'nickname'], $migrated->query('SELECT meta_key FROM blog_usermeta ORDER BY umeta_id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame('1', (string) $migrated->query("SELECT COUNT(*) FROM blog_options WHERE option_name='blog_user_roles'")->fetchColumn());
        self::assertFalse($migrated->query("SHOW TABLES LIKE 'wp\\_%'")->fetch(), 'old prefixed tables are gone');
        self::assertFalse($migrated->query("SHOW TABLES LIKE 'swbi_restore_progress'")->fetch(), 'progress table removed');
        self::assertStringContainsString('$table_prefix = \'blog_\';', (string) file_get_contents($target2 . '/wp-config.php'));
    }

    public function testResumableMigrationNeverAppliesReplacementTwice(): void
    {
        $pdo = ConnectionFactory::create($this->createDatabase());
        $pdo->exec('CREATE TABLE wp_t (id INT PRIMARY KEY, v TEXT, guid TEXT)');
        $insert = $pdo->prepare('INSERT INTO wp_t VALUES (?,?,?)');
        for ($i = 1; $i <= 50; $i++) {
            $insert->execute([$i, 'see https://a.de/page' . $i, 'https://a.de/?p=' . $i]);
        }
        // Nested replacement: the new URL contains the old one, so a repeated pass would produce /wp/wp.
        $pairs = ['https://a.de' => 'https://a.de/wp'];
        $migrator = new DatabaseMigrator($pdo, null ?? new \Psr\Log\NullLogger(), 7);
        $rounds = 0;
        do {
            $result = $migrator->run($pairs, 'wp_', [], false, new ResourceBudget(0.0));
            $rounds++;
            // New migrator instance each time = a "new process" that must continue from the stored progress.
            $migrator = new DatabaseMigrator($pdo, new \Psr\Log\NullLogger(), 7);
        } while (!$result->finished && $rounds < 100);
        self::assertGreaterThan(3, $rounds);
        self::assertSame(50, (int) $pdo->query("SELECT COUNT(*) FROM wp_t WHERE v LIKE 'see https://a.de/wp/page%'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM wp_t WHERE v LIKE '%/wp/wp/%'")->fetchColumn());
        self::assertSame('https://a.de/?p=1', (string) $pdo->query('SELECT guid FROM wp_t WHERE id = 1')->fetchColumn(), 'guid is an identifier and stays unchanged');
    }

    public function testTablePrefixMigrationRefusesToOverwriteExistingTables(): void
    {
        $pdo = ConnectionFactory::create($this->createDatabase());
        $pdo->exec('CREATE TABLE wp_options (option_id INT PRIMARY KEY, option_name VARCHAR(50), option_value TEXT)');
        $pdo->exec('CREATE TABLE new_options (x INT)');
        $this->expectException(\SWBackupIt\Core\Exception\ConfigurationException::class);
        (new TablePrefixMigrator($pdo))->migrate('wp_', 'new_');
    }
}
