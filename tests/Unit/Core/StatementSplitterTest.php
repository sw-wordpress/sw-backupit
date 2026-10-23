<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Restore\Sql\StatementSplitter;

final class StatementSplitterTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function split(string $sql, int $chunkSize): array
    {
        $splitter = new StatementSplitter();
        $statements = [];
        foreach (str_split($sql, $chunkSize) as $chunk) {
            foreach ($splitter->feed($chunk) as $statement) {
                $statements[] = $statement;
            }
        }
        foreach ($splitter->finish() as $statement) {
            $statements[] = $statement;
        }

        return $statements;
    }

    public function testSplitsRegardlessOfChunkBoundaries(): void
    {
        $sql = "-- header comment; with semicolon\nSET NAMES utf8mb4;\n"
            . "INSERT INTO `t;x` VALUES ('a;b','it''s','back\\\\slash \\' quote',\"dq;\");\n"
            . "# hash; comment\n/* block ; comment */ INSERT INTO t VALUES (1);\n"
            . "SELECT 1 -- trailing; comment\n;\n"
            . "DELIMITER ;;\nCREATE TRIGGER x BEFORE INSERT ON t FOR EACH ROW BEGIN SET @a = 1; SET @b = 2; END;;\nDELIMITER ;\n"
            . "SELECT 'last'";
        $expected = [
            "-- header comment; with semicolon\nSET NAMES utf8mb4",
            "INSERT INTO `t;x` VALUES ('a;b','it''s','back\\\\slash \\' quote',\"dq;\")",
            "# hash; comment\n/* block ; comment */ INSERT INTO t VALUES (1)",
            "SELECT 1 -- trailing; comment\n",
            'CREATE TRIGGER x BEFORE INSERT ON t FOR EACH ROW BEGIN SET @a = 1; SET @b = 2; END',
            "SELECT 'last'",
        ];
        $expected[3] = 'SELECT 1 -- trailing; comment';
        foreach ([1, 2, 3, 7, 64, 100000] as $size) {
            self::assertSame($expected, $this->split($sql, $size), "chunk size $size");
        }
    }

    public function testCommentOnlyFragmentsAreDropped(): void
    {
        self::assertSame(['SELECT 1'], $this->split("-- only comment\n;\n\n/* x */;\nSELECT 1;\n-- tail", 5));
    }

    public function testVersionedCommentsArePreserved(): void
    {
        self::assertSame(['/*!40101 SET NAMES utf8 */'], $this->split('/*!40101 SET NAMES utf8 */;', 4));
    }
}
