<?php
/**
 * Backups: catalog, details, verify, protect, browse, download, delete.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Rest;

use SWBackupIt\Core\Backup\BackupLayout;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\WordPress\Support\Capabilities;
use SWBackupIt\WordPress\Support\Settings;

final class BackupsController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/backups',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_backups' ),
				'permission_callback' => $this->can( Capabilities::VIEW ),
				'args'                => $this->pagination_args() + array(
					'profile_id' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'type'       => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array( '', 'full', 'files', 'database', 'incremental', 'differential' ),
					),
					'status'     => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array( '', 'complete', 'incomplete', 'verified', 'corrupt' ),
					),
					'search'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		$id = array( 'id' => $this->uuid_arg() );
		register_rest_route(
			self::NAMESPACE,
			'/backups/(?P<id>[0-9a-f-]{36})',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_backup' ),
					'permission_callback' => $this->can( Capabilities::VIEW ),
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => $this->can( Capabilities::DELETE ),
					'args'                => $id + array(
						'confirm' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/backups/(?P<id>[0-9a-f-]{36})/verify',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'verify' ),
				'permission_callback' => $this->can( Capabilities::RUN ),
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/backups/(?P<id>[0-9a-f-]{36})/protect',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'protect' ),
				'permission_callback' => $this->can( Capabilities::DELETE ),
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/backups/(?P<id>[0-9a-f-]{36})/files',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'files' ),
				'permission_callback' => $this->can( Capabilities::VIEW ),
				'args'                => $id + $this->pagination_args() + array(
					'path'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'search' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/backups/(?P<id>[0-9a-f-]{36})/download',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download' ),
				'permission_callback' => $this->can( Capabilities::DOWNLOAD ),
				'args'                => $id + array(
					'volume' => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'part'   => array(
						'type'    => 'string',
						'default' => 'volume',
						'enum'    => array( 'volume', 'manifest', 'index', 'database' ),
					),
				),
			)
		);
		foreach ( array( 'compare', 'restore/prepare', 'restore' ) as $route ) {
			register_rest_route(
				self::NAMESPACE,
				'/backups/(?P<id>[0-9a-f-]{36})/' . $route,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn () => $this->error( 'swbi_not_implemented', __( 'This feature is planned for a later release. Use the standalone restore application.', 'swbackupit' ), 501 ),
					'permission_callback' => $this->can( 'compare' === $route ? Capabilities::VIEW : Capabilities::RESTORE ),
					'args'                => $id,
				)
			);
		}
	}

	public function list_backups( \WP_REST_Request $request ) {
		$result   = $this->services()->backups()->search(
			array(
				'profile_id' => (int) $request['profile_id'],
				'type'       => (string) $request['type'],
				'status'     => (string) $request['status'],
				'search'     => (string) $request['search'],
			),
			(int) $request['page'],
			(int) $request['per_page']
		);
		$profiles = array();
		foreach ( $this->services()->profiles()->all() as $profile ) {
			$profiles[ (int) $profile->id ] = $profile->name;
		}
		$items = array_map( fn ( array $row ): array => $this->services()->backups()->to_rest( $row, array(), $profiles ), $result['items'] );
		return $this->collection( $items, $result['total'], (int) $request['page'], (int) $request['per_page'] );
	}

	public function get_backup( \WP_REST_Request $request ) {
		$row = $this->services()->backups()->find_row( (string) $request['id'] );
		if ( null === $row ) {
			return $this->error( 'swbi_not_found', __( 'Backup not found.', 'swbackupit' ), 404 );
		}
		$profiles = array();
		foreach ( $this->services()->profiles()->all() as $profile ) {
			$profiles[ (int) $profile->id ] = $profile->name;
		}
		return new \WP_REST_Response( $this->services()->backups()->to_rest( $row, array(), $profiles ) );
	}

	public function delete( \WP_REST_Request $request ) {
		$id  = (string) $request['id'];
		$row = $this->services()->backups()->find_row( $id );
		if ( null === $row ) {
			return $this->error( 'swbi_not_found', __( 'Backup not found.', 'swbackupit' ), 404 );
		}
		if ( $id !== (string) $request['confirm'] ) {
			return $this->error( 'swbi_confirmation_required', __( 'Deleting a backup requires confirming its id.', 'swbackupit' ), 400 );
		}
		if ( (bool) $row['protected'] ) {
			return $this->error( 'swbi_conflict', __( 'The backup is protected. Remove the protection first.', 'swbackupit' ), 409 );
		}
		( new BackupLayout( Settings::staging_dir(), $id ) )->removeAll();
		$this->services()->backups()->delete( $id );
		do_action( 'swbi_backup_deleted', $id, get_current_user_id() );
		return new \WP_REST_Response( array( 'deleted' => true ) );
	}

	public function verify( \WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( null === $this->services()->backups()->find_row( $id ) ) {
			return $this->error( 'swbi_not_found', __( 'Backup not found.', 'swbackupit' ), 404 );
		}
		try {
			$set    = BackupSet::open( Settings::staging_dir() . '/' . $id );
			$report = ( new Verifier() )->verify( $set );
		} catch ( SwbiException $exception ) {
			return $this->error( 'swbi_verify_failed', $exception->getMessage(), 422 );
		}
		$this->services()->backups()->mark_verified( $id, $report->isOk() ? 'verified' : 'corrupt' );
		return new \WP_REST_Response(
			array(
				'ok'              => $report->isOk(),
				'checked_volumes' => $report->volumesChecked,
				'errors'          => $report->errors(),
			)
		);
	}

	public function protect( \WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( null === $this->services()->backups()->find_row( $id ) ) {
			return $this->error( 'swbi_not_found', __( 'Backup not found.', 'swbackupit' ), 404 );
		}
		$this->services()->backups()->set_protected( $id, rest_sanitize_boolean( $this->body( $request )['protected'] ?? true ) );
		return $this->get_backup( $request );
	}

	public function files( \WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( null === $this->services()->backups()->find_row( $id ) ) {
			return $this->error( 'swbi_not_found', __( 'Backup not found.', 'swbackupit' ), 404 );
		}
		try {
			$set = BackupSet::open( Settings::staging_dir() . '/' . $id );
		} catch ( SwbiException $exception ) {
			return $this->error( 'swbi_backup_unavailable', $exception->getMessage(), 404 );
		}
		$prefix   = trim( (string) $request['path'], '/' );
		$search   = strtolower( (string) $request['search'] );
		$page     = (int) $request['page'];
		$per_page = (int) $request['per_page'];
		$skip     = ( $page - 1 ) * $per_page;
		$total    = 0;
		$items    = array();
		if ( $set->manifest()->hasFiles() ) {
			// Streams the index; memory stays constant regardless of the number of files.
			foreach ( $set->indexReader()->read() as [ $entry ] ) {
				$path = (string) $entry['p'];
				if ( '' !== $search ) {
					if ( ! str_contains( strtolower( $path ), $search ) ) {
						continue;
					}
				} elseif ( dirname( $path ) !== ( '' === $prefix ? '.' : $prefix ) ) {
					continue;
				}
				++$total;
				if ( $total > $skip && count( $items ) < $per_page ) {
					$items[] = array(
						'path'   => $path,
						'size'   => (int) $entry['s'],
						'mtime'  => (int) $entry['m'],
						'type'   => 'd' === $entry['t'] ? 'dir' : 'file',
						'volume' => isset( $entry['v'] ) ? (int) $entry['v'] : null,
					);
				}
			}
		}
		return $this->collection( $items, $total, $page, $per_page );
	}

	/**
	 * Streams one artifact of a backup after authorization. No public URL ever exists.
	 *
	 * @return \WP_Error|void
	 */
	public function download( \WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( null === $this->services()->backups()->find_row( $id ) ) {
			return $this->error( 'swbi_not_found', __( 'Backup not found.', 'swbackupit' ), 404 );
		}
		try {
			$set      = BackupSet::open( Settings::staging_dir() . '/' . $id );
			$manifest = $set->manifest();
			$part     = (string) $request['part'];
			if ( 'manifest' === $part ) {
				$path = $set->directory() . '/manifest.json';
			} elseif ( 'index' === $part ) {
				$path = $set->indexPath();
			} elseif ( 'database' === $part ) {
				$parts = $manifest->databaseParts();
				$path  = isset( $parts[ (int) $request['volume'] - 1 ] ) ? $set->databasePartPath( $parts[ (int) $request['volume'] - 1 ]['name'] ) : '';
			} else {
				$path = $set->volumePath( $set->volumeFileName( (int) $request['volume'] ) );
				if ( (int) $request['volume'] > count( $manifest->volumes() ) ) {
					$path = '';
				}
			}
		} catch ( SwbiException $exception ) {
			return $this->error( 'swbi_backup_unavailable', $exception->getMessage(), 404 );
		}
		if ( '' === $path || ! is_file( $path ) ) {
			return $this->error( 'swbi_not_found', __( 'The requested file does not exist.', 'swbackupit' ), 404 );
		}
		do_action( 'swbi_backup_downloaded', $id, basename( $path ), get_current_user_id() );
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $path ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $handle ) {
			return $this->error( 'swbi_error', __( 'Cannot read the file.', 'swbackupit' ), 500 );
		}
		while ( ! feof( $handle ) ) {
			echo fread( $handle, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput,WordPress.WP.AlternativeFunctions
			flush();
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
