<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Support\AtomicFile;

final class ReleaseCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/swbi-release-command-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/checkout/bin', 0700, true);
        $source = dirname(__DIR__, 2);
        copy($source . '/Makefile', $this->root . '/checkout/Makefile');
        copy($source . '/bin/release.sh', $this->root . '/checkout/bin/release.sh');
        file_put_contents($this->root . '/checkout/sw-backupit.php', "<?php\n/**\n * Version:           0.8.0\n */\ndefine( 'SWBACKUPIT_VERSION', '0.8.0' );\n");
        $this->runCommand(['git', 'init', '--bare', $this->root . '/origin.git']);
        $this->runCommand(['git', 'init', '-b', 'main']);
        $this->runCommand(['git', 'config', 'user.name', 'Release Test']);
        $this->runCommand(['git', 'config', 'user.email', 'release@example.invalid']);
        $this->runCommand(['git', 'config', 'commit.gpgsign', 'false']);
        $this->runCommand(['git', 'config', 'tag.gpgsign', 'false']);
        $this->runCommand(['git', 'add', '.']);
        $this->runCommand(['git', 'commit', '-m', 'Initial fixture']);
        $this->runCommand(['git', 'remote', 'add', 'origin', $this->root . '/origin.git']);
        $this->runCommand(['git', 'push', 'origin', 'main']);
    }

    protected function tearDown(): void
    {
        AtomicFile::removeTree($this->root);
    }

    private function runCommand(array $command, int $expectedExit = 0): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root . '/checkout');
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame($expectedExit, proc_close($process), $output);
        return trim($output);
    }

    public function testReleaseCommitsBothVersionsAndPushesBranchAndAnnotatedTag(): void
    {
        $this->runCommand(['make', 'release', 'VERSION=0.8.1']);
        $source = file_get_contents($this->root . '/checkout/sw-backupit.php');
        self::assertStringContainsString('Version:           0.8.1', $source);
        self::assertStringContainsString("define( 'SWBACKUPIT_VERSION', '0.8.1' );", $source);
        self::assertSame('', $this->runCommand(['git', 'status', '--porcelain']));
        self::assertSame('Release 0.8.1', $this->runCommand(['git', 'log', '-1', '--format=%s']));
        self::assertSame('tag', $this->runCommand(['git', 'cat-file', '-t', 'refs/tags/0.8.1']));
        $head = $this->runCommand(['git', 'rev-parse', 'HEAD']);
        self::assertSame($head, $this->runCommand(['git', '--git-dir=' . $this->root . '/origin.git', 'rev-parse', 'main']));
        self::assertSame($head, $this->runCommand(['git', '--git-dir=' . $this->root . '/origin.git', 'rev-parse', '0.8.1^{}']));
    }

    public static function rejectedReleases(): array
    {
        return [
            'missing version' => ['missing', ''],
            'invalid version' => ['invalid', 'v0.8.1'],
            'downgrade' => ['downgrade', '0.7.0'],
            'dirty tree' => ['dirty', '0.8.1'],
            'staged changes' => ['staged', '0.8.1'],
            'inconsistent versions' => ['inconsistent', '0.8.1'],
            'existing local tag' => ['local-tag', '0.8.1'],
            'existing remote tag' => ['remote-tag', '0.8.1'],
            'detached head' => ['detached', '0.8.1'],
            'unreachable remote' => ['no-remote', '0.8.1'],
        ];
    }

    #[DataProvider('rejectedReleases')]
    public function testRejectedReleaseDoesNotChangePluginOrCommit(string $scenario, string $version): void
    {
        if ($scenario === 'dirty') {
            file_put_contents($this->root . '/checkout/untracked.txt', 'uncommitted work');
        } elseif ($scenario === 'staged') {
            file_put_contents($this->root . '/checkout/staged.txt', 'staged work');
            $this->runCommand(['git', 'add', 'staged.txt']);
        } elseif ($scenario === 'inconsistent') {
            $path = $this->root . '/checkout/sw-backupit.php';
            file_put_contents($path, str_replace("'0.8.0'", "'0.7.0'", file_get_contents($path)));
            $this->runCommand(['git', 'add', 'sw-backupit.php']);
            $this->runCommand(['git', 'commit', '-m', 'Inconsistent fixture']);
        } elseif (in_array($scenario, ['local-tag', 'remote-tag'], true)) {
            $this->runCommand(['git', 'tag', '0.8.1']);
            if ($scenario === 'remote-tag') {
                $this->runCommand(['git', 'push', 'origin', '0.8.1']);
                $this->runCommand(['git', 'tag', '-d', '0.8.1']);
            }
        } elseif ($scenario === 'detached') {
            $this->runCommand(['git', 'checkout', '--detach']);
        } elseif ($scenario === 'no-remote') {
            $this->runCommand(['git', 'remote', 'remove', 'origin']);
        }
        $before = file_get_contents($this->root . '/checkout/sw-backupit.php');
        $head = $this->runCommand(['git', 'rev-parse', 'HEAD']);
        $this->runCommand(['make', 'release', 'VERSION=' . $version], 2);
        self::assertSame($before, file_get_contents($this->root . '/checkout/sw-backupit.php'));
        self::assertSame($head, $this->runCommand(['git', 'rev-parse', 'HEAD']));
    }

    public function testCurrentVersionCanBeTaggedWithoutAnEmptyCommit(): void
    {
        $head = $this->runCommand(['git', 'rev-parse', 'HEAD']);
        $this->runCommand(['make', 'release', 'VERSION=0.8.0']);
        self::assertSame($head, $this->runCommand(['git', 'rev-parse', 'HEAD']));
        self::assertSame($head, $this->runCommand(['git', '--git-dir=' . $this->root . '/origin.git', 'rev-parse', '0.8.0^{}']));
    }

    public function testRejectedBranchPushDoesNotPublishTheTag(): void
    {
        $head = $this->runCommand(['git', 'rev-parse', 'HEAD']);
        // Advance origin independently, so the release branch cannot be pushed without force.
        $tree = $this->runCommand(['git', 'rev-parse', 'HEAD^{tree}']);
        $remoteHead = $this->runCommand(['git', 'commit-tree', $tree, '-p', $head, '-m', 'Remote commit']);
        $this->runCommand(['git', 'push', 'origin', $remoteHead . ':refs/heads/main']);
        $this->runCommand(['make', 'release', 'VERSION=0.8.1'], 2);
        self::assertSame($remoteHead, $this->runCommand(['git', '--git-dir=' . $this->root . '/origin.git', 'rev-parse', 'main']));
        self::assertSame('', $this->runCommand(['git', 'ls-remote', '--tags', 'origin', 'refs/tags/0.8.1']));
        self::assertSame('tag', $this->runCommand(['git', 'cat-file', '-t', 'refs/tags/0.8.1']));
    }
}
