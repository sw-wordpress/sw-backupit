<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Storage;

final class ArrayStorageResolver implements StorageResolverInterface
{
    /** @var array<int, StorageTarget> */
    private array $targets = [];

    /** @param list<StorageTarget> $targets */
    public function __construct(array $targets)
    {
        foreach ($targets as $target) {
            $this->targets[$target->id] = $target;
        }
    }

    public function find(int $id): ?StorageTarget
    {
        return $this->targets[$id] ?? null;
    }
}
