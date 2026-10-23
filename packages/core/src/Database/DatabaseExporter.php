<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Database;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SWBackupIt\Core\Exception\DatabaseException;
use SWBackupIt\Core\Exception\TransientException;
use SWBackupIt\Core\Support\ResourceBudget;

/**
 * Streaming, resumable MySQL/MariaDB exporter (PLAN.md §8).
 *
 * - Data is read in keyset-paginated batches (primary key), never whole tables.
 * - Binary columns are exported as hex literals, text via PDO::quote() (backslash escaped).
 * - Output is split into gzip parts at statement boundaries; every closed part is immutable and
 *   reported through $onPart so the caller can checkpoint it. The cursor describes the position
 *   directly after the last closed part: resuming discards any half-written part.
 * - Tables without a primary key are paged with LIMIT/OFFSET (order not guaranteed under concurrent writes).
 *
 * Consistency: see docs/backup-format.md. A transactionally consistent snapshot is only possible
 * for InnoDB tables when the whole export runs in ONE invocation ($consistentSnapshot = true).
 */
final class DatabaseExporter
{
    private const MAX_STATEMENT_BYTES = 262144;

    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<TableInfo> $tables Selected tables/views (only used to initialise a fresh cursor).
     * @param array<string, mixed> $cursor Empty for a fresh export.
     * @param callable(array<string, mixed>): void $onPart Called after each closed part with the committed cursor.
     */
    public function export(
        string $directory,
        array $tables,
        bool $includeRoutines,
        int $batchRows,
        int $partBytes,
        array $cursor,
        ResourceBudget $budget,
        callable $onPart,
        bool $consistentSnapshot = false,
    ): ExportResult {
        if ($cursor === []) {
            $cursor = [
                'tables' => array_map(static fn (TableInfo $table): array => $table->toArray(), $tables),
                'ti' => 0,
                'pk' => null,
                'offset' => 0,
                'structure' => false,
                'rows_done' => 0,
                'part_index' => 0,
                'parts' => [],
                'phase' => 'data',
                'objects' => null,
                'oi' => 0,
                'table_rows' => [],
                'include_routines' => $includeRoutines,
            ];
        }
        try {
            if ($consistentSnapshot) {
                $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            }

            return $this->run($directory, $cursor, $batchRows, $partBytes, $budget, $onPart);
        } catch (PDOException $exception) {
            throw $this->translate($exception);
        } finally {
            if ($consistentSnapshot && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }

    /**
     * @param array<string, mixed> $cursor
     * @param callable(array<string, mixed>): void $onPart
     */
    private function run(string $directory, array $cursor, int $batchRows, int $partBytes, ResourceBudget $budget, callable $onPart): ExportResult
    {
        $writer = null;
        $committed = $cursor;
        try {
            while ($cursor['phase'] !== 'done') {
                $writer ??= $this->openPart($directory, $cursor);
                $this->step($writer, $cursor, $batchRows);

                $finished = $cursor['phase'] === 'done';
                if ($finished || $writer->rawBytes() >= $partBytes || $budget->exhausted()) {
                    $part = $writer->close();
                    $writer = null;
                    $cursor['parts'][] = $part;
                    $cursor['part_index'] = (int) $cursor['part_index'] + 1;
                    $committed = $cursor;
                    $onPart($committed);
                    if (!$finished && $budget->exhausted()) {
                        return new ExportResult(false, $committed);
                    }
                }
            }
        } catch (\Throwable $throwable) {
            $writer?->abort();
            throw $throwable;
        }

        return new ExportResult(true, $committed);
    }

    /**
     * @param array<string, mixed> $cursor
     */
    private function openPart(string $directory, array $cursor): SqlPartWriter
    {
        $index = (int) $cursor['part_index'] + 1;
        $writer = new SqlPartWriter($directory, $index);
        $writer->write("-- SWBackupIt SQL export, part {$index}\n");
        $writer->write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n");
        $writer->write("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n");

        return $writer;
    }

    /**
     * Performs one unit of work (a batch of rows, a table structure or one database object).
     *
     * @param array<string, mixed> $cursor
     */
    private function step(SqlPartWriter $writer, array &$cursor, int $batchRows): void
    {
        if ($cursor['phase'] === 'data') {
            $this->stepData($writer, $cursor, $batchRows);

            return;
        }
        $this->stepObjects($writer, $cursor);
    }

    /**
     * @param array<string, mixed> $cursor
     */
    private function stepData(SqlPartWriter $writer, array &$cursor, int $batchRows): void
    {
        /** @var list<array{name: string, type: string, rows: int}> $tables */
        $tables = $cursor['tables'];
        $index = (int) $cursor['ti'];
        if ($index >= count($tables)) {
            $cursor['phase'] = 'objects';

            return;
        }
        $table = $tables[$index];
        if ($table['type'] === 'view') {
            $cursor['ti'] = $index + 1;

            return;
        }
        $name = $table['name'];
        if (!$cursor['structure']) {
            $create = $this->pdo->query('SHOW CREATE TABLE ' . self::id($name))->fetch(PDO::FETCH_NUM);
            if (!is_array($create) || !isset($create[1])) {
                throw new DatabaseException('Cannot read table structure.', ['table' => $name]);
            }
            $writer->write("-- Table structure for {$name}\nDROP TABLE IF EXISTS " . self::id($name) . ";\n" . $create[1] . ";\n\n");
            $cursor['structure'] = true;

            return;
        }

        $columns = $this->columns($name);
        $primary = $this->primaryKey($name);
        $rows = $this->fetchBatch($name, $columns, $primary, $cursor, $batchRows);
        if ($rows === []) {
            $cursor['table_rows'][$name] = (int) $cursor['rows_done'];
            $cursor['ti'] = $index + 1;
            $cursor['pk'] = null;
            $cursor['offset'] = 0;
            $cursor['structure'] = false;
            $cursor['rows_done'] = 0;

            return;
        }

        $columnList = implode(',', array_map(static fn (array $column): string => self::id($column['name']), $columns));
        $prefix = 'INSERT INTO ' . self::id($name) . " ({$columnList}) VALUES\n";
        $statement = '';
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $this->literal($row[$column['name']] ?? null, $column['kind']);
            }
            $tuple = '(' . implode(',', $values) . ')';
            if ($statement !== '' && strlen($statement) + strlen($tuple) > self::MAX_STATEMENT_BYTES) {
                $writer->write($prefix . $statement . ";\n");
                $statement = '';
            }
            $statement .= ($statement === '' ? '' : ",\n") . $tuple;
        }
        if ($statement !== '') {
            $writer->write($prefix . $statement . ";\n");
        }
        $cursor['rows_done'] = (int) $cursor['rows_done'] + count($rows);
        if ($primary !== []) {
            $lastRow = $rows[array_key_last($rows)];
            $cursor['pk'] = array_map(static fn (string $column): string => (string) $lastRow[$column], $primary);
        } else {
            $cursor['offset'] = (int) $cursor['offset'] + count($rows);
        }
    }

