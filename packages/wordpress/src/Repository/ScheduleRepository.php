<?php
/**
 * Schedule persistence.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Repository;

use DateTimeImmutable;
use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Support\Time;

final class ScheduleRepository {

	private function table(): string {
		return Schema::table( 'schedules' );
	}

	/**
	 * @return list<array<string, mixed>> Raw rows.
	 */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function due( DateTimeImmutable $now ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE enabled = 1 AND next_run_at IS NOT NULL AND next_run_at <= %s ORDER BY next_run_at ASC", Time::to_db( $now ) ),
			ARRAY_A
		);
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * @param array<string, mixed> $data Validated schedule fields.
	 */
	public function create( array $data ): int {
		global $wpdb;
		$now = Time::to_db( Time::now() );
		$wpdb->insert( $this->table(), $data + array( 'created_at' => $now, 'updated_at' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( $this->table(), $data + array( 'updated_at' => Time::to_db( Time::now() ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function delete( int $id ): bool {
		global $wpdb;
		return 1 === $wpdb->delete( $this->table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Next planned run of any enabled schedule (dashboard). */
	public function next_planned(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM {$this->table()} WHERE enabled = 1 AND next_run_at IS NOT NULL ORDER BY next_run_at ASC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : null;
	}

	/**
	 * REST representation.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public function to_rest( array $row ): array {
		return array(
			'id'              => (int) $row['id'],
			'name'            => (string) $row['name'],
			'profile_id'      => (int) $row['profile_id'],
			'enabled'         => (bool) $row['enabled'],
			'frequency'       => (string) $row['frequency'],
			'cron_expression' => (string) $row['cron_expression'],
			'timezone'        => (string) $row['timezone'],
			'time_of_day'     => (string) $row['time_of_day'],
			'weekday'         => (int) $row['weekday'],
			'monthday'        => (int) $row['monthday'],
			'retry'           => array(
				'max_attempts'    => (int) $row['retry_max'],
				'backoff_seconds' => (int) $row['retry_backoff'],
			),
			'next_run_at'     => Time::to_iso( $row['next_run_at'] ),
			'last_run_at'     => Time::to_iso( $row['last_run_at'] ),
			'last_status'     => $row['last_status'],
			'last_error'      => $row['last_error'],
		);
	}
}
