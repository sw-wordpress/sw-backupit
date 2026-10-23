<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Cli\RestoreCli;
use SWBackupIt\Restore\Files\Comparator;
use SWBackupIt\Restore\Files\Selection;
use SWBackupIt\Tests\Support\FilesBackupFixture;

final class CompareTest extends TestCase
{
    use FilesBackupFixture;

    public function testCompareClassifiesAllDifferences(): void
    {
        $runner = $this->setUpFilesBackup(['include_paths' => ['wp-content', 'index.php']]);
        $job = $this->newJob();
        self::assertSame(JobStatus::Completed, $runner->runToCompletion($job->id, 5.0));
        $set = BackupSet::open($this->backupDir($job));

        // Local state = a copy of the site with: one modified (same size), one grown, one deleted, one new file, a type change.
        $local = $this->makeTempDir('local');
        exec('cp -a ' . escapeshellarg($this->site) . '/. ' . escapeshellarg($local) . '/');
        file_put_contents($local . '/index.php', str_replace('front', 'XXXXX', (string) file_get_contents($local . '/index.php')));
        file_put_contents($local . '/wp-content/uploads/many/f1.txt', 'grown content!!', FILE_APPEND);
        unlink($local . '/wp-content/uploads/many/f2.txt');
        file_put_contents($local . '/wp-content/uploads/brand-new.txt', 'new');
        unlink($local . '/wp-content/uploads/empty.txt');
        mkdir($local . '/wp-content/uploads/empty.txt');

        $meta = (new Comparator())->compare($set, $local, Comparator::MODE_METADATA);
        $sha = (new Comparator())->compare($set, $local, Comparator::MODE_SHA256);

        foreach ([$meta, $sha] as $result) {
            self::assertContains('wp-content/uploads/many/f2.txt', $result->samples['only_in_backup']);
            self::assertContains('wp-content/uploads/brand-new.txt', $result->samples['only_local']);
            self::assertContains('wp-content/uploads/many/f1.txt', $result->samples['modified']);
            self::assertContains('wp-content/uploads/empty.txt', $result->samples['incomparable'], 'file vs directory');
            self::assertGreaterThan(50, $result->counts['identical']);
        }
        // Same size, same mtime preserved by cp -a, changed content: only the hash comparison can see it.
        touch($local . '/index.php', (int) filemtime($this->site . '/index.php'));
        $metaAgain = (new Comparator())->compare($set, $local, Comparator::MODE_METADATA);
        $shaAgain = (new Comparator())->compare($set, $local, Comparator::MODE_SHA256);
        self::assertNotContains('index.php', $metaAgain->samples['modified']);
        self::assertContains('index.php', $shaAgain->samples['modified']);

        $selected = (new Comparator())->compare($set, $local, Comparator::MODE_METADATA, new Selection(['wp-content/uploads/many']));
        self::assertSame(0, $selected->counts['only_local'] - count(array_filter($selected->samples['only_local'], static fn (string $p): bool => !str_starts_with($p, 'wp-content/uploads/many'))));
    }

    public function testCompareViaCli(): void
    {
        $runner = $this->setUpFilesBackup();
        $job = $this->newJob();
        $runner->runToCompletion($job->id, 5.0);
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new RestoreCli($out, $err))->run(['compare', $this->backupDir($job), '--target=' . $this->site, '--mode=sha256', '--format=json']);
        rewind($out);
        $data = json_decode((string) stream_get_contents($out), true);
        self::assertSame(0, $code);
        self::assertSame(0, $data['modified']);
        self::assertSame(0, $data['only_local']);
        self::assertGreaterThan(50, $data['identical']);
    }
}
