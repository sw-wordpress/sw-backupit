<?php
/**
 * Reacts to finished/failed jobs: catalog registration and schedule bookkeeping.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Runner;

use SWBackupIt\Core\Event\EventDispatcherInterface;
use SWBackupIt\Core\Event\Events;
use SWBackupIt\Core\Job\JobRepositoryInterface;
use SWBackupIt\Core\Manifest\Manifest;
use SWBackupIt\WordPress\Repository\BackupRepository;
use SWBackupIt\WordPress\Repository\ScheduleCalculator;
use SWBackupIt\WordPress\Repository\ScheduleRepository;
use SWBackupIt\WordPress\Support\Settings;
use SWBackupIt\WordPress\Support\Time;

final class LifecycleListener {

	public function __construct(
		private readonly JobRepositoryInterface $jobs,
		private readonly BackupRepository $backups,
		private readonly ScheduleRepository $schedules
	) {
	}

	public function register( EventDispatcherInterface $dispatcher ): void {
		$dispatcher->listen( Events::AFTER_BACKUP, array( $this, 'on_completed' ) );
		$dispatcher->listen( Events::BACKUP_FAILED, array( $this, 'on_failed' ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function on_completed( array $payload ): void {
		$job = $this->jobs->find( (string) $payload['job_id'] );
		$dir = Settings::staging_dir() . '/' . $job->backupId;
		$this->backups->register( Manifest::read( $dir ), $job->profileId, $job->id, $dir );
		if ( null !== $job->scheduleId ) {
			$this->schedules->update(
				$job->scheduleId,
				array(
					'last_run_at' => Time::to_db( $job->finishedAt ?? Time::now() ),
					'last_status' => 'completed',
					'last_error'  => null,
					'retry_count' => 0,
				)
			);
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function on_failed( array $payload ): void {
		$job = $this->jobs->find( (string) $payload['job_id'] );
		if ( null === $job->scheduleId ) {
			return;
		}
		$schedule = $this->schedules->find( $job->scheduleId );
		if ( null === $schedule ) {
			return;
		}
		$update  = array(
			'last_run_at' => Time::to_db( $job->finishedAt ?? Time::now() ),
			'last_status' => 'failed',
			'last_error'  => (string) ( $payload['error'] ?? '' ),
		);
		$retries = (int) $schedule['retry_count'];
		if ( $retries < (int) $schedule['retry_max'] ) {
			// Retry strategy: exponential backoff, capped at 6 hours.
			$delay                 = (int) min( 21600, (int) $schedule['retry_backoff'] * ( 2 ** $retries ) );
			$update['next_run_at'] = Time::to_db( Time::now()->modify( '+' . $delay . ' seconds' ) );
			$update['retry_count'] = $retries + 1;
		} else {
			$update['retry_count'] = 0;
			$update['next_run_at'] = Time::to_db( ScheduleCalculator::next_run( $schedule, Time::now() ) );
		}
		$this->schedules->update( $job->scheduleId, $update );
	}
}
