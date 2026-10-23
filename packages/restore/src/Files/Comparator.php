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
            $b = $backup->current();
            $l = $local->current();
            while ($b !== null || $l !== null) {
                $bPath = $b === null ? null : (string) $b[0]['p'];
                $lPath = $l === null ? null : (string) $l[0]['p'];
                $order = $bPath === null ? 1 : ($lPath === null ? -1 : PathOrder::compare($bPath, $lPath));
                if ($order < 0) {
                    if ($selection->matches((string) $bPath)) {
                        $result->add($this->isRestorable($b[0]) ? 'only_in_backup' : 'incomparable', (string) $bPath);
                    }
                    $backup->next();
                    $b = $backup->valid() ? $backup->current() : null;
                } elseif ($order > 0) {
                    if ($selection->matches((string) $lPath)) {
                        $result->add('only_local', (string) $lPath);
                    }
                    $local->next();
                    $l = $local->valid() ? $local->current() : null;
                } else {
                    if ($selection->matches((string) $bPath)) {
                        $result->add($this->classify($b[0], $l[0], $localRoot, $mode), (string) $bPath);
                    }
                    $backup->next();
                    $local->next();
                    $b = $backup->valid() ? $backup->current() : null;
                    $l = $local->valid() ? $local->current() : null;
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
