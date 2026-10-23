<?php
/**
 * Fine-grained capabilities (REST contract: docs/rest-api.md).
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Support;

final class Capabilities {

	public const VIEW            = 'swbi_view';
	public const RUN             = 'swbi_run';
	public const MANAGE_PROFILES = 'swbi_manage_profiles';
	public const MANAGE_STORAGE  = 'swbi_manage_storage';
	public const DOWNLOAD        = 'swbi_download';
	public const DELETE          = 'swbi_delete';
	public const RESTORE         = 'swbi_restore';
	public const SETTINGS        = 'swbi_settings';

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::VIEW,
			self::RUN,
			self::MANAGE_PROFILES,
			self::MANAGE_STORAGE,
			self::DOWNLOAD,
			self::DELETE,
			self::RESTORE,
			self::SETTINGS,
		);
	}

	/** Grants every capability to administrators. */
	public static function grant_defaults(): void {
		$role = get_role( 'administrator' );
		if ( null === $role ) {
			return;
		}
		foreach ( self::all() as $capability ) {
			$role->add_cap( $capability );
		}
	}

	public static function revoke_all(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::all() as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}

	/**
	 * Capabilities of the current user (for the SPA; the server stays authoritative).
	 *
	 * @return list<string>
	 */
	public static function current_user_caps(): array {
		return array_values( array_filter( self::all(), static fn ( string $cap ): bool => current_user_can( $cap ) ) );
	}
}
