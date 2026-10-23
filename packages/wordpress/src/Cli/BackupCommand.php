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

	/**
	 * Compares a backup with the files of this installation (nothing is changed).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup id.
	 *
	 * [--mode=<mode>]
	 * : metadata (default) or sha256.
	 *
	 * [--format=<format>]
	 * : table or json.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function compare( array $args, array $assoc_args ): void {
		$id = (string) ( $args[0] ?? '' );
		if ( ! \SWBackupIt\Core\Support\Uuid::isValid( $id ) ) {
			\WP_CLI::error( 'Invalid backup id.' );
		}
		$work = untrailingslashit( Settings::staging_dir() ) . '/tmp/compare-' . bin2hex( random_bytes( 4 ) );
		try {
			$set = BackupSet::open( Settings::staging_dir() . '/' . $id, Services::instance()->keys(), $work );
			try {
				$result = ( new \SWBackupIt\Restore\Files\Comparator() )->compare( $set, untrailingslashit( ABSPATH ), (string) ( $assoc_args['mode'] ?? 'metadata' ), null, dirname( $work ) );
			} finally {
				$set->close();
			}
		} catch ( SwbiException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
		if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $result->toArray(), JSON_PRETTY_PRINT ) );
			return;
		}
		$rows = array();
		foreach ( $result->counts as $category => $count ) {
			$rows[] = array(
				'category' => $category,
				'count'    => $count,
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'category', 'count' ) );
	}

	/**
	 * Restores a backup into this installation using the standalone restore engine (destructive).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup id.
	 *
	 * --confirm-target=<path>
	 * : The exact target directory (defaults to this installation); typing it confirms the overwrite.
	 *
	 * [--target=<path>]
	 * : Restore somewhere else than ABSPATH.
	 *
	 * [--no-database]
	 * : Do not restore the database.
	 *
	 * [--confirm-database=<name>]
	 * : The name of this installation's database; required to restore the database.
	 *
	 * [--overwrite=<mode>]
	 * : always, never or if_different (default).
	 *
	 * [--key=<key>]
	 * : Passphrase or base64:KEY for encrypted backups (default: key store).
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function restore( array $args, array $assoc_args ): void {
		$id = (string) ( $args[0] ?? '' );
		if ( ! \SWBackupIt\Core\Support\Uuid::isValid( $id ) ) {
			\WP_CLI::error( 'Invalid backup id.' );
		}
		$target   = (string) ( $assoc_args['target'] ?? untrailingslashit( ABSPATH ) );
		$with_db  = ! isset( $assoc_args['no-database'] );
		$database = $with_db ? \SWBackupIt\Core\Database\ConnectionConfig::fromWordPressHost( DB_HOST, DB_NAME, DB_USER, DB_PASSWORD ) : null;
		$keys     = isset( $assoc_args['key'] ) ? new \SWBackupIt\Core\Crypto\StaticKeyProvider( (string) $assoc_args['key'] ) : Services::instance()->keys();
		$state    = untrailingslashit( Settings::staging_dir() ) . '/restore-state-' . $id;
		$request  = new \SWBackupIt\Restore\RestoreRequest(
			Settings::staging_dir() . '/' . $id,
			$target,
			(string) ( $assoc_args['confirm-target'] ?? '' ),
			$state,
			true,
			$with_db,
			$database,
			(string) ( $assoc_args['confirm-database'] ?? '' ),
			true,
			\SWBackupIt\Restore\Files\OverwriteMode::tryFrom( (string) ( $assoc_args['overwrite'] ?? 'if_different' ) ) ?? \SWBackupIt\Restore\Files\OverwriteMode::IfDifferent,
			new \SWBackupIt\Restore\Files\Selection(),
			false,
			true,
			keys: $keys
		);
		$engine   = new \SWBackupIt\Restore\RestoreEngine( new \SWBackupIt\Restore\Cli\ConsoleLogger( STDERR ) );
		do {
			$outcome = $engine->run( $request, new \SWBackupIt\Core\Support\ResourceBudget( 60.0 ) );
		} while ( \SWBackupIt\Restore\RestoreOutcome::CONTINUE === $outcome->status );
		if ( ! $outcome->isSuccess() ) {
			\WP_CLI::error( 'Restore failed in phase ' . $outcome->phase . ': ' . implode( '; ', $outcome->messages ) );
		}
		\WP_CLI::success( 'Restore completed.' );
	}
}
