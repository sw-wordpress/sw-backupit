<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Files;

final class ComparisonResult
{
    public const CATEGORIES = ['only_in_backup', 'only_local', 'modified', 'identical', 'incomparable'];

    /** @var array<string, int> */
    public array $counts = ['only_in_backup' => 0, 'only_local' => 0, 'modified' => 0, 'identical' => 0, 'incomparable' => 0];
    /** @var array<string, list<string>> */
    public array $samples = ['only_in_backup' => [], 'only_local' => [], 'modified' => [], 'identical' => [], 'incomparable' => []];

    public function add(string $category, string $path, int $sampleLimit = 100): void
    {
        $this->counts[$category]++;
        if (count($this->samples[$category]) < $sampleLimit && $category !== 'identical') {
            $this->samples[$category][] = $path;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->counts + ['samples' => $this->samples];
    }
}
