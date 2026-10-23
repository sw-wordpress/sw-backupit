<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Scanner\FileIndexReader;
use SWBackupIt\Core\Scanner\FileScanner;
use SWBackupIt\Core\Scanner\GlobMatcher;
use SWBackupIt\Core\Scanner\RuleSet;
use SWBackupIt\Core\Support\PathOrder;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Tests\Support\TempDir;

final class ScannerTest extends TestCase
{
    use TempDir;

    public function testGlobMatching(): void
    {
        self::assertTrue(GlobMatcher::matches('*.log', 'wp-content/debug.log'));
        self::assertTrue(GlobMatcher::matches('**/*.log', 'a/b/c.log'));
        self::assertTrue(GlobMatcher::matches('**/*.log', 'c.log'));
        self::assertFalse(GlobMatcher::matches('wp-content/*.log', 'wp-content/a/b.log'));
        self::assertTrue(GlobMatcher::matches('cache/**', 'cache/x/y'));
        self::assertTrue(GlobMatcher::matches('file?.txt', 'file1.txt'));
        self::assertFalse(GlobMatcher::matches('file?.txt', 'file12.txt'));
    }

    public function testScansInCanonicalOrderWithSpecialNames(): void
    {
        $root = $this->makeTempDir();
        $this->createTree($root, [
            'b.txt' => 'b', 'a/z.txt' => 'z', 'a/a.txt' => 'a', 'a-b/c.txt' => 'c', 'a.txt' => 'x',
            'ünï cödé/файл 1.txt' => 'u', 'with space/and"quote.txt' => 'q',
        ]);
        mkdir($root . '/empty');
        symlink($root . '/a', $root . '/link');

        $paths = $this->scanAll($root, new RuleSet());

        $sorted = $paths;
        usort($sorted, PathOrder::compare(...));
        self::assertSame($sorted, $paths, 'scanner output must follow PathOrder');
        self::assertContains('ünï cödé/файл 1.txt', $paths);
        self::assertContains('empty', $paths);
        self::assertContains('link', $paths);
        self::assertNotContains('link/a.txt', $paths, 'symlinks are never followed');
    }

    public function testRulesIncludeExclude(): void
    {
        $root = $this->makeTempDir();
        $this->createTree($root, [
            'wp-content/uploads/a.jpg' => '1', 'wp-content/cache/c.tmp' => '2', 'wp-content/debug.log' => '3',
            'wp-config.php' => '4', 'other/x.txt' => '5',
        ]);
        $paths = $this->scanAll($root, new RuleSet(['wp-content'], ['wp-content/cache'], [], ['*.log']));
        self::assertContains('wp-content/uploads/a.jpg', $paths);
        self::assertNotContains('wp-content/cache/c.tmp', $paths);
        self::assertNotContains('wp-content/debug.log', $paths);
        self::assertNotContains('wp-config.php', $paths);
        self::assertNotContains('other/x.txt', $paths);

        $patternOnly = $this->scanAll($root, new RuleSet([], [], ['**/*.jpg']));
        self::assertContains('wp-content/uploads/a.jpg', $patternOnly);
        self::assertNotContains('wp-config.php', $patternOnly);
    }

    public function testStagingMarkerDirectoriesAreExcluded(): void
    {
        $root = $this->makeTempDir();
        $this->createTree($root, ['staging/.swbi-staging' => '', 'staging/backup.zip' => 'z', 'keep.txt' => 'k']);
        $paths = $this->scanAll($root, new RuleSet());
        self::assertSame(['keep.txt'], $paths);
    }

    public function testResumeProducesIdenticalIndexAsUninterruptedScan(): void
    {
        $root = $this->makeTempDir();
        for ($i = 0; $i < 12; $i++) {
            for ($j = 0; $j < 12; $j++) {
                $this->createTree($root, ["d$i/s$j/file$j.txt" => "$i-$j", "d$i/top.txt" => 'x']);
            }
        }
        $expected = $this->scanAll($root, new RuleSet());

        $work = $this->makeTempDir();
        $inventory = $work . '/inventory.ndjson';
        $scanner = new FileScanner($root, new RuleSet());
        $cursor = [];
        $ticks = 0;
        do {
            $result = $scanner->scan($inventory, $cursor, new ResourceBudget(0.0));
            $cursor = $result->cursor;
            $ticks++;
            // Simulate a crash after a tick: junk beyond the checkpoint must be discarded on resume.
            file_put_contents($inventory, "{\"p\":\"junk\",\"t\":\"f\"}\n", FILE_APPEND);
        } while (!$result->finished && $ticks < 1000);

        self::assertGreaterThan(3, $ticks);
        $actual = [];
        foreach ((new FileIndexReader($inventory))->read() as [$entry]) {
            $actual[] = $entry['p'];
        }
        // The last junk line is appended after completion; the committed offset excludes it.
        self::assertSame($expected, array_slice($actual, 0, count($expected)));
        self::assertSame(count($expected), $cursor['count']);
        self::assertSame($cursor['offset'], strpos((string) file_get_contents($inventory), '{"p":"junk"'));
    }

    /**
     * @return list<string>
     */
    private function scanAll(string $root, RuleSet $rules): array
    {
        $work = $this->makeTempDir();
        $scanner = new FileScanner($root, $rules);
        $result = $scanner->scan($work . '/inv.ndjson', [], ResourceBudget::unlimited());
        self::assertTrue($result->finished);
        $paths = [];
        foreach ((new FileIndexReader($work . '/inv.ndjson'))->read() as [$entry]) {
            $paths[] = $entry['p'];
        }

        return $paths;
    }
}
