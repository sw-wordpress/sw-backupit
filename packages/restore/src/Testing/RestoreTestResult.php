<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Testing;

final class RestoreTestResult
{
    public const PASSED = 'passed';
    public const PARTIAL = 'partial';
    public const FAILED = 'failed';

    /** @var list<array{name: string, status: string, detail: string}> */
    public array $checks = [];
    public float $seconds = 0.0;

    public function add(string $name, string $status, string $detail = ''): void
    {
        $this->checks[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
    }

    public function status(): string
    {
        $statuses = array_column($this->checks, 'status');
        if (in_array(self::FAILED, $statuses, true)) {
            return self::FAILED;
        }

        return in_array('skipped', $statuses, true) ? self::PARTIAL : self::PASSED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['status' => $this->status(), 'seconds' => round($this->seconds, 2), 'checks' => $this->checks, 'tested_at' => gmdate('Y-m-d\TH:i:s\Z')];
    }
}
