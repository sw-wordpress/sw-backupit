<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Database;

use SWBackupIt\Core\Exception\ConfigurationException;

/** MySQL/MariaDB connection settings. The password is never part of toSafeArray(). */
final readonly class ConnectionConfig
{
    public function __construct(
        public string $host,
        public string $name,
        public string $user,
        #[\SensitiveParameter]
        public string $password,
        public int $port = 3306,
        public ?string $socket = null,
        public string $charset = 'utf8mb4',
    ) {
        if ($name === '' || preg_match('/^[A-Za-z0-9_$\-]+$/', $name) !== 1) {
            throw new ConfigurationException('Invalid database name.');
        }
        if (preg_match('/^[A-Za-z0-9_]+$/', $charset) !== 1) {
            throw new ConfigurationException('Invalid database charset.');
        }
    }

    /**
     * Parses a WordPress style DB_HOST ("host", "host:port", "host:/path/to.sock").
     */
    public static function fromWordPressHost(string $dbHost, string $name, string $user, #[\SensitiveParameter] string $password, string $charset = 'utf8mb4'): self
    {
        $host = $dbHost;
        $port = 3306;
        $socket = null;
        if (str_contains($dbHost, ':')) {
            [$host, $rest] = explode(':', $dbHost, 2);
            if ($rest !== '' && $rest[0] === '/') {
                $socket = $rest;
            } elseif (ctype_digit($rest)) {
                $port = (int) $rest;
            }
        }
        if ($charset === 'utf8' || $charset === '') {
            $charset = 'utf8mb4';
        }

        return new self($host === '' ? 'localhost' : $host, $name, $user, $password, $port, $socket, $charset);
    }

    public function dsn(): string
    {
        $parts = $this->socket !== null
            ? ['unix_socket=' . $this->socket]
            : ['host=' . $this->host, 'port=' . $this->port];

        return 'mysql:' . implode(';', $parts) . ';dbname=' . $this->name . ';charset=' . $this->charset;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toSafeArray(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'name' => $this->name, 'user' => $this->user, 'charset' => $this->charset];
    }
}
