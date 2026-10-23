<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Database;

use PDO;
use SWBackupIt\Core\Scanner\GlobMatcher;

/**
 * Chooses the tables of a backup.
 *
 * Default: all tables with the WordPress prefix. Foreign tables (other prefixes) are only
 * included when explicitly requested, because they may belong to other applications.
 */
final class TableSelector
{
    /**
     * @param list<string> $include Exact names; empty = by prefix.
     * @param list<string> $exclude Exact names or glob patterns.
     * @return list<TableInfo>
     */
    public function select(PDO $pdo, string $prefix, array $include, array $exclude, bool $includeForeign): array
    {
        $rows = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        $statusRows = [];
        $engines = [];
        foreach ($pdo->query('SHOW TABLE STATUS')->fetchAll() as $status) {
            $statusRows[(string) $status['Name']] = (int) ($status['Rows'] ?? 0);
            $engines[(string) $status['Name']] = (string) ($status['Engine'] ?? '');
        }
        $selected = [];
        foreach ($rows as $row) {
            $name = (string) $row[0];
            $isView = strtoupper((string) $row[1]) === 'VIEW';
            if ($include !== []) {
                if (!in_array($name, $include, true)) {
                    continue;
                }
            } elseif (!$includeForeign && ($prefix === '' || !str_starts_with($name, $prefix))) {
                continue;
            }
            foreach ($exclude as $pattern) {
                if ($pattern === $name || GlobMatcher::matches($pattern, $name)) {
                    continue 2;
                }
            }
            $selected[] = new TableInfo($name, $isView, $statusRows[$name] ?? 0, $engines[$name] ?? '');
        }
        usort($selected, static fn (TableInfo $a, TableInfo $b): int => strcmp($a->name, $b->name));

        return $selected;
    }
}
