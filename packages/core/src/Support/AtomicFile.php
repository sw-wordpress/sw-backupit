<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Support;

use JsonException;
use SWBackupIt\Core\Exception\SwbiException;

/**
 * Durable file writes: write to a temp file in the same directory, fsync, rename.
 *
 * A reader either sees the old or the new complete content, never a partial file.
 * A checkpoint may only be considered valid after write() returned.
 */
final class AtomicFile
{
    public static function write(string $path, string $contents): void
    {
        $directory = dirname($path);
        self::ensureDirectory($directory);
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            throw new SwbiException('Cannot write file.', ['path' => basename($path)]);
        }
        try {
            $length = strlen($contents);
            $written = 0;
            while ($written < $length) {
                $result = fwrite($handle, substr($contents, $written));
                if ($result === false || $result === 0) {
                    throw new SwbiException('Disk write failed (disk full?).', ['path' => basename($path)], true);
                }
                $written += $result;
            }
            fflush($handle);
            self::sync($handle);
        } catch (SwbiException $exception) {
            fclose($handle);
            @unlink($temporary);
            throw $exception;
        }
        fclose($handle);

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new SwbiException('Cannot finalize file.', ['path' => basename($path)]);
        }
    }

    /**
     * @param array<mixed> $data
     */
    public static function writeJson(string $path, array $data): void
    {
        try {
            $json = json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            );
        } catch (JsonException $exception) {
            throw new SwbiException('Cannot encode JSON.', ['path' => basename($path)], false, $exception);
        }
        self::write($path, $json . "\n");
    }

    /**
     * @return array<mixed>
     */
    public static function readJson(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new SwbiException('Cannot read file.', ['path' => basename($path)]);
        }
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SwbiException('File contains invalid JSON.', ['path' => basename($path)], false, $exception);
        }
        if (!is_array($decoded)) {
            throw new SwbiException('File does not contain a JSON object.', ['path' => basename($path)]);
        }

        return $decoded;
    }

    public static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new SwbiException('Cannot create directory.', ['path' => basename($directory)]);
        }
    }

    /**
     * Flushes a stream to stable storage where the platform supports it.
     *
     * @param resource $handle
     */
    public static function sync($handle): void
    {
        if (function_exists('fsync')) {
            fsync($handle);
        }
    }

    /** Removes a directory tree. Only used on directories created by sw-backupit. */
    public static function removeTree(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        $items = scandir($directory);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
