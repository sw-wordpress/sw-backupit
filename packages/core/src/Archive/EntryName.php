<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Archive;

/**
 * Maps a relative file path to its ZIP entry name.
 *
 * ZipStream silently rewrites the characters \ : * ? " < > | to "_" (Windows safety). That would make
 * entry names ambiguous, so those characters (and "%") are percent-encoded instead. The file index always
 * records the real path (`p`) and the entry name (`a`), restore only trusts `a` for lookups.
 */
final class EntryName
{
    public static function for(string $path): string
    {
        return preg_replace_callback(
            '/[\\\\:*?"<>|%]/',
            static fn (array $match): string => sprintf('%%%02X', ord($match[0])),
            $path,
        ) ?? $path;
    }
}
