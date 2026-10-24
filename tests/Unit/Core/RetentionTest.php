<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Unit\Core;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Domain\BackupType;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Retention\BackupCatalogInterface;
use SWBackupIt\Core\Retention\BackupRecord;
use SWBackupIt\Core\Retention\RetentionExecutor;
use SWBackupIt\Core\Retention\RetentionPlanner;
use SWBackupIt\Core\Retention\RetentionPolicy;

final class RetentionTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-24 12:00:00', new DateTimeZone('UTC'));
    }

    private function record(string $id, int $daysAgo, BackupType $type = BackupType::Full, ?string $parent = null, bool $protected = false, bool $reliable = true, int $size = 100): BackupRecord
    {
        return new BackupRecord($id, $type, $this->now->modify("-{$daysAgo} days"), $size, $parent, $protected, $reliable);
    }

    /**
     * @param list<BackupRecord> $records
     * @return list<string>
     */
    private function deleted(array $records, RetentionPolicy $policy): array
    {
        $ids = (new RetentionPlanner())->plan($records, $policy, $this->now)->deletions();
        sort($ids);

        return $ids;
    }

    public function testNoRuleKeepsEverything(): void
    {
        $plan = (new RetentionPlanner())->plan([$this->record('a', 400), $this->record('b', 1)], new RetentionPolicy(), $this->now);
        self::assertSame([], $plan->deletions());
        $kept = array_keys($plan->keep);
        sort($kept);
        self::assertSame(['a', 'b'], $kept);
    }

    public function testMaxCountKeepsNewestAndNewestFull(): void
    {
        $records = [$this->record('d', 1), $this->record('c', 2), $this->record('b', 3), $this->record('a', 4)];
        self::assertSame(['a', 'b'], $this->deleted($records, new RetentionPolicy(maxCount: 2)));
    }

    public function testMaxAge(): void
    {
        $records = [$this->record('new', 1), $this->record('mid', 20), $this->record('old', 90)];
        // "new" is also the newest full backup; "old" is deleted by age.
        self::assertSame(['mid', 'old'], $this->deleted($records, new RetentionPolicy(maxAgeDays: 10)));
    }

    public function testProtectedAndNewestFullAreNeverDeleted(): void
    {
        $records = [$this->record('latest-full', 200), $this->record('inc', 1, BackupType::Files), $this->record('prot', 300, protected: true), $this->record('old', 250)];
        $deleted = $this->deleted($records, new RetentionPolicy(maxCount: 1));
        self::assertNotContains('prot', $deleted);
        self::assertNotContains('latest-full', $deleted, 'the newest valid FULL backup survives even when only an inc is newer');
        self::assertContains('old', $deleted);
    }

    public function testGrandfatherFatherSon(): void
    {
        $records = [];
        for ($d = 0; $d < 120; $d++) {
            $records[] = $this->record("b{$d}", $d);
        }
        $plan = (new RetentionPlanner())->plan($records, new RetentionPolicy(daily: 3, weekly: 2, monthly: 2), $this->now);
        $kept = array_keys($plan->keep);
        // 3 dailies: today, yesterday, 2 days ago
        foreach (['b0', 'b1', 'b2'] as $id) {
            self::assertContains($id, $kept);
        }
        // Far older backups are only kept if they are the newest of their (limited) week/month bucket.
        self::assertNotContains('b100', $kept);
        self::assertLessThan(10, count($kept));
        self::assertGreaterThan(4, count($kept));
        $months = [];
        foreach ($plan->keep as $id => $reasons) {
            foreach ($reasons as $reason) {
                if (str_starts_with($reason, 'monthly')) {
                    $months[] = $reason;
                }
            }
        }
        self::assertCount(2, $months);
    }

    public function testChainDependenciesAreRespected(): void
    {
        // base <- inc1 <- inc2 ; inc2 is kept by count, so inc1 and base must survive although they are older.
        $records = [
            $this->record('base', 30),
            $this->record('inc1', 20, BackupType::Incremental, 'base'),
            $this->record('inc2', 1, BackupType::Incremental, 'inc1'),
            $this->record('other-full', 40),
            $this->record('stale', 50),
        ];
        $plan = (new RetentionPlanner())->plan($records, new RetentionPolicy(maxCount: 1), $this->now);
        self::assertContains('base', array_keys($plan->keep));
        self::assertContains('inc1', array_keys($plan->keep));
        self::assertContains('inc2', array_keys($plan->keep));
        self::assertContains('stale', $plan->deletions());
        self::assertContains('other-full', $plan->deletions());
    }

    public function testMissingParentProducesWarningNotDeletion(): void
    {
        $plan = (new RetentionPlanner())->plan([$this->record('inc', 1, BackupType::Incremental, 'ghost')], new RetentionPolicy(maxCount: 1), $this->now);
        self::assertSame([], $plan->deletions());
        self::assertNotEmpty($plan->warnings);
    }

    public function testUnreliableMetadataIsNeverDeleted(): void
    {
        $records = [$this->record('good', 1), $this->record('broken', 500, reliable: false), $this->record('older', 400)];
        $deleted = $this->deleted($records, new RetentionPolicy(maxCount: 1));
        self::assertNotContains('broken', $deleted);
        self::assertSame(['older'], $deleted);
    }

    public function testStorageLimitDropsOldestButNotEssentials(): void
    {
        $records = [$this->record('c', 1, size: 100), $this->record('b', 2, size: 100), $this->record('a', 3, size: 100)];
        $plan = (new RetentionPlanner())->plan($records, new RetentionPolicy(maxCount: 10, maxStorageBytes: 150), $this->now);
        self::assertSame(['a', 'b'], array_values(array_intersect(['a', 'b', 'c'], $plan->deletions())));
        self::assertSame([], $plan->warnings);
        $impossible = (new RetentionPlanner())->plan($records, new RetentionPolicy(maxCount: 10, maxStorageBytes: 10 * 1048576 > 0 ? 50 : 0), $this->now);
        self::assertNotEmpty($impossible->warnings, 'a limit below the size of the newest full backup cannot be reached');
        self::assertContains('c', array_keys($impossible->keep));
    }

    /**
     * Regression: database-only/files-only profiles have no "full" backups; a tight storage limit deleted every backup.
     */
    public function testStorageLimitNeverDeletesNewestBackupOfNonFullProfiles(): void
    {
        foreach ([BackupType::Database, BackupType::Files] as $type) {
            $records = [$this->record('c', 1, $type, size: 500), $this->record('b', 2, $type, size: 500), $this->record('a', 3, $type, size: 500)];
            $plan = (new RetentionPlanner())->plan($records, new RetentionPolicy(maxCount: 5, maxStorageBytes: 400), $this->now);
            $deleted = $plan->deletions();
            sort($deleted);
            self::assertSame(['a', 'b'], $deleted, $type->value);
            self::assertNotEmpty($plan->warnings, $type->value);
        }
    }

    public function testExecutorDryRunAndFailureIsolation(): void
    {
        $records = [$this->record('keep', 1), $this->record('x', 100), $this->record('y', 101)];
        $plan = (new RetentionPlanner())->plan($records, new RetentionPolicy(maxCount: 1), $this->now);
        $catalog = new class implements BackupCatalogInterface {
            /** @var list<string> */
            public array $deleted = [];

            public function records(?int $profileId = null): array
            {
                return [];
            }

            public function delete(string $backupId): void
            {
                if ($backupId === 'x') {
                    throw new SwbiException('storage unreachable');
                }
                $this->deleted[] = $backupId;
            }
        };
        $dry = (new RetentionExecutor())->execute($plan, $catalog, true);
        self::assertSame([], $catalog->deleted, 'dry run deletes nothing');
        self::assertCount(2, $dry['deleted']);

        $result = (new RetentionExecutor())->execute($plan, $catalog);
        self::assertSame(['y'], $catalog->deleted);
        self::assertArrayHasKey('x', $result['failed']);
    }
}
