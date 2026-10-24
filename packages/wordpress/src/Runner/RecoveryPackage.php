<?php
/**
 * Complete recovery package of a backup as one ZIP (PLAN.md §9.2, §22).
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Runner;

use SWBackupIt\Core\Archive\RecoveryPackageWriter;
use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\Uuid;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\WordPress\Support\Settings;

/**
 * Packs a backup, every base backup of its chain and the standalone restore application into one ZIP that is streamed to
 * the caller. Encrypted backups stay encrypted (the key is never part of the package). Everything needed for a disaster
 * recovery on a new server is in the package: unzip, then run the restore CLI or the web wizard.
 */
final class RecoveryPackage {

	/**
	 * File name offered for the package.
	 *
	 * @param string $backup_id Backup id.
	 */
	public static function file_name( string $backup_id ): string {
		return 'sw-backupit-recovery-' . $backup_id . '.zip';
	}

	/**
	 * Writes the package.
	 *
	 * @param resource $output    Destination stream.
	 * @param string   $backup_id Backup id.
	 * @return int Number of files in the package.
	 * @throws ConfigurationException When the backup or a base backup has no local copy.
	 */
	public function write( $output, string $backup_id ): int {
		if ( ! Uuid::isValid( $backup_id ) ) {
			throw new ConfigurationException( __( 'Invalid backup id.', 'sw-backupit' ) );
		}
		$staging   = untrailingslashit( Settings::staging_dir() );
		$set       = BackupSet::open( $staging . '/' . $backup_id );
		$ids       = array_merge( array( $backup_id ), array_map( 'strval', (array) ( $set->manifest()->section( 'chain' )['requires'] ?? array() ) ) );
		$encrypted = $set->manifest()->isEncrypted();
		$set->close();

		$directories = array();
		foreach ( array_unique( $ids ) as $id ) {
			$directory = $staging . '/' . $id;
			if ( ! Uuid::isValid( $id ) || ! is_file( $directory . '/manifest.json' ) || ! BackgroundChecks::has_local_data( $id ) ) {
				/* translators: %s: backup id */
				throw new ConfigurationException( sprintf( __( 'The backup %s has no complete local copy. Download it from a storage target first.', 'sw-backupit' ), $id ) );
			}
			$directories[ 'backups/' . $id ] = $directory;
		}

		$app     = SWBACKUPIT_DIR . 'restore-app';
		$scratch = null;
		if ( ! is_file( $app . '/index.php' ) ) {
			// Development checkout: assemble the distribution like the restore wizard does.
			$scratch = $staging . '/tmp/package-app-' . bin2hex( random_bytes( 4 ) );
			( new RestorePreparer() )->install_app( $scratch );
			$app = $scratch;
		}
		$directories['sw-backupit-restore'] = $app;
		try {
			return ( new RecoveryPackageWriter() )->write( $output, $directories, self::readme( $backup_id, $encrypted ) );
		} finally {
			if ( null !== $scratch ) {
				AtomicFile::removeTree( $scratch );
			}
		}
	}

	/**
	 * Instructions included in the package.
	 *
	 * @param string $backup_id Backup id.
	 * @param bool   $encrypted Whether the backup is encrypted.
	 */
	public static function readme( string $backup_id, bool $encrypted ): string {
		$lines = array(
			/* translators: %s: backup id */
			sprintf( __( 'sw-backupit recovery package for backup %s', 'sw-backupit' ), $backup_id ),
			'',
			__( 'Contents: backups/ holds the backup and every base backup it needs, sw-backupit-restore/ the standalone restore application (needs PHP 8.2 with zip and PDO MySQL, no WordPress).', 'sw-backupit' ),
			'',
			__( 'Restore on the command line:', 'sw-backupit' ),
			'  php sw-backupit-restore/bin/sw-backupit-restore verify backups/' . $backup_id . ' --deep',
			'  php sw-backupit-restore/bin/sw-backupit-restore restore backups/' . $backup_id . ' --target=/var/www/site --confirm-target=/var/www/site \\',
			'      --db-host=localhost --db-name=NAME --db-user=USER --confirm-database=NAME',
			'  php sw-backupit-restore/bin/sw-backupit-restore help',
			'',
			__( 'Restore in the browser: copy sw-backupit-restore/ into the web root of the new server (keep backups/ outside of it), run "php sw-backupit-restore/bin/sw-backupit-restore web-init --backups=/path/to/backups --app-dir=/path/to/sw-backupit-restore" and open the shown address with the shown one-time token.', 'sw-backupit' ),
		);
		if ( $encrypted ) {
			$lines[] = '';
			$lines[] = __( 'The backup is encrypted. The key or passphrase is not part of this package: you need it for the restore.', 'sw-backupit' );
		}
		return implode( "\n", $lines ) . "\n";
	}
}
