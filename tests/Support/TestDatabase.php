<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Support;

use PDO;
use PDOException;
use SWBackupIt\Core\Database\ConnectionConfig;

/**
 * Provides throw-away databases on the private test MariaDB instance (bin/test-db.sh).
 * Tests are skipped when no test server is reachable. Never point this at a real database.
 */
trait TestDatabase
{
    /** @var list<string> */
    private array $createdDatabases = [];

    protected function adminPdo(): PDO
    {
        $host = getenv('SWBI_TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('SWBI_TEST_DB_PORT') ?: 33077);
        $user = getenv('SWBI_TEST_DB_USER') ?: 'swbi';
        $password = getenv('SWBI_TEST_DB_PASSWORD') ?: 'swbi';
        try {
            return new PDO("mysql:host={$host};port={$port}", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
        } catch (PDOException) {
            self::markTestSkipped('No test database available. Start one with bin/test-db.sh start.');
        }
    }

    protected function createDatabase(): ConnectionConfig
    {
        $admin = $this->adminPdo();
        $name = 'swbi_t_' . bin2hex(random_bytes(5));
        $admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci");
        $this->createdDatabases[] = $name;

        return new ConnectionConfig(
            getenv('SWBI_TEST_DB_HOST') ?: '127.0.0.1',
            $name,
            getenv('SWBI_TEST_DB_USER') ?: 'swbi',
            getenv('SWBI_TEST_DB_PASSWORD') ?: 'swbi',
            (int) (getenv('SWBI_TEST_DB_PORT') ?: 33077),
        );
    }

    #[\PHPUnit\Framework\Attributes\After]
    protected function dropDatabases(): void
    {
        if ($this->createdDatabases === []) {
            return;
        }
        $admin = $this->adminPdo();
        foreach ($this->createdDatabases as $name) {
            $admin->exec("DROP DATABASE IF EXISTS `{$name}`");
        }
        $this->createdDatabases = [];
    }
}
