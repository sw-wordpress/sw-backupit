<?php
/**
 * Storage targets: CRUD, connection test, remote backup discovery and import.
 *
 * Credentials are write-only: accepted in `credentials`, encrypted at rest, never returned (only `credentials_set`).
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Rest;

use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Storage\BackupDownloader;
use SWBackupIt\Core\Storage\RemoteCatalog;
use SWBackupIt\Core\Storage\StorageFactory;
use SWBackupIt\WordPress\Support\Capabilities;
use SWBackupIt\WordPress\Support\Settings;

final class StorageTargetsController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/storage-targets',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_targets' ),
					'permission_callback' => $this->can( Capabilities::VIEW ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_STORAGE ),
				),
			)
		);
		$id = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/storage-targets/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_target' ),
					'permission_callback' => $this->can( Capabilities::VIEW ),
					'args'                => $id,
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_STORAGE ),
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_STORAGE ),
					'args'                => $id,
				),
			)
		);
		foreach ( array(
			'test'     => array( \WP_REST_Server::CREATABLE, 'test', Capabilities::MANAGE_STORAGE ),
			'discover' => array( \WP_REST_Server::READABLE, 'discover', Capabilities::MANAGE_STORAGE ),
			'import'   => array( \WP_REST_Server::CREATABLE, 'import', Capabilities::MANAGE_STORAGE ),
		) as $route => [ $method, $callback, $capability ] ) {
			register_rest_route(
				self::NAMESPACE,
				'/storage-targets/(?P<id>\d+)/' . $route,
				array(
					'methods'             => $method,
					'callback'            => array( $this, $callback ),
					'permission_callback' => $this->can( $capability ),
					'args'                => $id,
				)
			);
		}
	}

	public function list_targets() {
		$repo  = $this->services()->storage_targets();
		$items = array_map( array( $repo, 'to_rest' ), $repo->all() );
		return $this->collection( $items, count( $items ), 1, max( 1, count( $items ) ) );
	}

	public function get_target( \WP_REST_Request $request ) {
		$row = $this->services()->storage_targets()->find_row( (int) $request['id'] );
		return null === $row
			? $this->error( 'swbi_not_found', __( 'Storage target not found.', 'swbackupit' ), 404 )
			: new \WP_REST_Response( $this->services()->storage_targets()->to_rest( $row ) );
	}

	public function create( \WP_REST_Request $request ) {
		return $this->save( null, $request, 201 );
	}

	public function update( \WP_REST_Request $request ) {
		return $this->save( (int) $request['id'], $request, 200 );
	}

	private function save( ?int $id, \WP_REST_Request $request, int $status ) {
		try {
			$saved = $this->services()->storage_targets()->save( $id, $this->body( $request ) );
		} catch ( ConfigurationException $exception ) {
			return $this->error( 'swbi_invalid_param', $exception->getMessage(), 400 );
		}
		do_action( 'swbi_storage_target_saved', $saved, get_current_user_id() );
		return new \WP_REST_Response( $this->services()->storage_targets()->to_rest( (array) $this->services()->storage_targets()->find_row( $saved ) ), $status );
	}

	public function delete( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		foreach ( $this->services()->profiles()->all() as $profile ) {
			if ( in_array( $id, $profile->storageTargetIds, true ) ) {
				return $this->error( 'swbi_conflict', __( 'The storage target is used by a profile.', 'swbackupit' ), 409 );
			}
		}
		return $this->services()->storage_targets()->delete( $id )
			? new \WP_REST_Response( array( 'deleted' => true ) )
			: $this->error( 'swbi_not_found', __( 'Storage target not found.', 'swbackupit' ), 404 );
	}

	public function test( \WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$target = $this->services()->storage_targets()->find( $id );
		if ( null === $target ) {
			return $this->error( 'swbi_not_found', __( 'Storage target not found.', 'swbackupit' ), 404 );
		}
		try {
			$storage = ( new StorageFactory() )->create( $target );
			$result  = $storage->test();
			$usage   = $result->ok ? $storage->usage() : null;
		} catch ( SwbiException $exception ) {
			$this->services()->storage_targets()->record_test( $id, false, $exception->getMessage() );
			return new \WP_REST_Response(
				array(
					'ok'         => false,
					'message'    => $exception->getMessage(),
					'latency_ms' => 0,
				)
			);
		}
		$this->services()->storage_targets()->record_test( $id, $result->ok, $result->message, $usage );
		return new \WP_REST_Response(
			array(
				'ok'         => $result->ok,
				'message'    => $result->message,
				'latency_ms' => $result->latencyMs,
			)
		);
	}

	public function discover( \WP_REST_Request $request ) {
		$target = $this->services()->storage_targets()->find( (int) $request['id'] );
		if ( null === $target ) {
			return $this->error( 'swbi_not_found', __( 'Storage target not found.', 'swbackupit' ), 404 );
		}
		try {
			$found = ( new RemoteCatalog() )->discover( ( new StorageFactory() )->create( $target ), $this->temp_dir() );
		} catch ( SwbiException $exception ) {
			return $this->error( 'swbi_storage_error', $exception->getMessage(), 502 );
		}
		$items = array();
		foreach ( $found as $entry ) {
			$manifest = $entry['manifest'];
			$size     = 0;
			if ( null !== $manifest ) {
				foreach ( $manifest->volumes() as $volume ) {
					$size += $volume->size;
				}
				foreach ( $manifest->databaseParts() as $part ) {
					$size += $part['size'];
				}
			}
			$items[] = array(
				'backup_id'        => $entry['backup_id'],
				'created_at'       => $manifest?->createdAt(),
				'size_bytes'       => $size,
				'type'             => $manifest?->type()->value,
				'profile_name'     => $manifest ? (string) ( $manifest->section( 'profile' )['name'] ?? '' ) : null,
				'already_imported' => null !== $this->services()->backups()->find_row( $entry['backup_id'] ),
				'error'            => $entry['error'],
			);
		}
		return $this->collection( $items, count( $items ), 1, max( 1, count( $items ) ) );
	}

	/**
	 * Registers remote backups in the catalog (disaster recovery: the catalog is rebuilt from storage manifests).
	 * With `download: true` the data is also fetched into the local staging area.
	 */
	public function import( \WP_REST_Request $request ) {
		$target_id = (int) $request['id'];
		$target    = $this->services()->storage_targets()->find( $target_id );
		if ( null === $target ) {
			return $this->error( 'swbi_not_found', __( 'Storage target not found.', 'swbackupit' ), 404 );
		}
		$body     = $this->body( $request );
		$ids      = is_array( $body['backup_ids'] ?? null ) ? array_filter( $body['backup_ids'], static fn ( $id ): bool => is_string( $id ) && \SWBackupIt\Core\Support\Uuid::isValid( $id ) ) : array();
		$download = ! empty( $body['download'] );
		$imported = 0;
		try {
			$storage = ( new StorageFactory() )->create( $target );
			foreach ( $ids as $backup_id ) {
				$read = ( new RemoteCatalog() )->read( $storage, $backup_id, $this->temp_dir() );
				if ( null === $read['manifest'] ) {
					continue;
				}
				$dir = Settings::staging_dir() . '/' . $backup_id;
				if ( $download ) {
					( new BackupDownloader() )->download( $storage, $backup_id, Settings::staging_dir() );
				} elseif ( ! is_dir( $dir ) ) {
					wp_mkdir_p( $dir );
					file_put_contents( $dir . '/manifest.json', (string) wp_json_encode( $read['manifest']->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
				$this->services()->backups()->register( $read['manifest'], null, null, $dir );
				$this->services()->backups()->set_location( $backup_id, $target_id, 'verified', '', $backup_id );
				if ( ! $download ) {
					$this->services()->backups()->set_location( $backup_id, 0, 'pending', 'Data is not stored locally.' );
				}
				++$imported;
			}
		} catch ( SwbiException $exception ) {
			return $this->error( 'swbi_storage_error', $exception->getMessage(), 502 );
		}
		do_action( 'swbi_backups_imported', $target_id, $imported, get_current_user_id() );
		return new \WP_REST_Response( array( 'imported' => $imported ) );
	}

	private function temp_dir(): string {
		$dir = untrailingslashit( Settings::staging_dir() ) . '/tmp';
		wp_mkdir_p( $dir );
		return $dir;
	}
}
