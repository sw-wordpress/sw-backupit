<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Web;

final class WebResponse
{
    /**
     * @param array<string, string> $headers
     * @param list<string> $cookies Raw Set-Cookie header values.
     */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = [],
        public array $cookies = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, (string) json_encode($data, JSON_UNESCAPED_SLASHES), ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $location): self
    {
        return new self(303, '', ['Location' => $location]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        $security = [
            'Content-Security-Policy' => "default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
        foreach ($security + $this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }
        echo $this->body;
    }
}
