<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Files;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SWBackupIt\Core\Archive\VolumeReader;
use SWBackupIt\Core\Exception\IntegrityException;
use SWBackupIt\Core\Exception\PathViolationException;
use SWBackupIt\Core\Support\PathGuard;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Restore\Backup\BackupSet;
use ZipArchive;

/**
 * Secure, resumable file extraction (PLAN.md §13, CODE-STYLE §4.5).
 *
 * Security properties:
 * - Every index path is validated (no absolute paths, traversal, backslashes, NUL) and resolved below the
 *   canonical target root; an existing symlink anywhere on the way aborts the write.
 * - Symlinks from the backup are never recreated, special files are never created.
 * - Files are written to a temporary name in the destination directory, verified (size + SHA-256) and then
 *   atomically renamed; a failed or interrupted extraction never leaves a half-written file under the final name.
 * - Read sizes are bounded by the index (zip bomb protection) and by RestoreLimits; the compression ratio is checked.
 * - Nothing from the archive is ever included or executed.
 */
final class FileRestorer
{
    /** Upper bound of paths listed per note (skipped symlinks, inconsistent files) in the restore result. */
    public const MAX_REPORTED_SYMLINKS = 50;

    private ?ZipArchive $zip = null;
    private ?string $zipKey = null;

    public function __construct(
        private readonly BackupSet $set,
        private readonly RestoreOptions $options,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array<string, mixed> $state Resume state (empty for a fresh run).
     * @param callable(array<string, mixed>): void $onCheckpoint Persists the state; called regularly.
     * @param callable(string, array<string, mixed>): void|null $onProgress
     */
    public function run(array $state, ResourceBudget $budget, callable $onCheckpoint, ?callable $onProgress = null): FileRestoreResult
    {
        $result = new FileRestoreResult();
        $state += ['offset' => 0, 'files' => 0, 'dirs' => 0, 'bytes' => 0, 'skipped' => 0, 'unchanged' => 0, 'failed' => 0, 'entries' => 0];
        $limits = $this->options->limits;
        if (!is_dir($this->options->targetPath)) {
            throw new PathViolationException('The restore target directory does not exist.');
        }
        $declared = (int) ($this->set->manifest()->section('files')['bytes'] ?? 0);
        if ($declared > $limits->maxTotalBytes) {
            throw new IntegrityException('The backup exceeds the configured restore size limit.');
        }

        $sinceCheckpoint = 0;
        $lastCheckpoint = microtime(true);
        $finished = true;
        try {
            foreach ($this->set->indexReader()->read((int) $state['offset']) as [$entry, $next]) {
                if (++$state['entries'] > $limits->maxEntries) {
                    throw new IntegrityException('The backup contains more entries than the configured limit.');
                }
                $path = (string) $entry['p'];
                if ($this->options->selection->matches($path)) {
                    try {
                        $this->handle($entry, $state);
                    } catch (PathViolationException | IntegrityException $exception) {
                        if ($exception instanceof PathViolationException) {
                            throw $exception;
                        }
                        $state['failed']++;
                        $result->failures[] = $path . ': ' . $exception->getMessage();
                        $this->logger->error($exception->getMessage(), ['path' => $path]);
                        if ($exception->context() !== [] && ($exception->context()['fatal'] ?? false)) {
                            throw $exception;
                        }
                    }
                    if ($onProgress !== null) {
                        $onProgress($path, $state);
                    }
                }
                $state['offset'] = $next;
                $sinceCheckpoint++;
                if ($sinceCheckpoint % 20 === 0 && $budget->exhausted()) {
                    $finished = false;
                    break;
                }
                if ($sinceCheckpoint >= 200 || microtime(true) - $lastCheckpoint > 2.0) {
                    $onCheckpoint($state);
                    $sinceCheckpoint = 0;
                    $lastCheckpoint = microtime(true);
                }
            }
        } finally {
            $this->closeZip();
        }
        $onCheckpoint($state);
        $result->finished = $finished;
        $result->state = $state;

        return $result;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $state
     */
    private function handle(array $entry, array &$state): void
    {
        $relative = PathGuard::assertSafeRelative((string) $entry['p']);
        $type = (string) $entry['t'];
        $status = (string) ($entry['st'] ?? 'ok');
        if ($type === 'l') {
            $this->logger->notice('Symlink from backup not recreated (policy).', ['path' => $relative]);
            $state['skipped']++;
            // Reported to the user at the end of the restore (bounded list) so links can be recreated manually.
            $state['symlinks'] = (int) ($state['symlinks'] ?? 0) + 1;
            if (count($state['symlink_list'] ?? []) < self::MAX_REPORTED_SYMLINKS) {
                $state['symlink_list'][] = $relative . ' -> ' . (string) ($entry['lt'] ?? '?');
            }

            return;
        }
        if ($type === 'f' && in_array($status, ['unreadable', 'vanished', 'skipped'], true)) {
            $this->logger->warning('Entry was not part of the backup data, skipped.', ['path' => $relative, 'status' => $status]);
            $state['skipped']++;

            return;
        }
        $target = PathGuard::resolveBelow($this->options->targetPath, $relative);

        if ($type === 'd') {
            if (!$this->options->dryRun) {
                if (is_file($target)) {
                    throw new IntegrityException('A file exists where a directory must be created.', ['path' => $relative]);
                }
                if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new IntegrityException('Cannot create directory.', ['path' => $relative]);
                }
                @chmod($target, (((int) ($entry['mode'] ?? 0755)) & 0775) | 0700);
            }
            $state['dirs']++;

            return;
        }
        if ($type !== 'f') {
            $state['skipped']++;

            return;
        }

        if ((int) $entry['s'] > $this->options->limits->maxEntryBytes) {
            throw new IntegrityException('A file exceeds the configured per-file size limit.', ['path' => $relative]);
        }
        $exists = file_exists($target) || is_link($target);
        if ($exists && is_dir($target)) {
            throw new IntegrityException('A directory exists where a file must be restored.', ['path' => $relative]);
        }
        if ($exists) {
            if ($this->options->overwrite === OverwriteMode::Never) {
                $state['skipped']++;

                return;
            }
            if ($this->options->overwrite === OverwriteMode::IfDifferent && $this->isIdentical($entry, $target)) {
                $state['unchanged']++;

                return;
            }
        }
        if ($this->options->dryRun) {
            $state['files']++;
            $state['bytes'] += (int) $entry['s'];

            return;
        }
        $directory = dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new IntegrityException('Cannot create directory.', ['path' => dirname($relative)]);
        }
        // Re-validate after creating directories (a directory could have been replaced by a symlink meanwhile).
        $target = PathGuard::resolveBelow($this->options->targetPath, $relative);
        $this->extract($entry, $relative, $target);
        if ($status === 'changed') {
            // The file changed while it was archived; its content may mix old and new data. Reported to the user.
            $state['changed'] = (int) ($state['changed'] ?? 0) + 1;
            if (count($state['changed_list'] ?? []) < self::MAX_REPORTED_SYMLINKS) {
                $state['changed_list'][] = $relative;
            }
        }
        $state['files']++;
        $state['bytes'] += (int) $entry['s'];
        if ($state['bytes'] > $this->options->limits->maxTotalBytes) {
            throw new IntegrityException('Restore exceeded the configured total size limit.', ['fatal' => true]);
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function isIdentical(array $entry, string $target): bool
    {
        if (is_link($target) || !is_file($target) || filesize($target) !== (int) $entry['s']) {
            return false;
        }
        if (isset($entry['h'])) {
            $hash = hash_file('sha256', $target);

            return $hash !== false && hash_equals((string) $entry['h'], $hash);
        }

        return filemtime($target) === (int) $entry['m'];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function extract(array $entry, string $relative, string $target): void
    {
        $temporary = dirname($target) . '/.swbi-' . bin2hex(random_bytes(6)) . '.tmp';
        $output = fopen($temporary, 'xb');
        if ($output === false) {
            throw new IntegrityException('Cannot write to the restore target (permissions?).', ['path' => $relative]);
        }
        $declared = (int) $entry['s'];
        $total = hash_init('sha256');
        $written = 0;
        try {
            $chunks = isset($entry['c']) && is_array($entry['c']) ? $entry['c'] : [['v' => $entry['v'], 'a' => $entry['a'] ?? $entry['p'], 's' => $declared, 'h' => $entry['h'] ?? null]];
            $source = isset($entry['b']) ? $this->ancestorSet((string) $entry['b'], $relative) : $this->set;
            foreach ($chunks as $chunk) {
                $zip = $this->zipFor($source, (int) $chunk['v']);
                $name = (string) $chunk['a'];
                $stat = $zip->statName($name);
                if ($stat === false) {
                    throw new IntegrityException('Archive entry is missing.', ['path' => $relative]);
                }
                $size = (int) $stat['size'];
                $compressed = max(1, (int) $stat['comp_size']);
                if ($size !== (int) $chunk['s']) {
                    throw new IntegrityException('Archive entry size differs from the index.', ['path' => $relative]);
                }
                if ($size >= 65536 && $size / $compressed > $this->options->limits->maxCompressionRatio) {
                    throw new IntegrityException('Suspicious compression ratio, refusing to extract.', ['path' => $relative]);
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new IntegrityException('Cannot read archive entry.', ['path' => $relative]);
                }
                $segment = hash_init('sha256');
                $read = 0;
                while (!feof($stream)) {
                    $data = fread($stream, 1048576);
                    if ($data === false) {
                        fclose($stream);
                        throw new IntegrityException('Reading the archive entry failed.', ['path' => $relative]);
                    }
                    $read += strlen($data);
                    if ($read > $size || $written + strlen($data) > $declared) {
                        fclose($stream);
                        throw new IntegrityException('Archive entry is larger than declared (possible zip bomb).', ['path' => $relative]);
                    }
                    hash_update($segment, $data);
                    hash_update($total, $data);
                    if (fwrite($output, $data) !== strlen($data)) {
                        fclose($stream);
                        throw new IntegrityException('Writing to the restore target failed (disk full?).', ['path' => $relative]);
                    }
                    $written += strlen($data);
                }
                fclose($stream);
                if ($read !== $size) {
                    throw new IntegrityException('Archive entry is truncated.', ['path' => $relative]);
                }
                if ($this->options->verifyHashes && isset($chunk['h']) && !hash_equals((string) $chunk['h'], hash_final($segment))) {
                    throw new IntegrityException('Checksum mismatch while restoring a file.', ['path' => $relative]);
                }
            }
            if ($written !== $declared) {
                throw new IntegrityException('Restored file size differs from the index.', ['path' => $relative]);
            }
            if ($this->options->verifyHashes && isset($entry['h']) && !hash_equals((string) $entry['h'], hash_final($total))) {
                throw new IntegrityException('Checksum mismatch while restoring a file.', ['path' => $relative]);
            }
            fflush($output);
            fclose($output);
            $output = null;
            @chmod($temporary, (((int) ($entry['mode'] ?? 0644)) & 0775) | 0600);
            @touch($temporary, (int) ($entry['m'] ?? time()));
            if (is_link($target) || (file_exists($target) && !is_file($target))) {
                throw new PathViolationException('Refusing to replace a non-regular file.', ['path' => $relative]);
            }
            if (!rename($temporary, $target)) {
                throw new IntegrityException('Cannot move the restored file into place.', ['path' => $relative]);
            }
        } catch (\Throwable $throwable) {
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($temporary);
            throw $throwable;
        }
    }

    private function ancestorSet(string $backupId, string $relative): BackupSet
    {
        try {
            return $this->set->ancestor($backupId);
        } catch (\SWBackupIt\Core\Exception\SwbiException $exception) {
            throw new IntegrityException($exception->getMessage(), ['path' => $relative, 'fatal' => true]);
        }
    }

    private function zipFor(BackupSet $set, int $volume): ZipArchive
    {
        $key = $set->manifest()->backupId() . ':' . $volume;
        if ($this->zip !== null && $this->zipKey === $key) {
            return $this->zip;
        }
        $this->closeZip();
        $this->zip = (new VolumeReader())->open($set->volumePath($set->volumeFileName($volume)));
        $this->zipKey = $key;

        return $this->zip;
    }

    private function closeZip(): void
    {
        $this->zip?->close();
        $this->zip = null;
        $this->zipKey = null;
    }
}
