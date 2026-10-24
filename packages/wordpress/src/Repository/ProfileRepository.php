<?php
/**
 * Backup profile persistence and REST (de)serialisation.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Repository;

use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Support\Time;

final class ProfileRepository {

	private function table(): string {
		return Schema::table( 'profiles' );
	}

	/**
	 * @return list<BackupProfile>
	 */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_values( array_filter( array_map( fn ( array $row ): ?BackupProfile => $this->hydrate( $row ), is_array( $rows ) ? $rows : array() ) ) );
	}

	public function find( int $id ): ?BackupProfile {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Row metadata (timestamps) for REST output.
	 *
	 * @return array{created_at: ?string, updated_at: ?string}
	 */
	public function timestamps( int $id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT created_at, updated_at FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'created_at' => is_array( $row ) ? Time::to_iso( $row['created_at'] ) : null,
			'updated_at' => is_array( $row ) ? Time::to_iso( $row['updated_at'] ) : null,
		);
	}

	/**
	 * Creates a profile from REST input.
	 *
	 * @param array<string, mixed> $input
	 * @throws ConfigurationException On invalid input.
	 */
	public function create( array $input ): BackupProfile {
		global $wpdb;
		$profile = BackupProfile::fromArray( array( 'id' => null ) + $input );
		$now     = Time::to_db( Time::now() );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'name'        => $profile->name,
				'description' => $profile->description,
				'type'        => $profile->type->value,
				'config'      => $this->encode( $profile ),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		return BackupProfile::fromArray( array( 'id' => (int) $wpdb->insert_id ) + $profile->toArray() );
	}

	/**
	 * @param int                  $id    Profile id.
	 * @param array<string, mixed> $input Fields to change.
	 * @throws ConfigurationException On invalid input.
	 */
	public function update( int $id, array $input ): ?BackupProfile {
		global $wpdb;
		$current = $this->find( $id );
		if ( null === $current ) {
			return null;
		}
		$profile = BackupProfile::fromArray( array( 'id' => $id ) + $input + $current->toArray() );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'name'        => $profile->name,
				'description' => $profile->description,
				'type'        => $profile->type->value,
				'config'      => $this->encode( $profile ),
				'updated_at'  => Time::to_db( Time::now() ),
			),
			array( 'id' => $id )
		);
		return $profile;
	}

	public function delete( int $id ): bool {
		global $wpdb;
		return 1 === $wpdb->delete( $this->table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function duplicate( int $id ): ?BackupProfile {
		$source = $this->find( $id );
		if ( null === $source ) {
			return null;
		}
		$data         = $source->toArray();
		$data['name'] = sprintf(
			/* translators: %s: profile name */
			__( '%s (copy)', 'sw-backupit' ),
			$source->name
		);
		return $this->create( $data );
	}

	/**
	 * REST representation (volume size in MB as documented in docs/rest-api.md).
	 *
	 * @return array<string, mixed>
	 */
	public function to_rest( BackupProfile $profile ): array {
		$data = $profile->toArray();
		unset( $data['volume_size_bytes'] );
		$data['volume_size_mb'] = round( $profile->volumeSizeBytes / 1048576, 2 );
		if ( null !== $profile->id ) {
			$data += $this->timestamps( $profile->id );
		}
		return $data;
	}

	private function encode( BackupProfile $profile ): string {
		$data = $profile->toArray();
		unset( $data['id'] );
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate( array $row ): ?BackupProfile {
		$config = json_decode( (string) $row['config'], true );
		if ( ! is_array( $config ) ) {
			return null;
		}
		try {
			return BackupProfile::fromArray( array( 'id' => (int) $row['id'] ) + $config );
		} catch ( ConfigurationException ) {
			return null;
		}
	}
}
