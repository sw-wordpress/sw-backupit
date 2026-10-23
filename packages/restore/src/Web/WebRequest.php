<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Web;

final readonly class WebRequest
{
    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $cookies
     * @param array<string, mixed> $server
     */
    public function __construct(
        public string $method,
        public array $query = [],
        public array $post = [],
        public array $cookies = [],
        public array $server = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            array_map('strval', array_filter($_GET, 'is_scalar')),
            $_POST,
            array_map('strval', array_filter($_COOKIE, 'is_scalar')),
            $_SERVER,
        );
    }

    public function isHttps(): bool
    {
        return ($this->server['HTTPS'] ?? '') !== '' && ($this->server['HTTPS'] ?? 'off') !== 'off';
    }

    public function post(string $name, string $default = ''): string
    {
        $value = $this->post[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
