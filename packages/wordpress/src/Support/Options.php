<?php
/**
 * Plugin options with defined multisite semantics: on a multisite network all sw-backupit data (tables, settings, keys) is
 * network wide and lives on the main site, because a backup always covers the whole network (PLAN.md §22).
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Support;

final class Options {

	/**
	 * @param string $name    Option name.
	 * @param mixed  $default Value when the option does not exist.
	 * @return mixed
	 */
	public static function get( string $name, mixed $default = false ): mixed {
		return is_multisite() ? get_site_option( $name, $default ) : get_option( $name, $default );
	}

	/**
	 * Reads the value from the database, bypassing the object cache (state shared with concurrent workers).
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Value when the option does not exist.
	 * @return mixed
	 */
	public static function get_fresh( string $name, mixed $default = false ): mixed {
		if ( is_multisite() ) {
			wp_cache_delete( get_current_network_id() . ':' . $name, 'site-options' );
		} else {
			wp_cache_delete( $name, 'options' );
		}
		return self::get( $name, $default );
	}

	public static function update( string $name, mixed $value, bool $autoload = false ): bool {
		return is_multisite() ? update_site_option( $name, $value ) : update_option( $name, $value, $autoload );
	}

	public static function delete( string $name ): bool {
		return is_multisite() ? delete_site_option( $name ) : delete_option( $name );
	}
}
