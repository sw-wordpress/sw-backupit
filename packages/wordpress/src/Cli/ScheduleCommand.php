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
 * Schedule commands.
 */
final class ScheduleCommand {
	use CommandSupport;

	/**
	 * Lists schedules.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$repo  = Services::instance()->schedules();
		$items = array();
		foreach ( $repo->all() as $row ) {
			$items[] = array(
				'id'      => $row['id'],
				'name'    => $row['name'],
				'enabled' => $row['enabled'] ? 'yes' : 'no',
				'next'    => $row['next_run_at'],
				'last'    => $row['last_run_at'],
				'status'  => $row['last_status'],
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $items, array( 'id', 'name', 'enabled', 'next', 'last', 'status' ) );
	}

	/**
	 * Runs one schedule immediately and waits for the job.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Schedule id.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		$row = Services::instance()->schedules()->find( (int) ( $args[0] ?? 0 ) );
		if ( null === $row ) {
			\WP_CLI::error( 'Schedule not found.' );
		}
		$job_id = Services::instance()->schedule_runner()->start( $row, 'cli', false );
		if ( null === $job_id ) {
			\WP_CLI::error( 'The schedule could not be started (see schedule list for the reason).' );
		}
		$status = $this->run_job( $job_id );
		JobStatus::Completed === $status ? \WP_CLI::success( 'Backup completed.' ) : \WP_CLI::error( 'Backup ended with status: ' . $status->value );
	}

	/**
	 * Starts all due schedules and executes their jobs. Use this from a system cron job.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function run_due( array $args, array $assoc_args ): void {
		$started = Services::instance()->schedule_runner()->run_due( false );
		foreach ( $started as $job_id ) {
			$status = $this->run_job( $job_id );
			\WP_CLI::log( sprintf( 'Job %s: %s', $job_id, $status->value ) );
		}
		// Also continue jobs that were queued/interrupted (e.g. started from the UI while WP-Cron is disabled).
		foreach ( Services::instance()->jobs()->list( array( 'pending', 'preparing', 'scanning', 'exporting', 'archiving', 'encrypting', 'uploading', 'verifying', 'retry_waiting' ), 5 ) as $job ) {
			if ( in_array( $job->id, $started, true ) ) {
				continue;
			}
			$status = $this->run_job( $job->id );
			\WP_CLI::log( sprintf( 'Job %s: %s', $job->id, $status->value ) );
		}
		\WP_CLI::success( sprintf( '%d schedule(s) started.', count( $started ) ) );
	}
}
