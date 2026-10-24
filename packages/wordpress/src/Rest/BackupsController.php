<?php
/**
 * Backups: catalog, details, verify, protect, browse, download, delete.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Rest;

use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Files\Comparator;
use SWBackupIt\WordPress\Runner\BackgroundChecks;
use SWBackupIt\WordPress\Runner\RecoveryPackage;
use SWBackupIt\WordPress\Runner\RestorePreparer;
use SWBackupIt\WordPress\Runner\RestoreTestService;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\WordPress\Support\Capabilities;
use SWBackupIt\WordPress\Support\PluginLogger;
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
		register_rest_route(
			self::NAMESPACE,
			'/backups/(?P<id>[0-9a-f-]{36})/package',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'package' ),
				'permission_callback' => $this->can( Capabilities::DOWNLOAD ),
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/backups/(?P<id>[0-9a-f-]{36})/transfer',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'transfer' ),
				'permission_callback' => $this->can( Capabilities::MANAGE_STORAGE ),
				'args'                => $id + array(
					'target_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'source_id' => array(
						'type'    => array( 'integer', 'null' ),
						'minimum' => 0,
						'default' => null,
					),
					'move'      => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/transfers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn () => new \WP_REST_Response( $this->services()->transfers()->all() ),
				'permission_callback' => $this->can( Capabilities::VIEW ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/transfers/(?P<transfer>[a-f0-9]{12})/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel_transfer' ),
				'permission_callback' => $this->can( Capabilities::MANAGE_STORAGE ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/backups/(?P<id>[0-9a-f-]{36})/compare',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'compare' ),
				'permission_callback' => $this->can( Capabilities::VIEW ),
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/backups/(?P<id>[0-9a-f-]{36})/restore-test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_test' ),
				'permission_callback' => $this->can( Capabilities::RESTORE ),
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/backups/(?P<id>[0-9a-f-]{36})/restore/prepare',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_prepare' ),
				'permission_callback' => $this->can( Capabilities::RESTORE ),
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/backups/(?P<id>[0-9a-f-]{36})/restore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn () => $this->error( 'swbi_use_wizard', __( 'A restore is never executed inside WordPress. Use "Prepare restore" and continue in the standalone restore application (or the restore CLI).', 'sw-backupit' ), 501 ),
				'permission_callback' => $this->can( Capabilities::RESTORE ),
				'args'                => $id,
			)
		);
	}

	/**
	 * Queues a copy (or move) of the backup to another storage target.
	 */
	public function transfer( \WP_REST_Request $request ) {
		$move = rest_sanitize_boolean( $request['move'] );
		if ( $move && ! current_user_can( Capabilities::DELETE ) ) {
			return $this->error( 'swbi_forbidden', __( 'Moving a backup removes the source copy and needs the permission to delete backups.', 'sw-backupit' ), 403 );
		}
		$source = $request['source_id'];
		try {
			$transfer = $this->services()->transfers()->start( (string) $request['id'], (int) $request['target_id'], null === $source ? null : (int) $source, $move );
		} catch ( \Throwable $throwable ) {
			return $this->from_exception( $throwable );
		}
		return new \WP_REST_Response( $this->services()->transfers()->to_rest( $transfer ), 202 );
	}

	public function cancel_transfer( \WP_REST_Request $request ) {
		return $this->services()->transfers()->cancel( (string) $request['transfer'] )
			? new \WP_REST_Response( array( 'cancelled' => true ) )
			: $this->error( 'swbi_not_found', __( 'No running transfer with this id.', 'sw-backupit' ), 404 );
	}

	/**
	 * Compares the backup file index with the files of this installation (nothing is changed).
	 */
	public function compare( \WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( null === $this->services()->backups()->find_row( $id ) ) {
			return $this->error( 'swbi_not_found', __( 'Backup not found.', 'sw-backupit' ), 404 );
		}
		$mode = (string) ( $this->body( $request )['mode'] ?? 'metadata' );
		if ( ! in_array( $mode, array( 'metadata', 'sha256' ), true ) ) {
			return $this->error( 'swbi_invalid_param', __( 'The mode must be metadata or sha256.', 'sw-backupit' ), 400 );
		}
		$work = untrailingslashit( Settings::staging_dir() ) . '/tmp/compare-' . bin2hex( random_bytes( 4 ) );
		try {
			wp_raise_memory_limit( 'admin' );
			$set = BackupSet::open( Settings::staging_dir() . '/' . $id, $this->services()->keys(), $work );
			try {
				$result = ( new Comparator() )->compare( $set, untrailingslashit( ABSPATH ), $mode, null, dirname( $work ) );
			} finally {
				$set->close();
			}
		} catch ( SwbiException $exception ) {
			return $this->error( 'swbi_compare_failed', $exception->getMessage(), 422 );
		}
		return new \WP_REST_Response( $result->toArray() );
	}

	/**
	 * Automated test restore into an isolated temp directory and scratch database; nothing productive is touched.
	 */
	public function restore_test( \WP_REST_Request $request ) {
		try {
			return new \WP_REST_Response( ( new RestoreTestService() )->run( (string) $request['id'] ) );
		} catch ( SwbiException $exception ) {
			return $this->error( 'swbi_not_found', $exception->getMessage(), 404 );
		}
	}

	/**
	 * Prepares the standalone restore wizard (token shown once).
	 */
	public function restore_prepare( \WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( null === $this->services()->backups()->find_row( $id ) ) {
			return $this->error( 'swbi_not_found', __( 'Backup not found.', 'sw-backupit' ), 404 );
		}
		try {
			return new \WP_REST_Response( ( new RestorePreparer() )->prepare( $id ) );
		} catch ( SwbiException $exception ) {
			return $this->error( 'swbi_restore_prepare_failed', $exception->getMessage(), 422 );
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
			return $this->error( 'swbi_not_found', __( 'Backup not found.', 'sw-backupit' ), 404 );
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
			return $this->error( 'swbi_not_found', __( 'Backup not found.', 'sw-backupit' ), 404 );
		}
		if ( $id !== (string) $request['confirm'] ) {
			return $this->error( 'swbi_confirmation_required', __( 'Deleting a backup requires confirming its id.', 'sw-backupit' ), 400 );
		}
		if ( (bool) $row['protected'] ) {
			return $this->error( 'swbi_conflict', __( 'The backup is protected. Remove the protection first.', 'sw-backupit' ), 409 );
		}
		try {
			// Every copy: local staging and all storage targets (a backup that only disappears from the catalog would
			// leave unmanaged copies behind).
			$this->services()->catalog()->delete( $id );
		} catch ( SwbiException $exception ) {
			return $this->error( 'swbi_conflict', $exception->getMessage(), 409 );
		}
		do_action( 'swbi_backup_deleted', $id );
		return new \WP_REST_Response( array( 'deleted' => true ) );
	}

	public function verify( \WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( null === $this->services()->backups()->find_row( $id ) ) {
			return $this->error( 'swbi_not_found', __( 'Backup not found.', 'sw-backupit' ), 404 );
		}
		if ( ! BackgroundChecks::has_local_data( $id ) ) {
			// Not a defect: the status stays as it is.
			return $this->error( 'swbi_no_local_copy', __( 'There is no local copy of this backup to verify. Its copies on the storage targets are verified during upload; download the backup from a storage target to verify it here.', 'sw-backupit' ), 409 );
		}
		try {
			$set    = BackupSet::open( Settings::staging_dir() . '/' . $id );
			$report = ( new Verifier() )->verify( $set );
		} catch ( SwbiException $exception ) {
			return $this->error( 'swbi_verify_failed', $exception->getMessage(), 422 );
		}
		$this->services()->backups()->mark_verified( $id, $report->isOk() ? 'verified' : 'corrupt' );
		do_action( 'swbi_backup_verified', $id, $report->isOk() ? 'ok' : 'corrupt' );
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
			return $this->error( 'swbi_not_found', __( 'Backup not found.', 'sw-backupit' ), 404 );
		}
		$protect = rest_sanitize_boolean( $this->body( $request )['protected'] ?? true );
		$this->services()->backups()->set_protected( $id, $protect );
		do_action( 'swbi_backup_protected', $id, $protect ? 'protected' : 'unprotected' );
		return $this->get_backup( $request );
	}

	public function files( \WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( null === $this->services()->backups()->find_row( $id ) ) {
			return $this->error( 'swbi_not_found', __( 'Backup not found.', 'sw-backupit' ), 404 );
		}
		$work = untrailingslashit( Settings::staging_dir() ) . '/tmp/browse-' . bin2hex( random_bytes( 4 ) );
		try {
			$set = BackupSet::open( Settings::staging_dir() . '/' . $id, $this->services()->keys(), $work );
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
		try {
			$entries = $set->manifest()->hasFiles() ? $set->indexReader()->read() : array();
			// Streams the index; memory stays constant regardless of the number of files.
			foreach ( $entries as [ $entry ] ) {
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
		} catch ( SwbiException $exception ) {
			return $this->error( 'swbi_backup_unavailable', $exception->getMessage(), 422 );
		} finally {
			$set->close();
		}
		return $this->collection( $items, $total, $page, $per_page );
	}

	/**
	 * Streams one artifact of a backup after authorization. No public URL ever exists.
	 *
	 * @return \WP_Error|void
	 */
	public function download( \WP_REST_Request $request ) {
		$path = $this->download_path( $request );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $handle ) {
			return $this->error( 'swbi_error', __( 'Cannot read the file.', 'sw-backupit' ), 500 );
		}
		do_action( 'swbi_backup_downloaded', (string) $request['id'], basename( $path ) );
		$this->stream( $handle, $path );
		exit;
	}

	/**
	 * Streams the complete recovery package (backup + chain + restore application) as one ZIP.
	 *
	 * @return \WP_Error|void
	 */
	public function package( \WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( null === $this->services()->backups()->find_row( $id ) ) {
			return $this->error( 'swbi_not_found', __( 'Backup not found.', 'sw-backupit' ), 404 );
		}
		if ( ! BackgroundChecks::has_local_data( $id ) ) {
			return $this->error( 'swbi_no_local_copy', __( 'The backup has no complete local copy. Download it from a storage target first.', 'sw-backupit' ), 409 );
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions -- the archive is streamed, the duration depends on the size.
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . RecoveryPackage::file_name( $id ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		do_action( 'swbi_backup_downloaded', $id, 'recovery-package' );
		$output = fopen( 'php://output', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		try {
			( new RecoveryPackage() )->write( $output, $id );
		} catch ( SwbiException $exception ) {
			// Headers are out already; the truncated ZIP is unusable and the error is logged.
			( new PluginLogger() )->error( 'Recovery package failed.', array( 'exception' => $exception::class ) );
		}
		exit;
	}

	/**
	 * Resolves the stored file of the requested artifact.
	 *
	 * @return string|\WP_Error Absolute path of an existing file.
	 */
	public function download_path( \WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( null === $this->services()->backups()->find_row( $id ) ) {
			return $this->error( 'swbi_not_found', __( 'Backup not found.', 'sw-backupit' ), 404 );
		}
		try {
			$set      = BackupSet::open( Settings::staging_dir() . '/' . $id );
			$manifest = $set->manifest();
			$part     = (string) $request['part'];
			// Artifacts are served exactly as stored (encrypted backups stay encrypted, .swbe): nothing is decrypted on the
			// server, the file name and checksum match the manifest.
			if ( 'manifest' === $part ) {
				$path = $set->directory() . '/manifest.json';
			} elseif ( 'index' === $part ) {
				$path = $manifest->hasFiles() ? $set->storedIndexPath() : '';
			} elseif ( 'database' === $part ) {
				$parts = $manifest->databaseParts();
				$path  = isset( $parts[ (int) $request['volume'] - 1 ] ) ? $set->storedDatabasePartPath( $parts[ (int) $request['volume'] - 1 ]['name'] ) : '';
			} else {
				$path = (int) $request['volume'] > count( $manifest->volumes() ) ? '' : $set->storedVolumePath( $set->volumeFileName( (int) $request['volume'] ) );
			}
		} catch ( SwbiException $exception ) {
			return $this->error( 'swbi_backup_unavailable', $exception->getMessage(), 404 );
		}
		if ( '' === $path || ! is_file( $path ) ) {
			return $this->error( 'swbi_not_found', __( 'The requested file does not exist.', 'sw-backupit' ), 404 );
		}
		return $path;
	}

	/**
	 * Sends the file with download headers. Output buffers (themes, plugins, compression) are discarded first, so
	 * nothing is prepended to the binary and large files are never held in memory.
	 *
	 * @param resource $handle       Open file handle.
	 * @param string   $path         File path (size and name).
	 * @param int      $keep_buffers Output buffer levels to keep (tests capture the output this way).
	 */
	public function stream( $handle, string $path, int $keep_buffers = 0 ): void {
		while ( ob_get_level() > $keep_buffers ) {
			ob_end_clean();
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Length: ' . (string) filesize( $path ) );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $path ) ) . '"' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		while ( ! feof( $handle ) ) {
			echo fread( $handle, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput,WordPress.WP.AlternativeFunctions
			flush();
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
