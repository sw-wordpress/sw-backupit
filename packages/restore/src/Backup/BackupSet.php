<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Backup;

use SWBackupIt\Core\Archive\VolumeInfo;
use SWBackupIt\Core\Crypto\KeyProviderInterface;
use SWBackupIt\Core\Crypto\StreamCipher;
use SWBackupIt\Core\Exception\CryptoException;
use SWBackupIt\Core\Exception\ManifestException;
use SWBackupIt\Core\Manifest\Manifest;
use SWBackupIt\Core\Scanner\FileIndexReader;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\Uuid;

/**
 * A backup directory opened for reading (restore, verification, browsing).
 * Needs nothing but the files of the backup: no WordPress, no database, no catalog.
 *
 * Encrypted backups are decrypted lazily into a private work directory (index once, volumes/parts one at a time) and
 * the plaintext is removed again by close().
 */
final class BackupSet
{
    /** @var array<string, BackupSet> */
    private array $ancestors = [];
    private ?string $decryptedIndex = null;
    private ?string $decryptedVolume = null;
    private ?string $decryptedPart = null;

    private function __construct(
        private readonly string $directory,
        private readonly Manifest $manifest,
        private readonly ?KeyProviderInterface $keys,
        private readonly string $workDirectory,
    ) {
    }

    /**
     * @throws ManifestException
     */
    public static function open(string $directory, ?KeyProviderInterface $keys = null, ?string $workDirectory = null): self
    {
        $real = realpath($directory);
        if ($real === false || !is_dir($real)) {
            throw new ManifestException('Backup directory does not exist.');
        }

        return new self($real, Manifest::read($real), $keys, $workDirectory ?? sys_get_temp_dir() . '/swbi-decrypt-' . bin2hex(random_bytes(6)));
    }

    public function manifest(): Manifest
    {
        return $this->manifest;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function isEncrypted(): bool
    {
        return $this->manifest->isEncrypted();
    }

    public function hasKeys(): bool
    {
        return $this->keys !== null;
    }

    /** True when the data files were removed after a verified upload (restore from a storage target instead). */
    public function isLocalCopyRemoved(): bool
    {
        return is_file($this->directory . '/.local-copy-removed');
    }

    public function indexReader(): FileIndexReader
    {
        return new FileIndexReader($this->indexPath());
    }

    /** Path of the stored index file (encrypted when the backup is encrypted). */
    public function storedIndexPath(): string
    {
        return $this->directory . '/' . $this->manifest->indexFileName();
    }

    /** Path of the readable (plain) index; decrypts it first when necessary. */
    public function indexPath(): string
    {
        if (!$this->isEncrypted()) {
            return $this->storedIndexPath();
        }

        return $this->decryptedIndex ??= $this->decrypt($this->storedIndexPath(), 'files.ndjson');
    }

    public function storedVolumePath(string $name): string
    {
        return $this->directory . '/volumes/' . $name;
    }

    /** Path of a readable (plain ZIP) volume. */
    public function volumePath(string $name): string
    {
        if (!str_ends_with($name, '.swbe')) {
            return $this->storedVolumePath($name);
        }
        if ($this->decryptedVolume !== null) {
            @unlink($this->decryptedVolume);
        }

        return $this->decryptedVolume = $this->decrypt($this->storedVolumePath($name), substr($name, 0, -5));
    }

    public function storedDatabasePartPath(string $name): string
    {
        return $this->directory . '/database/' . $name;
    }

    /** Path of a readable (plain gzip) database part. */
    public function databasePartPath(string $name): string
    {
        if (!str_ends_with($name, '.swbe')) {
            return $this->storedDatabasePartPath($name);
        }
        if ($this->decryptedPart !== null) {
            @unlink($this->decryptedPart);
        }

        return $this->decryptedPart = $this->decrypt($this->storedDatabasePartPath($name), substr($name, 0, -5));
    }

    public function volumeFileName(int $index): string
    {
        foreach ($this->manifest->volumes() as $volume) {
            if ($volume->index === $index) {
                return $volume->name;
            }
        }

        return VolumeInfo::nameFor($index);
    }

    /**
     * Opens another backup of the same chain (a sibling directory) whose volumes this backup's index references.
     *
     * @throws ManifestException When the ancestor is not available.
     */
    public function ancestor(string $backupId): self
    {
        if (!Uuid::isValid($backupId)) {
            throw new ManifestException('The file index references an invalid backup id.');
        }
        if (!isset($this->ancestors[$backupId])) {
            $directory = dirname($this->directory) . '/' . $backupId;
            if (!is_dir($directory)) {
                throw new ManifestException('Required base backup ' . $backupId . ' is not available next to this backup. Download or restore the whole chain.');
            }
            $this->ancestors[$backupId] = self::open($directory, $this->keys, $this->workDirectory . '/ancestor-' . $backupId);
        }

        return $this->ancestors[$backupId];
    }

    /** Removes decrypted plaintext from the work directory. */
    public function close(): void
    {
        foreach ($this->ancestors as $ancestor) {
            $ancestor->close();
        }
        $this->ancestors = [];
        AtomicFile::removeTree($this->workDirectory);
        $this->decryptedIndex = $this->decryptedVolume = $this->decryptedPart = null;
    }

    private function decrypt(string $stored, string $plainName): string
    {
        if ($this->keys === null) {
            throw new CryptoException('This backup is encrypted. Provide the encryption key to read its contents.');
        }
        if (!is_dir($this->workDirectory)) {
            mkdir($this->workDirectory, 0700, true);
        }
        $target = $this->workDirectory . '/' . $plainName;
        $keys = $this->keys;
        StreamCipher::decryptFile($stored, $target, static fn (string $keyId) => $keys->find($keyId));

        return $target;
    }
}
