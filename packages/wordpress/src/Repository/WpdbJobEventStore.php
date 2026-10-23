<?php
/**
 * Job log persistence (swbi_job_events) with bounded size per job.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from Schema::table() (fixed allowlist + $wpdb->prefix), never from input.

namespace SWBackupIt\WordPress\Repository;

use SWBackupIt\Core\Log\JobEventStoreInterface;
use SWBackupIt\Core\Support\Uuid;
use SWBackupIt\WordPress\Database\Schema;

final class WpdbJobEventStore implements JobEventStoreInterface {

	private const SEVERITIES = array(
		'debug'   => 0,
		'info'    => 1,
		'notice'  => 2,
		'warning' => 3,
		'error'   => 4,
	);

	public function __construct( private readonly int $max_events = 5000 ) {
	}

	public function append( string $jobId, string $severity, string $phase, string $message, array $context = array() ): void {
		global $wpdb;
		if ( ! Uuid::isValid( $jobId ) ) {
			return;
		}
		$table = Schema::table( 'job_events' );
		$seq   = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(seq),0) FROM {$table} WHERE job_id = %s", $jobId ) ); // phpcs:ignore WordPress.DB
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'job_id'     => $jobId,
				'seq'        => $seq,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'severity'   => $severity,
				'phase'      => $phase,
				'message'    => $message,
				'context'    => array() === $context ? null : wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ),
			)
		);
		if ( 0 === $seq % 250 ) {
			$this->prune( $jobId );
		}
	}

	public function read( string $jobId, int $afterId = 0, int $limit = 200, ?string $minSeverity = null, ?string $phase = null ): array {
		global $wpdb;
		$table = Schema::table( 'job_events' );
		$min   = self::SEVERITIES[ $minSeverity ?? 'debug' ] ?? 0;
		$sql   = "SELECT seq, created_at, severity, phase, message, context FROM {$table} WHERE job_id = %s AND seq > %d";
		$args  = array( $jobId, $afterId );
		if ( null !== $phase && '' !== $phase ) {
			$sql   .= ' AND phase = %s';
			$args[] = $phase;
		}
		$levels = array_keys( array_filter( self::SEVERITIES, static fn ( int $rank ): bool => $rank >= $min ) );
		$sql   .= ' AND severity IN (' . implode( ',', array_fill( 0, count( $levels ), '%s' ) ) . ')';
		$args   = array_merge( $args, $levels );
		$sql   .= ' ORDER BY seq ASC LIMIT %d';
		$args[] = max( 1, min( 1000, $limit ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$items  = array();
		$last   = $afterId;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$last    = (int) $row['seq'];
			$context = is_string( $row['context'] ) ? json_decode( $row['context'], true ) : array();
			$items[] = array(
				'id'       => (int) $row['seq'],
				'time'     => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $row['created_at'] . ' UTC' ) ),
				'severity' => $row['severity'],
				'phase'    => $row['phase'],
				'message'  => $row['message'],
				'context'  => is_array( $context ) ? $context : array(),
			);
		}
		return array(
			'items'   => $items,
			'last_id' => $last,
		);
	}

	/** Keeps the newest events; warnings and errors survive longer than debug/info. */
	private function prune( string $jobId ): void {
		global $wpdb;
		$table = Schema::table( 'job_events' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE job_id = %s", $jobId ) ); // phpcs:ignore WordPress.DB
		if ( $count <= $this->max_events ) {
			return;
		}
		$excess = $count - (int) ( $this->max_events * 0.8 );
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE job_id = %s AND severity IN ('debug','info','notice') ORDER BY seq ASC LIMIT %d",
				$jobId,
				$excess
			)
		);
	}

	/** Deletes events of finished jobs older than the retention period. */
	public static function purge_older_than( int $days ): int {
		global $wpdb;
		$table = Schema::table( 'job_events' );
		$jobs  = Schema::table( 'jobs' );
		$limit = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$res   = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"DELETE e FROM {$table} e INNER JOIN {$jobs} j ON j.id = e.job_id WHERE j.finished_at IS NOT NULL AND j.finished_at < %s",
				$limit
			)
		);
		return is_int( $res ) ? $res : 0;
	}
}
