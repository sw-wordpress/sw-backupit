<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Database;

use PDO;
use PDOStatement;
use SWBackupIt\Core\Exception\DatabaseException;

/** Small typed wrapper around PDO::query() (which is typed as returning false in stubs). */
final class PdoHelper
{
    public static function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new DatabaseException('Database query failed.');
        }

        return $statement;
    }
}
