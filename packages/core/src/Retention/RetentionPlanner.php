<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Retention;

use DateTimeImmutable;
use DateTimeZone;
use SWBackupIt\Core\Domain\BackupType;

/**
 * Pure retention decision logic (PLAN.md §11): count, age, storage, grandfather-father-son.
 *
 * Safety rules that always apply: protected backups are kept; the newest valid full backup is kept; every ancestor
 * of a kept backup is kept (chain aware); backups with unreliable metadata are never deleted; with no active rule
 * nothing is deleted.
 */
final class RetentionPlanner
{
    /**
     * @param list<BackupRecord> $records
     */
    public function plan(array $records, RetentionPolicy $policy, DateTimeImmutable $now): RetentionPlan
    {
        $plan = new RetentionPlan();
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        /** @var array<string, BackupRecord> $byId */
        $byId = [];
        foreach ($records as $record) {
            $byId[$record->id] = $record;
        }
        $reliable = array_values(array_filter($records, static fn (BackupRecord $r): bool => $r->reliable));
        usort($reliable, static fn (BackupRecord $a, BackupRecord $b): int => $b->createdAt <=> $a->createdAt);

        foreach ($records as $record) {
            if (!$record->reliable) {
                $plan->keep[$record->id][] = 'unreliable metadata (never deleted automatically)';
            }
        }
        if (!$policy->isActive()) {
            foreach ($reliable as $record) {
                $plan->keep[$record->id][] = 'no retention rule configured';
            }

            return $plan;
        }

        foreach ($reliable as $record) {
            if ($record->protected) {
                $plan->keep[$record->id][] = 'protected';
            }
        }
        foreach ($reliable as $record) {
            if (!$record->type->isDelta() && $record->type === BackupType::Full) {
                $plan->keep[$record->id][] = 'newest valid full backup';
                break;
            }
        }
        if ($policy->maxCount > 0) {
            foreach (array_slice($reliable, 0, $policy->maxCount) as $record) {
                $plan->keep[$record->id][] = 'within the newest ' . $policy->maxCount;
            }
        }
        if ($policy->maxAgeDays > 0) {
            $limit = $now->modify('-' . $policy->maxAgeDays . ' days');
            foreach ($reliable as $record) {
                if ($record->createdAt >= $limit) {
                    $plan->keep[$record->id][] = 'younger than ' . $policy->maxAgeDays . ' days';
                }
            }
        }
        $this->gfs($plan, $reliable, $now, $policy);
        $this->keepAncestors($plan, $byId);

        if ($policy->maxStorageBytes > 0) {
            $this->enforceStorage($plan, $reliable, $byId, $policy);
        }
        foreach ($reliable as $record) {
            if (!isset($plan->keep[$record->id])) {
                $plan->delete[$record->id] = 'not required by any retention rule';
            }
        }

        return $plan;
    }

    /**
     * @param list<BackupRecord> $reliable newest first
     */
    private function gfs(RetentionPlan $plan, array $reliable, DateTimeImmutable $now, RetentionPolicy $policy): void
    {
        $buckets = [
            'daily' => [$policy->daily, 'Y-m-d', 'P%dD', static fn (DateTimeImmutable $d): string => $d->format('Y-m-d')],
            'weekly' => [$policy->weekly, 'o-W', 'P%dW', static fn (DateTimeImmutable $d): string => $d->format('o-W')],
            'monthly' => [$policy->monthly, 'Y-m', 'P%dM', static fn (DateTimeImmutable $d): string => $d->format('Y-m')],
            'yearly' => [$policy->yearly, 'Y', 'P%dY', static fn (DateTimeImmutable $d): string => $d->format('Y')],
        ];
        foreach ($buckets as $name => [$count, , , $key]) {
            if ($count < 1) {
                continue;
            }
            $allowed = [];
            $cursor = $now;
            for ($i = 0; $i < $count; $i++) {
                $allowed[$key($cursor)] = true;
                $cursor = match ($name) {
                    'daily' => $cursor->modify('-1 day'),
                    'weekly' => $cursor->modify('-1 week'),
                    'monthly' => $cursor->modify('first day of last month'),
                    default => $cursor->modify('-1 year'),
                };
            }
            $seen = [];
            foreach ($reliable as $record) {
                $bucket = $key($record->createdAt->setTimezone(new DateTimeZone('UTC')));
                if (!isset($allowed[$bucket]) || isset($seen[$bucket])) {
                    continue;
                }
                $seen[$bucket] = true;
                $plan->keep[$record->id][] = "{$name} bucket {$bucket}";
            }
        }
    }

    /**
     * @param array<string, BackupRecord> $byId
     */
    private function keepAncestors(RetentionPlan $plan, array $byId): void
    {
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach (array_keys($plan->keep) as $id) {
                $dependencies = $byId[$id]->requires;
                if (($byId[$id]->parentId ?? null) !== null) {
                    $dependencies[] = (string) $byId[$id]->parentId;
                }
                foreach (array_unique($dependencies) as $parentId) {
                    if (!isset($byId[$parentId])) {
                        $warning = "Backup {$id} depends on missing backup {$parentId}.";
                        if (!in_array($warning, $plan->warnings, true)) {
                            $plan->warnings[] = $warning;
                        }
                        continue;
                    }
                    if (!in_array("required by {$id}", $plan->keep[$parentId] ?? [], true)) {
                        $plan->keep[$parentId][] = "required by {$id}";
                        $changed = true;
                    }
                }
            }
        }
    }

    /**
     * @param list<BackupRecord> $reliable newest first
     * @param array<string, BackupRecord> $byId
     */
    private function enforceStorage(RetentionPlan $plan, array $reliable, array $byId, RetentionPolicy $policy): void
    {
        $total = static function () use ($plan, $byId): int {
            return array_sum(array_map(static fn (string $id): int => $byId[$id]->sizeBytes, array_keys($plan->keep)));
        };
        foreach (array_reverse($reliable) as $record) {
            if ($total() <= $policy->maxStorageBytes) {
                return;
            }
            if (!isset($plan->keep[$record->id]) || $record->protected) {
                continue;
            }
            $reasons = $plan->keep[$record->id];
            $essential = array_filter($reasons, static fn (string $reason): bool => $reason === 'newest valid full backup' || str_starts_with($reason, 'required by '));
            if ($essential !== []) {
                continue;
            }
            unset($plan->keep[$record->id]);
            $plan->delete[$record->id] = 'storage limit exceeded';
        }
        if ($total() > $policy->maxStorageBytes) {
            $plan->warnings[] = 'The storage limit cannot be reached without deleting protected or required backups.';
        }
    }
}
