<?php
/**
 * Optional automatic backup before WordPress core, plugin or theme updates (PLAN.md §22).
 *
 * Safety rules, so this can never block or loop an update unsafely:
 * - runs at most once per `max_age_minutes` window per profile (a recent successful backup is reused), guarded by a lock so bulk
 *   updates and re-entrancy never start a second backup;
 * - waits at most `wait_seconds` (default 20) inside the update request; a longer backup simply continues in the background
 *   while the update proceeds;
 * - a failed backup only aborts the update when the administrator explicitly enabled `block_on_failure`.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Runner;

use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Settings;

final class PreUpdateBackup {

	private const LOCK = 'swbi_pre_update_lock';

	private static bool $running = false;

	public function register(): void {
		add_filter( 'upgrader_pre_install', array( $this, 'before_install' ), 5, 2 );
	}

	/**
	 * @param bool|\WP_Error       $response   Passed through unchanged unless the backup is required and failed.
	 * @param array<string, mixed> $hook_extra Upgrader context (type: core|plugin|theme).
	 * @return bool|\WP_Error
	 */
	public function before_install( $response, $hook_extra ) {
		if ( is_wp_error( $response ) || self::$running ) {
			return $response;
		}
		$type     = is_array( $hook_extra ) ? (string) ( $hook_extra['type'] ?? '' ) : '';
		$settings = (array) ( Settings::all()['pre_update_backup'] ?? array() );
		$enabled  = match ( $type ) {
			'core' => ! empty( $settings['core'] ),
			'plugin' => ! empty( $settings['plugins'] ),
			'theme' => ! empty( $settings['themes'] ),
			default => false,
		};
		$profile  = $enabled && isset( $settings['profile_id'] ) ? Services::instance()->profiles()->find( (int) $settings['profile_id'] ) : null;
		if ( null === $profile ) {
			return $response;
		}
		self::$running = true;
		try {
			return $this->ensure_backup( $profile, $settings, $response );
		} finally {
			self::$running = false;
		}
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param bool|\WP_Error       $response
	 * @return bool|\WP_Error
	 */
	private function ensure_backup( \SWBackupIt\Core\Domain\BackupProfile $profile, array $settings, $response ) {
		$window = max( 5, (int) ( $settings['max_age_minutes'] ?? 60 ) ) * MINUTE_IN_SECONDS;
		if ( false !== get_transient( self::LOCK ) ) {
			return $response; // A pre-update backup was handled just now (bulk update).
		}
		set_transient( self::LOCK, 1, $window );
		$latest = Services::instance()->backups()->search( array( 'profile_id' => (int) $profile->id ), 1, 1 )['items'];
		if ( array() !== $latest && strtotime( $latest[0]['created_at'] . ' UTC' ) > time() - $window ) {
			return $response; // Recent backup exists.
		}
		$service = Services::instance()->job_service();
		try {
			$job = $service->start( $profile, 'pre_update', null, false );
		} catch ( SwbiException ) {
			return $response; // Another backup is already running: do not stack a second one and never block.
		}
		$deadline = microtime( true ) + max( 1, min( 60, (int) ( $settings['wait_seconds'] ?? 20 ) ) );
		do {
			$result = $service->tick( $job->id, max( 1.0, $deadline - microtime( true ) ) );
		} while ( $result->shouldContinue && microtime( true ) < $deadline );
		$status = Services::instance()->jobs()->find( $job->id )->status;
		if ( JobStatus::Failed === $status && ! empty( $settings['block_on_failure'] ) ) {
			return new \WP_Error( 'swbi_pre_update_backup_failed', __( 'The update was stopped because the backup before the update failed.', 'swbackupit' ) );
		}
		if ( ! $status->isTerminal() ) {
			Services::instance()->cron()->dispatch_job( $job->id ); // Continues in the background; the update proceeds.
		}
		return $response;
	}
}
