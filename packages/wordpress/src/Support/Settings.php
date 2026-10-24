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
				'retention_days'     => 30,
				'max_events_per_job' => 5000,
				'level'              => 'info',
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
				'core'       => false,
				'plugins'    => false,
				'themes'     => false,
				'profile_id' => null,
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
		$merged = self::merge( self::all(), $input );
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
				$base[ $key ] = (bool) $value;
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

	public static function is_inside_docroot( string $path ): bool {
		$root = realpath( ABSPATH );
		$real = realpath( $path );
		return false !== $root && false !== $real && ( $real === $root || str_starts_with( $real . '/', $root . '/' ) );
	}
}
