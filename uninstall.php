<?php
/**
 * Uninstall handler. Data is only removed when the user explicitly enabled "delete data on uninstall".
 *
 * @package sw-backupit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$swbackupit_autoload = defined( 'SWBACKUPIT_AUTOLOADER' ) ? SWBACKUPIT_AUTOLOADER : __DIR__ . '/vendor-prefixed/autoload.php';
if ( ! defined( 'SWBACKUPIT_AUTOLOADER' ) && ! is_readable( $swbackupit_autoload ) ) {
	$swbackupit_autoload = __DIR__ . '/vendor/autoload.php';
}
$swbackupit_loader = require $swbackupit_autoload;
$swbackupit_loader->addPsr4( 'SWBackupIt\\Core\\', __DIR__ . '/packages/core/src/' );
$swbackupit_loader->addPsr4( 'SWBackupIt\\Restore\\', __DIR__ . '/packages/restore/src/' );
$swbackupit_loader->addPsr4( 'SWBackupIt\\WordPress\\', __DIR__ . '/packages/wordpress/src/' );
unset( $swbackupit_autoload, $swbackupit_loader );

\SWBackupIt\WordPress\Lifecycle::uninstall();
