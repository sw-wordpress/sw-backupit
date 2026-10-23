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
 * Job commands.
 */
final class JobCommand {
	use CommandSupport;

	/**
	 * Resumes a paused, waiting or failed job and runs it to completion in this process.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function resume( array $args, array $assoc_args ): void {
		$job     = $this->find_job( $args[0] ?? '' );
		$service = Services::instance()->job_service();
		try {
			if ( JobStatus::Paused === $job->status ) {
				$service->resume( $job->id );
			} elseif ( JobStatus::Failed === $job->status ) {
				$service->retry( $job->id );
			}
		} catch ( SwbiException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
		$status = $this->run_job( $job->id );
		JobStatus::Completed === $status ? \WP_CLI::success( 'Job completed.' ) : \WP_CLI::error( 'Job ended with status: ' . $status->value );
	}

	/**
	 * Cancels a job.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function cancel( array $args, array $assoc_args ): void {
		$job = $this->find_job( $args[0] ?? '' );
		try {
			Services::instance()->job_service()->cancel( $job->id );
		} catch ( SwbiException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
		\WP_CLI::success( 'Cancel requested.' );
	}
}
