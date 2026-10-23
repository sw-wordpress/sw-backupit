<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Files;

use SWBackupIt\Core\Scanner\FileIndexReader;
use SWBackupIt\Core\Scanner\FileScanner;
use SWBackupIt\Core\Scanner\RuleSet;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\PathOrder;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Restore\Backup\BackupSet;

/**
 * Compares the backup file index with a local directory before a restore (PLAN.md §13.5).
 *
 * Both sides are streamed in canonical path order and merge-joined, so memory stays constant for any number of files.
 * Categories: only in backup / only local / modified / identical / incomparable (type mismatch, symlinks, unreadable
 * or vanished entries, missing checksums in sha256 mode). The local scan uses the include/exclude rules of the backup's profile,
 * so unrelated directories do not show up as "only local".
 */
final class Comparator
{
    public const MODE_METADATA = 'metadata';
    public const MODE_SHA256 = 'sha256';

    public function compare(BackupSet $set, string $localRoot, string $mode = self::MODE_METADATA, ?Selection $selection = null, string $tempDirectory = ''): ComparisonResult
    {
        $selection ??= new Selection();
        $result = new ComparisonResult();
        $temp = ($tempDirectory !== '' ? $tempDirectory : sys_get_temp_dir()) . '/swbi-compare-' . bin2hex(random_bytes(5));
        AtomicFile::ensureDirectory($temp);
        try {
            $profile = $set->manifest()->section('profile');
            $rules = new RuleSet(
                $this->strings($profile['include_paths'] ?? []),
                $this->strings($profile['exclude_paths'] ?? []),
                $this->strings($profile['include_patterns'] ?? []),
                $this->strings($profile['exclude_patterns'] ?? []),
            );
            $inventory = $temp . '/local.ndjson';
            (new FileScanner($localRoot, $rules))->scan($inventory, [], ResourceBudget::unlimited());
            $backup = $set->indexReader()->read();
            $local = (new FileIndexReader($inventory))->read();
            $current = static fn (\Generator $generator): ?array => $generator->valid() ? $generator->current() : null;
            $b = $current($backup);
            $l = $current($local);
            while ($b !== null || $l !== null) {
                $order = 0;
                if ($b === null) {
                    $order = 1;
                } elseif ($l === null) {
                    $order = -1;
                } else {
                    $order = PathOrder::compare((string) $b[0]['p'], (string) $l[0]['p']);
                }
                if ($order < 0) {
                    $path = (string) ($b[0]['p'] ?? '');
                    if ($selection->matches($path)) {
                        $result->add($this->isRestorable($b[0] ?? []) ? 'only_in_backup' : 'incomparable', $path);
                    }
                    $backup->next();
                    $b = $current($backup);
                } elseif ($order > 0) {
                    $path = (string) ($l[0]['p'] ?? '');
                    if ($selection->matches($path)) {
                        $result->add('only_local', $path);
                    }
                    $local->next();
                    $l = $current($local);
                } else {
                    $path = (string) ($b[0]['p'] ?? '');
                    if ($selection->matches($path)) {
                        $result->add($this->classify($b[0] ?? [], $l[0] ?? [], $localRoot, $mode), $path);
                    }
                    $backup->next();
                    $local->next();
                    $b = $current($backup);
                    $l = $current($local);
                }
            }
        } finally {
            AtomicFile::removeTree($temp);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function isRestorable(array $entry): bool
    {
        return in_array($entry['t'], ['f', 'd'], true) && !in_array($entry['st'] ?? 'ok', ['unreadable', 'vanished', 'skipped'], true);
    }

    /**
     * @param array<string, mixed> $backup
     * @param array<string, mixed> $local
     */
    private function classify(array $backup, array $local, string $root, string $mode): string
    {
        if (!$this->isRestorable($backup) || $backup['t'] !== $local['t'] || ($local['st'] ?? 'ok') !== 'ok') {
            return 'incomparable';
        }
        if ($backup['t'] === 'd') {
            return 'identical';
        }
        if ((int) $backup['s'] !== (int) $local['s']) {
            return 'modified';
        }
        if ($mode === self::MODE_SHA256) {
            if (!isset($backup['h'])) {
                return 'incomparable';
            }
            $hash = hash_file('sha256', rtrim($root, '/') . '/' . $backup['p']);

            return $hash !== false && hash_equals((string) $backup['h'], $hash) ? 'identical' : 'modified';
        }

        return abs((int) $backup['m'] - (int) $local['m']) <= 2 ? 'identical' : 'modified';
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
