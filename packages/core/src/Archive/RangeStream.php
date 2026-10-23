<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Archive;

use RuntimeException;

/**
 * Stream wrapper `swbrange://` that reads a byte range of a local file and hashes it on the fly.
 *
 * Used so the ZIP writer can stream (a) whole files and (b) chunks of huge files
 * without loading data into memory, while the SHA-256 is computed in the same pass.
 *
 * Context options (wrapper "swbrange"): path (string), offset (int), length (int|null), result (ReadResult).
 */
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- method names are dictated by PHP's stream wrapper API
final class RangeStream
{
    private const PROTOCOL = 'swbrange';

    /** @var resource|null */
    public $context;
    /** @var resource|null */
    private $handle = null;
    private ?int $remaining = null;
    private ReadResult $result;
    private int $length = 0;

    public static function register(): void
    {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    /**
     * Opens a read stream for a file range.
     *
     * @return resource
     */
    public static function open(string $path, int $offset, ?int $length, ReadResult $result)
    {
        self::register();
        $context = stream_context_create([self::PROTOCOL => [
            'path' => $path,
            'offset' => $offset,
            'length' => $length,
            'result' => $result,
        ]]);
        $stream = @fopen(self::PROTOCOL . '://range', 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException('Cannot open file for archiving.');
        }

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $context = $this->context !== null ? stream_context_get_options($this->context) : [];
        $settings = $context[self::PROTOCOL] ?? null;
        if (!is_array($settings) || !is_string($settings['path'] ?? null) || !($settings['result'] ?? null) instanceof ReadResult) {
            return false;
        }
        $handle = @fopen($settings['path'], 'rb');
        if ($handle === false) {
            return false;
        }
        $offset = (int) ($settings['offset'] ?? 0);
        if ($offset > 0 && fseek($handle, $offset) !== 0) {
            fclose($handle);

            return false;
        }
        $this->handle = $handle;
        $this->remaining = isset($settings['length']) ? (int) $settings['length'] : null;
        $this->length = $this->remaining ?? 0;
        $this->result = $settings['result'];

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->handle === null) {
            return false;
        }
        if ($this->remaining !== null) {
            $count = min($count, $this->remaining);
            if ($count <= 0) {
                return '';
            }
        }
        $data = fread($this->handle, max(1, $count));
        if ($data === false) {
            return false;
        }
        if ($this->remaining !== null) {
            $this->remaining -= strlen($data);
        }
        $this->result->update($data);

        return $data;
    }

    public function stream_eof(): bool
    {
        if ($this->handle === null) {
            return true;
        }

        return ($this->remaining !== null && $this->remaining <= 0) || feof($this->handle);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => $this->length];
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
// phpcs:enable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
