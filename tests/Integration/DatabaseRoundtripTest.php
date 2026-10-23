<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Database\ConnectionFactory;
use SWBackupIt\Core\Database\DatabaseExporter;
use SWBackupIt\Core\Database\TableSelector;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Restore\Sql\SqlImporter;
use SWBackupIt\Tests\Support\TempDir;
use SWBackupIt\Tests\Support\TestDatabase;

final class DatabaseRoundtripTest extends TestCase
{
    use TempDir;
    use TestDatabase;

    private function seed(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE wp_posts (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title TEXT, body LONGTEXT, meta BLOB, price DECIMAL(10,2), d DATETIME NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE wp_term_rel (a INT NOT NULL, b VARCHAR(20) NOT NULL, note VARCHAR(50) NULL, PRIMARY KEY (a, b)) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE wp_nopk (x INT, y VARCHAR(10))');
        $pdo->exec('CREATE TABLE other_app (id INT PRIMARY KEY)');
        $pdo->exec('INSERT INTO other_app VALUES (1)');
        $insert = $pdo->prepare('INSERT INTO wp_posts (title, body, meta, price, d) VALUES (?,?,?,?,?)');
        $special = "Line1\nLine2\r\n'quote' \"dq\" back\\slash ;-- /* */ 😀 ünï";
        for ($i = 1; $i <= 250; $i++) {
            $insert->bindValue(1, "Title $i " . ($i % 7 === 0 ? $special : ''));
            $insert->bindValue(2, str_repeat('x', $i % 50 === 0 ? 70000 : 10) . $special);
            $insert->bindValue(3, random_bytes(32) . "\0\xff", PDO::PARAM_LOB);
            $insert->bindValue(4, $i % 5 === 0 ? null : (string) ($i / 4));
            $insert->bindValue(5, $i % 3 === 0 ? null : '2020-01-02 03:04:05');
            $insert->execute();
        }
        $pdo->exec("INSERT INTO wp_posts (title, body, meta, price, d) VALUES ('zero', '', '', 0, '0000-00-00 00:00:00')");
        $pdo->exec("INSERT INTO wp_term_rel VALUES (1,'a','n1'),(1,'b',NULL),(2,'a','x'),(10,'a','y'),(2,'zz','')");
        $pdo->exec("INSERT INTO wp_nopk VALUES (1,'a'),(2,NULL),(3,'c')");
        $pdo->exec('CREATE VIEW wp_view AS SELECT ID, title FROM wp_posts WHERE ID < 10');
        $pdo->exec('CREATE VIEW wp_view2 AS SELECT * FROM wp_view');
        $pdo->exec('CREATE TRIGGER wp_trg BEFORE INSERT ON wp_nopk FOR EACH ROW BEGIN SET NEW.y = IFNULL(NEW.y, \'t\'); END');
    }

    /**
     * @return array<string, mixed>
     */
    private function dump(PDO $pdo): array
    {
        $result = [];
        foreach (['wp_posts' => 'ID', 'wp_term_rel' => 'a,b', 'wp_nopk' => 'x'] as $table => $order) {
            $rows = $pdo->query("SELECT * FROM {$table} ORDER BY {$order}")->fetchAll(PDO::FETCH_NUM);
            $result[$table] = md5(serialize($rows)) . ':' . count($rows);
        }

        return $result;
    }

    public function testExportImportRoundtripWithResumeAcrossParts(): void
    {
        $source = ConnectionFactory::create($this->createDatabase());
        $this->seed($source);
        $sourceConfig = $this->createDatabaseConfigOf($source);

        $selector = new TableSelector();
        $tables = $selector->select($source, 'wp_', [], [], false);
        self::assertSame(['wp_nopk', 'wp_posts', 'wp_term_rel', 'wp_view', 'wp_view2'], array_map(static fn ($t) => $t->name, $tables));

        $dir = $this->makeTempDir() . '/database';
        $exporter = new DatabaseExporter($source);
        $cursor = [];
        $ticks = 0;
        do {
            // Tiny parts + immediate budget exhaustion force many resumes across process "restarts".
            $result = $exporter->export($dir, $tables, false, 40, 20000, $cursor, new ResourceBudget(0.0), static function (array $c): void {
            });
            $cursor = $result->cursor;
            $ticks++;
            // Simulate crash residue: a stray .part file must not matter.
            file_put_contents($dir . '/db-9999.sql.gz.part', 'garbage');
        } while (!$result->finished && $ticks < 500);
        self::assertTrue($result->finished);
        self::assertGreaterThan(3, count($cursor['parts']));
        self::assertSame(251, $cursor['table_rows']['wp_posts']);

        $target = ConnectionFactory::create($this->createDatabase());
        $importer = new SqlImporter($target);
        foreach ($cursor['parts'] as $part) {
            self::assertSame($part['sha256'], hash_file('sha256', $dir . '/' . $part['name']));
            $importer->importPart($dir . '/' . $part['name']);
        }
        $importer->finalize();

        self::assertSame($this->dump($source), $this->dump($target));
        self::assertSame('9', (string) $target->query('SELECT COUNT(*) FROM wp_view2')->fetchColumn());
        self::assertSame('0000-00-00 00:00:00', (string) $target->query("SELECT d FROM wp_posts WHERE title='zero'")->fetchColumn());
        $target->exec("INSERT INTO wp_nopk VALUES (9, NULL)");
        self::assertSame('t', (string) $target->query('SELECT y FROM wp_nopk WHERE x=9')->fetchColumn(), 'trigger restored');
        self::assertFalse($target->query("SHOW TABLES LIKE 'other_app'")->fetch(), 'foreign tables are excluded by default');
        self::assertNotNull($sourceConfig);
    }

    public function testConsistentSnapshotModeRunsInOnePass(): void
    {
        $source = ConnectionFactory::create($this->createDatabase());
        $this->seed($source);
        $tables = (new TableSelector())->select($source, 'wp_', [], ['wp_nopk'], false);
        $dir = $this->makeTempDir() . '/database';
        $result = (new DatabaseExporter($source))->export($dir, $tables, false, 1000, 1000000, [], ResourceBudget::unlimited(), static function (): void {
        }, true);
        self::assertTrue($result->finished);
        self::assertArrayNotHasKey('wp_nopk', $result->cursor['table_rows']);
    }

    public function testIncludeForeignTablesAndExcludePatterns(): void
    {
        $source = ConnectionFactory::create($this->createDatabase());
        $this->seed($source);
        $names = array_map(static fn ($t) => $t->name, (new TableSelector())->select($source, 'wp_', [], ['wp_view*'], true));
        self::assertSame(['other_app', 'wp_nopk', 'wp_posts', 'wp_term_rel'], $names);
        $only = array_map(static fn ($t) => $t->name, (new TableSelector())->select($source, 'wp_', ['wp_posts'], [], false));
        self::assertSame(['wp_posts'], $only);
    }

    private function createDatabaseConfigOf(PDO $pdo): string
    {
        return (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    }
}
