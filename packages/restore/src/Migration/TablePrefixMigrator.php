<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Migration;

use PDO;
use SWBackupIt\Core\Database\DatabaseExporter;
use SWBackupIt\Core\Exception\ConfigurationException;

/**
 * Renames WordPress tables from one prefix to another and fixes the prefix dependent keys
 * ({prefix}user_roles option, {prefix}capabilities / {prefix}user_level user meta, ...).
 */
final class TablePrefixMigrator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return int Number of renamed tables.
     */
    public function migrate(string $oldPrefix, string $newPrefix): int
    {
        foreach ([$oldPrefix, $newPrefix] as $prefix) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
                throw new ConfigurationException('Invalid table prefix.');
            }
        }
        if ($oldPrefix === $newPrefix) {
            return 0;
        }
        $tables = [];
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
            $name = (string) $row[0];
            if (str_starts_with($name, $oldPrefix)) {
                $tables[] = $name;
            }
        }
        foreach ($tables as $name) {
            $target = $newPrefix . substr($name, strlen($oldPrefix));
            if ($this->tableExists($target)) {
                throw new ConfigurationException('The target table already exists: ' . $target);
            }
        }
        foreach ($tables as $name) {
            $target = $newPrefix . substr($name, strlen($oldPrefix));
            $this->pdo->exec('RENAME TABLE ' . DatabaseExporter::id($name) . ' TO ' . DatabaseExporter::id($target));
        }
        $options = $newPrefix . 'options';
        if ($this->tableExists($options)) {
            $this->pdo->prepare('UPDATE ' . DatabaseExporter::id($options) . ' SET option_name = ? WHERE option_name = ?')
                ->execute([$newPrefix . 'user_roles', $oldPrefix . 'user_roles']);
        }
        $usermeta = $newPrefix . 'usermeta';
        if ($this->tableExists($usermeta)) {
            $update = $this->pdo->prepare(
                'UPDATE ' . DatabaseExporter::id($usermeta) . ' SET meta_key = CONCAT(?, SUBSTRING(meta_key, ?)) WHERE meta_key LIKE ?',
            );
            $update->execute([$newPrefix, strlen($oldPrefix) + 1, str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $oldPrefix) . '%']);
        }

        return count($tables);
    }

    private function tableExists(string $name): bool
    {
        $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $name);
        $statement = $this->pdo->query('SHOW TABLES LIKE ' . $this->pdo->quote($pattern));

        return $statement !== false && $statement->fetch() !== false;
    }
}
