<?php
/**
 * Audit log of critical operations (PLAN.md §22): who created, downloaded, deleted or restored backups and which configuration
 * changed. The table is append-only for the plugin (no update/delete code paths except time based purging).
 *
 * Entries never contain secrets: only identifiers and the names of changed settings.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Support;

use SWBackupIt\WordPress\Database\Schema;

final class AuditLogger {

	/** Actions to listen to => [object type, argument holding the object id (0-based) or null]. */
	private const MAP = array(
		'swbi_backup_started'           => array( 'job', 0 ),
		'swbi_backup_downloaded'        => array( 'backup', 0 ),
		'swbi_backup_deleted'           => array( 'backup', 0 ),
		'swbi_backup_protected'         => array( 'backup', 0 ),
		'swbi_backup_verified'          => array( 'backup', 0 ),
		'swbi_restore_prepared'         => array( 'backup', 0 ),
		'swbi_restore_tested'           => array( 'backup', 0 ),
		'swbi_profile_saved'            => array( 'profile', 0 ),
		'swbi_profile_deleted'          => array( 'profile', 0 ),
		'swbi_schedule_saved'           => array( 'schedule', 0 ),
		'swbi_schedule_deleted'         => array( 'schedule', 0 ),
		'swbi_storage_target_saved'     => array( 'storage_target', 0 ),
		'swbi_storage_target_deleted'   => array( 'storage_target', 0 ),
		'swbi_encryption_key_created'   => array( 'encryption_key', 0 ),
		'swbi_encryption_key_deleted'   => array( 'encryption_key', 0 ),
		'swbi_settings_updated'         => array( 'settings', null ),
		'swbi_backups_imported'         => array( 'storage_target', 0 ),
	);

	public function register(): void {
		foreach ( array_keys( self::MAP ) as $hook ) {
			add_action(
				$hook,
				function ( ...$args ) use ( $hook ): void {
					$this->on_action( $hook, $args );
				},
				10,
				5
			);
		}
	}

	/**
	 * @param list<mixed> $args
	 */
	private function on_action( string $hook, array $args ): void {
		[ $type, $index ] = self::MAP[ $hook ];
		$object_id        = null === $index ? '' : (string) ( $args[ $index ] ?? '' );
		// The acting user is the last numeric argument by convention (actions pass get_current_user_id()), else the current user.
		$context = array();
		foreach ( array_slice( $args, null === $index ? 0 : 1 ) as $extra ) {
			if ( is_array( $extra ) ) {
				$context['changed'] = array_values( array_map( 'strval', $extra ) );
			} elseif ( is_scalar( $extra ) && ! is_int( $extra ) ) {
				$context['detail'] = (string) $extra;
			} elseif ( is_int( $extra ) && $extra > 100 ) {
				$context['count'] = $extra;
			}
		}
		self::record( preg_replace( '/^swbi_/', '', $hook ) ?? $hook, $type, $object_id, $context );
	}

	/**
	 * @param array<string, mixed> $context Must not contain secrets.
	 */
	public static function record( string $action, string $object_type = '', string $object_id = '', array $context = array() ): void {
		global $wpdb;
		$user = wp_get_current_user();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'audit' ),
			array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => (int) $user->ID,
				'user_login'  => $user->ID > 0 ? (string) $user->user_login : ( defined( 'WP_CLI' ) && WP_CLI ? 'wp-cli' : ( wp_doing_cron() ? 'wp-cron' : '' ) ),
				'ip'          => self::ip(),
				'action'      => substr( $action, 0, 60 ),
				'object_type' => substr( $object_type, 0, 30 ),
				'object_id'   => substr( $object_id, 0, 64 ),
				'context'     => array() === $context ? null : wp_json_encode( \SWBackupIt\Core\Log\Redactor::redact( $context ), JSON_UNESCAPED_SLASHES ),
			),
			// Explicit formats: wpdb::$field_types maps a column named "object_id" to %d (WordPress core tables), which would
			// silently cast our string ids to numbers.
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private static function ip(): string {
		// REMOTE_ADDR only: forwarded headers can be spoofed by the client.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * @param array{action?: string, user_id?: int, object_type?: string, since?: string} $filters
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public static function search( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$table = Schema::table( 'audit' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['action'] ) ) {
			$where[] = 'action = %s';
			$args[]  = $filters['action'];
		}
		if ( ! empty( $filters['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$args[]  = (int) $filters['user_id'];
		}
		if ( ! empty( $filters['object_type'] ) ) {
			$where[] = 'object_type = %s';
			$args[]  = $filters['object_type'];
		}
		$clause = implode( ' AND ', $where );
		$total  = (int) $wpdb->get_var( array() === $args ? "SELECT COUNT(*) FROM {$table} WHERE {$clause}" : $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$clause}", $args ) ); // phpcs:ignore WordPress.DB
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, max( 0, ( $page - 1 ) * $per_page ) ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$items  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$context = is_string( $row['context'] ) ? json_decode( $row['context'], true ) : array();
			$items[] = array(
				'id'          => (int) $row['id'],
				'time'        => Time::to_iso( $row['created_at'] ),
				'user_id'     => (int) $row['user_id'],
				'user'        => (string) $row['user_login'],
				'ip'          => (string) $row['ip'],
				'action'      => (string) $row['action'],
				'object_type' => (string) $row['object_type'],
				'object_id'   => (string) $row['object_id'],
				'context'     => is_array( $context ) ? $context : array(),
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	public static function purge_older_than( int $days ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'audit' ) . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - max( 30, $days ) * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	}
}
