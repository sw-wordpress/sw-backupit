<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Sql;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SWBackupIt\Core\Database\DatabaseExporter;
use SWBackupIt\Core\Exception\DatabaseException;
use SWBackupIt\Core\Exception\IntegrityException;

/**
 * Streams gzip compressed SQL parts into a database (never loads a part into memory).
 *
 * Exactly-once resume: the number of applied statements per part is stored in a progress table of the target database.
 * Data statements (INSERT, ...) and their progress row are committed in one transaction, so after a crash a statement is
 * either applied and counted or neither (transactional engines; for MyISAM tables a crash between the statement and the
 * progress update can repeat that single statement). DDL commits implicitly; its progress is written right after it, and
 * the first statement after a resume tolerates "already exists" errors for exactly that case.
 *
 * Views that reference objects created later are deferred; the deferred statements are persisted in the progress table too,
 * so they survive a resume in a later process, and finalize() retries them until no further view can be created.
 *
 * Every part starts with the import session settings; they are applied again before a part is resumed in the middle.
 */
final class SqlImporter
{
    public const PROGRESS_TABLE = 'swbi_import_progress';
    private const DEFERRED_KEY = '#deferred';
    /** Must match the header DatabaseExporter writes into every part. */
    private const SESSION = [
        'SET NAMES utf8mb4', 'SET FOREIGN_KEY_CHECKS=0', 'SET UNIQUE_CHECKS=0', "SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'", "SET time_zone='+00:00'",
    ];
    /** Errors of a re-executed DDL statement whose effect is already present (table/trigger/routine/event exists). */
    private const ALREADY_EXISTS = [1050, 1359, 1304, 1537, 1062];

    /** @var list<string>|null */
    private ?array $deferred = null;
    private bool $progressTable = false;

    /**
     * @param list<string>|null $tables Selective import: only statements of these tables (plus session settings) are executed.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?array $tables = null,
    ) {
    }

    /** Removes the progress of an earlier import (called before a fresh import into the database). */
    public static function reset(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ' . DatabaseExporter::id(self::PROGRESS_TABLE));
    }

    /**
     * @param int $skip Statements known to be applied (from the caller's state); the progress table is authoritative when it knows more.
     * @param callable(int): void|null $onProgress Called every ~200 statements with the statement count in this part.
     * @return int Statements of this part that are now applied (including skipped ones).
     */
    public function importPart(string $path, int $skip = 0, ?callable $onProgress = null): int
    {
        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            throw new IntegrityException('Cannot open database export part.', ['part' => basename($path)]);
        }
        $part = basename($path);
        $applied = max($skip, $this->appliedStatements($part));
        $resumed = $applied > 0;
        if ($resumed) {
            foreach (self::SESSION as $statement) {
                $this->pdo->exec($statement);
            }
        }
        $splitter = new StatementSplitter();
        $count = 0;
        try {
            while (!gzeof($handle)) {
                $chunk = gzread($handle, 262144);
                if ($chunk === false) {
                    throw new IntegrityException('Database export part is corrupt (read failed).', ['part' => $part]);
                }
                foreach ($splitter->feed($chunk) as $statement) {
                    $count = $this->execute($part, $statement, $count, $applied, $resumed, $onProgress);
                }
            }
            foreach ($splitter->finish() as $statement) {
                $count = $this->execute($part, $statement, $count, $applied, $resumed, $onProgress);
            }
        } finally {
            gzclose($handle);
        }

