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
use SWBackupIt\Tests\Support\KilledException;
use SWBackupIt\Tests\Support\KillingPdo;
use SWBackupIt\Tests\Support\TempDir;
use SWBackupIt\Tests\Support\TestDatabase;

/**
 * Regression tests for the resumable SQL import:
 * - a crash between two progress checkpoints used to repeat statements (duplicate rows in keyless tables, or a failed resume);
 * - deferred views were kept in memory only and got lost when the import continued in a new process;
 * - view chains needing several passes failed in finalize().
 */
final class DatabaseResumeTest extends TestCase
{
    use TempDir;
    use TestDatabase;

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function exportFixture(PDO $source): array
    {
        // id 0 needs NO_AUTO_VALUE_ON_ZERO: a resume in a new connection must re-apply the session settings of the part header.
        $source->exec("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
        $source->exec('CREATE TABLE wp_log (msg VARCHAR(20))');
        $source->exec('CREATE TABLE wp_items (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, n INT)');
        for ($i = 0; $i < 5; $i++) {
            $source->exec("INSERT INTO wp_log VALUES ('m{$i}')");
            $source->exec('INSERT INTO wp_items (id, n) VALUES (' . ($i === 0 ? 0 : $i) . ", {$i})");
        }
        // Alphabetical export order is the reverse of the dependency order: wp_va -> wp_vb -> wp_vc -> wp_items.
        $source->exec('CREATE VIEW wp_vc AS SELECT id, n FROM wp_items');
        $source->exec('CREATE VIEW wp_vb AS SELECT id FROM wp_vc');
        $source->exec('CREATE VIEW wp_va AS SELECT id FROM wp_vb');

        $dir = $this->makeTempDir() . '/database';
        $tables = (new TableSelector())->select($source, 'wp_', [], [], false);
        $cursor = [];
        do {
            $result = (new DatabaseExporter($source))->export($dir, $tables, false, 1, 600, $cursor, new ResourceBudget(0.0), static function (array $c): void {
            });
            $cursor = $result->cursor;
        } while (!$result->finished);

        return [$dir, array_map(static fn (array $part): string => $dir . '/' . $part['name'], $cursor['parts'])];
    }

    /** @return array<string, string> */
    private function snapshot(PDO $pdo): array
    {
        return [
            'log' => implode(',', $pdo->query('SELECT msg FROM wp_log ORDER BY msg')->fetchAll(PDO::FETCH_COLUMN)),
            'items' => implode(',', $pdo->query('SELECT CONCAT(id, ":", n) FROM wp_items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)),
            'views' => implode(',', $pdo->query('SELECT COUNT(*) FROM wp_va')->fetchAll(PDO::FETCH_COLUMN)),
        ];
    }

    private function wipe(PDO $pdo): void
    {
        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$name, $type]) {
            $pdo->exec(($type === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . DatabaseExporter::id((string) $name));
        }
    }

    public function testCrashAtAnyStatementResumesExactlyOnce(): void
    {
        $source = ConnectionFactory::create($this->createDatabase());
        [, $parts] = $this->exportFixture($source);
        self::assertGreaterThan(2, count($parts), 'the fixture spans several parts');
        $target = ConnectionFactory::create($this->createDatabase());

        $expected = $this->snapshot($source);
        $crashes = 0;
        $killAt = 0;
        while (true) {
            $killAt++;
            $this->wipe($target);
            $killer = new KillingPdo($target, $killAt);
            $killed = false;
            $part = 0;
            $importer = new SqlImporter($killer);
            // The caller's own state is deliberately stale (statement offset 0): the progress table must be authoritative.
            while ($part < count($parts)) {
                try {
                    $importer->importPart($parts[$part]);
                    $part++;
                } catch (KilledException) {
                    $killed = true;
                    // "New process": fresh connection state, fresh importer, same part again.
                    $importer = new SqlImporter($target);
                }
            }
            (new SqlImporter($target))->finalize();
            self::assertSame($expected, $this->snapshot($target), "crash after exec #{$killAt}");
            self::assertSame([], $target->query("SHOW TABLES LIKE 'swbi_import_progress'")->fetchAll(), 'progress table removed');
            if (!$killed) {
                break;
            }
            $crashes++;
        }
        self::assertGreaterThan(20, $crashes);
    }
}
