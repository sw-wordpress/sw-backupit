<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Domain;

use SWBackupIt\Core\Exception\ConfigurationException;

final readonly class CompressionSettings
{
    public const METHOD_DEFLATE = 'deflate';
    public const METHOD_STORE = 'store';

    public function __construct(public string $method = self::METHOD_DEFLATE, public int $level = 6)
    {
        if (!in_array($method, [self::METHOD_DEFLATE, self::METHOD_STORE], true)) {
            throw new ConfigurationException('Unsupported compression method.', ['method' => $method]);
        }
        if ($level < 1 || $level > 9) {
            throw new ConfigurationException('Compression level must be between 1 and 9.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['method'] ?? null) ? $data['method'] : self::METHOD_DEFLATE,
            is_numeric($data['level'] ?? null) ? (int) $data['level'] : 6,
        );
    }

    /**
     * @return array{method: string, level: int}
     */
    public function toArray(): array
    {
        return ['method' => $this->method, 'level' => $this->level];
    }
}
