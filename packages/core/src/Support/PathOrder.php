<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Support;

/**
 * Canonical ordering of relative paths used by the file index.
 *
 * Paths are compared segment by segment (byte-wise), which equals a depth-first
 * traversal with sorted directory entries. Both the scanner and the incremental
 * merge-join rely on this order.
 */
final class PathOrder
{
    public static function compare(string $left, string $right): int
    {
        $a = explode('/', $left);
        $b = explode('/', $right);
        $count = min(count($a), count($b));
        for ($i = 0; $i < $count; $i++) {
            $result = strcmp($a[$i], $b[$i]);
            if ($result !== 0) {
                return $result < 0 ? -1 : 1;
            }
        }

        return count($a) <=> count($b);
    }
}
