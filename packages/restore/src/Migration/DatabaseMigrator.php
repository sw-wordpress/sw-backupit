<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Migration;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SWBackupIt\Core\Database\DatabaseExporter;
use SWBackupIt\Core\Support\ResourceBudget;

/**
 * URL/path migration inside the restored database (PLAN.md §13.3).
 *
 * - Serialization safe via SerializedReplacer (no unserialize, no blind SQL REPLACE()).
 * - Keyset batches by primary key, constant memory, resumable: the progress row lives in a table of the target database and is
 *   updated in the SAME transaction as the batch, so a crash can neither skip nor repeat (double-replace) a batch.
 * - Dry run counts what would change and writes nothing (no progress table either).
 * - Tables without primary key are skipped and reported; primary key columns are never rewritten.
 */
final class DatabaseMigrator
{
    public const PROGRESS_TABLE = 'swbi_restore_progress';

    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $batchRows = 200,
    ) {
    }

    /** Removes the progress of an earlier migration (called before a fresh database import). */
    public static function reset(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ' . DatabaseExporter::id(self::PROGRESS_TABLE));
    }

    /**
     * @param array<string, string> $pairs search => replace
     * @param list<string> $tables Tables to process (empty = all tables starting with $prefix).
     * @param list<string> $skipColumns Column names never rewritten (WordPress: guid is an identifier, not a link).
     */
    public function run(array $pairs, string $prefix, array $tables = [], bool $dryRun = false, ?ResourceBudget $budget = null, array $skipColumns = ['guid']): MigrationResult
    {
        $result = new MigrationResult();
        $budget ??= ResourceBudget::unlimited();
        $replacer = new SerializedReplacer($pairs);
        $tables = $tables !== [] ? $tables : $this->tables($prefix);
        $progress = $dryRun ? [] : $this->loadProgress();
        $startIndex = (int) ($progress['table_index'] ?? 0);
        $cursor = is_array($progress['pk'] ?? null) ? array_values(array_map('strval', $progress['pk'])) : null;
        $offset = (int) ($progress['offset'] ?? 0);

        for ($index = $startIndex; $index < count($tables); $index++) {
            $table = $tables[$index];
            $meta = $this->describe($table, $skipColumns);
            if ($meta['pk'] === []) {
                $result->skippedTables[] = $table . ' (no primary key)';
                $cursor = null;
                $offset = 0;
                continue;
            }
            if ($meta['columns'] === []) {
                $cursor = null;
                $offset = 0;
                continue;
            }
            while (true) {
                $rows = $this->batch($table, $meta, $cursor, $offset);
                if ($rows === []) {
                    break;
                }
                $changes = [];
                foreach ($rows as $row) {
                    $result->rowsScanned++;
                    $set = [];
                    foreach ($meta['columns'] as $column) {
                        $value = $row[$column];
                        if (!is_string($value) || $value === '') {
                            continue;
                        }
                        $new = $replacer->replace($value);
                        if ($new !== $value) {
                            $set[$column] = $new;
                        }
                    }
                    if ($set !== []) {
                        $changes[] = [array_map(static fn (string $pk): string => (string) $row[$pk], $meta['pk']), $set];
                    }
                }
                $last = $rows[array_key_last($rows)];
                if ($meta['keyset']) {
                    $cursor = array_map(static fn (string $pk): string => (string) $last[$pk], $meta['pk']);
                } else {
                    $offset += count($rows);
                }
                $result->rowsChanged += count($changes);
                if (!$dryRun) {
                    $this->commitBatch($table, $meta, $changes, $index, $cursor, $offset);
                }
                if ($budget->exhausted()) {
                    $result->replacements = $replacer->replacements;
                    $result->skippedValues = $replacer->skipped;

                    return $result;
                }
            }
            $cursor = null;
            $offset = 0;
            if (!$dryRun) {
                $this->saveProgress(['table_index' => $index + 1, 'pk' => null, 'offset' => 0]);
            }
        }
        $result->replacements = $replacer->replacements;
        $result->skippedValues = $replacer->skipped;
        $result->finished = true;
        if (!$dryRun) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . DatabaseExporter::id(self::PROGRESS_TABLE));
        }
        $this->logger->info('Database migration finished.', $result->toArray());

        return $result;
    }

    /**
     * @return list<string>
     */
    private function tables(string $prefix): array
    {
        $tables = [];
        foreach ($this->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM) as $row) {
            $name = (string) $row[0];
            if ($name !== self::PROGRESS_TABLE && ($prefix === '' || str_starts_with($name, $prefix))) {
                $tables[] = $name;
            }
        }
        sort($tables);

        return $tables;
    }

    /**
     * Binary key columns are handled hex encoded (JSON-safe progress, exact matching via UNHEX); keys that cannot be paged by
     * keyset (ENUM, FLOAT, ...) are paged with ORDER BY key + OFFSET, which is stable because updates never change keys.
     *
     * @param list<string> $skipColumns
     * @return array{pk: list<string>, columns: list<string>, binary: list<string>, keyset: bool}
     */
    private function describe(string $table, array $skipColumns): array
    {
        $pk = [];
        $index = $this->query('SHOW INDEX FROM ' . DatabaseExporter::id($table) . " WHERE Key_name = 'PRIMARY'")->fetchAll();
        usort($index, static fn (array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
        foreach ($index as $row) {
            $pk[] = (string) $row['Column_name'];
        }
        $columns = [];
        $binary = [];
        $keyset = $pk !== [];
        foreach ($this->query('SHOW COLUMNS FROM ' . DatabaseExporter::id($table))->fetchAll() as $row) {
            $name = (string) $row['Field'];
            $type = strtolower((string) $row['Type']);
            if (in_array($name, $pk, true)) {
                $keyset = $keyset && DatabaseExporter::isKeysetSafeType($type);
                if (preg_match('/^(var)?binary/', $type) === 1) {
                    $binary[] = $name;
                }
                continue;
            }
            if (in_array($name, $skipColumns, true) || DatabaseExporter::isGeneratedColumn((string) ($row['Extra'] ?? ''))) {
                continue;
            }
            if (preg_match('/^(char|varchar|tinytext|text|mediumtext|longtext)/', $type) === 1) {
                $columns[] = $name;
            }
        }

        return ['pk' => $pk, 'columns' => $columns, 'binary' => $binary, 'keyset' => $keyset];
    }

    /** @param list<string> $binary */
    private static function keyPlaceholder(string $column, array $binary): string
    {
        return in_array($column, $binary, true) ? 'UNHEX(?)' : '?';
    }

    /**
     * @param array{pk: list<string>, columns: list<string>, binary: list<string>, keyset: bool} $meta
     * @param list<string>|null $cursor
     * @return list<array<string, string|null>>
     */
    private function batch(string $table, array $meta, ?array $cursor, int $offset): array
    {
        $keys = array_map(
            static fn (string $column): string => in_array($column, $meta['binary'], true) ? 'HEX(' . DatabaseExporter::id($column) . ') AS ' . DatabaseExporter::id($column) : DatabaseExporter::id($column),
            $meta['pk'],
        );
        $select = 'SELECT ' . implode(',', [...$keys, ...array_map(DatabaseExporter::id(...), $meta['columns'])]) . ' FROM ' . DatabaseExporter::id($table);
        $order = implode(',', array_map(DatabaseExporter::id(...), $meta['pk']));
        if (!$meta['keyset']) {
            $statement = $this->pdo->prepare($select . ' ORDER BY ' . $order . ' LIMIT ' . $this->batchRows . ' OFFSET ' . $offset);
            $statement->execute();

            return array_values($statement->fetchAll());
        }
        $params = [];
        $where = '';
        if ($cursor !== null) {
            $where = ' WHERE (' . $order . ') > (' . implode(',', array_map(static fn (string $column): string => self::keyPlaceholder($column, $meta['binary']), $meta['pk'])) . ')';
            $params = $cursor;
        }
        $statement = $this->pdo->prepare($select . $where . ' ORDER BY ' . $order . ' LIMIT ' . $this->batchRows);
        $statement->execute($params);

        return array_values($statement->fetchAll());
    }

    /**
     * @param array{pk: list<string>, columns: list<string>, binary: list<string>, keyset: bool} $meta
     * @param list<array{0: list<string>, 1: array<string, string>}> $changes
     * @param list<string>|null $cursor
     */
    private function commitBatch(string $table, array $meta, array $changes, int $tableIndex, ?array $cursor, int $offset): void
    {
        $pk = $meta['pk'];
        $this->pdo->beginTransaction();
        try {
            foreach ($changes as [$keys, $set]) {
                $assign = implode(',', array_map(static fn (string $column): string => DatabaseExporter::id($column) . ' = ?', array_keys($set)));
                $where = implode(' AND ', array_map(static fn (string $column): string => DatabaseExporter::id($column) . ' = ' . self::keyPlaceholder($column, $meta['binary']), $pk));
                $this->pdo->prepare('UPDATE ' . DatabaseExporter::id($table) . " SET {$assign} WHERE {$where}")->execute([...array_values($set), ...$keys]);
            }
            $this->saveProgress(['table_index' => $tableIndex, 'pk' => $cursor, 'offset' => $offset]);
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadProgress(): array
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . DatabaseExporter::id(self::PROGRESS_TABLE) . ' (id TINYINT NOT NULL PRIMARY KEY, progress TEXT NOT NULL) ENGINE=InnoDB');
        $row = $this->query('SELECT progress FROM ' . DatabaseExporter::id(self::PROGRESS_TABLE) . ' WHERE id = 1')->fetch();
        $decoded = is_array($row) ? json_decode((string) $row['progress'], true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function saveProgress(array $progress): void
    {
        $this->pdo->prepare('REPLACE INTO ' . DatabaseExporter::id(self::PROGRESS_TABLE) . ' (id, progress) VALUES (1, ?)')->execute([json_encode($progress)]);
    }

    private function query(string $sql): \PDOStatement
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Query failed.');
        }

        return $statement;
    }
}
