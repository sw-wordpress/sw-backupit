<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Retention;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SWBackupIt\Core\Exception\SwbiException;

/** Applies a retention plan. A failing deletion never aborts the others and never hides the backup. */
final class RetentionExecutor
{
    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * @return array{deleted: list<string>, failed: array<string, string>}
     */
    public function execute(RetentionPlan $plan, BackupCatalogInterface $catalog, bool $dryRun = false): array
    {
        $result = ['deleted' => [], 'failed' => []];
        foreach ($plan->deletions() as $id) {
            if ($dryRun) {
                $result['deleted'][] = $id;
                continue;
            }
            try {
                $catalog->delete($id);
                $result['deleted'][] = $id;
                $this->logger->info('Retention deleted a backup.', ['backup_id' => $id, 'reason' => $plan->delete[$id]]);
            } catch (SwbiException $exception) {
                $result['failed'][$id] = $exception->getMessage();
                $this->logger->warning('Retention could not delete a backup; it is kept.', ['backup_id' => $id, 'error' => $exception->getMessage()]);
            }
        }

        return $result;
    }
}
