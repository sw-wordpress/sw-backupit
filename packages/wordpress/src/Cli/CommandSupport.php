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

trait CommandSupport {
	/**
	 * Runs ticks in this process until the job reaches a resting state.
	 */
	private function run_job( string $job_id ): JobStatus {
		$service = Services::instance()->job_service();
		$status  = JobStatus::Pending;
		while ( true ) {
			$result = $service->tick( $job_id, 60.0 );
			$status = $result->status;
			\WP_CLI::debug( 'tick: ' . $status->value . ' ' . $result->note, 'swbackupit' );
			if ( JobStatus::RetryWaiting === $status ) {
				$job = Services::instance()->jobs()->find( $job_id );
				$wait = max( 1, ( $job->nextRetryAt?->getTimestamp() ?? time() ) - time() );
				\WP_CLI::log( sprintf( 'Transient error, retrying in %d seconds.', $wait ) );
				sleep( min( $wait, 900 ) );
				continue;
			}
			if ( ! $result->shouldContinue ) {
				return $status;
			}
		}
	}
	private function resolve_profile( string $identifier ): \SWBackupIt\Core\Domain\BackupProfile {
		$repo = Services::instance()->profiles();
		if ( ctype_digit( $identifier ) ) {
			$profile = $repo->find( (int) $identifier );
			if ( null !== $profile ) {
				return $profile;
			}
		}
		foreach ( $repo->all() as $profile ) {
			if ( 0 === strcasecmp( $profile->name, $identifier ) ) {
				return $profile;
			}
		}
		\WP_CLI::error( 'Profile not found: ' . $identifier );
	}
	private function find_job( string $id ): \SWBackupIt\Core\Job\Job {
		try {
			return Services::instance()->jobs()->find( $id );
		} catch ( SwbiException ) {
			foreach ( Services::instance()->jobs()->list( null, 200 ) as $job ) {
				if ( $job->backupId === $id ) {
					return $job;
				}
			}
		}
		\WP_CLI::error( 'Job not found: ' . $id );
	}
	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $assoc_args
	 */
	private function output( array $data, array $assoc_args ): void {
		if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		}
	}
}
