<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Scanner;

/**
 * Include/exclude rules of a profile for the file scanner (PLAN.md §7.1).
 */
final class RuleSet
{
    /**
     * @param list<string> $includePaths
     * @param list<string> $excludePaths
     * @param list<string> $includePatterns
     * @param list<string> $excludePatterns
     */
    public function __construct(
        private readonly array $includePaths = [],
        private readonly array $excludePaths = [],
        private readonly array $includePatterns = [],
        private readonly array $excludePatterns = [],
    ) {
    }

    public function visibility(string $relativePath, bool $isDirectory): Visibility
    {
        foreach ($this->excludePaths as $excluded) {
            $excluded = trim($excluded, '/');
            if ($relativePath === $excluded || str_starts_with($relativePath, $excluded . '/')) {
                return Visibility::Skip;
            }
        }
        foreach ($this->excludePatterns as $pattern) {
            if (GlobMatcher::matches($pattern, $relativePath)) {
                return Visibility::Skip;
            }
        }

        $inScope = $this->includePaths === [];
        foreach ($this->includePaths as $included) {
            $included = trim($included, '/');
            if ($relativePath === $included || str_starts_with($relativePath, $included . '/')) {
                $inScope = true;
                break;
            }
            if ($isDirectory && str_starts_with($included, $relativePath . '/')) {
                return Visibility::Traverse;
            }
        }
        if (!$inScope) {
            return Visibility::Skip;
        }
        if (!$isDirectory && $this->includePatterns !== []) {
            foreach ($this->includePatterns as $pattern) {
                if (GlobMatcher::matches($pattern, $relativePath)) {
                    return Visibility::Emit;
                }
            }

            return Visibility::Skip;
        }

        return Visibility::Emit;
    }
}
