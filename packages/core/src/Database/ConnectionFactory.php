<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Database;

use PDO;
use PDOException;
use SWBackupIt\Core\Exception\DatabaseException;

final class ConnectionFactory
{
    public static function create(ConnectionConfig $config): PDO
    {
        try {
            $pdo = new PDO($config->dsn(), $config->user, $config->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => true,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);
        } catch (PDOException $exception) {
            // The PDO message may contain the DSN or user name; never forward it verbatim.
            throw new DatabaseException(
                'Cannot connect to the database.',
                ['code' => (string) $exception->getCode()],
                false,
                null,
            );
        }
        $pdo->exec('SET NAMES ' . $config->charset);
        // Raw newlines/quotes in data must be escaped with backslashes by PDO::quote().
        $pdo->exec("SET SESSION sql_mode = ''");
        $pdo->exec("SET SESSION time_zone = '+00:00'");

        return $pdo;
    }
}
