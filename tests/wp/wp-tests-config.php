<?php
/**
 * WordPress test suite configuration. Uses ONLY the private throw-away MariaDB instance (bin/test-db.sh),
 * never the development site's database.
 */

define( 'ABSPATH', __DIR__ . '/vendor/johnpbloch/wordpress-core/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'SWBI_WP_TEST_DB_NAME' ) ?: 'swbi_wp_tests' );
define( 'DB_USER', getenv( 'SWBI_TEST_DB_USER' ) ?: 'swbi' );
define( 'DB_PASSWORD', getenv( 'SWBI_TEST_DB_PASSWORD' ) ?: 'swbi' );
define( 'DB_HOST', ( getenv( 'SWBI_TEST_DB_HOST' ) ?: '127.0.0.1' ) . ':' . ( getenv( 'SWBI_TEST_DB_PORT' ) ?: '33077' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'SWBackupIt Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

$table_prefix = 'wptests_';
