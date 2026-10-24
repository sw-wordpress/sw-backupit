<?php
/**
 * Application service for jobs: start, tick, pause, resume, cancel, retry.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Runner;

use SWBackupIt\Core\Backup\BackupLayout;
use SWBackupIt\Core\Backup\BackupRunner;
use SWBackupIt\Core\Backup\JobFactory;
use SWBackupIt\Core\Backup\TickResult;
use SWBackupIt\Core\Domain\BackupProfile;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Job\Job;
use SWBackupIt\WordPress\Repository\ScheduleRepository;
use SWBackupIt\WordPress\Repository\WpdbJobRepository;
use SWBackupIt\WordPress\Support\Settings;

final class JobService {

	/**
	 * Statuses that count as "a backup is in progress" (parallel backups are prevented). A paused job still owns its
	 * staging data and will be resumed, so it counts as well.
	 */
	public const IN_PROGRESS = array( 'pending', 'preparing', 'scanning', 'exporting', 'archiving', 'encrypting', 'uploading', 'verifying', 'retry_waiting', 'paused' );

	/** Seconds to wait for the start lock before reporting a conflict. */
	private const START_LOCK_TIMEOUT = 10;

	public function __construct(
		private readonly BackupRunner $runner,
		private readonly WpdbJobRepository $jobs,
		private readonly CronManager $cron,
		private readonly ?ScheduleRepository $schedules = null
	) {
	}

	/**
	 * The profile with a different destination for one run ("run now" with another storage target).
	 *
	 * @param BackupProfile $profile   Profile.
	 * @param int           $target_id Storage target id, 0 = keep the backup only in the local staging directory.
	 */
	public static function with_storage_target( BackupProfile $profile, int $target_id ): BackupProfile {
		$data                       = $profile->toArray();
		$data['storage_target_ids'] = 0 === $target_id ? array() : array( $target_id );
		$data['success_policy']     = 0 === $target_id ? 'local_only' : 'all';
		return BackupProfile::fromArray( $data );
	}

	/**
	 * Creates a job with an immutable profile snapshot and queues it.
	 *
	 * The "nothing in progress" check and the insert run under a database lock (GET_LOCK), so two concurrent requests
	 * (WP-Cron, CLI, REST) can never both start a backup.
	 *
	 * @throws SwbiException With code context 'conflict' when another backup is running.
	 */
	public function start( BackupProfile $profile, string $trigger = 'manual', ?int $schedule_id = null, bool $dispatch = true ): Job {
		$settings = Settings::all();
		$data     = $profile->toArray();
		// Global resource limits are the ceiling for the profile's own limits.
		$data['limits']['time_budget_seconds'] = min( (int) $data['limits']['time_budget_seconds'], (int) $settings['limits']['time_budget_seconds'] );
		$data['limits']['batch_rows']          = min( (int) $data['limits']['batch_rows'], (int) $settings['limits']['batch_rows'] );
		$job                                   = ( new JobFactory() )->create( BackupProfile::fromArray( $data ), $trigger, $schedule_id );
		$this->with_start_lock(
			function () use ( $job ): void {
				$this->assert_nothing_in_progress();
				$this->jobs->create( $job );
			}
		);
		do_action( 'swbi_backup_started', $job->id, $trigger );
		if ( $dispatch ) {
			$this->cron->dispatch_job( $job->id );
		}
		return $job;
	}

	public function tick( string $job_id, ?float $budget = null ): TickResult {
		$result = $this->runner->tick( $job_id, $budget );
		\SWBackupIt\WordPress\Support\Options::update( CronManager::TICK_OPTION, time(), false );
		if ( JobStatus::Cancelled === $result->status ) {
			// The runner applied a cancel request between two work units.
			$this->record_cancelled( $this->jobs->find( $job_id ) );
		}
		return $result;
	}

	/**
	 * Runs $callback while holding the database wide start lock.
	 *
	 * @param callable(): void $callback Check-and-insert.
	 * @throws SwbiException With conflict context when the lock cannot be acquired in time.
	 */
	private function with_start_lock( callable $callback ): void {
		global $wpdb;
		// Lock names are server wide: scope it to this database and table prefix (max. 64 characters).
		$lock = 'swbi_job_start_' . substr( md5( DB_NAME . '|' . $wpdb->base_prefix ), 0, 16 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::START_LOCK_TIMEOUT ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw new SwbiException( __( 'Another backup is already running.', 'sw-backupit' ), array( 'conflict' => true ) );
		}
		try {
			$callback();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * @param string|null $except Job id that may be in progress (the job being resumed/retried itself).
	 * @throws SwbiException With conflict context when another backup is in progress.
	 */
	private function assert_nothing_in_progress( ?string $except = null ): void {
		foreach ( $this->jobs->list( self::IN_PROGRESS, 2 ) as $other ) {
			if ( $other->id !== $except ) {
				throw new SwbiException( __( 'Another backup is already running.', 'sw-backupit' ), array( 'conflict' => true ) );
			}
		}
	}

	/** Schedule bookkeeping for a cancelled scheduled job (it would otherwise stay "running" forever). */
	private function record_cancelled( Job $job ): void {
		if ( null === $job->scheduleId || null === $this->schedules ) {
			return;
		}
		$this->schedules->update(
			$job->scheduleId,
			array(
				'last_status' => 'cancelled',
				'last_error'  => null,
			)
		);
	}

	/** WP-Cron callback: one tick, then re-queue itself while there is more to do. */
	public function run_in_cron( string $job_id ): void {
		try {
			$result = $this->tick( $job_id );
		} catch ( SwbiException ) {
			return;
		}
		if ( $result->shouldContinue ) {
			$this->cron->dispatch_job( $job_id, 1 );
		} elseif ( JobStatus::RetryWaiting === $result->status ) {
			$job = $this->jobs->find( $job_id );
			$at  = $job->nextRetryAt?->getTimestamp() ?? time() + 60;
			$this->cron->dispatch_job( $job_id, max( 1, $at - time() ) );
		}
	}

	public function pause( string $job_id ): void {
		$job = $this->jobs->find( $job_id );
		if ( $job->status->isTerminal() || JobStatus::Paused === $job->status ) {
			throw new SwbiException( __( 'The job cannot be paused in its current state.', 'sw-backupit' ) );
		}
		$this->jobs->requestPause( $job_id );
		if ( ! $job->hasLeaseAt( \SWBackupIt\WordPress\Support\Time::now() ) ) {
			$this->runner->tick( $job_id, 0.0 ); // Applies the flag immediately when nobody is working on it.
		}
	}

	public function resume( string $job_id ): void {
		$job = $this->jobs->find( $job_id );
		if ( JobStatus::Paused !== $job->status ) {
			throw new SwbiException( __( 'Only paused jobs can be resumed.', 'sw-backupit' ) );
		}
		$this->with_start_lock(
			function () use ( $job, $job_id ): void {
				$this->assert_nothing_in_progress( $job_id );
				$this->runner->stateMachine()->resume( $job );
				$this->jobs->save( $job );
			}
		);
		$this->cron->dispatch_job( $job_id );
	}

	public function cancel( string $job_id ): void {
		$job = $this->jobs->find( $job_id );
		if ( $job->status->isTerminal() ) {
			throw new SwbiException( __( 'The job has already finished.', 'sw-backupit' ) );
		}
		$this->jobs->requestCancel( $job_id );
		if ( ! $job->hasLeaseAt( \SWBackupIt\WordPress\Support\Time::now() ) ) {
			// Nobody is working on it: apply directly (a paused/pending job would never tick).
			$job->cancelRequested = true;
			$this->runner->stateMachine()->cancel( $job );
			$this->jobs->save( $job );
			$this->jobs->clearFlags( $job_id );
			( new BackupLayout( Settings::staging_dir(), $job->backupId ) )->removeAll();
			$this->record_cancelled( $job );
		}
	}

	/**
	 * Manually retries a failed job; it continues from its checkpoints.
	 *
	 * @throws SwbiException When the job is not in the failed state.
	 */
	public function retry( string $job_id ): void {
		$job = $this->jobs->find( $job_id );
		if ( JobStatus::Failed !== $job->status ) {
			throw new SwbiException( __( 'Only failed jobs can be retried.', 'sw-backupit' ) );
		}
		$this->with_start_lock(
			function () use ( $job, $job_id ): void {
				$this->assert_nothing_in_progress( $job_id );
				$job->error       = null;
				$job->nextRetryAt = null;
				$this->runner->stateMachine()->transition( $job, JobStatus::RetryWaiting );
				$this->jobs->save( $job );
			}
		);
		$this->cron->dispatch_job( $job_id );
	}
}
