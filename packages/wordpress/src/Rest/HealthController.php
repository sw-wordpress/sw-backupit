<?php
/**
 * Health overview for the dashboard.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Rest;

use SWBackupIt\WordPress\Runner\CronManager;
use SWBackupIt\WordPress\Support\Capabilities;
use SWBackupIt\WordPress\Support\Settings;
use SWBackupIt\WordPress\Support\Time;

final class HealthController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/health',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get' ),
				'permission_callback' => $this->can( Capabilities::VIEW ),
			)
		);
	}

	public function get() {
		$checks   = array();
		$warnings = array();
		$add      = static function ( string $id, string $label, string $status, string $message ) use ( &$checks, &$warnings ): void {
			$checks[] = compact( 'id', 'label', 'status', 'message' );
			if ( 'ok' !== $status ) {
				$warnings[] = $label . ': ' . $message;
			}
		};

		$add( 'php', 'PHP', 'ok', PHP_VERSION );
		foreach ( array(
			'zip'       => 'error',
			'zlib'      => 'error',
			'sodium'    => 'warning',
			'pdo_mysql' => 'error',
		) as $extension => $level ) {
			$loaded = extension_loaded( $extension );
			$add(
				'ext_' . $extension,
				sprintf( 'PHP extension %s', $extension ),
				$loaded ? 'ok' : $level,
				$loaded ? __( 'Available', 'swbackupit' ) : ( 'sodium' === $extension ? __( 'Missing: encryption is unavailable.', 'swbackupit' ) : __( 'Missing: backups will fail.', 'swbackupit' ) )
			);
		}
		$staging  = Settings::staging_dir();
		$writable = is_dir( $staging ) ? wp_is_writable( $staging ) : wp_is_writable( dirname( $staging ) );
		$add( 'staging_writable', __( 'Staging directory', 'swbackupit' ), $writable ? 'ok' : 'error', $writable ? __( 'Writable', 'swbackupit' ) : __( 'Not writable', 'swbackupit' ) );
		if ( Settings::is_inside_docroot( dirname( $staging ) ) || Settings::is_inside_docroot( $staging ) ) {
			$add( 'staging_public', __( 'Staging location', 'swbackupit' ), 'warning', __( 'The staging directory is inside the web root. Make sure the web server denies access or choose a directory outside the web root in the settings.', 'swbackupit' ) );
		}
		$free = is_dir( $staging ) ? @disk_free_space( $staging ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unsupported on some hosts, handled below.
		if ( false !== $free ) {
			$add( 'disk', __( 'Free disk space', 'swbackupit' ), $free < 536870912 ? 'warning' : 'ok', size_format( (float) $free ) );
		}

		$wp_cron_disabled = CronManager::is_wp_cron_disabled();
		$last_tick        = (int) get_option( CronManager::TICK_OPTION, 0 );
		$cron_notice      = __( 'WP-Cron only runs when your site receives visitors. For reliable schedules use a real system cron job: * * * * * wp swbackupit schedule run-due', 'swbackupit' );

		$backups = $this->services()->backups()->totals();
		$last    = $this->services()->backups()->search( array(), 1, 1 )['items'];
		$failed  = $this->services()->jobs()->list( array( 'failed' ), 1 );
		$next    = $this->services()->schedules()->next_planned();

		$overdue = $this->overdue_schedules();
		if ( array() !== $overdue ) {
			$warnings[] = sprintf( /* translators: %s: schedule names */ __( 'Overdue schedules: %s', 'swbackupit' ), implode( ', ', $overdue ) );
		}
		$status = 'ok';
		foreach ( $checks as $check ) {
			if ( 'error' === $check['status'] ) {
				$status = 'error';
				break;
			}
			if ( 'warning' === $check['status'] ) {
				$status = 'warning';
			}
		}
		if ( 'ok' === $status && ( array() !== $overdue || ( array() !== $failed && ( array() === $last || strtotime( $failed[0]->updatedAt->format( 'c' ) ) > strtotime( $last[0]['created_at'] . ' UTC' ) ) ) ) ) {
			$status = 'warning';
		}

		return new \WP_REST_Response(
			array(
				'status'          => $status,
				'checks'          => $checks,
				'last_success'    => array() === $last ? null : array(
					'backup_id'   => $last[0]['id'],
					'finished_at' => Time::to_iso( $last[0]['created_at'] ),
				),
				'last_failure'    => array() === $failed ? null : array(
					'job_id'      => $failed[0]->id,
					'finished_at' => $failed[0]->finishedAt?->format( 'Y-m-d\TH:i:s\Z' ),
					'error'       => $failed[0]->error['message'] ?? '',
				),
				'next_run'        => null === $next ? null : array(
					'schedule_id'   => (int) $next['id'],
					'schedule_name' => (string) $next['name'],
					'at'            => Time::to_iso( $next['next_run_at'] ),
				),
				'backup_count'    => $backups['count'],
				'storage_bytes'   => $backups['size'],
				'storage_targets' => array(),
				'warnings'        => $warnings,
				'cron'            => array(
					'mode'             => (string) Settings::all()['cron']['mode'],
					'wp_cron_disabled' => $wp_cron_disabled,
					'last_tick_at'     => $last_tick > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $last_tick ) : null,
					'notice'           => $cron_notice,
				),
			)
		);
	}

	/**
	 * @return list<string>
	 */
	private function overdue_schedules(): array {
		$names = array();
		$limit = Time::now()->modify( '-1 hour' );
		foreach ( $this->services()->schedules()->due( $limit ) as $schedule ) {
			$names[] = (string) $schedule['name'];
		}
		return $names;
	}
}
