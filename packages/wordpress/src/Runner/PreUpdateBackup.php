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

	/**
	 * Hook points (verified against the WordPress upgrader source):
	 * - core: Core_Upgrader::upgrade() never calls install_package(), so `upgrader_pre_install` does not fire for core
	 *   updates; it does call download_package(), which applies `upgrader_pre_download` before anything is changed
	 *   (manual and automatic core updates).
	 * - plugins/themes: `upgrader_pre_install` fires before the new files replace the old ones. It also fires for
	 *   installs of new plugins/themes, which are ignored. Bulk updates pass no type/action, only the plugin/theme key.
	 */
	public function register(): void {
		add_filter( 'upgrader_pre_download', array( $this, 'before_download' ), 5, 4 );
		add_filter( 'upgrader_pre_install', array( $this, 'before_install' ), 5, 2 );
	}

	/**
	 * Core updates.
	 *
	 * @param bool|string|\WP_Error $reply      False to continue the download; anything else short-circuits it.
	 * @param string                $package    Package URL (unused).
	 * @param mixed                 $upgrader   Upgrader instance.
	 * @param array<string, mixed>  $hook_extra Extra arguments (empty for core updates).
	 * @return bool|string|\WP_Error
	 */
	public function before_download( $reply, $package, $upgrader, $hook_extra = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- signature dictated by the filter.
		if ( false !== $reply || ! $upgrader instanceof \Core_Upgrader ) {
			return $reply;
		}
		$result = $this->maybe_backup( 'core' );
		return is_wp_error( $result ) ? $result : $reply;
	}

	/**
	 * Plugin and theme updates.
	 *
	 * @param bool|\WP_Error       $response   Passed through unchanged unless the backup is required and failed.
	 * @param array<string, mixed> $hook_extra Upgrader context (type: plugin|theme, action: update|install).
	 * @return bool|\WP_Error
	 */
	public function before_install( $response, $hook_extra ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$type = self::update_type( is_array( $hook_extra ) ? $hook_extra : array() );
		if ( null === $type || 'core' === $type ) {
			return $response;
		}
		$result = $this->maybe_backup( $type );
		return is_wp_error( $result ) ? $result : $response;
	}

	/**
	 * Classifies upgrader context: plugin|theme|core for updates, null for installs and everything else (translations).
	 *
	 * @param array<string, mixed> $hook_extra Upgrader context.
	 */
	public static function update_type( array $hook_extra ): ?string {
		$type = (string) ( $hook_extra['type'] ?? '' );
		if ( '' === $type ) {
			// Bulk updates (Plugin_Upgrader::bulk_upgrade(), Theme_Upgrader::bulk_upgrade()) only pass the item key.
			$type = isset( $hook_extra['plugin'] ) ? 'plugin' : ( isset( $hook_extra['theme'] ) ? 'theme' : '' );
		}
		$action = (string) ( $hook_extra['action'] ?? ( '' !== $type ? 'update' : '' ) );
		if ( 'update' !== $action || ! in_array( $type, array( 'core', 'plugin', 'theme' ), true ) ) {
			return null;
		}
		return $type;
	}

	/**
	 * @return true|\WP_Error|null Null when nothing was done.
	 */
	private function maybe_backup( string $type ) {
		if ( self::$running ) {
			return null;
		}
		$settings = (array) ( Settings::all()['pre_update_backup'] ?? array() );
		$enabled  = match ( $type ) {
			'core' => ! empty( $settings['core'] ),
			'plugin' => ! empty( $settings['plugins'] ),
			'theme' => ! empty( $settings['themes'] ),
			default => false,
		};
		$profile = $enabled && isset( $settings['profile_id'] ) ? Services::instance()->profiles()->find( (int) $settings['profile_id'] ) : null;
		if ( null === $profile ) {
			return null;
		}
		self::$running = true;
		try {
			return $this->ensure_backup( $profile, $settings, true );
		} finally {
			self::$running = false;
		}
	}

	/**
	 * @param \SWBackupIt\Core\Domain\BackupProfile $profile  Profile used for the pre-update backup.
	 * @param array<string, mixed>                  $settings pre_update_backup settings.
	 * @param bool|\WP_Error                        $response Value to return unchanged unless the update must be blocked.
	 * @return bool|\WP_Error
	 */
	private function ensure_backup( \SWBackupIt\Core\Domain\BackupProfile $profile, array $settings, $response ) {
		$window = max( 5, (int) ( $settings['max_age_minutes'] ?? 60 ) ) * MINUTE_IN_SECONDS;
		if ( false !== get_transient( self::LOCK ) ) {
			return $response; // A pre-update backup was handled just now (bulk update).
		}
		set_transient( self::LOCK, 1, $window );
		foreach ( Services::instance()->backups()->search( array( 'profile_id' => (int) $profile->id ), 1, 5 )['items'] as $recent ) {
			if ( strtotime( $recent['created_at'] . ' UTC' ) <= time() - $window ) {
				break; // Newest first: everything else is older.
			}
			if ( in_array( (string) $recent['status'], array( 'complete', 'verified' ), true ) ) {
				return $response; // A recent intact backup exists (corrupt/incomplete ones are never reused).
			}
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
