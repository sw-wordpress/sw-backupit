<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Sql;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SWBackupIt\Core\Exception\DatabaseException;
use SWBackupIt\Core\Exception\IntegrityException;

/**
 * Streams gzip compressed SQL parts into a database (never loads a part into memory).
 *
 * Resume support: importPart() can skip the first N statements of a part. If the process died
 * between executing a batch and persisting the counter, the very first statement after a resume may
 * hit a duplicate key (1062); that single case is treated as "already applied".
 */
final class SqlImporter
{
    /** @var list<string> */
    private array $deferred = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param callable(int): void|null $onProgress Called every ~200 statements with the statement count in this part.
     * @return int Statements of this part that are now applied (including skipped ones).
     */
    public function importPart(string $path, int $skip = 0, ?callable $onProgress = null): int
    {
        $handle = gzopen($path, 'rb');
        if ($handle === false) {
            throw new IntegrityException('Cannot open database export part.', ['part' => basename($path)]);
        }
        $splitter = new StatementSplitter();
        $count = 0;
        $resumedFirst = $skip > 0;
        try {
            while (!gzeof($handle)) {
                $chunk = gzread($handle, 262144);
                if ($chunk === false) {
                    throw new IntegrityException('Database export part is corrupt (read failed).', ['part' => basename($path)]);
                }
                foreach ($splitter->feed($chunk) as $statement) {
                    $count = $this->execute($statement, $count, $skip, $resumedFirst, $onProgress);
                }
            }
            foreach ($splitter->finish() as $statement) {
                $count = $this->execute($statement, $count, $skip, $resumedFirst, $onProgress);
            }
        } finally {
            gzclose($handle);
        }

        return $count;
    }

    /**
     * Retries statements that failed because of missing dependencies (views referencing views).
     */
    public function finalize(): void
    {
        for ($pass = 0; $pass < 5 && $this->deferred !== []; $pass++) {
            $pending = $this->deferred;
            $this->deferred = [];
            foreach ($pending as $statement) {
                $this->runStatement($statement, true);
            }
        }
        if ($this->deferred !== []) {
            throw new DatabaseException('Some database objects could not be created (unresolved dependencies).', ['count' => count($this->deferred)]);
        }
    }

    /**
     * @param callable(int): void|null $onProgress
     */
    private function execute(string $statement, int $count, int $skip, bool &$resumedFirst, ?callable $onProgress): int
    {
        $count++;
        if ($count <= $skip) {
            return $count;
        }
        $this->runStatement($statement, false, $resumedFirst);
        $resumedFirst = false;
        if ($onProgress !== null && $count % 200 === 0) {
            $onProgress($count);
        }

        return $count;
    }

    private function runStatement(string $statement, bool $final, bool $tolerateDuplicate = false): void
    {
        try {
            $this->pdo->exec($statement);
        } catch (PDOException $exception) {
            $code = (int) ($exception->errorInfo[1] ?? 0);
            if ($tolerateDuplicate && $code === 1062) {
                $this->logger->warning('Duplicate key on resume; statement was already applied.');

                return;
            }
            if (!$final && $this->isDeferrable($statement, $code)) {
                $this->deferred[] = $statement;

                return;
            }
            $this->logger->error('SQL statement failed.', ['db_error' => $code]);
            throw new DatabaseException('Database import failed.', ['db_error' => $code, 'statement' => mb_substr($statement, 0, 80)], false, $exception);
        }
    }

    private function isDeferrable(string $statement, int $code): bool
    {
        return in_array($code, [1146, 1356, 1109], true)
            && preg_match('/^\s*CREATE\s+(OR\s+REPLACE\s+)?(ALGORITHM\s*=\s*\w+\s+)?(SQL\s+SECURITY\s+\w+\s+)?VIEW\b/i', $statement) === 1;
    }
}
