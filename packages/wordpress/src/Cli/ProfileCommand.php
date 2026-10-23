<?php
/**
 * WP-CLI commands (docs/wp-cli.md).
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Cli;

use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Backup\Verifier;
use SWBackupIt\WordPress\Rest\JobPresenter;
use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Settings;

/**
 * Profile commands.
 */
final class ProfileCommand {
	use CommandSupport;

	/**
	 * Lists profiles.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml. Default table.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$repo  = Services::instance()->profiles();
		$items = array();
		foreach ( $repo->all() as $profile ) {
			$items[] = array(
				'id'      => $profile->id,
				'name'    => $profile->name,
				'type'    => $profile->type->value,
				'volume'  => round( $profile->volumeSizeBytes / 1048576 ) . ' MB',
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $items, array( 'id', 'name', 'type', 'volume' ) );
	}

	/**
	 * Shows a profile as JSON.
	 *
	 * ## OPTIONS
	 *
	 * <profile>
	 * : Profile id or name.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function show( array $args, array $assoc_args ): void {
		$profile = $this->resolve_profile( $args[0] ?? '' );
		\WP_CLI::line( (string) wp_json_encode( Services::instance()->profiles()->to_rest( $profile ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
