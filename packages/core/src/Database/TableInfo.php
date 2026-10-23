<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Database;

final readonly class TableInfo
{
    public function __construct(
        public string $name,
        public bool $isView,
        public int $estimatedRows = 0,
        public string $engine = '',
    )
    {
    }

    /**
     * @return array{name: string, type: string, rows: int, engine: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'type' => $this->isView ? 'view' : 'table', 'rows' => $this->estimatedRows, 'engine' => $this->engine];
    }
}
