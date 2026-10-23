<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup;

use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Job\Job;

/**
 * The files that make up a backup as they are *stored* (encrypted or not), resolved from job checkpoints.
 * Single place that knows whether the Encrypt step replaced the plain artifacts.
 */
final class Artifacts
{
    /**
     * @return list<array{index: int, name: string, size: int, sha256: string, entries: int}>
     */
    public static function volumes(Job $job): array
    {
        $encrypt = $job->checkpoint(Step::Encrypt);
        $source = ($encrypt['volumes'] ?? null) ?: ($job->checkpoint(Step::Archive)['volumes'] ?? []);
        $volumes = [];
        foreach (is_array($source) ? $source : [] as $volume) {
            $volumes[] = [
                'index' => (int) $volume['index'],
                'name' => (string) $volume['name'],
                'size' => (int) $volume['size'],
                'sha256' => (string) $volume['sha256'],
                'entries' => (int) $volume['entries'],
            ];
        }

        return $volumes;
    }

    /**
     * @return list<array{name: string, size: int, sha256: string, raw_bytes: int}>
     */
    public static function databaseParts(Job $job): array
    {
        $encrypt = $job->checkpoint(Step::Encrypt);
        $source = ($encrypt['parts'] ?? null) ?: ($job->checkpoint(Step::Export)['parts'] ?? []);
        $parts = [];
        foreach (is_array($source) ? $source : [] as $part) {
            $parts[] = [
                'name' => (string) $part['name'],
                'size' => (int) $part['size'],
                'sha256' => (string) $part['sha256'],
                'raw_bytes' => (int) ($part['raw_bytes'] ?? 0),
            ];
        }

        return $parts;
    }

    /** Stored index description: file name, sha256 of the stored file. */
    public static function indexName(Job $job): string
    {
        return isset($job->checkpoint(Step::Encrypt)['index']['name']) ? (string) $job->checkpoint(Step::Encrypt)['index']['name'] : 'files.ndjson';
    }

    public static function isEncrypted(Job $job): bool
    {
        return $job->checkpoint(Step::Encrypt)['finished'] ?? false;
    }
}
