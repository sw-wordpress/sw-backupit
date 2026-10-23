<?php
/**
 * Storage targets with encrypted credentials; also resolves targets for the core storage manager.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Repository;

use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Storage\StorageFactory;
use SWBackupIt\Core\Storage\StorageResolverInterface;
use SWBackupIt\Core\Storage\StorageTarget;
use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Support\Secrets;
use SWBackupIt\WordPress\Support\Time;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from Schema::table() (fixed allowlist + $wpdb->prefix), never from input.

final class StorageTargetRepository implements StorageResolverInterface {

	private const CREDENTIAL_KEYS = array(
		'sftp'  => array( 'password', 'private_key', 'passphrase' ),
		's3'    => array( 'access_key', 'secret_key' ),
		'local' => array(),
	);

	private const CONFIG_KEYS = array(
		'local' => array( 'path' ),
		'sftp'  => array( 'host', 'port', 'username', 'root', 'host_fingerprint', 'trust_unknown_host', 'allow_private_network', 'timeout' ),
		's3'    => array( 'endpoint', 'region', 'bucket', 'prefix', 'path_style', 'allow_private_network' ),
	);

	private function table(): string {
		return Schema::table( 'storage_targets' );
	}

	/**
	 * @return list<array<string, mixed>> Raw rows.
	 */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_row( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : null;
	}

	public function find( int $id ): ?StorageTarget {
		$row = $this->find_row( $id );
		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * @param int|null             $id    Target id, null to create.
	 * @param array<string, mixed> $input name, type, config, credentials, immutable.
	 * @throws ConfigurationException On invalid input.
	 */
	public function save( ?int $id, array $input ): int {
		global $wpdb;
		$current = null === $id ? null : $this->find_row( $id );
		if ( null !== $id && null === $current ) {
			throw new ConfigurationException( 'Storage target not found.' );
		}
		$type   = (string) ( $input['type'] ?? ( $current['type'] ?? '' ) );
		$name   = trim( (string) ( $input['name'] ?? ( $current['name'] ?? '' ) ) );
		$config = is_array( $input['config'] ?? null ) ? $input['config'] : ( null === $current ? array() : (array) json_decode( (string) $current['config'], true ) );
		if ( '' === $name || ! isset( self::CONFIG_KEYS[ $type ] ) ) {
			throw new ConfigurationException( 'A name and a valid storage type are required.' );
		}
		$config = array_intersect_key( $config, array_flip( self::CONFIG_KEYS[ $type ] ) );

		$credentials = null === $current ? array() : $this->decrypt_credentials( $current );
		if ( is_array( $input['credentials'] ?? null ) ) {
			foreach ( array_intersect_key( $input['credentials'], array_flip( self::CREDENTIAL_KEYS[ $type ] ) ) as $key => $value ) {
				if ( is_string( $value ) && '' !== $value ) {
					$credentials[ $key ] = $value;
				}
			}
		}
		// Validates endpoint/path/SSRF rules without connecting.
		( new StorageFactory() )->create( new StorageTarget( (int) $id, $name, $type, $config, $credentials ) );

		$now  = Time::to_db( Time::now() );
		$data = array(
			'name'        => $name,
			'type'        => $type,
			'config'      => (string) wp_json_encode( $config ),
			'credentials' => array() === $credentials ? null : Secrets::encrypt( (string) wp_json_encode( $credentials ) ),
			'immutable'   => ! empty( $input['immutable'] ) ? 1 : (int) ( $current['immutable'] ?? 0 ),
			'updated_at'  => $now,
		);
		if ( array_key_exists( 'immutable', $input ) ) {
			$data['immutable'] = rest_sanitize_boolean( $input['immutable'] ) ? 1 : 0;
		}
		if ( null === $id ) {
			$data['created_at'] = $now;
			$data['status']     = 'unknown';
			$wpdb->insert( $this->table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->insert_id;
		}
		$wpdb->update( $this->table(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $id;
	}

	public function delete( int $id ): bool {
		global $wpdb;
		return 1 === $wpdb->delete( $this->table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function record_test( int $id, bool $ok, string $message, ?int $usage = null ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'status'          => $ok ? 'ok' : 'error',
				'message'         => $message,
				'usage_bytes'     => $usage,
				'last_checked_at' => Time::to_db( Time::now() ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * REST representation: secrets are write-only and never returned.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public function to_rest( array $row ): array {
		$config = json_decode( (string) $row['config'], true );
		return array(
			'id'              => (int) $row['id'],
			'name'            => (string) $row['name'],
			'type'            => (string) $row['type'],
			'config'          => is_array( $config ) ? $config : array(),
			'credentials_set' => null !== $row['credentials'] && '' !== $row['credentials'],
			'immutable'       => (bool) $row['immutable'],
			'retention'       => null,
			'status'          => (string) $row['status'],
			'usage_bytes'     => null === $row['usage_bytes'] ? null : (int) $row['usage_bytes'],
			'last_checked_at' => Time::to_iso( $row['last_checked_at'] ),
			'message'         => (string) ( $row['message'] ?? '' ),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate( array $row ): StorageTarget {
		$config = json_decode( (string) $row['config'], true );
		return new StorageTarget(
			(int) $row['id'],
			(string) $row['name'],
			(string) $row['type'],
			is_array( $config ) ? $config : array(),
			$this->decrypt_credentials( $row ),
			(bool) $row['immutable']
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, string>
	 */
	private function decrypt_credentials( array $row ): array {
		if ( empty( $row['credentials'] ) ) {
			return array();
		}
		$plain   = Secrets::decrypt( (string) $row['credentials'] );
		$decoded = null === $plain ? null : json_decode( $plain, true );
		return is_array( $decoded ) ? array_map( 'strval', $decoded ) : array();
	}
}
