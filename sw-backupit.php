<?php
/**
 * Plugin Name:       SW-BackupIt
 * Plugin URI:        https://example.com/sw-backupit
 * Description:       Robust, resumable WordPress backup, restore and disaster recovery with a standalone restore application.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            sw-backupit contributors
 * License:           MIT
 * Text Domain:       sw-backupit
 * Domain Path:       /languages
 *
 * @package sw-backupit
 */

defined( 'ABSPATH' ) || exit;

define( 'SWBACKUPIT_VERSION', '0.1.0' );
define( 'SWBACKUPIT_FILE', __FILE__ );
define( 'SWBACKUPIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWBACKUPIT_URL', plugin_dir_url( __FILE__ ) );
define( 'SWBACKUPIT_MIN_PHP', '8.2' );

if ( version_compare( PHP_VERSION, SWBACKUPIT_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'sw-backupit requires PHP %1$s or newer. You are running PHP %2$s.', 'sw-backupit' ),
						SWBACKUPIT_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

// SWBACKUPIT_AUTOLOADER may be defined by the test environment to supply its own dependency set.
$swbackupit_autoload = defined( 'SWBACKUPIT_AUTOLOADER' ) ? SWBACKUPIT_AUTOLOADER : SWBACKUPIT_DIR . 'vendor-prefixed/autoload.php';
if ( ! defined( 'SWBACKUPIT_AUTOLOADER' ) && ! is_readable( $swbackupit_autoload ) ) {
	// Source checkouts use Composer; release packages only ship isolated dependencies.
	$swbackupit_autoload = SWBACKUPIT_DIR . 'vendor/autoload.php';
}
if ( ! is_readable( $swbackupit_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'sw-backupit: the vendor directory is missing. Install the release package or run "composer install".', 'sw-backupit' )
			);
		}
	);
	return;
}
$swbackupit_loader = require $swbackupit_autoload;
// Mozart's loader only knows the dependencies, not the monorepo's own classes.
$swbackupit_loader->addPsr4( 'SWBackupIt\\Core\\', SWBACKUPIT_DIR . 'packages/core/src/' );
$swbackupit_loader->addPsr4( 'SWBackupIt\\Restore\\', SWBACKUPIT_DIR . 'packages/restore/src/' );
$swbackupit_loader->addPsr4( 'SWBackupIt\\WordPress\\', SWBACKUPIT_DIR . 'packages/wordpress/src/' );
unset( $swbackupit_autoload, $swbackupit_loader );

register_activation_hook( __FILE__, array( \SWBackupIt\WordPress\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \SWBackupIt\WordPress\Lifecycle::class, 'deactivate' ) );

\SWBackupIt\WordPress\Plugin::instance()->boot();
