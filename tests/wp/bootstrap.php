<?php
/**
 * Bootstrap for WordPress integration tests.
 */

$swbi_tests_dir = __DIR__ . '/vendor/wp-phpunit/wp-phpunit';
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

define( 'SWBACKUPIT_AUTOLOADER', __DIR__ . '/vendor/autoload.php' );

require_once $swbi_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/sw-backupit.php';
	}
);

require $swbi_tests_dir . '/includes/bootstrap.php';
