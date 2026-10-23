<?php
/**
 * Application service for jobs: start, tick, pause, resume, cancel, retry.
 *
 * @package SWBackupIt
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
use SWBackupIt\WordPress\Repository\WpdbJobRepository;
use SWBackupIt\WordPress\Support\Settings;

final class JobService {

	/** Statuses that count as "a backup is in progress" (parallel backups are prevented). */
	private const IN_PROGRESS = array( 'pending', 'preparing', 'scanning', 'exporting', 'archiving', 'encrypting', 'uploading', 'verifying', 'retry_waiting' );

	public function __construct(
		private readonly BackupRunner $runner,
		private readonly WpdbJobRepository $jobs,
		private readonly CronManager $cron
	) {
	}

	/**
	 * Creates a job with an immutable profile snapshot and queues it.
	 *
	 * @throws SwbiException With code context 'conflict' when another backup is running.
	 */
	public function start( BackupProfile $profile, string $trigger = 'manual', ?int $schedule_id = null, bool $dispatch = true ): Job {
		if ( $this->jobs->count( self::IN_PROGRESS ) > 0 ) {
			throw new SwbiException( __( 'Another backup is already running.', 'swbackupit' ), array( 'conflict' => true ) );
		}
		$settings = Settings::all();
		$data     = $profile->toArray();
		// Global resource limits are the ceiling for the profile's own limits.
		$data['limits']['time_budget_seconds'] = min( (int) $data['limits']['time_budget_seconds'], (int) $settings['limits']['time_budget_seconds'] );
		$data['limits']['batch_rows']          = min( (int) $data['limits']['batch_rows'], (int) $settings['limits']['batch_rows'] );
		$job                                   = ( new JobFactory() )->create( BackupProfile::fromArray( $data ), $trigger, $schedule_id );
		$this->jobs->create( $job );
		if ( $dispatch ) {
			$this->cron->dispatch_job( $job->id );
		}
		return $job;
	}

	public function tick( string $job_id, ?float $budget = null ): TickResult {
		$result = $this->runner->tick( $job_id, $budget );
		update_option( CronManager::TICK_OPTION, time(), false );
		return $result;
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
			throw new SwbiException( __( 'The job cannot be paused in its current state.', 'swbackupit' ) );
		}
		$this->jobs->requestPause( $job_id );
		if ( ! $job->hasLeaseAt( \SWBackupIt\WordPress\Support\Time::now() ) ) {
			$this->runner->tick( $job_id, 0.0 ); // Applies the flag immediately when nobody is working on it.
		}
	}

	public function resume( string $job_id ): void {
		$job = $this->jobs->find( $job_id );
		if ( JobStatus::Paused !== $job->status ) {
			throw new SwbiException( __( 'Only paused jobs can be resumed.', 'swbackupit' ) );
		}
		$this->runner->stateMachine()->resume( $job );
		$this->jobs->save( $job );
		$this->cron->dispatch_job( $job_id );
	}

	public function cancel( string $job_id ): void {
		$job = $this->jobs->find( $job_id );
		if ( $job->status->isTerminal() ) {
			throw new SwbiException( __( 'The job has already finished.', 'swbackupit' ) );
		}
		$this->jobs->requestCancel( $job_id );
		if ( ! $job->hasLeaseAt( \SWBackupIt\WordPress\Support\Time::now() ) ) {
			// Nobody is working on it: apply directly (a paused/pending job would never tick).
			$job->cancelRequested = true;
			$this->runner->stateMachine()->cancel( $job );
			$this->jobs->save( $job );
			$this->jobs->clearFlags( $job_id );
			( new BackupLayout( Settings::staging_dir(), $job->backupId ) )->removeAll();
		}
	}

	/** Manually retries a failed job; it continues from its checkpoints. */
	public function retry( string $job_id ): void {
		$job = $this->jobs->find( $job_id );
		if ( JobStatus::Failed !== $job->status ) {
			throw new SwbiException( __( 'Only failed jobs can be retried.', 'swbackupit' ) );
		}
		$job->error       = null;
		$job->nextRetryAt = null;
		$this->runner->stateMachine()->transition( $job, JobStatus::RetryWaiting );
		$this->jobs->save( $job );
		$this->cron->dispatch_job( $job_id );
	}
}
