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
 * - Keyset paging needs a primary key whose sort order equals its comparison order; keys with ENUM/SET (sorted by index,
 *   compared as strings), FLOAT/DOUBLE (inexact cursor values) or other exotic types are paged with ORDER BY key + OFFSET.
 *   Binary key values are kept hex encoded in the (JSON) cursor.
 * - BIT columns are exported as integers; only real generated columns (VIRTUAL/STORED/PERSISTENT) are skipped, columns with
 *   an expression default (MySQL 8: DEFAULT_GENERATED) are exported.
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
            // The parts declare utf8mb4 (SET NAMES in every part header): read with the same connection charset whatever
            // DB_CHARSET says, so the server converts each column losslessly (also latin1 columns holding legacy bytes).
            $this->pdo->exec('SET NAMES utf8mb4');
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
        $writer->write("-- sw-backupit SQL export, part {$index}\n");
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
            $create = PdoHelper::query($this->pdo, 'SHOW CREATE TABLE ' . self::id($name))->fetch(PDO::FETCH_NUM);
            if (!is_array($create) || !isset($create[1])) {
                throw new DatabaseException('Cannot read table structure.', ['table' => $name]);
            }
            $writer->write("-- Table structure for " . str_replace(["\r", "\n"], " ", $name) . "\nDROP TABLE IF EXISTS " . self::id($name) . ";\n" . $create[1] . ";\n\n");
            $cursor['structure'] = true;

            return;
        }

        $columns = $this->columns($name);
        $primary = $this->primaryKey($name);
        $keyset = $this->supportsKeyset($columns, $primary);
        $rows = $this->fetchBatch($name, $columns, $primary, $keyset, $cursor, $batchRows);
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
        $buffer = [];
        $bufferSize = 0;
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $this->literal($row[$column['name']] ?? null, $column['kind']);
            }
            $tuple = '(' . implode(',', $values) . ')';
            if ($buffer !== [] && $bufferSize + strlen($tuple) > self::MAX_STATEMENT_BYTES) {
                $writer->write($prefix . implode(",\n", $buffer) . ";\n");
                $buffer = [];
                $bufferSize = 0;
            }
            $buffer[] = $tuple;
            $bufferSize += strlen($tuple);
        }
        // $rows is never empty here, so the buffer always holds the last (partial) statement.
        $writer->write($prefix . implode(",\n", $buffer) . ";\n");
        $cursor['rows_done'] = (int) $cursor['rows_done'] + count($rows);
        if ($keyset) {
            $lastRow = $rows[array_key_last($rows)];
            $kinds = array_column($columns, 'kind', 'name');
            $next = array_map(static fn (string $column): string => $kinds[$column] === 'binary' ? bin2hex((string) $lastRow[$column]) : (string) $lastRow[$column], $primary);
            if ($next === $cursor['pk']) {
                // Defensive: a key whose comparison does not advance would loop forever and duplicate rows.
                throw new DatabaseException('Primary key paging does not advance.', ['table' => $name]);
            }
            $cursor['pk'] = $next;
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
        // MySQL qualifies every column of a view definition with the source database (`db`.`table`.`col`); restored into a
        // database with another name, such a view would read the original database. The qualifier is removed.
        $qualifier = self::id((string) PdoHelper::query($this->pdo, 'SELECT DATABASE()')->fetchColumn()) . '.';
        foreach ($tables as $table) {
            if ($table['type'] !== 'view') {
                continue;
            }
            $row = PdoHelper::query($this->pdo, 'SHOW CREATE VIEW ' . self::id($table['name']))->fetch(PDO::FETCH_NUM);
            if (is_array($row) && isset($row[1])) {
                $definition = str_replace($qualifier, '', self::stripDefiner((string) $row[1]));
                $blocks[] = 'DROP VIEW IF EXISTS ' . self::id($table['name']) . ";\n" . $definition . ";\n";
            }
        }
        foreach (PdoHelper::query($this->pdo, 'SHOW TRIGGERS')->fetchAll() as $trigger) {
            if (!in_array((string) $trigger['Table'], $selected, true)) {
                continue;
            }
            $name = (string) $trigger['Trigger'];
            $create = PdoHelper::query($this->pdo, 'SHOW CREATE TRIGGER ' . self::id($name))->fetch();
            if (is_array($create) && isset($create['SQL Original Statement'])) {
                $blocks[] = 'DROP TRIGGER IF EXISTS ' . self::id($name) . ";\n" . self::delimited(self::stripDefiner((string) $create['SQL Original Statement']));
            }
        }
        if ($cursor['include_routines'] ?? false) {
            $routines = PdoHelper::query($this->pdo, 'SELECT ROUTINE_NAME AS n, ROUTINE_TYPE AS t FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()')->fetchAll();
            foreach ($routines as $routine) {
                $type = strtoupper((string) $routine['t']) === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE';
                $create = PdoHelper::query($this->pdo, "SHOW CREATE {$type} " . self::id((string) $routine['n']))->fetch(PDO::FETCH_NUM);
                if (is_array($create) && isset($create[2])) {
                    $blocks[] = "DROP {$type} IF EXISTS " . self::id((string) $routine['n']) . ";\n" . self::delimited(self::stripDefiner((string) $create[2]));
                }
            }
            foreach (PdoHelper::query($this->pdo, 'SHOW EVENTS')->fetchAll() as $event) {
                $create = PdoHelper::query($this->pdo, 'SHOW CREATE EVENT ' . self::id((string) $event['Name']))->fetch(PDO::FETCH_NUM);
                if (is_array($create) && isset($create[3])) {
                    $blocks[] = 'DROP EVENT IF EXISTS ' . self::id((string) $event['Name']) . ";\n" . self::delimited(self::stripDefiner((string) $create[3]));
                }
            }
        }

        return $blocks;
    }

    /**
     * @return list<array{name: string, kind: string, type: string}>
     */
    private function columns(string $table): array
    {
        $columns = [];
        foreach (PdoHelper::query($this->pdo, 'SHOW COLUMNS FROM ' . self::id($table))->fetchAll() as $row) {
            if (self::isGeneratedColumn((string) ($row['Extra'] ?? ''))) {
                continue;
            }
            $type = strtolower((string) $row['Type']);
            $kind = match (true) {
                str_starts_with($type, 'bit') => 'bit',
                preg_match('/^(tiny|medium|long)?blob|^(var)?binary|^geometry|^point|^linestring|^polygon|^multi|^geometrycollection/', $type) === 1 => 'binary',
                preg_match('/^(tiny|small|medium|big)?int|^decimal|^numeric|^float|^double|^real|^year/', $type) === 1 => 'numeric',
                default => 'text',
            };
            $columns[] = ['name' => (string) $row['Field'], 'kind' => $kind, 'type' => $type];
        }

        return $columns;
    }

    /**
     * Only real generated columns are skipped; MySQL 8 reports "DEFAULT_GENERATED" for columns with an expression default
     * (e.g. DEFAULT CURRENT_TIMESTAMP) whose values must be exported.
     */
    public static function isGeneratedColumn(string $extra): bool
    {
        $extra = str_replace('DEFAULT_GENERATED', '', strtoupper($extra));

        return str_contains($extra, 'GENERATED') || str_contains($extra, 'VIRTUAL') || str_contains($extra, 'PERSISTENT');
    }

    /**
     * Keyset paging is only correct when the key's sort order equals its comparison order and the values round-trip exactly.
     *
     * @param list<array{name: string, kind: string, type: string}> $columns
     * @param list<string> $primary
     */
    private function supportsKeyset(array $columns, array $primary): bool
    {
        if ($primary === []) {
            return false;
        }
        $types = array_column($columns, 'type', 'name');
        foreach ($primary as $column) {
            if (!isset($types[$column]) || !self::isKeysetSafeType($types[$column])) {
                return false;
            }
        }

        return true;
    }

    /** Whether "ORDER BY col" and "col > ?" agree for this column type (not for ENUM/SET/FLOAT/DOUBLE/BIT/...). */
    public static function isKeysetSafeType(string $type): bool
    {
        return preg_match('/^((tiny|small|medium|big)?int|decimal|numeric|year|(var)?char|(tiny|medium|long)?text|(var)?binary|date|datetime|timestamp|time)\b/', strtolower($type)) === 1;
    }

    /**
     * @return list<string> Primary key columns in index order (empty when the table has none).
     */
    private function primaryKey(string $table): array
    {
        $rows = PdoHelper::query($this->pdo, 'SHOW INDEX FROM ' . self::id($table) . " WHERE Key_name = 'PRIMARY'")->fetchAll();
        usort($rows, static fn (array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);

        return array_map(static fn (array $row): string => (string) $row['Column_name'], $rows);
    }

    /**
     * @param list<array{name: string, kind: string, type: string}> $columns
     * @param list<string> $primary
     * @param array<string, mixed> $cursor
     * @return list<array<string, string|null>>
     */
    private function fetchBatch(string $table, array $columns, array $primary, bool $keyset, array $cursor, int $limit): array
    {
        $select = 'SELECT ' . implode(',', array_map(
            static fn (array $c): string => $c['kind'] === 'bit' ? 'CAST(' . self::id($c['name']) . ' AS UNSIGNED) AS ' . self::id($c['name']) : self::id($c['name']),
            $columns,
        )) . ' FROM ' . self::id($table);
        $order = implode(',', array_map(self::id(...), $primary));
        if (!$keyset) {
            // A stable order for keys that cannot be paged by keyset; tables without a key keep the physical order.
            $orderBy = $primary === [] ? '' : ' ORDER BY ' . $order;
            $statement = $this->pdo->prepare($select . $orderBy . ' LIMIT ' . $limit . ' OFFSET ' . (int) $cursor['offset']);
            $statement->execute();

            return array_values($statement->fetchAll());
        }
        $params = [];
        $where = '';
        if (is_array($cursor['pk'])) {
            $kinds = array_column($columns, 'kind', 'name');
            $left = '(' . $order . ')';
            $right = '(' . implode(',', array_map(static fn (string $column): string => $kinds[$column] === 'binary' ? 'UNHEX(?)' : '?', $primary)) . ')';
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
        if ($kind === 'bit') {
            // Selected as CAST(... AS UNSIGNED): a decimal string that may exceed PHP_INT_MAX (BIT(64)).
            if (preg_match('/^\d+$/', $value) !== 1) {
                throw new DatabaseException('Unexpected BIT value.');
            }

            return $value;
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
