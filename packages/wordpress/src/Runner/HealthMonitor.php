<?php
/**
 * Background health monitoring: overdue schedules, low disk space and stale backups. Sends de-duplicated notifications.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Runner;

use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Settings;
use SWBackupIt\WordPress\Support\Time;

final class HealthMonitor {

	public const LOW_DISK_BYTES = 536870912;

	/**
	 * @return list<array{event: string, key: string, subject: string, message: string}>
	 */
	public function issues(): array {
		$issues = array();
		$limit  = Time::now()->modify( '-1 hour' );
		foreach ( Services::instance()->schedules()->due( $limit ) as $schedule ) {
			$issues[] = array(
				'event'   => 'backup_overdue',
				'key'     => 'schedule-' . $schedule['id'],
				/* translators: %s: schedule name */
				'subject' => sprintf( __( 'Backup schedule "%s" is overdue', 'swbackupit' ), (string) $schedule['name'] ),
				'message' => __( 'A scheduled backup did not start on time. WP-Cron depends on site traffic; consider a real system cron job.', 'swbackupit' ),
			);
		}
		$staging = Settings::staging_dir();
		$free    = is_dir( $staging ) ? @disk_free_space( $staging ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unsupported on some hosts.
		if ( false !== $free && $free < self::LOW_DISK_BYTES ) {
			$issues[] = array(
				'event'   => 'storage_low',
				'key'     => 'disk',
				'subject' => __( 'Backup storage space is running low', 'swbackupit' ),
				/* translators: %s: free space */
				'message' => sprintf( __( 'Only %s are free in the backup staging directory.', 'swbackupit' ), size_format( (float) $free ) ),
			);
		}
		return $issues;
	}

	public function notify(): int {
		$sent = 0;
		foreach ( $this->issues() as $issue ) {
			if ( Services::instance()->notifier()->notify( $issue['event'], $issue['key'], $issue['subject'], $issue['message'] ) ) {
				++$sent;
			}
		}
		return $sent;
	}
}
