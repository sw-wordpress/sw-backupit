<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Exception\PathViolationException;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\PathGuard;
use SWBackupIt\Core\Support\PathOrder;
use SWBackupIt\Core\Support\Uuid;
use SWBackupIt\Tests\Support\TempDir;

final class SupportTest extends TestCase
{
    use TempDir;

    /**
     * @return array<string, array{string}>
     */
    public static function unsafePaths(): array
    {
        return [
            'empty' => [''],
            'absolute' => ['/etc/passwd'],
            'traversal' => ['a/../../b'],
            'leading traversal' => ['../x'],
            'dot segment' => ['a/./b'],
            'backslash' => ['a\\b'],
            'nul' => ["a\0b"],
            'drive' => ['C:/x'],
            'empty segment' => ['a//b'],
            'control char' => ["a\nb"],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafePathsAreRejected(string $path): void
    {
        $this->expectException(PathViolationException::class);
        PathGuard::assertSafeRelative($path);
    }

    public function testSafePathIsNormalized(): void
    {
        self::assertSame('wp-content/uploads/ä ö.jpg', PathGuard::assertSafeRelative('wp-content/uploads/ä ö.jpg'));
        self::assertSame('dir', PathGuard::assertSafeRelative('dir/'));
    }

    public function testResolveBelowRefusesSymlinks(): void
    {
        $root = $this->makeTempDir();
        $outside = $this->makeTempDir();
        symlink($outside, $root . '/link');
        $this->expectException(PathViolationException::class);
        PathGuard::resolveBelow($root, 'link/evil.php');
    }

    public function testResolveBelowReturnsPathInsideRoot(): void
    {
        $root = $this->makeTempDir();
        $resolved = PathGuard::resolveBelow($root, 'a/b/c.txt');
        self::assertTrue(PathGuard::isBelow((string) realpath($root), $resolved));
    }

    public function testPathOrderIsSegmentWise(): void
    {
        $paths = ['a.txt', 'a/b', 'a/a', 'a-b/c', 'b'];
        usort($paths, PathOrder::compare(...));
        // Depth-first order: the content of directory "a" comes before its siblings "a-b" and "a.txt".
        self::assertSame(['a/a', 'a/b', 'a-b/c', 'a.txt', 'b'], $paths);
    }

    public function testAtomicWriteAndJsonRoundtrip(): void
    {
        $dir = $this->makeTempDir();
        AtomicFile::writeJson($dir . '/x/data.json', ['a' => 1, 'ü' => 'ß']);
        self::assertSame(['a' => 1, 'ü' => 'ß'], AtomicFile::readJson($dir . '/x/data.json'));
        self::assertSame([], glob($dir . '/x/*.tmp') ?: []);
    }

    public function testUuid(): void
    {
        $uuid = Uuid::v4();
        self::assertTrue(Uuid::isValid($uuid));
        self::assertFalse(Uuid::isValid('../etc'));
    }
}
