<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use SWBackupIt\Core\Support\AtomicFile;

/** Creates and cleans isolated temp directories for tests. */
trait TempDir
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function makeTempDir(string $prefix = 'swbi'): string
    {
        $base = getenv('SWBI_TEST_TMP') ?: sys_get_temp_dir();
        $dir = $base . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    #[After]
    protected function cleanTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            AtomicFile::removeTree($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * @param array<string, string> $files relative path => contents
     */
    protected function createTree(string $root, array $files): void
    {
        foreach ($files as $path => $contents) {
            $full = $root . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0775, true);
            }
            file_put_contents($full, $contents);
        }
    }
}
