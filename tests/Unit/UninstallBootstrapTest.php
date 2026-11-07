<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Support\AtomicFile;

final class UninstallBootstrapTest extends TestCase
{
    public function testUninstallWorksWithOnlyTheReleaseAutoloader(): void
    {
        $source = dirname(__DIR__, 2);
        $root = sys_get_temp_dir() . '/swbi-uninstall-' . bin2hex(random_bytes(6));
        mkdir($root . '/vendor-prefixed', 0700, true);
        try {
            copy($source . '/uninstall.php', $root . '/uninstall.php');
            symlink($source . '/packages', $root . '/packages');
            // A release loader starts with dependency mappings only, without the monorepo classes.
            $loader = '<?php require ' . var_export($source . '/vendor/composer/ClassLoader.php', true) . '; ' .
                '$loader = new \\Composer\\Autoload\\ClassLoader(); $loader->register(); return $loader;';
            file_put_contents($root . '/vendor-prefixed/autoload.php', $loader);
            $process = proc_open([PHP_BINARY, $source . '/tests/uninstall.php', $root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            self::assertStringContainsString('PASS: Uninstall', $output);
        } finally {
            AtomicFile::removeTree($root);
        }
    }
}
