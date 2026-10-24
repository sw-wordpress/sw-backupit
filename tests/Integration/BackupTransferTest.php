<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Exception\IntegrityException;
use SWBackupIt\Core\Manifest\Manifest;
use SWBackupIt\Core\Retry\RetryPolicy;
use SWBackupIt\Core\Storage\BackupDownloader;
use SWBackupIt\Core\Storage\BackupTransfer;
use SWBackupIt\Core\Storage\RemoteStorageInterface;
use SWBackupIt\Core\Storage\StorageFactory;
use SWBackupIt\Core\Storage\StorageTarget;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\Tests\Support\FilesBackupFixture;

/** Copy/move of a stored backup between storage targets. */
final class BackupTransferTest extends TestCase
{
    use FilesBackupFixture;

    protected function tearDown(): void
    {
        $this->cleanTempDirs();
    }

    private function backup(): string
    {
        $runner = $this->setUpFilesBackup(['volume_size_bytes' => 60000, 'compression' => 'none']);
        $job = $this->newJob();
        for ($i = 0; $i < 500; $i++) {
            if (!$runner->tick($job->id, 0.0)->shouldContinue) {
                break;
            }
        }
        self::assertSame(JobStatus::Completed, $this->repository->find($job->id)?->status);

        return $job->backupId;
    }

    private function storage(int $id): RemoteStorageInterface
    {
        return (new StorageFactory(new RetryPolicy(1, 1, 1)))->create(new StorageTarget($id, 'T' . $id, 'local', ['path' => $this->makeTempDir('target' . $id)]));
    }

    public function testCopiesLocalBackupResumablyAndManifestLast(): void
    {
        $id = $this->backup();
        $local = $this->staging . '/' . $id;
        $destination = $this->storage(1);
        $transfer = new BackupTransfer($this->makeTempDir('work'));
        $files = BackupTransfer::files(Manifest::read($local));
        self::assertGreaterThan(2, count($files));

        $state = [];
        $calls = 0;
        do {
            $state = $transfer->run($id, $local, null, $destination, $state, new ResourceBudget(0.0));
            $calls++;
            if (!$state['finished']) {
                self::assertFalse($destination->exists($id . '/manifest.json'), 'manifest is uploaded last');
            }
        } while (!$state['finished'] && $calls < 100);

        self::assertTrue($state['finished']);
        self::assertGreaterThan(1, $calls, 'the budget makes the copy yield between files');
        self::assertCount(count($files) + 1, $state['done']);

        $restored = $this->makeTempDir('fetched');
        (new BackupDownloader())->download($destination, $id, $restored);
        $set = BackupSet::open($restored . '/' . $id);
        $report = (new Verifier())->verify($set, true);
        self::assertTrue($report->isOk(), implode(", ", $report->errors()));
    }

    public function testCopiesFromAnotherTargetAndSkipsVerifiedFiles(): void
    {
        $id = $this->backup();
        $first = $this->storage(1);
        $second = $this->storage(2);
        (new BackupTransfer($this->makeTempDir('work')))->run($id, $this->staging . '/' . $id, null, $first);

        $sent = 0;
        $state = (new BackupTransfer($this->makeTempDir('work')))->run($id, null, $first, $second, [], null, null, function (int $bytes) use (&$sent): void {
            $sent += $bytes;
        });
        self::assertTrue($state['finished']);
        self::assertTrue($second->exists($id . '/manifest.json'));
        self::assertGreaterThan(0, $sent);

        // A repeated run finds every data file verified on the destination and sends only the manifest.
        $again = 0;
        (new BackupTransfer($this->makeTempDir('work')))->run($id, null, $first, $second, [], null, null, function (int $bytes) use (&$again): void {
            $again += $bytes;
        });
        $manifest = Manifest::read($this->staging . '/' . $id);
        self::assertTrue($manifest->hasFiles());
        $index = (int) filesize($this->staging . '/' . $id . '/' . $manifest->indexFileName());
        self::assertSame($index, $again, 'only the index (size unknown in the manifest) is sent again');
    }

    public function testDamagedSourceIsRefusedAndDestinationGetsNoManifest(): void
    {
        $id = $this->backup();
        $local = $this->staging . '/' . $id;
        $manifest = Manifest::read($local);
        $volume = $local . '/volumes/' . $manifest->volumes()[0]->name;
        file_put_contents($volume, 'X', FILE_APPEND);
        $destination = $this->storage(1);

        try {
            (new BackupTransfer($this->makeTempDir('work')))->run($id, $local, null, $destination);
            self::fail('A damaged source must not be copied.');
        } catch (IntegrityException) {
            self::assertFalse($destination->exists($id . '/manifest.json'));
        }
    }
}
