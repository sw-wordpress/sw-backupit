<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Storage;

interface StorageResolverInterface
{
    public function find(int $id): ?StorageTarget;
}
