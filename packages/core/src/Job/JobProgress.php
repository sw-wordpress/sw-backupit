<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Job;

use SWBackupIt\Core\Domain\Step;

/** Mutable progress counters of a job, persisted with the job (PLAN.md §15). */
final class JobProgress
{
    public int $filesTotal = 0;
    public int $filesDone = 0;
    public int $bytesTotal = 0;
    public int $bytesDone = 0;
    public int $bytesCompressed = 0;
    public string $currentItem = '';
    public int $volumesDone = 0;
    public int $uploadBytesDone = 0;
    public int $uploadBytesTotal = 0;
    public float $uploadRateBps = 0.0;
    public float $stepFraction = 0.0;
    /** @var list<array<string, mixed>> */
    public array $targets = [];

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $progress = new self();
        foreach (['filesTotal', 'filesDone', 'bytesTotal', 'bytesDone', 'bytesCompressed', 'volumesDone', 'uploadBytesDone', 'uploadBytesTotal'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                $progress->{$key} = (int) $data[$key];
            }
        }
        $progress->currentItem = is_string($data['currentItem'] ?? null) ? $data['currentItem'] : '';
        $progress->uploadRateBps = is_numeric($data['uploadRateBps'] ?? null) ? (float) $data['uploadRateBps'] : 0.0;
        $progress->stepFraction = is_numeric($data['stepFraction'] ?? null) ? (float) $data['stepFraction'] : 0.0;
        $progress->targets = is_array($data['targets'] ?? null) ? array_values($data['targets']) : [];

        return $progress;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function compressionRatio(): float
    {
        return $this->bytesDone > 0 ? round($this->bytesCompressed / $this->bytesDone, 4) : 0.0;
    }

    /** Overall percent (0-100) based on the step weights. */
    public function percent(?Step $step, bool $completed = false): float
    {
        if ($completed) {
            return 100.0;
        }
        if ($step === null) {
            return 0.0;
        }
        [$low, $high] = $step->weight();
        $fraction = $step === Step::Archive && $this->bytesTotal > 0
            ? min(1.0, $this->bytesDone / $this->bytesTotal)
            : max(0.0, min(1.0, $this->stepFraction));

        return round($low + ($high - $low) * $fraction, 1);
    }
}
