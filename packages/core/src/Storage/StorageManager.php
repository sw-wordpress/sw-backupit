<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Storage;

use SWBackupIt\Core\Exception\ConfigurationException;

/** Resolves configured targets and caches their storage implementations. */
final class StorageManager
{
    /** @var array<int, RemoteStorageInterface> */
    private array $cache = [];

    public function __construct(
        private readonly StorageResolverInterface $resolver,
        private readonly StorageFactory $factory = new StorageFactory(),
    ) {
    }

    public function target(int $id): StorageTarget
    {
        return $this->resolver->find($id) ?? throw new ConfigurationException('The storage target does not exist.', ['target' => $id]);
    }

    public function remote(int $id): RemoteStorageInterface
    {
        return $this->cache[$id] ??= $this->factory->create($this->target($id));
    }
}
