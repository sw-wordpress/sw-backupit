<?php
/**
 * Starts due schedules. Called by the WP-Cron watcher, by `wp swbackupit schedule run-due` (system cron) or manually.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Runner;

use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\WordPress\Repository\ProfileRepository;
use SWBackupIt\WordPress\Repository\ScheduleCalculator;
use SWBackupIt\WordPress\Repository\ScheduleRepository;
use SWBackupIt\WordPress\Support\Time;

final class ScheduleRunner {

	public function __construct(
		private readonly ScheduleRepository $schedules,
		private readonly ProfileRepository $profiles,
		private readonly JobService $jobs
	) {
	}

	/**
	 * @return list<string> Ids of the jobs that were started.
	 */
	public function run_due( bool $dispatch = true ): array {
		$started = array();
		$now     = Time::now();
		foreach ( $this->schedules->due( $now ) as $schedule ) {
			$id = (int) $schedule['id'];
			// Advance first: a crash or failure can never cause a start loop.
			try {
				$next = ScheduleCalculator::next_run( $schedule, $now );
			} catch ( \InvalidArgumentException ) {
				$this->schedules->update(
					$id,
					array(
						'enabled'     => 0,
						'last_status' => 'failed',
						'last_error'  => 'Invalid schedule configuration; schedule disabled.',
					)
				);
				continue;
			}
			$this->schedules->update( $id, array( 'next_run_at' => Time::to_db( $next ) ) );
			$job_id = $this->start( $schedule, 'schedule', $dispatch );
			if ( null !== $job_id ) {
				$started[] = $job_id;
			}
		}
		return $started;
	}

	/**
	 * Starts a schedule's profile right now (manual run).
	 *
	 * @param array<string, mixed> $schedule
	 */
	public function start( array $schedule, string $trigger, bool $dispatch = true ): ?string {
		$id      = (int) $schedule['id'];
		$profile = $this->profiles->find( (int) $schedule['profile_id'] );
		if ( null === $profile ) {
			$this->schedules->update(
				$id,
				array(
					'last_status' => 'failed',
					'last_error'  => 'Profile not found.',
				)
			);
			return null;
		}
		try {
			$job = $this->jobs->start( $profile, $trigger, $id, $dispatch );
		} catch ( SwbiException $exception ) {
			$this->schedules->update(
				$id,
				array(
					'last_status' => 'skipped',
					'last_error'  => $exception->getMessage(),
				)
			);
			return null;
		}
		$this->schedules->update(
			$id,
			array(
				'last_run_at' => Time::to_db( Time::now() ),
				'last_status' => 'running',
				'last_error'  => null,
			)
		);
		return $job->id;
	}
}