    /**
     * @param array<string, mixed> $cursor
     */
    private function stepObjects(SqlPartWriter $writer, array &$cursor): void
    {
        if ($cursor['objects'] === null) {
            $cursor['objects'] = $this->collectObjects($cursor);
            $cursor['oi'] = 0;
        }
        /** @var list<string> $objects */
        $objects = $cursor['objects'];
        $index = (int) $cursor['oi'];
        if ($index >= count($objects)) {
            $cursor['phase'] = 'done';
            $cursor['objects'] = [];

            return;
        }
        $writer->write($objects[$index] . "\n");
        $cursor['oi'] = $index + 1;
    }

    /**
     * Builds the SQL blocks for views, triggers and (optionally) routines and events.
     *
     * @param array<string, mixed> $cursor
     * @return list<string>
     */
    private function collectObjects(array $cursor): array
    {
        /** @var list<array{name: string, type: string, rows: int}> $tables */
        $tables = $cursor['tables'];
        $selected = array_column($tables, 'name');
        $blocks = [];
        foreach ($tables as $table) {
            if ($table['type'] !== 'view') {
                continue;
            }
            $row = $this->pdo->query('SHOW CREATE VIEW ' . self::id($table['name']))->fetch(PDO::FETCH_NUM);
            if (is_array($row) && isset($row[1])) {
                $blocks[] = 'DROP VIEW IF EXISTS ' . self::id($table['name']) . ";\n" . self::stripDefiner((string) $row[1]) . ";\n";
            }
        }
        foreach ($this->pdo->query('SHOW TRIGGERS')->fetchAll() as $trigger) {
            if (!in_array((string) $trigger['Table'], $selected, true)) {
                continue;
            }
            $name = (string) $trigger['Trigger'];
            $create = $this->pdo->query('SHOW CREATE TRIGGER ' . self::id($name))->fetch();
            if (is_array($create) && isset($create['SQL Original Statement'])) {
                $blocks[] = 'DROP TRIGGER IF EXISTS ' . self::id($name) . ";\n" . self::delimited(self::stripDefiner((string) $create['SQL Original Statement']));
            }
        }
        if ($cursor['include_routines'] ?? false) {
            $routines = $this->pdo->query('SELECT ROUTINE_NAME AS n, ROUTINE_TYPE AS t FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()')->fetchAll();
            foreach ($routines as $routine) {
                $type = strtoupper((string) $routine['t']) === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE';
                $create = $this->pdo->query("SHOW CREATE {$type} " . self::id((string) $routine['n']))->fetch(PDO::FETCH_NUM);
                if (is_array($create) && isset($create[2])) {
                    $blocks[] = "DROP {$type} IF EXISTS " . self::id((string) $routine['n']) . ";\n" . self::delimited(self::stripDefiner((string) $create[2]));
                }
            }
            foreach ($this->pdo->query('SHOW EVENTS')->fetchAll() as $event) {
                $create = $this->pdo->query('SHOW CREATE EVENT ' . self::id((string) $event['Name']))->fetch(PDO::FETCH_NUM);
                if (is_array($create) && isset($create[3])) {
                    $blocks[] = 'DROP EVENT IF EXISTS ' . self::id((string) $event['Name']) . ";\n" . self::delimited(self::stripDefiner((string) $create[3]));
                }
            }
        }

        return $blocks;
    }

