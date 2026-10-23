<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Scanner;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Support\ResourceBudget;

/**
 * Resumable, deterministic file inventory (PLAN.md §7.1/§7.2).
 *
 * Traversal is depth-first with byte-wise sorted directory entries (see PathOrder).
 * Symlinks are never followed; they are recorded with their target.
 * The inventory is streamed to an NDJSON file, so memory is bounded by the largest directory listing.
 *
 * Resume: the cursor stores, per directory level, the last emitted entry name and the committed
 * inventory offset. Lines written after the last checkpoint are truncated on resume.
 */
final class FileScanner
{
    public const STAGING_MARKER = '.swbi-staging';

    private int $sinceCheck = 0;

    public function __construct(
        private readonly string $root,
        private readonly RuleSet $rules,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array<string, mixed> $cursor State returned by the previous call (empty on first call).
     */
    public function scan(string $inventoryPath, array $cursor, ResourceBudget $budget): ScanResult
    {
        $root = rtrim($this->root, '/');
        $offset = (int) ($cursor['offset'] ?? 0);
        $count = (int) ($cursor['count'] ?? 0);
        $bytes = (int) ($cursor['bytes'] ?? 0);
        /** @var list<array{dir: string, last: ?string}> $stack */
        $stack = isset($cursor['stack']) && is_array($cursor['stack']) ? $cursor['stack'] : [['dir' => '', 'last' => null]];
        $writer = new FileIndexWriter($inventoryPath, $offset);
        /** @var array<int, list<string>> $listings */
        $listings = [];

        try {
            while ($stack !== []) {
                $depth = count($stack) - 1;
                $directory = $stack[$depth]['dir'];
                $listings[$depth] ??= $this->list($root, $directory);
                $names = $listings[$depth];
                $last = $stack[$depth]['last'];
                $next = null;
                foreach ($names as $name) {
                    if ($last === null || strcmp($name, $last) > 0) {
                        $next = $name;
                        break;
                    }
                }
                if ($next === null) {
                    array_pop($stack);
                    unset($listings[$depth]);
                    continue;
                }
                $stack[$depth]['last'] = $next;
                $relative = $directory === '' ? $next : $directory . '/' . $next;
                $descend = $this->visit($root, $relative, $writer, $count, $bytes);
                if ($descend) {
                    $stack[] = ['dir' => $relative, 'last' => null];
                }
                if ($this->checkBudget($budget)) {
                    $offset = $writer->commit();

                    return new ScanResult(false, $this->cursor($stack, $offset, $count, $bytes));
                }
            }
            $offset = $writer->commit();

            return new ScanResult(true, $this->cursor([], $offset, $count, $bytes));
        } finally {
            $writer->close();
        }
    }

    private function checkBudget(ResourceBudget $budget): bool
    {
        if (++$this->sinceCheck < 50) {
            return false;
        }
        $this->sinceCheck = 0;

        return $budget->exhausted();
    }

    /**
     * @param list<array{dir: string, last: ?string}> $stack
     * @return array<string, mixed>
     */
    private function cursor(array $stack, int $offset, int $count, int $bytes): array
    {
        return ['stack' => $stack, 'offset' => $offset, 'count' => $count, 'bytes' => $bytes];
    }

    /**
     * @return list<string>
     */
    private function list(string $root, string $directory): array
    {
        $path = $directory === '' ? $root : $root . '/' . $directory;
        $items = @scandir($path, SCANDIR_SORT_NONE);
        if ($items === false) {
            $this->logger->warning('Directory is not readable.', ['path' => $directory]);

            return [];
        }
        $items = array_values(array_filter($items, static fn (string $name): bool => $name !== '.' && $name !== '..'));
        usort($items, 'strcmp');

        return $items;
    }

    /** Emits an index entry; returns true when the entry is a directory to descend into. */
    private function visit(string $root, string $relative, FileIndexWriter $writer, int &$count, int &$bytes): bool
    {
        $absolute = $root . '/' . $relative;
        if (!mb_check_encoding($relative, 'UTF-8')) {
            $this->logger->warning('Skipping entry with a non UTF-8 name.', ['path' => bin2hex($relative)]);

            return false;
        }
        $isLink = is_link($absolute);
        $isDirectory = !$isLink && is_dir($absolute);
        $visibility = $this->rules->visibility($relative, $isDirectory);
        if ($visibility === Visibility::Skip) {
            return false;
        }
        if ($isDirectory && is_file($absolute . '/' . self::STAGING_MARKER)) {
            // Never back up backup staging areas (recursive backups).
            return false;
        }
        if ($visibility === Visibility::Traverse) {
            return $isDirectory;
        }
        $stat = @lstat($absolute);
        if ($stat === false) {
            $this->logger->warning('Cannot stat entry, skipped.', ['path' => $relative]);

            return false;
        }
        $mode = $stat['mode'] & 0777;
        if ($isLink) {
            $target = @readlink($absolute);
            $writer->append([
                'p' => $relative, 't' => 'l', 's' => 0, 'm' => $stat['mtime'], 'mode' => $mode,
                'lt' => $target === false ? '' : $target, 'st' => 'ok',
            ]);
            $count++;

            return false;
        }
        if ($isDirectory) {
            $writer->append(['p' => $relative, 't' => 'd', 's' => 0, 'm' => $stat['mtime'], 'mode' => $mode, 'st' => 'ok']);
            $count++;

            return true;
        }
        if (!is_file($absolute)) {
            $this->logger->notice('Special file skipped.', ['path' => $relative]);

            return false;
        }
        $readable = is_readable($absolute);
        if (!$readable) {
            $this->logger->warning('File is not readable, not backed up.', ['path' => $relative]);
        }
        $writer->append([
            'p' => $relative, 't' => 'f', 's' => $stat['size'], 'm' => $stat['mtime'], 'mode' => $mode,
            'st' => $readable ? 'ok' : 'unreadable',
        ]);
        $count++;
        if ($readable) {
            $bytes += $stat['size'];
        }

        return false;
    }
}
