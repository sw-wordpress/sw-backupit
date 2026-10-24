<?php
/**
 * Runs the automated, isolated test restore of a backup and documents the result.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Runner;

use SWBackupIt\Core\Database\ConnectionConfig;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Restore\Testing\RestoreTester;
use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Settings;

final class RestoreTestService {

	/**
	 * @return array<string, mixed> Result (status passed|partial|failed, checks[], seconds, tested_at).
	 * @throws SwbiException When the backup does not exist.
	 */
	public function run( string $backup_id ): array {
		if ( null === Services::instance()->backups()->find_row( $backup_id ) ) {
			throw new SwbiException( __( 'Backup not found.', 'sw-backupit' ) );
		}
		$work = untrailingslashit( Settings::staging_dir() ) . '/tmp';
		wp_mkdir_p( $work );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host.
		}
		$admin  = ConnectionConfig::fromWordPressHost( DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, defined( 'DB_CHARSET' ) ? (string) DB_CHARSET : 'utf8mb4' );
		$result = ( new RestoreTester() )->run( Settings::staging_dir() . '/' . $backup_id, Services::instance()->keys(), $work, $admin )->toArray();
		Services::instance()->backups()->record_restore_test( $backup_id, $result );
		do_action( 'swbi_restore_tested', $backup_id, (string) $result['status'] );
		$this->notify( $backup_id, (string) $result['status'] );
		return $result;
	}

	/** Restore notifications (PLAN.md §16) with the notification settings of the backup's profile. */
	private function notify( string $backup_id, string $status ): void {
		$row           = Services::instance()->backups()->find_row( $backup_id );
		$profile       = null !== $row && null !== $row['profile_id'] ? Services::instance()->profiles()->find( (int) $row['profile_id'] ) : null;
		$notifications = null !== $profile ? (array) ( $profile->toArray()['notifications'] ?? array() ) : array();
		if ( 'failed' === $status ) {
			Services::instance()->notifier()->notify(
				'restore_failed',
				$backup_id,
				__( 'Test restore of a backup failed', 'sw-backupit' ),
				sprintf( /* translators: %s: backup id */ __( 'The automated test restore of backup %s failed. The backup may not be restorable.', 'sw-backupit' ), $backup_id ),
				$notifications
			);
		} elseif ( 'passed' === $status ) {
			Services::instance()->notifier()->notify(
				'restore_success',
				$backup_id,
				__( 'Test restore of a backup succeeded', 'sw-backupit' ),
				sprintf( /* translators: %s: backup id */ __( 'The automated test restore of backup %s succeeded.', 'sw-backupit' ), $backup_id ),
				$notifications
			);
		}
	}
}
