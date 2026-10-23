<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Storage;

use SWBackupIt\Core\Exception\ConfigurationException;

/** Configured storage destination. Credentials are runtime only and never serialised or logged. */
final readonly class StorageTarget
{
    public const TYPES = ['local', 'sftp', 's3'];

    /**
     * @param array<string, mixed> $config      Non-secret settings.
     * @param array<string, string> $credentials Secrets (password, private_key, secret_key, access_key ...).
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public array $config,
        #[\SensitiveParameter]
        public array $credentials = [],
        public bool $immutable = false,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new ConfigurationException('Unsupported storage type.', ['type' => $type]);
        }
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'type' => $this->type];
    }

    public function __serialize(): array
    {
        throw new ConfigurationException('Storage targets must not be serialized.');
    }
}
