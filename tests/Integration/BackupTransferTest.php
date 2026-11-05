<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Exception\IntegrityException;
use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Exception\PathViolationException;
use SWBackupIt\Core\Manifest\Manifest;
use SWBackupIt\Core\Retry\RetryPolicy;
use SWBackupIt\Core\Storage\BackupDownloader;
use SWBackupIt\Core\Storage\BackupTransfer;
use SWBackupIt\Core\Storage\RemoteStorageInterface;
use SWBackupIt\Core\Storage\RemoteCatalog;
use SWBackupIt\Core\Storage\StorageFactory;
use SWBackupIt\Core\Storage\StorageTarget;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\Uuid;
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

    public function testDownloaderRejectsTraversalBeforeAccessingStorageOrCreatingDirectories(): void
    {
        $storage = $this->createMock(RemoteStorageInterface::class);
        $storage->expects(self::never())->method('exists');
        $storage->expects(self::never())->method('download');
        $root = $this->makeTempDir('download');
        try {
            (new BackupDownloader())->download($storage, '../escape', $root . '/not-created');
            self::fail('Traversal must be rejected.');
        } catch (ConfigurationException) {
            self::assertDirectoryDoesNotExist($root . '/not-created');
            self::assertDirectoryDoesNotExist($root . '/escape');
        }
    }

    public function testCatalogRejectsTraversalBeforeDownloadingOrDeletingAnything(): void
    {
        $storage = $this->createMock(RemoteStorageInterface::class);
        $storage->expects(self::never())->method('download');
        $root = $this->makeTempDir('catalog');
        file_put_contents($root . '/sentinel.manifest.json', 'keep');
        try {
            (new RemoteCatalog())->read($storage, '../sentinel', $root . '/temp');
            self::fail('Traversal must be rejected.');
        } catch (ConfigurationException) {
            self::assertSame('keep', file_get_contents($root . '/sentinel.manifest.json'));
        }
    }

    public function testDownloaderRefusesSymlinkBackupDirectory(): void
    {
        $root = $this->makeTempDir('download');
        $outside = $this->makeTempDir('outside');
        $id = Uuid::v4();
        symlink($outside, $root . '/' . $id);
        $storage = $this->createMock(RemoteStorageInterface::class);
        $storage->expects(self::never())->method('download');
        $this->expectException(PathViolationException::class);
        (new BackupDownloader())->download($storage, $id, $root);
    }

    public function testDownloaderRefusesSymlinkDataDirectoryAndManifest(): void
    {
        $id = $this->backup();
        $storage = $this->storage(1);
        (new BackupTransfer($this->makeTempDir('work')))->run($id, $this->staging . '/' . $id, null, $storage);
        foreach (['volumes', 'manifest.json'] as $name) {
            $root = $this->makeTempDir('download');
            $outside = $this->makeTempDir('outside');
            AtomicFile::ensureDirectory($root . '/' . $id);
            file_put_contents($outside . '/sentinel', 'keep');
            symlink($name === 'volumes' ? $outside : $outside . '/sentinel', $root . '/' . $id . '/' . $name);
            try {
                (new BackupDownloader())->download($storage, $id, $root);
                self::fail('Symlink destination must be rejected.');
            } catch (PathViolationException) {
                self::assertSame('keep', file_get_contents($outside . '/sentinel'));
                self::assertSame(['.', '..', 'sentinel'], scandir($outside));
            }
        }
    }

    public function testCatalogDoesNotUsePredictableScratchPaths(): void
    {
        $id = $this->backup();
        $storage = $this->storage(1);
        (new BackupTransfer($this->makeTempDir('work')))->run($id, $this->staging . '/' . $id, null, $storage);
        $root = $this->makeTempDir('catalog');
        $outside = $this->makeTempDir('outside') . '/sentinel';
        file_put_contents($outside, 'keep');
        symlink($outside, $root . '/' . $id . '.manifest.json');
        $read = (new RemoteCatalog())->read($storage, $id, $root);
        self::assertSame($id, $read['manifest']?->backupId());
        self::assertSame('keep', file_get_contents($outside));
        self::assertTrue(is_link($root . '/' . $id . '.manifest.json'));
        self::assertCount(1, glob($root . '/*.json') ?: []);
    }

    public function testAncestorTraversalIsRejected(): void
    {
        $id = $this->backup();
        $manifestPath = $this->staging . '/' . $id . '/manifest.json';
        $manifest = AtomicFile::readJson($manifestPath);
        $manifest['chain']['requires'] = ['../outside'];
        AtomicFile::writeJson($manifestPath, $manifest);
        $storage = $this->storage(1);
        (new BackupTransfer($this->makeTempDir('work')))->run($id, $this->staging . '/' . $id, null, $storage);
        $this->expectException(ConfigurationException::class);
        (new BackupDownloader())->downloadWithAncestors($storage, $id, $this->makeTempDir('download'));
    }

    public function testDownloaderPublishesTheManifestSnapshotItVerified(): void
    {
        $id = $this->backup();
        $expected = Manifest::read($this->staging . '/' . $id)->toArray();
        $storage = $this->storage(1);
        (new BackupTransfer($this->makeTempDir('work')))->run($id, $this->staging . '/' . $id, null, $storage);
        $replacement = $this->makeTempDir('replacement') . '/manifest.json';
        file_put_contents($replacement, 'invalid replacement manifest');
        $root = $this->makeTempDir('download');
        (new BackupDownloader())->download($storage, $id, $root, function () use ($storage, $id, $replacement): void {
            $storage->upload($replacement, $id . '/manifest.json');
        });
        self::assertSame($expected, Manifest::read($root . '/' . $id)->toArray());
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
