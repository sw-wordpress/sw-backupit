<?php
/**
 * Fine-grained capabilities (REST contract: docs/rest-api.md).
 *
 * @package sw-backupit
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

	/**
	 * Registers the multisite capability policy.
	 */
	public static function register(): void {
		add_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ), 10, 4 );
	}

	/**
	 * Capabilities that expose or change network wide data (whole-network backups incl. credentials, restore, settings,
	 * storage credentials, deletion). On multisite they require a super admin.
	 *
	 * @return list<string>
	 */
	public static function network_sensitive(): array {
		return array( self::DOWNLOAD, self::RESTORE, self::SETTINGS, self::MANAGE_STORAGE, self::DELETE );
	}

	/**
	 * On multisite all sw-backupit data (backups, settings, keys, storage) is network wide, so the sensitive capabilities
	 * are reserved to super admins. Roles of individual sites (including a subsite administrator that got the caps by
	 * accident) are never sufficient for them; view/run/profile capabilities can still be delegated per role.
	 *
	 * @param list<string> $caps    Primitive capabilities required.
	 * @param string       $cap     Capability being checked.
	 * @param int          $user_id User id.
	 * @param array<mixed> $args    Extra arguments (unused).
	 * @return list<string>
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature dictated by the filter.
		$caps = (array) $caps;
		if ( is_multisite() && in_array( $cap, self::network_sensitive(), true ) && ! is_super_admin( (int) $user_id ) ) {
			$caps[] = 'do_not_allow';
		}
		return array_values( $caps );
	}

	/**
	 * Grants every capability to administrators. On multisite only the main site's administrator role gets them (the
	 * plugin is managed network wide; subsite administrators must never receive download/restore/settings rights).
	 */
	public static function grant_defaults(): void {
		if ( is_multisite() ) {
			switch_to_blog( get_main_site_id() );
			try {
				self::grant_on_current_site();
			} finally {
				restore_current_blog();
			}
			return;
		}
		self::grant_on_current_site();
	}

	private static function grant_on_current_site(): void {
		$role = get_role( 'administrator' );
		if ( null === $role ) {
			return;
		}
		foreach ( self::all() as $capability ) {
			$role->add_cap( $capability );
		}
	}

	/** Removes the capabilities from every role (on multisite: of every site of the network). */
	public static function revoke_all(): void {
		if ( ! is_multisite() ) {
			self::revoke_on_current_site();
			return;
		}
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				self::revoke_on_current_site();
			} finally {
				restore_current_blog();
			}
		}
	}

	private static function revoke_on_current_site(): void {
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
