<?php
/**
 * Recovers jobs whose background execution got lost (lost WP-Cron event, crashed worker) and fails jobs that are stalled for good.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Runner;

use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Event\Events;
use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Time;

final class JobWatchdog {

	public const STALL_RESTART_SECONDS = 300;
	public const STALL_FAIL_SECONDS    = 172800;

	/**
	 * @return array{restarted: int, failed: int}
	 */
	public function run(): array {
		$result = array(
			'restarted' => 0,
			'failed'    => 0,
		);
		$now    = Time::now();
		$jobs   = Services::instance()->jobs();
		foreach ( $jobs->list( array( 'pending', 'preparing', 'scanning', 'exporting', 'archiving', 'encrypting', 'uploading', 'verifying', 'retry_waiting' ), 20 ) as $job ) {
			if ( $job->hasLeaseAt( $now ) ) {
				continue; // A worker is active.
			}
			$idle = $now->getTimestamp() - $job->updatedAt->getTimestamp();
			if ( JobStatus::RetryWaiting === $job->status && null !== $job->nextRetryAt && $job->nextRetryAt > $now ) {
				continue;
			}
			if ( $idle > self::STALL_FAIL_SECONDS ) {
				$error = array(
					'code'      => 'swbi_stalled',
					'message'   => 'The job made no progress for more than 48 hours and was stopped.',
					'retryable' => true,
				);
				Services::instance()->runner()->stateMachine()->fail( $job, $error );
				$jobs->save( $job );
				// Same contract as a failure inside the runner: notification, schedule bookkeeping/retry and hooks.
				Services::instance()->dispatcher()->dispatch(
					Events::BACKUP_FAILED,
					array(
						'job_id'    => $job->id,
						'backup_id' => $job->backupId,
						'error'     => $error['message'],
						'code'      => $error['code'],
					)
				);
				++$result['failed'];
			} elseif ( $idle > self::STALL_RESTART_SECONDS ) {
				Services::instance()->cron()->dispatch_job( $job->id );
				++$result['restarted'];
			}
		}
		return $result;
	}
}
