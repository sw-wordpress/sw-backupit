<?php
/**
 * Plugin settings with validation and defaults.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Support;

final class Settings {

	private const OPTION = 'swbi_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'staging_dir'              => '',
			// Backups contain the database and wp-config.php: paths below the web root are refused unless explicitly allowed.
			'allow_public_paths'       => false,
			'limits'                   => array(
				'time_budget_seconds'  => 20,
				'memory_mb'            => 128,
				'batch_rows'           => 1000,
				'max_parallel_uploads' => 1,
			),
			'encryption'               => array( 'default_key_id' => '' ),
			'notifications'            => array(
				'recipients'     => array(),
				'dedupe_minutes' => 60,
			),
			'logging'                  => array(
				'retention_days'       => 30,
				'max_events_per_job'   => 5000,
				'level'                => 'info',
				'audit_retention_days' => 365,
			),
			'cron'                     => array(
				'mode'     => 'wp-cron',
				'timezone' => 'UTC',
			),
			'retention_default'        => array(
				'max_count'    => 10,
				'max_age_days' => 0,
				'gfs'          => array(
					'daily'   => 7,
					'weekly'  => 4,
					'monthly' => 6,
					'yearly'  => 0,
				),
			),
			'pre_update_backup'        => array(
				'core'             => false,
				'plugins'          => false,
				'themes'           => false,
				'profile_id'       => null,
				'max_age_minutes'  => 60,
				'wait_seconds'     => 20,
				'block_on_failure' => false,
			),
			'restore_test'             => array(
				'enabled'       => false,
				'interval_days' => 7,
			),
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = \SWBackupIt\WordPress\Support\Options::get( self::OPTION, array() );
		return self::merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error Saved settings.
	 */
	public static function update( array $input ) {
		$current = self::all();
		if ( isset( $input['staging_dir'] ) && is_string( $input['staging_dir'] ) && '' === (string) $current['staging_dir']
			&& rtrim( $input['staging_dir'], '/' ) === self::staging_dir() ) {
			// The UI shows the resolved automatic directory; posting it back unchanged must not pin it as configured.
			$input['staging_dir'] = '';
		}
		$merged = self::merge( $current, $input );
		$error  = self::validate( $merged );
		if ( null !== $error ) {
			return new \WP_Error( 'swbi_invalid_param', $error, array( 'status' => 400 ) );
		}
		\SWBackupIt\WordPress\Support\Options::update( self::OPTION, $merged, false );
		return $merged;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function validate( array $settings ): ?string {
		$limits = $settings['limits'];
		if ( $limits['time_budget_seconds'] < 1 || $limits['time_budget_seconds'] > 3600 ) {
			return __( 'The time budget must be between 1 and 3600 seconds.', 'swbackupit' );
		}
		if ( $limits['memory_mb'] < 16 || $limits['batch_rows'] < 1 || $limits['batch_rows'] > 100000 ) {
			return __( 'Resource limits are out of range.', 'swbackupit' );
		}
		if ( ! in_array( $settings['cron']['mode'], array( 'wp-cron', 'system' ), true ) ) {
			return __( 'Unknown cron mode.', 'swbackupit' );
		}
		if ( ! \SWBackupIt\WordPress\Repository\ScheduleCalculator::is_valid_timezone( (string) $settings['cron']['timezone'] ) ) {
			return __( 'Unknown timezone.', 'swbackupit' );
		}
		if ( ! in_array( $settings['logging']['level'], array( 'debug', 'info', 'notice', 'warning', 'error' ), true ) ) {
			return __( 'Unknown log level.', 'swbackupit' );
		}
		$dir = (string) $settings['staging_dir'];
		if ( '' !== $dir && ( '/' !== $dir[0] || str_contains( $dir, '..' ) || str_contains( $dir, "\0" ) ) ) {
			return __( 'The staging directory must be an absolute path without "..".', 'swbackupit' );
		}
		if ( '' !== $dir && empty( $settings['allow_public_paths'] ) && self::is_inside_docroot( $dir ) ) {
			return __( 'The staging directory is inside the web root, where backups could be downloaded by anyone. Choose a directory outside the web root.', 'swbackupit' );
		}
		foreach ( (array) $settings['notifications']['recipients'] as $recipient ) {
			if ( ! is_email( (string) $recipient ) ) {
				return __( 'Invalid notification recipient.', 'swbackupit' );
			}
		}
		return null;
	}

	/**
	 * Recursive merge that only accepts keys known from the defaults (no arbitrary option injection).
	 *
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private static function merge( array $base, array $input ): array {
		foreach ( $base as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = $input[ $key ];
			if ( is_array( $default ) && array() !== $default && ! array_is_list( $default ) ) {
				$base[ $key ] = is_array( $value ) ? self::merge( $default, $value ) : $default;
			} elseif ( is_int( $default ) ) {
				$base[ $key ] = is_numeric( $value ) ? (int) $value : $default;
			} elseif ( is_bool( $default ) ) {
				// "false", "0", "" and "no" are false (JSON/form input); (bool) "false" would be true.
				$base[ $key ] = is_scalar( $value ) ? rest_sanitize_boolean( $value ) : $default;
			} elseif ( is_string( $default ) ) {
				$base[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $default;
			} elseif ( is_array( $default ) ) {
				$base[ $key ] = is_array( $value ) ? array_values( array_map( 'sanitize_text_field', array_filter( $value, 'is_string' ) ) ) : $default;
			} elseif ( null === $default ) {
				$base[ $key ] = is_numeric( $value ) ? (int) $value : null;
			}
		}
		return $base;
	}

	/**
	 * Resolved staging directory; prefers a location outside the web root.
	 */
	public static function staging_dir(): string {
		$configured = (string) self::all()['staging_dir'];
		if ( '' !== $configured ) {
			return rtrim( $configured, '/' );
		}
		$stored = \SWBackupIt\WordPress\Support\Options::get( 'swbi_staging_dir', '' );
		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}
		$suffix  = substr( hash( 'sha256', wp_salt( 'auth' ) . ABSPATH ), 0, 12 );
		$outside = dirname( untrailingslashit( ABSPATH ) ) . '/swbackupit-staging-' . $suffix;
		$parent  = dirname( $outside );
		$dir     = is_dir( $parent ) && wp_is_writable( $parent ) && ! self::is_inside_docroot( $parent )
			? $outside
			: untrailingslashit( WP_CONTENT_DIR ) . '/swbackupit-staging-' . $suffix;
		\SWBackupIt\WordPress\Support\Options::update( 'swbi_staging_dir', $dir, false );
		return $dir;
	}

	/**
	 * Whether a path (existing or not) lies inside the WordPress directory or the web server's document root.
	 *
	 * Both are checked because WordPress may live in a subdirectory of the document root, where a sibling of ABSPATH is
	 * still public. Not yet existing paths are resolved through their nearest existing ancestor.
	 */
	public static function is_inside_docroot( string $path ): bool {
		$real = self::resolve_path( $path );
		if ( null === $real ) {
			return false;
		}
		$roots = array( ABSPATH );
		if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) && '' !== $_SERVER['DOCUMENT_ROOT'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only compared as a path, never output.
			$roots[] = wp_unslash( $_SERVER['DOCUMENT_ROOT'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		foreach ( $roots as $root ) {
			$root = realpath( (string) $root );
			if ( false !== $root && '/' !== $root && ( $real === $root || str_starts_with( $real . '/', $root . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Canonical form of a path whose tail may not exist yet. */
	private static function resolve_path( string $path ): ?string {
		$path   = rtrim( $path, '/' );
		$suffix = '';
		while ( '' !== $path && false === realpath( $path ) ) {
			$suffix = '/' . basename( $path ) . $suffix;
			$parent = dirname( $path );
			if ( $parent === $path ) {
				return null;
			}
			$path = $parent;
		}
		$real = realpath( '' === $path ? '/' : $path );
		return false === $real ? null : rtrim( $real, '/' ) . $suffix;
	}
}
