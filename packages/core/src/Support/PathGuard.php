<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Support;

use SWBackupIt\Core\Exception\PathViolationException;

/**
 * Validates untrusted relative paths (archive entries, index entries, user input)
 * and resolves them below a trusted root without following symlinks.
 */
final class PathGuard
{
    /**
     * Returns the normalized relative path or throws.
     *
     * Rejects: empty, NUL, backslashes, absolute paths, drive letters, "." / ".." segments,
     * empty segments, control characters.
     */
    public static function assertSafeRelative(string $path): string
    {
        if ($path === '' || strlen($path) > 4096) {
            throw new PathViolationException('Unsafe path: empty or too long.');
        }
        if (str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new PathViolationException('Unsafe path: forbidden character.');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new PathViolationException('Unsafe path: control character.');
        }
        if ($path[0] === '/' || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new PathViolationException('Unsafe path: absolute path.');
        }
        $normalized = rtrim($path, '/');
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new PathViolationException('Unsafe path: traversal or empty segment.');
            }
        }

        return $normalized;
    }

    /**
     * Resolves a validated relative path below $root and verifies that no existing
     * ancestor between the root and the target is a symlink.
     *
     * $root must be an existing directory (it is canonicalised with realpath once).
     */
    public static function resolveBelow(string $root, string $relative): string
    {
        $relative = self::assertSafeRelative($relative);
        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false || !is_dir($canonicalRoot)) {
            throw new PathViolationException('Target root does not exist.');
        }
        $current = rtrim($canonicalRoot, '/');
        $segments = explode('/', $relative);
        foreach ($segments as $index => $segment) {
            $current .= '/' . $segment;
            $isLast = $index === count($segments) - 1;
            if (is_link($current)) {
                throw new PathViolationException('Refusing to write through a symlink.', ['path' => $relative]);
            }
            if (!$isLast && file_exists($current) && !is_dir($current)) {
                throw new PathViolationException('A path component is not a directory.', ['path' => $relative]);
            }
        }

        return $current;
    }

    /** True when $path is $root or lies below it (lexical check on canonical paths). */
    public static function isBelow(string $root, string $path): bool
    {
        $root = rtrim($root, '/');

        return $path === $root || str_starts_with($path, $root . '/');
    }
}
