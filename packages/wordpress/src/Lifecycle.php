<?php
/**
 * Activation, deactivation and uninstall handling.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress;

use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Runner\CronManager;
use SWBackupIt\WordPress\Support\Capabilities;

final class Lifecycle {

	/**
	 * Creates tables, capabilities and the schedule watcher.
	 *
	 * @param bool $network_wide Whether activated network wide (multisite).
	 */
	public static function activate( bool $network_wide = false ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature dictated by register_activation_hook().
		if ( is_multisite() ) {
			// Network wide data model: set up once, on the main site (tables use the base prefix).
			switch_to_blog( get_main_site_id() );
			self::activate_site();
			restore_current_blog();
			return;
		}
		self::activate_site();
	}

	private static function activate_site(): void {
		Schema::migrate();
		Capabilities::grant_defaults();
		Support\DefaultProfiles::seed_if_empty();
		( new CronManager() )->ensure_watcher();
	}

	/**
	 * Deactivation keeps all data (deactivation is not uninstallation), but removes prepared restore wizards: they are
	 * executable copies of the restore application inside the web root and must not outlive the active plugin.
	 */
	public static function deactivate(): void {
		( new CronManager() )->clear_all();
		( new Runner\RestorePreparer() )->remove_all();
	}

	/**
	 * Removes plugin data only if the user opted in via settings. Prepared restore wizards and scheduled events are
	 * always removed. Backups in the staging directory and on storage targets are never deleted here.
	 */
	public static function uninstall(): void {
		( new CronManager() )->clear_all();
		( new Runner\RestorePreparer() )->remove_all();
		$settings = \SWBackupIt\WordPress\Support\Options::get( 'swbi_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
			return;
		}
		Schema::drop_all();
		Capabilities::revoke_all();
		foreach ( array(
			'swbi_settings',
			Schema::VERSION_OPTION,
			'swbi_secret_key',
			'swbi_encryption_keys',
			'swbi_staging_dir',
			'swbi_notification_log',
			CronManager::TICK_OPTION,
			Plugin::MAINTENANCE_OPTION,
		) as $option ) {
			\SWBackupIt\WordPress\Support\Options::delete( $option );
		}
		delete_transient( 'swbi_health_check' );
		delete_transient( 'swbi_pre_update_lock' );
	}
}
