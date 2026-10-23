<?php
/**
 * Activation, deactivation and uninstall handling.
 *
 * @package SWBackupIt
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
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_site();
	}

	private static function activate_site(): void {
		Schema::migrate();
		Capabilities::grant_defaults();
		( new CronManager() )->ensure_watcher();
	}

	/** Deactivation keeps all data (deactivation is not uninstallation). */
	public static function deactivate(): void {
		( new CronManager() )->clear_all();
	}

	/** Removes plugin data only if the user opted in via settings. */
	public static function uninstall(): void {
		$settings = get_option( 'swbi_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
			return;
		}
		Schema::drop_all();
		Capabilities::revoke_all();
		delete_option( 'swbi_settings' );
		delete_option( Schema::VERSION_OPTION );
		delete_option( 'swbi_secret_key' );
	}
}
