<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup;

use Closure;
use PDO;
use SWBackupIt\Core\Crypto\KeyProviderInterface;
use SWBackupIt\Core\Database\ConnectionFactory;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Event\EventDispatcherInterface;
use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Job\Job;
use SWBackupIt\Core\Log\JobLogger;
use SWBackupIt\Core\Support\ClockInterface;
use SWBackupIt\Core\Storage\StorageManager;
use SWBackupIt\Core\Support\ResourceBudget;

/**
 * Everything a step needs during one tick. commit() persists the job (checkpoints, progress)
 * and renews the lease; steps call it only after the data a checkpoint refers to is durable.
 */
final class BackupContext
{
    private ?PDO $pdo = null;

    /**
     * @param Closure(): void $commit
     */
    public function __construct(
        public readonly Job $job,
        public readonly BackupProfile $profile,
        public readonly BackupLayout $layout,
        public readonly SourceInfo $source,
        public readonly JobLogger $logger,
        public readonly EventDispatcherInterface $events,
        public readonly ClockInterface $clock,
        public readonly ResourceBudget $budget,
        private readonly Closure $commit,
        public readonly ?KeyProviderInterface $keys = null,
        public readonly ?StorageManager $storage = null,
    ) {
    }

    public function commit(): void
    {
        ($this->commit)();
    }

    public function pdo(): PDO
    {
        if ($this->source->database === null) {
            throw new ConfigurationException('No database connection configured.');
        }

        return $this->pdo ??= ConnectionFactory::create($this->source->database);
    }
}
