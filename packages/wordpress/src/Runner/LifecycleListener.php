<?php
/**
 * Reacts to finished/failed jobs: catalog registration and schedule bookkeeping.
 *
 * @package sw-backupit
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
use SWBackupIt\WordPress\Support\Notifier;
use SWBackupIt\WordPress\Support\Settings;
use SWBackupIt\WordPress\Support\Time;

final class LifecycleListener {

	public function __construct(
		private readonly JobRepositoryInterface $jobs,
		private readonly BackupRepository $backups,
		private readonly ScheduleRepository $schedules,
		private readonly ?Notifier $notifier = null
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
		$this->record_locations( $job );
		$notifications = (array) ( $job->profileSnapshot['notifications'] ?? array() );
		$profile       = $job->profileName();
		if ( $job->warningsCount > 0 ) {
			$this->notifier?->notify(
				'backup_warnings',
				(string) $job->profileId,
				sprintf( /* translators: %s: profile name */ __( 'Backup "%s" completed with warnings', 'sw-backupit' ), $profile ),
				sprintf( /* translators: 1: profile name, 2: number of warnings */ __( 'The backup "%1$s" completed with %2$d warning(s). Check the job log.', 'sw-backupit' ), $profile, $job->warningsCount ),
				$notifications
			);
		} else {
			$this->notifier?->notify(
				'backup_success',
				(string) $job->profileId,
				sprintf( /* translators: %s: profile name */ __( 'Backup "%s" completed', 'sw-backupit' ), $profile ),
				sprintf( /* translators: %s: profile name */ __( 'The backup "%s" completed successfully.', 'sw-backupit' ), $profile ),
				$notifications
			);
		}
		$failed_targets = $this->failed_targets( $job );
		if ( array() !== $failed_targets ) {
			$this->notifier?->notify(
				'upload_failed',
				$job->profileId . '|' . implode( ',', $failed_targets ),
				sprintf( /* translators: %s: profile name */ __( 'Upload failed for backup "%s"', 'sw-backupit' ), $profile ),
				__( 'The backup was created, but at least one storage target does not hold a verified copy.', 'sw-backupit' ),
				$notifications
			);
		}
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
	 * @return list<int>
	 */
	private function failed_targets( \SWBackupIt\Core\Job\Job $job ): array {
		$state  = $job->checkpoint( \SWBackupIt\Core\Domain\Step::VerifyRemote )['targets'] ?? array();
		$failed = array();
		foreach ( (array) ( $job->profileSnapshot['storage_target_ids'] ?? array() ) as $target_id ) {
			if ( 'verified' !== ( $state[ (string) $target_id ]['status'] ?? '' ) ) {
				$failed[] = (int) $target_id;
			}
		}
		return $failed;
	}

	private function record_locations( \SWBackupIt\Core\Job\Job $job ): void {
		$state = $job->checkpoint( \SWBackupIt\Core\Domain\Step::VerifyRemote )['targets'] ?? array();
		foreach ( (array) ( $job->profileSnapshot['storage_target_ids'] ?? array() ) as $target_id ) {
			$entry = $state[ (string) $target_id ] ?? array(
				'status' => 'failed',
				'error'  => 'not uploaded',
			);
			$this->backups->set_location( $job->backupId, (int) $target_id, 'verified' === $entry['status'] ? 'verified' : 'failed', (string) ( $entry['error'] ?? '' ), $job->backupId );
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function on_failed( array $payload ): void {
		$job = $this->jobs->find( (string) $payload['job_id'] );
		$this->notifier?->notify(
			'backup_failed',
			$job->profileId . '|' . (string) ( $payload['code'] ?? '' ),
			sprintf( /* translators: %s: profile name */ __( 'Backup "%s" failed', 'sw-backupit' ), $job->profileName() ),
			sprintf( /* translators: 1: profile name, 2: error message */ __( 'The backup "%1$s" failed: %2$s', 'sw-backupit' ), $job->profileName(), (string) ( $payload['error'] ?? '' ) ),
			(array) ( $job->profileSnapshot['notifications'] ?? array() )
		);
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
		if ( 'schedule' !== $job->trigger ) {
			// A manual run of a schedule (UI/CLI) never triggers the schedule's automatic retry or moves its timing.
			$this->schedules->update( $job->scheduleId, $update );
			return;
		}
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
