<?php
/**
 * Local plugin identities. No requests or option reads.
 *
 * @package sw-backupit
 */

return array(
	// uninstall.php runs without the plugin's constants.
	defined( 'SWBACKUPIT_FILE' ) ? plugin_basename( SWBACKUPIT_FILE ) : 'sw-backupit/sw-backupit.php' => array(
		'slug'    => 'sw-backupit',
		'version' => defined( 'SWBACKUPIT_VERSION' ) ? SWBACKUPIT_VERSION : '0',
	),
);
