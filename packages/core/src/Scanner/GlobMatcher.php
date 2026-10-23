<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Scanner;

/**
 * Minimal glob matcher on relative paths with "/" separators.
 *
 * `*` matches within a segment, `**` across segments, `?` one character, `[abc]` classes.
 * A pattern without "/" is matched against the basename and the full path.
 */
final class GlobMatcher
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function matches(string $pattern, string $path): bool
    {
        $regex = self::$cache[$pattern] ??= self::toRegex($pattern);
        if (preg_match($regex, $path) === 1) {
            return true;
        }
        if (!str_contains($pattern, '/')) {
            $base = ($pos = strrpos($path, '/')) === false ? $path : substr($path, $pos + 1);

            return preg_match($regex, $base) === 1;
        }

        return false;
    }

    private static function toRegex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '*') {
                if (($pattern[$i + 1] ?? '') === '*') {
                    $i++;
                    if (($pattern[$i + 1] ?? '') === '/') {
                        $i++;
                        $regex .= '(?:.*/)?';
                    } else {
                        $regex .= '.*';
                    }
                } else {
                    $regex .= '[^/]*';
                }
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } elseif ($char === '[') {
                $end = strpos($pattern, ']', $i + 1);
                if ($end === false) {
                    $regex .= '\[';
                } else {
                    $class = substr($pattern, $i + 1, $end - $i - 1);
                    $regex .= '[' . str_replace(['\\', '^'], ['\\\\', '\^'], $class) . ']';
                    $i = $end;
                }
            } else {
                $regex .= preg_quote($char, '~');
            }
        }

        return '~^' . $regex . '$~u';
    }
}
