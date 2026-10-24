<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Support;

use PDO;

/**
 * Delegating PDO that "kills the process" right after its N-th exec() call (the statement itself was executed, but nothing
 * after it - like a crash between a statement and the progress update). An open transaction is rolled back, as the server
 * would do when the connection drops.
 */
final class KillingPdo extends PDO
{
    private int $calls = 0;

    /** @noinspection PhpMissingParentConstructorInspection */
    public function __construct(private readonly PDO $inner, private readonly int $killAt)
    {
    }

    public function exec(string $statement): int|false
    {
        $result = $this->inner->exec($statement);
        if (++$this->calls === $this->killAt) {
            if ($this->inner->inTransaction()) {
                $this->inner->rollBack();
            }
            throw new KilledException('killed');
        }

        return $result;
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        return $this->inner->prepare($query, $options);
    }

    public function beginTransaction(): bool
    {
        return $this->inner->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }

    public function rollBack(): bool
    {
        return $this->inner->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }
}
