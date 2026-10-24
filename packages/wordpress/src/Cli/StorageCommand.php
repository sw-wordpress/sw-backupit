<?php
/**
 * WP-CLI storage commands.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Cli;

use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Storage\StorageFactory;
use SWBackupIt\WordPress\Services;

/**
 * Storage target commands.
 */
final class StorageCommand {

	/**
	 * Lists storage targets.
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$repo  = Services::instance()->storage_targets();
		$items = array();
		foreach ( $repo->all() as $row ) {
			$items[] = array(
				'id'     => $row['id'],
				'name'   => $row['name'],
				'type'   => $row['type'],
				'status' => $row['status'],
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $items, array( 'id', 'name', 'type', 'status' ) );
	}

	/**
	 * Tests a storage target (write, read, delete).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage target id.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function test( array $args, array $assoc_args ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$target = Services::instance()->storage_targets()->find( $id );
		if ( null === $target ) {
			\WP_CLI::error( 'Storage target not found.' );
		}
		try {
			$result = ( new StorageFactory() )->create( $target )->test();
		} catch ( SwbiException $exception ) {
			Services::instance()->storage_targets()->record_test( $id, false, $exception->getMessage() );
			\WP_CLI::error( $exception->getMessage() );
		}
		Services::instance()->storage_targets()->record_test( $id, $result->ok, $result->message );
		$result->ok ? \WP_CLI::success( $result->message ) : \WP_CLI::error( $result->message );
	}
}
