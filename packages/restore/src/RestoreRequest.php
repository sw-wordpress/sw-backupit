<?php

declare(strict_types=1);

namespace SWBackupIt\Restore;

use SWBackupIt\Core\Crypto\KeyProviderInterface;
use SWBackupIt\Core\Database\ConnectionConfig;
use SWBackupIt\Restore\Files\OverwriteMode;
use SWBackupIt\Restore\Files\RestoreLimits;
use SWBackupIt\Restore\Files\Selection;

/**
 * Parameters of a restore. Destructive actions need explicit confirmation values that must match the
 * canonical target path / database name (a UI checkbox alone is not enough).
 */
final readonly class RestoreRequest
{
    /**
     * @param string $confirmedTargetPath Must equal the canonical (realpath) target directory.
     * @param string $confirmedDatabase   Must equal the target database name when the database is restored.
     * @param string $stateDirectory      Where resumable progress is stored (outside the target tree).
     * @param array<string, string> $replacements URL/path search => replace pairs applied serialization-safe after the import.
     * @param string|null $newTablePrefix Rename tables to this prefix after the import.
     */
    public function __construct(
        public string $backupDirectory,
        public string $targetPath,
        public string $confirmedTargetPath,
        public string $stateDirectory,
        public bool $restoreFiles = true,
        public bool $restoreDatabase = true,
        public ?ConnectionConfig $database = null,
        public string $confirmedDatabase = '',
        public bool $updateWpConfig = true,
        public OverwriteMode $overwrite = OverwriteMode::IfDifferent,
        public Selection $selection = new Selection(),
        public bool $dryRun = false,
        public bool $verifyFirst = true,
        public RestoreLimits $limits = new RestoreLimits(),
        public ?KeyProviderInterface $keys = null,
        public array $replacements = [],
        public ?string $newTablePrefix = null,
        public bool $migrationDryRun = false,
    ) {
    }

    /**
     * Identifies what this request restores where. Resume state is only reused for an identical request, so a new restore
     * (other target, database, selection, ...) never continues at the phase/offset of an earlier one. Contains no secrets.
     */
    public function fingerprint(): string
    {
        $database = $this->database === null ? null : [$this->database->host, $this->database->port, $this->database->socket, $this->database->name];

        return hash('sha256', (string) json_encode([
            realpath($this->backupDirectory) ?: $this->backupDirectory, realpath($this->targetPath) ?: $this->targetPath,
            $this->restoreFiles, $this->restoreDatabase, $database, $this->overwrite->value, $this->selection->toArray(),
            $this->dryRun, $this->replacements, $this->newTablePrefix, $this->migrationDryRun,
        ]));
    }
}
