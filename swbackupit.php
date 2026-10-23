<?php
/**
 * Plugin Name:       SWBackupIt
 * Plugin URI:        https://example.com/swbackupit
 * Description:       Robust, resumable WordPress backup, restore and disaster recovery with a standalone restore application.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            SWBackupIt contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       swbackupit
 * Domain Path:       /languages
 *
 * @package SWBackupIt
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
						__( 'SWBackupIt requires PHP %1$s or newer. You are running PHP %2$s.', 'swbackupit' ),
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
$swbackupit_autoload = defined( 'SWBACKUPIT_AUTOLOADER' ) ? SWBACKUPIT_AUTOLOADER : SWBACKUPIT_DIR . 'vendor/autoload.php';
if ( ! is_readable( $swbackupit_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'SWBackupIt: the vendor directory is missing. Install the release package or run "composer install".', 'swbackupit' )
			);
		}
	);
	return;
}
require_once $swbackupit_autoload;
unset( $swbackupit_autoload );

register_activation_hook( __FILE__, array( \SWBackupIt\WordPress\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \SWBackupIt\WordPress\Lifecycle::class, 'deactivate' ) );

\SWBackupIt\WordPress\Plugin::instance()->boot();
