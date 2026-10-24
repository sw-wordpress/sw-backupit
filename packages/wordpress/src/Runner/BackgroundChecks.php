<?php
/**
 * Regular background checks: integrity verification of stored backups and scheduled test restores.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Runner;

use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Settings;
use SWBackupIt\WordPress\Support\Time;

final class BackgroundChecks {

	/**
	 * Verifies the backup that was verified longest ago (one per call keeps the load predictable).
	 *
	 * @return string|null Backup id that was checked.
	 */
	public function verify_oldest(): ?string {
		global $wpdb;
		$table = Schema::table( 'backups' );
		$row   = $wpdb->get_row( "SELECT id FROM {$table} WHERE status IN ('complete','verified') ORDER BY (verified_at IS NULL) DESC, verified_at ASC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! is_array( $row ) ) {
			return null;
		}
		$id = (string) $row['id'];
		if ( ! self::has_local_data( $id ) ) {
			// Nothing local to verify (local copy removed after upload, or imported from a storage target): not a defect.
			Services::instance()->backups()->touch_checked( $id );
			return $id;
		}
		try {
			$report = ( new Verifier() )->verify( BackupSet::open( Settings::staging_dir() . '/' . $id ) );
		} catch ( SwbiException $exception ) {
			$report = null;
			$error  = $exception->getMessage();
		}
		$ok = null !== $report && $report->isOk();
		Services::instance()->backups()->mark_verified( $id, $ok ? 'verified' : 'corrupt' );
		do_action( 'swbi_backup_verified', $id, $ok ? 'ok' : 'corrupt (background)' );
		if ( ! $ok ) {
			Services::instance()->notifier()->notify(
				'backup_failed',
				'corrupt-' . $id,
				__( 'A stored backup failed its integrity check', 'sw-backupit' ),
				sprintf( /* translators: 1: backup id, 2: details */ __( 'Backup %1$s is damaged or incomplete: %2$s', 'sw-backupit' ), $id, null !== $report ? implode( '; ', array_slice( $report->errors(), 0, 3 ) ) : ( $error ?? '' ) )
			);
		}
		return $id;
	}

	/**
	 * Whether the staging directory holds the backup's data, so a local integrity check is meaningful.
	 *
	 * False when the local copy was deliberately removed after a verified upload (marker file written by the core
	 * finalize step) or when the backup was imported from a storage target without downloading it (manifest only) and
	 * a verified copy exists on a storage target. Missing data without any verified remote copy is still reported as
	 * damaged by the verifier.
	 */
	public static function has_local_data( string $backup_id ): bool {
		$dir = Settings::staging_dir() . '/' . $backup_id;
		if ( is_file( $dir . '/' . \SWBackupIt\Core\Backup\BackupLayout::LOCAL_COPY_REMOVED ) ) {
			return false;
		}
		try {
			$set = BackupSet::open( $dir );
		} catch ( SwbiException ) {
			return ! self::has_remote_copy( $backup_id );
		}
		$manifest = $set->manifest();
		$files    = array();
		foreach ( $manifest->volumes() as $volume ) {
			$files[] = $set->storedVolumePath( $volume->name );
		}
		foreach ( $manifest->databaseParts() as $part ) {
			$files[] = $set->storedDatabasePartPath( $part['name'] );
		}
		if ( $manifest->hasFiles() ) {
			$files[] = $set->storedIndexPath();
		}
		if ( array() === $files || array() !== array_filter( $files, 'is_file' ) ) {
			return true;
		}
		return ! self::has_remote_copy( $backup_id );
	}

	private static function has_remote_copy( string $backup_id ): bool {
		foreach ( Services::instance()->backups()->locations( $backup_id ) as $location ) {
			if ( 0 !== (int) $location['target_id'] && 'verified' === $location['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Runs a test restore of the newest backup when the configured interval has passed.
	 *
	 * @return array<string, mixed>|null The test result when a test ran.
	 */
	public function restore_test_if_due(): ?array {
		$config = (array) ( Settings::all()['restore_test'] ?? array() );
		if ( empty( $config['enabled'] ) ) {
			return null;
		}
		$latest = Services::instance()->backups()->search( array(), 1, 1 )['items'];
		if ( array() === $latest ) {
			return null;
		}
		$tested = Time::from_db( is_string( $latest[0]['restore_tested_at'] ) ? $latest[0]['restore_tested_at'] : null );
		$due    = null === $tested || $tested < Time::now()->modify( '-' . max( 1, (int) ( $config['interval_days'] ?? 7 ) ) . ' days' );
		if ( ! $due ) {
			return null;
		}
		try {
			return ( new RestoreTestService() )->run( (string) $latest[0]['id'] );
		} catch ( SwbiException ) {
			return null;
		}
	}
}
