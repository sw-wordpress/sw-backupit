<?php
/**
 * WP-Cron integration: background job execution and schedule watcher.
 *
 * WP-Cron depends on site traffic; the UI explains this and recommends a real system cron
 * (`wp swbackupit schedule run-due`). Nothing here requires a permanently running process.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Runner;

final class CronManager {

	public const HOOK_RUN_JOB   = 'swbi_run_job';
	public const HOOK_WATCH     = 'swbi_check_schedules';
	public const HOOK_MAINTAIN  = 'swbi_daily_maintenance';
	public const INTERVAL       = 'swbi_minute';
	public const TICK_OPTION    = 'swbi_last_cron_tick';

	/**
	 * @param array<string, array<string, mixed>> $schedules
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_interval( array $schedules ): array {
		$schedules[ self::INTERVAL ] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (SWBackupIt)', 'swbackupit' ),
		);
		return $schedules;
	}

	public function ensure_watcher(): void {
		if ( ! wp_next_scheduled( self::HOOK_WATCH ) ) {
			wp_schedule_event( time() + 30, self::INTERVAL, self::HOOK_WATCH );
		}
		if ( ! wp_next_scheduled( self::HOOK_MAINTAIN ) ) {
			wp_schedule_event( time() + 600, 'daily', self::HOOK_MAINTAIN );
		}
	}

	public function clear_all(): void {
		wp_clear_scheduled_hook( self::HOOK_WATCH );
		wp_clear_scheduled_hook( self::HOOK_MAINTAIN );
		wp_clear_scheduled_hook( self::HOOK_RUN_JOB );
	}

	/** Queues a background execution of a job (deduplicated) and nudges WP-Cron. */
	public function dispatch_job( string $job_id, int $delay = 0 ): void {
		if ( ! wp_next_scheduled( self::HOOK_RUN_JOB, array( $job_id ) ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK_RUN_JOB, array( $job_id ) );
		}
		if ( 0 === $delay && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	public static function is_wp_cron_disabled(): bool {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}
}
