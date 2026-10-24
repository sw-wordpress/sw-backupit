<?php
/**
 * Job persistence in the swbi_jobs table.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from Schema::table() (fixed allowlist + $wpdb->prefix), never from input.

namespace SWBackupIt\WordPress\Repository;

use DateTimeImmutable;
use SWBackupIt\Core\Exception\JobNotFoundException;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Job\Job;
use SWBackupIt\Core\Job\JobRepositoryInterface;
use SWBackupIt\Core\Support\Uuid;
use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Support\Time;

final class WpdbJobRepository implements JobRepositoryInterface {

	private const FLAG_CANCEL = 1;
	private const FLAG_PAUSE  = 2;

	private function table(): string {
		return Schema::table( 'jobs' );
	}

	public function create( Job $job ): void {
		global $wpdb;
		$row               = $this->to_row( $job );
		$row['id']         = $job->id;
		$row['created_at'] = Time::to_db( $job->createdAt );
		$row['created_by'] = get_current_user_id() > 0 ? get_current_user_id() : null;
		$inserted          = $wpdb->insert( $this->table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== $inserted ) {
			throw new SwbiException( 'Cannot create job.' );
		}
	}

	public function find( string $jobId ): Job {
		global $wpdb;
		if ( ! Uuid::isValid( $jobId ) ) {
			throw new JobNotFoundException( 'Job not found.' );
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %s", $jobId ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! is_array( $row ) ) {
			throw new JobNotFoundException( 'Job not found.', array( 'job' => $jobId ) );
		}
		return $this->from_row( $row );
	}

	public function save( Job $job ): void {
		global $wpdb;
		// Lease and flags are managed by dedicated statements and never overwritten here.
		$wpdb->update( $this->table(), $this->to_row( $job ), array( 'id' => $job->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function list( ?array $statuses = null, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$limit  = max( 1, min( 500, $limit ) );
		$offset = max( 0, $offset );
		$where  = '';
		if ( null !== $statuses && array() !== $statuses ) {
			$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$where        = $wpdb->prepare( "WHERE status IN ($placeholders)", $statuses ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
		}
		$sql  = $wpdb->prepare( "SELECT * FROM {$this->table()} $where ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset ); // phpcs:ignore WordPress.DB
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( fn ( array $row ): Job => $this->from_row( $row ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @param list<string>|null $statuses
	 */
	public function count( ?array $statuses = null ): int {
		global $wpdb;
		if ( null === $statuses || array() === $statuses ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" ); // phpcs:ignore WordPress.DB
		}
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE status IN ($placeholders)", $statuses ) ); // phpcs:ignore WordPress.DB
	}

	public function acquireLease( string $jobId, string $owner, int $ttlSeconds, DateTimeImmutable $now ): bool {
		global $wpdb;
		$expires = $now->modify( '+' . $ttlSeconds . ' seconds' );
		$result  = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$this->table()} SET lease_owner = %s, lease_expires_at = %s WHERE id = %s AND (lease_owner IS NULL OR lease_owner = %s OR lease_expires_at IS NULL OR lease_expires_at < %s)",
				$owner,
				Time::to_db( $expires ),
				$jobId,
				$owner,
				Time::to_db( $now )
			)
		);
		return 1 === $result;
	}

	public function renewLease( string $jobId, string $owner, int $ttlSeconds, DateTimeImmutable $now ): bool {
		global $wpdb;
		$result = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$this->table()} SET lease_expires_at = %s WHERE id = %s AND lease_owner = %s",
				Time::to_db( $now->modify( '+' . $ttlSeconds . ' seconds' ) ),
				$jobId,
				$owner
			)
		);
		if ( 1 === $result ) {
			return true;
		}
		// MySQL reports 0 affected rows when the value did not change (renewal within the same second): check ownership.
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT lease_owner FROM {$this->table()} WHERE id = %s", $jobId ) ); // phpcs:ignore WordPress.DB
		return 0 === $result && $owner === $current;
	}

	public function releaseLease( string $jobId, string $owner ): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$this->table()} SET lease_owner = NULL, lease_expires_at = NULL WHERE id = %s AND lease_owner = %s",
				$jobId,
				$owner
			)
		);
	}

	public function requestCancel( string $jobId ): void {
		$this->set_flag( $jobId, self::FLAG_CANCEL );
	}

	public function requestPause( string $jobId ): void {
		$this->set_flag( $jobId, self::FLAG_PAUSE );
	}

	public function clearFlags( string $jobId ): void {
		global $wpdb;
		$wpdb->update( $this->table(), array( 'flags' => 0 ), array( 'id' => $jobId ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function delete( string $jobId ): void {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'id' => $jobId ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'job_events' ), array( 'job_id' => $jobId ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function set_flag( string $jobId, int $flag ): void {
		global $wpdb;
		$result = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "UPDATE {$this->table()} SET flags = (flags | %d) WHERE id = %s", $flag, $jobId )
		);
		if ( false === $result ) {
			throw new SwbiException( 'Cannot update job.' );
		}
		$this->find( $jobId ); // Throws when the job does not exist.
	}

	/**
	 * @return array<string, mixed>
	 */
	private function to_row( Job $job ): array {
		return array(
			'backup_id'      => $job->backupId,
			'profile_id'     => $job->profileId,
			'schedule_id'    => $job->scheduleId,
			'trigger_source' => $job->trigger,
			'type'           => $job->type->value,
			'status'         => $job->status->value,
			'step'           => $job->step?->value,
			'data'           => (string) wp_json_encode( $job->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ),
			'updated_at'     => Time::to_db( $job->updatedAt ),
			'finished_at'    => Time::to_db( $job->finishedAt ),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @throws SwbiException When the stored data is corrupt.
	 */
	private function from_row( array $row ): Job {
		$data = json_decode( (string) $row['data'], true );
		if ( ! is_array( $data ) ) {
			throw new SwbiException( 'Stored job data is corrupt.', array( 'job' => (string) $row['id'] ) );
		}
		$job                  = Job::fromArray( $data );
		$flags                = (int) $row['flags'];
		$job->cancelRequested = (bool) ( $flags & self::FLAG_CANCEL );
		$job->pauseRequested  = (bool) ( $flags & self::FLAG_PAUSE );
		$job->leaseOwner      = is_string( $row['lease_owner'] ?? null ) ? $row['lease_owner'] : null;
		$job->leaseExpiresAt  = Time::from_db( is_string( $row['lease_expires_at'] ?? null ) ? $row['lease_expires_at'] : null );
		return $job;
	}
}
