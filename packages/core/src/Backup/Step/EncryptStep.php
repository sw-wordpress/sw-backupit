<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Backup\Step;

use SWBackupIt\Core\Backup\Artifacts;
use SWBackupIt\Core\Backup\BackupContext;
use SWBackupIt\Core\Backup\BackupStepInterface;
use SWBackupIt\Core\Backup\StepOutcome;
use SWBackupIt\Core\Crypto\StreamCipher;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Exception\CryptoException;
use SWBackupIt\Core\Scanner\FileIndexReader;

/**
 * Client side encryption of volumes, database parts and the file index (format SWBE1).
 *
 * Order of operations per file: structural verification of the plain data (once, up front) -> encrypt to a temp name
 * -> rename -> decrypt-and-hash round trip against the known plain checksum -> checkpoint -> delete the plain file.
 * A plain file is therefore only removed after the encrypted copy is proven correct and recorded.
 */
final class EncryptStep implements BackupStepInterface
{
    public function step(): Step
    {
        return Step::Encrypt;
    }

    public function applies(BackupProfile $profile): bool
    {
        return $profile->encryption->enabled;
    }

    public function run(BackupContext $context): StepOutcome
    {
        StreamCipher::assertAvailable();
        $job = $context->job;
        $keyId = $context->profile->encryption->keyId;
        $key = $context->keys?->find($keyId);
        if ($key === null) {
            throw new ConfigurationException('The encryption key "' . $keyId . '" is not available. Provide it via the configured key store.');
        }
        $state = $job->checkpoint(Step::Encrypt) + [
            'pre' => [], 'pre_done' => false, 'volumes' => [], 'parts' => [], 'index' => null,
            'index_stats' => null, 'finished' => false, 'kdf' => $key->isPassphrase ? 'argon2id' : 'raw',
        ];
        if ($state['finished'] === true) {
            return StepOutcome::Done;
        }
        $save = function (array $state) use ($context): void {
            $context->job->setCheckpoint(Step::Encrypt, $state);
            $context->commit();
        };

        if ($state['pre_done'] !== true) {
            $outcome = (new VerifyLocalStep())->verifyPlain($context, $state['pre'], function (array $pre) use (&$state, $save): void {
                $state['pre'] = $pre;
                $save($state);
            });
            if ($outcome === StepOutcome::Yield) {
                return StepOutcome::Yield;
            }
            if ($context->profile->type->includesFiles()) {
                $count = 0;
                $bytes = 0;
                foreach ((new FileIndexReader($context->layout->indexPath()))->read() as [$entry]) {
                    $count++;
                    $bytes += $entry['t'] === 'f' ? (int) $entry['s'] : 0;
                }
                $state['index_stats'] = ['count' => $count, 'bytes' => $bytes];
            }
            $state['pre_done'] = true;
            $save($state);
        }

        $plainVolumes = $job->checkpoint(Step::Archive)['volumes'] ?? [];
        foreach ($plainVolumes as $volume) {
            if ($this->isDone($state['volumes'], (int) $volume['index'])) {
                continue;
            }
            $plain = $context->layout->volumesDirectory() . '/' . $volume['name'];
            $info = $this->encryptFile($context, $key, $plain, (string) $volume['sha256']);
            $state['volumes'][] = ['index' => (int) $volume['index'], 'entries' => (int) $volume['entries']] + $info;
            $this->after($context, $state, $save, $plain, count($plainVolumes));
            if ($context->budget->exhausted()) {
                return StepOutcome::Yield;
            }
        }
        $plainParts = $job->checkpoint(Step::Export)['parts'] ?? [];
        foreach ($plainParts as $position => $part) {
            if (isset($state['parts'][$position])) {
                continue;
            }
            $plain = $context->layout->databaseDirectory() . '/' . $part['name'];
            $info = $this->encryptFile($context, $key, $plain, (string) $part['sha256']);
            $state['parts'][$position] = ['raw_bytes' => (int) ($part['raw_bytes'] ?? 0)] + $info;
            $this->after($context, $state, $save, $plain, count($plainParts));
            if ($context->budget->exhausted() && $position < count($plainParts) - 1) {
                return StepOutcome::Yield;
            }
        }
        if ($context->profile->type->includesFiles() && $state['index'] === null) {
            $plain = $context->layout->indexPath();
            $hash = hash_file('sha256', $plain);
            $state['index'] = $this->encryptFile($context, $key, $plain, $hash === false ? '' : $hash);
            $save($state);
            @unlink($plain);
        }
        $state['finished'] = true;
        $save($state);
        $context->logger->info('Backup artifacts encrypted.', ['key_id' => $keyId, 'volumes' => count($state['volumes']), 'database_parts' => count($state['parts'])]);

        return StepOutcome::Done;
    }

    /**
     * @param list<array<string, mixed>> $done
     */
    private function isDone(array $done, int $index): bool
    {
        foreach ($done as $item) {
            if ((int) $item['index'] === $index) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, size: int, sha256: string}
     */
    private function encryptFile(BackupContext $context, \SWBackupIt\Core\Crypto\EncryptionKey $key, string $plain, string $plainHash): array
    {
        $encrypted = $plain . '.swbe';
        StreamCipher::encryptFile($plain, $encrypted, $key);
        // Round trip: decrypt to a temp file, compare with the plain checksum, discard.
        $check = $context->layout->workDirectory() . '/roundtrip.tmp';
        StreamCipher::decryptFile($encrypted, $check, static fn (): \SWBackupIt\Core\Crypto\EncryptionKey => $key);
        $roundTrip = hash_file('sha256', $check);
        @unlink($check);
        if ($roundTrip === false || !hash_equals($plainHash, $roundTrip)) {
            @unlink($encrypted);
            throw new CryptoException('The encrypted file failed the decryption round trip.', ['file' => basename($plain)]);
        }
        $hash = hash_file('sha256', $encrypted);
        $size = filesize($encrypted);
        if ($hash === false || $size === false) {
            throw new CryptoException('Cannot read the encrypted file.');
        }

        return ['name' => basename($encrypted), 'size' => $size, 'sha256' => $hash];
    }

    /**
     * @param array<string, mixed> $state
     * @param callable(array<string, mixed>): void $save
     */
    private function after(BackupContext $context, array $state, callable $save, string $plain, int $total): void
    {
        $done = count($state['volumes']) + count($state['parts']);
        $context->job->progress->stepFraction = min(1.0, $done / max(1, $total * 2));
        $save($state);
        @unlink($plain);
    }
}
