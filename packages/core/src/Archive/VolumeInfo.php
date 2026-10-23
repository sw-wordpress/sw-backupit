<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Archive;

/** Description of a finished, immutable archive volume (as stored in checkpoints and the manifest). */
final readonly class VolumeInfo
{
    public function __construct(
        public int $index,
        public string $name,
        public int $size,
        public string $sha256,
        public int $entries,
    ) {
    }

    /**
     * @return array{index: int, name: string, size: int, sha256: string, entries: int}
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'name' => $this->name,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'entries' => $this->entries,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['index'],
            (string) $data['name'],
            (int) $data['size'],
            (string) $data['sha256'],
            (int) $data['entries'],
        );
    }

    public static function nameFor(int $index): string
    {
        return sprintf('backup-%03d.zip', $index);
    }
}
