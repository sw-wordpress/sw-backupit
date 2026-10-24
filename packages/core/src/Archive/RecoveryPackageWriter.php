<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Archive;

use DateTimeImmutable;
use SWBackupIt\Core\Exception\ArchiveException;
use SWBackupIt\Core\Support\PathGuard;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * Streams a complete recovery package (PLAN.md §9.2 "single ZIP export", §22 "Disaster Recovery") into one ZIP64 archive:
 * the backup directory, the base backups of its chain and the standalone restore application, plus a README.
 *
 * The package is written straight to the given stream (HTTP download or file), never assembled in memory or in a temp copy.
 * Already compressed or encrypted artifacts are stored, text files are deflated. Only regular files are added; symlinks and
 * special files are skipped, so a package can never contain links pointing outside the packaged directories.
 */
final class RecoveryPackageWriter
{
    private const DEFLATE_EXTENSIONS = ['php', 'json', 'ndjson', 'js', 'css', 'txt', 'md', 'html'];

    /**
     * @param resource $output
     * @param array<string, string> $directories Archive prefix (e.g. "backups/<id>") => absolute directory, added recursively.
     * @return int Number of files in the package.
     */
    public function write($output, array $directories, string $readme): int
    {
        $zip = new ZipStream(sendHttpHeaders: false, outputStream: $output, enableZip64: true, defaultEnableZeroHeader: true);
        $count = 0;
        try {
            $zip->addFile(fileName: 'README.txt', data: $readme, compressionMethod: CompressionMethod::DEFLATE);
            foreach ($directories as $prefix => $directory) {
                $prefix = PathGuard::assertSafeRelative(trim($prefix, '/'));
                $root = realpath($directory);
                if ($root === false || !is_dir($root)) {
                    throw new ArchiveException('A directory of the recovery package does not exist.', ['prefix' => $prefix]);
                }
                $count += $this->addDirectory($zip, $prefix, $root);
            }
            $zip->finish();
        } catch (ArchiveException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new ArchiveException('Writing the recovery package failed.', [], false, $throwable);
        }

        return $count + 1;
    }

    private function addDirectory(ZipStream $zip, string $prefix, string $root): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink() || !$item->isFile()) {
                continue;
            }
            $files[] = $item->getPathname();
        }
        sort($files, SORT_STRING);
        foreach ($files as $path) {
            $relative = substr($path, strlen($root) + 1);
            if (str_ends_with($relative, '.part') || str_ends_with($relative, '.tmp')) {
                continue; // unfinished artifacts of a running job
            }
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new ArchiveException('A file of the recovery package cannot be read.', ['file' => $relative]);
            }
            try {
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $zip->addFileFromStream(
                    fileName: EntryName::for($prefix . '/' . $relative),
                    stream: $stream,
                    compressionMethod: in_array($extension, self::DEFLATE_EXTENSIONS, true) ? CompressionMethod::DEFLATE : CompressionMethod::STORE,
                    lastModificationDateTime: (new DateTimeImmutable())->setTimestamp(max(0, (int) filemtime($path))),
                );
            } finally {
                fclose($stream);
            }
            $count++;
        }

        return $count;
    }
}
