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
 * Backup commands.
 */
final class BackupCommand {
	use CommandSupport;

	/**
	 * Creates a backup and runs it to completion in this process (no web time limits).
	 *
	 * ## OPTIONS
	 *
	 * --profile=<profile>
	 * : Profile id or name.
	 *
	 * [--background]
	 * : Only queue the job (WP-Cron executes it).
	 *
	 * [--format=<format>]
	 * : text or json. Default text.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function create( array $args, array $assoc_args ): void {
		$profile = $this->resolve_profile( (string) ( $assoc_args['profile'] ?? '' ) );
		$service = Services::instance()->job_service();
		try {
			$background = isset( $assoc_args['background'] );
			$job        = $service->start( $profile, 'cli', null, $background );
		} catch ( SwbiException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
		if ( ! isset( $assoc_args['background'] ) ) {
			$status = $this->run_job( $job->id );
			if ( JobStatus::Completed !== $status ) {
				$this->output( (array) JobPresenter::to_rest( Services::instance()->jobs()->find( $job->id ) ), $assoc_args );
				\WP_CLI::error( 'Backup ended with status: ' . $status->value );
			}
		}
		$this->output( (array) JobPresenter::to_rest( Services::instance()->jobs()->find( $job->id ) ), $assoc_args );
		\WP_CLI::success( 'Backup ' . $job->backupId . ( isset( $assoc_args['background'] ) ? ' queued.' : ' completed.' ) );
	}

	/**
	 * Lists backups.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$result = Services::instance()->backups()->search( array(), 1, 100 );
		$items  = array_map(
			static fn ( array $row ): array => array(
				'id'      => $row['id'],
				'type'    => $row['type'],
				'created' => $row['created_at'],
				'size'    => size_format( (float) $row['size_bytes'] ),
				'files'   => $row['file_count'],
				'volumes' => $row['volume_count'],
			),
			$result['items']
		);
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $items, array( 'id', 'type', 'created', 'size', 'files', 'volumes' ) );
	}

	/**
	 * Shows the status of a backup job.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id or backup id.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$job = $this->find_job( $args[0] ?? '' );
		$this->output( JobPresenter::to_rest( $job ), $assoc_args + array( 'format' => 'json' ) );
	}

	/**
	 * Verifies a backup against its manifest.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup id.
	 *
	 * [--deep]
	 * : Also read and hash every archived file.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function verify( array $args, array $assoc_args ): void {
		$id = (string) ( $args[0] ?? '' );
		if ( ! \SWBackupIt\Core\Support\Uuid::isValid( $id ) ) {
			\WP_CLI::error( 'Invalid backup id.' );
		}
		try {
			$report = ( new Verifier() )->verify( BackupSet::open( Settings::staging_dir() . '/' . $id ), isset( $assoc_args['deep'] ) );
		} catch ( SwbiException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
		Services::instance()->backups()->mark_verified( $id, $report->isOk() ? 'verified' : 'corrupt' );
		if ( ! $report->isOk() ) {
			foreach ( $report->errors() as $error ) {
				\WP_CLI::warning( $error );
			}
			\WP_CLI::error( 'Verification failed.' );
		}
		\WP_CLI::success( 'Backup verified.' );
	}
}
