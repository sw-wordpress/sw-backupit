<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Archive;

use SWBackupIt\Core\Domain\CompressionSettings;
use ZipStream\CompressionMethod;

/** Decides per entry whether deflate is worth the CPU. */
final class CompressionPolicy
{
    private const INCOMPRESSIBLE = [
        'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic',
        'mp3', 'mp4', 'm4a', 'mov', 'avi', 'mkv', 'webm', 'ogg', 'flac', 'aac', 'pdf', 'woff', 'woff2', 'docx',
        'xlsx', 'pptx', 'jar',
    ];

    public function __construct(private readonly CompressionSettings $settings)
    {
    }

    public function methodFor(string $path, int $size): CompressionMethod
    {
        if ($this->settings->method === CompressionSettings::METHOD_STORE || $size === 0) {
            return CompressionMethod::STORE;
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::INCOMPRESSIBLE, true) ? CompressionMethod::STORE : CompressionMethod::DEFLATE;
    }

    public function level(): int
    {
        return $this->settings->level;
    }
}