        return $count;
    }

    /**
     * Creates the deferred views (several passes: views may reference views) and removes the progress table.
     */
    public function finalize(): void
    {
        $pending = $this->deferred();
        while ($pending !== []) {
            $failed = [];
            foreach ($pending as $statement) {
                try {
                    $this->pdo->exec($statement);
                } catch (PDOException $exception) {
                    if (!$this->isDeferrable($statement, (int) ($exception->errorInfo[1] ?? 0))) {
                        throw new DatabaseException('Database import failed.', ['db_error' => (int) ($exception->errorInfo[1] ?? 0)], false, $exception);
                    }
                    $failed[] = $statement;
                }
            }
            if (count($failed) === count($pending)) {
                throw new DatabaseException('Some database objects could not be created (unresolved dependencies).', ['count' => count($failed)]);
            }
            $pending = $failed;
            $this->saveDeferred($pending);
        }
        self::reset($this->pdo);
        $this->progressTable = false;
        $this->deferred = null;
    }

    /**
     * @param callable(int): void|null $onProgress
     */
    private function execute(string $part, string $statement, int $count, int $applied, bool &$resumed, ?callable $onProgress): int
    {
        $count++;
        if ($count <= $applied || !$this->isSelected($statement)) {
            return $count;
        }
        $tolerateExisting = $resumed;
        $resumed = false;
        if (preg_match('/^\s*(INSERT|REPLACE|UPDATE|DELETE)\b/i', $statement) === 1) {
            $this->pdo->beginTransaction();
            try {
                $this->runStatement($statement, $tolerateExisting);
                $this->saveProgress($part, $count);
                $this->pdo->commit();
            } catch (\Throwable $throwable) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $throwable;
            }
        } else {
            $this->runStatement($statement, $tolerateExisting);
            $this->saveProgress($part, $count);
        }
        if ($onProgress !== null && $count % 200 === 0) {
            $onProgress($count);
        }

        return $count;
    }

    private function runStatement(string $statement, bool $tolerateExisting): void
    {
        try {
            $this->pdo->exec($statement);
        } catch (PDOException $exception) {
            $code = (int) ($exception->errorInfo[1] ?? 0);
            if ($tolerateExisting && in_array($code, self::ALREADY_EXISTS, true)) {
                $this->logger->warning('Statement was already applied before the resume.', ['db_error' => $code]);

                return;
            }
            if ($this->isDeferrable($statement, $code)) {
                $deferred = $this->deferred();
                if (!in_array($statement, $deferred, true)) {
                    $deferred[] = $statement;
                    $this->saveDeferred($deferred);
                }

                return;
            }
            $this->logger->error('SQL statement failed.', ['db_error' => $code]);
            if ($code === 1153 || (in_array($code, [2006, 2013], true) && strlen($statement) > 1048576)) {
                throw new DatabaseException('A statement is larger than max_allowed_packet of the target database server. Raise max_allowed_packet and run the restore again to continue.', ['db_error' => $code, 'bytes' => strlen($statement)], false, $exception);
            }
            throw new DatabaseException('Database import failed.', ['db_error' => $code, 'statement' => mb_substr($statement, 0, 80)], false, $exception);
        }
    }

    /**
     * Selective import: table statements (structure, data) of the selected tables and session settings. Everything else
     * (views, triggers, routines, events) belongs to the whole database and is skipped.
     */
    private function isSelected(string $statement): bool
    {
        if ($this->tables === null || preg_match('/^\s*SET\s/i', $statement) === 1) {
            return true;
        }
        $pattern = '/^\s*(?:DROP\s+TABLE\s+IF\s+EXISTS|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|LOCK\s+TABLES)\s+`((?:[^`]|``)+)`/i';
        if (preg_match($pattern, $statement, $match) !== 1) {
            return false;
        }

        return in_array(str_replace('``', '`', $match[1]), $this->tables, true);
    }

    private function isDeferrable(string $statement, int $code): bool
    {
        return in_array($code, [1146, 1356, 1109], true)
            && preg_match('/^\s*CREATE\s+(OR\s+REPLACE\s+)?(ALGORITHM\s*=\s*\w+\s+)?(SQL\s+SECURITY\s+\w+\s+)?VIEW\b/i', $statement) === 1;
    }

    private function ensureProgressTable(): void
    {
        if (!$this->progressTable) {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . DatabaseExporter::id(self::PROGRESS_TABLE)
                . ' (part VARCHAR(191) NOT NULL PRIMARY KEY, statements BIGINT NOT NULL DEFAULT 0, data LONGTEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $this->progressTable = true;
        }
    }

    private function appliedStatements(string $part): int
    {
        $this->ensureProgressTable();
        $statement = $this->pdo->prepare('SELECT statements FROM ' . DatabaseExporter::id(self::PROGRESS_TABLE) . ' WHERE part = ?');
        $statement->execute([$part]);

        return (int) $statement->fetchColumn();
    }

    private function saveProgress(string $part, int $count): void
    {
        $this->ensureProgressTable();
        $this->pdo->prepare('INSERT INTO ' . DatabaseExporter::id(self::PROGRESS_TABLE) . ' (part, statements) VALUES (?, ?) ON DUPLICATE KEY UPDATE statements = VALUES(statements)')
            ->execute([$part, $count]);
    }

    /** @return list<string> */
    private function deferred(): array
    {
        if ($this->deferred === null) {
            $this->ensureProgressTable();
            $statement = $this->pdo->prepare('SELECT data FROM ' . DatabaseExporter::id(self::PROGRESS_TABLE) . ' WHERE part = ?');
            $statement->execute([self::DEFERRED_KEY]);
            $decoded = json_decode((string) $statement->fetchColumn(), true);
            $this->deferred = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }

        return $this->deferred;
    }

    /** @param list<string> $deferred */
    private function saveDeferred(array $deferred): void
    {
        $this->ensureProgressTable();
        $this->pdo->prepare('REPLACE INTO ' . DatabaseExporter::id(self::PROGRESS_TABLE) . ' (part, statements, data) VALUES (?, 0, ?)')
            ->execute([self::DEFERRED_KEY, json_encode($deferred, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)]);
        $this->deferred = $deferred;
    }
}
