<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Files;

use SWBackupIt\Core\Scanner\GlobMatcher;

/** Selects entries from the backup file index (files, directories, glob patterns). Empty = everything. */
final class Selection
{
    /**
     * @param list<string> $paths    Exact files or directory prefixes (no leading slash).
     * @param list<string> $patterns Glob patterns.
     */
    public function __construct(private readonly array $paths = [], private readonly array $patterns = [])
    {
    }

    /**
     * @return array{paths: list<string>, patterns: list<string>}
     */
    public function toArray(): array
    {
        return ['paths' => $this->paths, 'patterns' => $this->patterns];
    }

    public function isAll(): bool
    {
        return $this->paths === [] && $this->patterns === [];
    }

    public function matches(string $path): bool
    {
        if ($this->isAll()) {
            return true;
        }
        foreach ($this->paths as $selected) {
            $selected = trim($selected, '/');
            if ($path === $selected || str_starts_with($path, $selected . '/')) {
                return true;
            }
        }
        foreach ($this->patterns as $pattern) {
            if (GlobMatcher::matches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