    /**
     * @return list<array{name: string, kind: string}>
     */
    private function columns(string $table): array
    {
        $columns = [];
        foreach ($this->pdo->query('SHOW COLUMNS FROM ' . self::id($table))->fetchAll() as $row) {
            $extra = strtoupper((string) ($row['Extra'] ?? ''));
            if (str_contains($extra, 'GENERATED') || str_contains($extra, 'VIRTUAL') || str_contains($extra, 'PERSISTENT')) {
                continue;
            }
            $type = strtolower((string) $row['Type']);
            $kind = match (true) {
                preg_match('/^(tiny|medium|long)?blob|^(var)?binary|^bit|^geometry|^point|^linestring|^polygon|^multi|^geometrycollection/', $type) === 1 => 'binary',
                preg_match('/^(tiny|small|medium|big)?int|^decimal|^numeric|^float|^double|^real|^year/', $type) === 1 => 'numeric',
                default => 'text',
            };
            $columns[] = ['name' => (string) $row['Field'], 'kind' => $kind];
        }

        return $columns;
    }

    /**
     * @return list<string> Primary key columns in index order (empty when the table has none).
     */
    private function primaryKey(string $table): array
    {
        $rows = $this->pdo->query('SHOW INDEX FROM ' . self::id($table) . " WHERE Key_name = 'PRIMARY'")->fetchAll();
        usort($rows, static fn (array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);

        return array_map(static fn (array $row): string => (string) $row['Column_name'], $rows);
    }

    /**
     * @param list<array{name: string, kind: string}> $columns
     * @param list<string> $primary
     * @param array<string, mixed> $cursor
     * @return list<array<string, string|null>>
     */
    private function fetchBatch(string $table, array $columns, array $primary, array $cursor, int $limit): array
    {
        $select = 'SELECT ' . implode(',', array_map(static fn (array $c): string => self::id($c['name']), $columns))
            . ' FROM ' . self::id($table);
        if ($primary === []) {
            $statement = $this->pdo->prepare($select . ' LIMIT ' . $limit . ' OFFSET ' . (int) $cursor['offset']);
            $statement->execute();

            return array_values($statement->fetchAll());
        }
        $order = implode(',', array_map(self::id(...), $primary));
        $params = [];
        $where = '';
        if (is_array($cursor['pk'])) {
            $left = '(' . $order . ')';
            $right = '(' . implode(',', array_fill(0, count($primary), '?')) . ')';
            $where = " WHERE {$left} > {$right}";
            $params = array_values($cursor['pk']);
        }
        $statement = $this->pdo->prepare($select . $where . ' ORDER BY ' . $order . ' LIMIT ' . $limit);
        $statement->execute($params);

        return array_values($statement->fetchAll());
    }

    private function literal(?string $value, string $kind): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if ($kind === 'binary') {
            return $value === '' ? "''" : '0x' . bin2hex($value);
        }
        if ($kind === 'numeric' && preg_match('/^-?\d+(\.\d+)?([eE][-+]?\d+)?$/', $value) === 1) {
            return $value;
        }

        return $this->pdo->quote($value);
    }

    public static function id(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function delimited(string $statement): string
    {
        return "DELIMITER ;;\n{$statement};;\nDELIMITER ;\n";
    }

    private static function stripDefiner(string $sql): string
    {
        return preg_replace('/\sDEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|[^@\s]+)@(`[^`]*`|\'[^\']*\'|\S+)/i', '', $sql) ?? $sql;
    }

    private function translate(PDOException $exception): DatabaseException|TransientException
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $context = ['db_error' => $driverCode];
        $this->logger->error('Database export query failed.', $context);
        if (in_array($driverCode, [2006, 2013, 1205, 1213, 1040, 1053], true)) {
            return new TransientException('Temporary database error during export.', $context, $exception);
        }

        return new DatabaseException('Database export failed.', $context, false, $exception);
    }
}
