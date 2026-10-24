<?php
/**
 * WP-CLI commands (docs/wp-cli.md).
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Cli;

use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\WordPress\Rest\JobPresenter;
use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Settings;

/**
 * Catalog commands.
 */
final class CatalogCommand {
	use CommandSupport;

	/**
	 * Rebuilds the backup catalog from the manifests in the staging directory (disaster recovery).
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function rebuild( array $args, array $assoc_args ): void {
		$count = Services::instance()->backups()->rebuild_from_directory( Settings::staging_dir() );
		\WP_CLI::success( sprintf( '%d backup(s) registered.', $count ) );
	}
}
