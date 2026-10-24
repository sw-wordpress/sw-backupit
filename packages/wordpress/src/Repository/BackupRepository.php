<?php
/**
 * Backup catalog (swbi_backups / swbi_backup_locations).
 *
 * The catalog is a cache: the manifests next to the volumes are the source of truth and the catalog can
 * be rebuilt from them (disaster recovery).
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from Schema::table() (fixed allowlist + $wpdb->prefix), never from input.

namespace SWBackupIt\WordPress\Repository;

use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Manifest\Manifest;
use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Support\Time;

final class BackupRepository {

	private function table(): string {
		return Schema::table( 'backups' );
	}

	/**
	 * Registers (idempotently) a finished backup from its manifest.
	 */
	public function register( Manifest $manifest, ?int $profile_id, ?string $job_id, string $backup_dir ): void {
		global $wpdb;
		$size = 0;
		foreach ( $manifest->volumes() as $volume ) {
			$size += $volume->size;
		}
		foreach ( $manifest->databaseParts() as $part ) {
			$size += $part['size'];
		}
		$source = $manifest->section( 'source' );
		$files  = $manifest->section( 'files' );
		$parent = $manifest->section( 'parent' );
		$data   = array(
			'id'              => $manifest->backupId(),
			'profile_id'      => $profile_id,
			'job_id'          => $job_id,
			'type'            => $manifest->type()->value,
			'status'          => 'complete',
			'created_at'      => gmdate( 'Y-m-d H:i:s', (int) strtotime( $manifest->createdAt() ) ),
			'size_bytes'      => $size,
			'file_count'      => (int) ( $files['count'] ?? 0 ),
			'volume_count'    => count( $manifest->volumes() ),
			'encrypted'       => $manifest->isEncrypted() ? 1 : 0,
			'parent_id'       => isset( $parent['backup_id'] ) ? (string) $parent['backup_id'] : null,
			'site_url'        => (string) ( $source['site_url'] ?? '' ),
			'wp_version'      => (string) ( $source['wp_version'] ?? '' ),
			'manifest_sha256' => hash_file( 'sha256', rtrim( $backup_dir, '/' ) . '/manifest.json' ) ?: null,
			'meta'            => (string) wp_json_encode(
				array(
					'warnings' => $manifest->toArray()['warnings_count'] ?? 0,
					'requires' => $manifest->requires(),
				)
			),
		);
		$exists = $this->find_row( $manifest->backupId() );
		if ( null === $exists ) {
			$wpdb->insert( $this->table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			unset( $data['id'] );
			$wpdb->update( $this->table(), $data, array( 'id' => $manifest->backupId() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->set_location( $manifest->backupId(), 0, 'verified' );
	}

	public function set_location( string $backup_id, int $target_id, string $status, string $error = '', string $remote_path = '' ): void {
		global $wpdb;
		$table = Schema::table( 'backup_locations' );
		$now   = Time::to_db( Time::now() );
		// Unique (backup_id,target_id): repeated uploads/verifications never create duplicate rows.
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"INSERT INTO {$table} (backup_id,target_id,status,remote_path,verified_at,error,updated_at) VALUES (%s,%d,%s,%s,%s,%s,%s)
				ON DUPLICATE KEY UPDATE status=VALUES(status), remote_path=IF(VALUES(remote_path)='',remote_path,VALUES(remote_path)), verified_at=VALUES(verified_at), error=VALUES(error), updated_at=VALUES(updated_at)",
				$backup_id,
				$target_id,
				$status,
				$remote_path,
				'verified' === $status ? $now : null,
				$error,
				$now
			)
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_row( string $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %s", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array{profile_id?: int, type?: string, status?: string, search?: string} $filters
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function search( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['profile_id'] ) ) {
			$where[] = 'profile_id = %d';
			$args[]  = (int) $filters['profile_id'];
		}
		if ( ! empty( $filters['type'] ) ) {
			$where[] = 'type = %s';
			$args[]  = $filters['type'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = $filters['status'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$where[] = '(id LIKE %s OR site_url LIKE %s)';
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$args[]  = $like;
			$args[]  = $like;
		}
		$clause = implode( ' AND ', $where );
		$total  = (int) $wpdb->get_var( array() === $args ? "SELECT COUNT(*) FROM {$this->table()} WHERE $clause" : $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE $clause", $args ) ); // phpcs:ignore WordPress.DB
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$sql    = $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE $clause ORDER BY created_at DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, $offset ) ) ); // phpcs:ignore WordPress.DB
		$rows   = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'items' => is_array( $rows ) ? array_values( $rows ) : array(),
			'total' => $total,
		);
	}

	public function delete( string $id ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'backup_locations' ), array( 'backup_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function set_protected( string $id, bool $protected ): void {
		global $wpdb;
		$wpdb->update( $this->table(), array( 'protected' => $protected ? 1 : 0 ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Stores the documented result of a test restore (PLAN.md §22: restore result documentation).
	 *
	 * @param string               $id     Backup id.
	 * @param array<string, mixed> $result Result of the RestoreTester.
	 */
	public function record_restore_test( string $id, array $result ): void {
		global $wpdb;
		$row                  = $this->find_row( $id );
		$meta                 = null !== $row && is_string( $row['meta'] ) ? json_decode( $row['meta'], true ) : array();
		$meta                 = is_array( $meta ) ? $meta : array();
		$meta['restore_test'] = $result;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'restore_tested_at' => Time::to_db( Time::now() ),
				'meta'              => (string) wp_json_encode( $meta ),
			),
			array( 'id' => $id )
		);
	}

	/** Records that a background check looked at the backup without changing its status (nothing local to verify). */
	public function touch_checked( string $id ): void {
		global $wpdb;
		$wpdb->update( $this->table(), array( 'verified_at' => Time::to_db( Time::now() ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function mark_verified( string $id, string $status ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'verified_at' => Time::to_db( Time::now() ),
				'status'      => $status,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * @return array{count: int, size: int}
	 */
	public function totals(): array {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT COUNT(*) AS c, COALESCE(SUM(size_bytes),0) AS s FROM {$this->table()}", ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'count' => is_array( $row ) ? (int) $row['c'] : 0,
			'size'  => is_array( $row ) ? (int) $row['s'] : 0,
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function locations( string $backup_id ): array {
		global $wpdb;
		$table = Schema::table( 'backup_locations' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE backup_id = %s ORDER BY target_id", $backup_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * REST representation.
	 *
	 * @param array<string, mixed> $row
	 * @param array<int, string>   $target_names
	 * @param array<int, string>   $profile_names
	 * @return array<string, mixed>
	 */
	public function to_rest( array $row, array $target_names = array(), array $profile_names = array() ): array {
		$locations = array();
		foreach ( $this->locations( (string) $row['id'] ) as $location ) {
			$target_id   = (int) $location['target_id'];
			$locations[] = array(
				'target_id'   => $target_id,
				'name'        => 0 === $target_id ? __( 'Local staging', 'swbackupit' ) : ( $target_names[ $target_id ] ?? '#' . $target_id ),
				'status'      => (string) $location['status'],
				'verified_at' => Time::to_iso( $location['verified_at'] ),
			);
		}
		$profile_id = null === $row['profile_id'] ? null : (int) $row['profile_id'];
		$meta       = is_string( $row['meta'] ) ? json_decode( $row['meta'], true ) : array();
		return array(
			'id'                => (string) $row['id'],
			'profile_id'        => $profile_id,
			'profile_name'      => null === $profile_id ? '' : ( $profile_names[ $profile_id ] ?? '' ),
			'type'              => (string) $row['type'],
			'status'            => (string) $row['status'],
			'created_at'        => Time::to_iso( $row['created_at'] ),
			'size_bytes'        => (int) $row['size_bytes'],
			'file_count'        => (int) $row['file_count'],
			'volume_count'      => (int) $row['volume_count'],
			'encrypted'         => (bool) $row['encrypted'],
			'protected'         => (bool) $row['protected'],
			'parent_id'         => $row['parent_id'],
			'wp_version'        => (string) $row['wp_version'],
			'site_url'          => (string) $row['site_url'],
			'verified_at'       => Time::to_iso( $row['verified_at'] ),
			'restore_tested_at' => Time::to_iso( $row['restore_tested_at'] ),
			'restore_test'      => is_array( $meta ) && is_array( $meta['restore_test'] ?? null ) ? $meta['restore_test'] : null,
			'locations'         => $locations,
		);
	}

	/**
	 * Rebuilds the catalog from manifests in a staging directory (disaster recovery).
	 *
	 * @return int Number of backups registered.
	 */
	public function rebuild_from_directory( string $staging ): int {
		$count = 0;
		foreach ( glob( rtrim( $staging, '/' ) . '/*/manifest.json' ) ?: array() as $file ) {
			try {
				$dir      = dirname( $file );
				$manifest = Manifest::read( $dir );
				$this->register( $manifest, null, null, $dir );
				++$count;
			} catch ( SwbiException ) {
				continue;
			}
		}
		return $count;
	}
}
