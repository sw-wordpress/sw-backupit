<?php
/**
 * Copies or moves stored backups to another storage target in the background.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Runner;

use SWBackupIt\Core\Backup\BackupLayout;
use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Exception\TransientException;
use SWBackupIt\Core\Manifest\Manifest;
use SWBackupIt\Core\Storage\BackupTransfer;
use SWBackupIt\Core\Storage\StorageFactory;
use SWBackupIt\Core\Storage\StorageTarget;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\ResourceBudget;
use SWBackupIt\Core\Support\Uuid;
use SWBackupIt\WordPress\Repository\BackupRepository;
use SWBackupIt\WordPress\Repository\StorageTargetRepository;
use SWBackupIt\WordPress\Support\CoreMessages;
use SWBackupIt\WordPress\Support\Options;
use SWBackupIt\WordPress\Support\PluginLogger;
use SWBackupIt\WordPress\Support\Settings;
use SWBackupIt\WordPress\Support\Time;

/**
 * Transfers are queued in a network wide option and processed by WP-Cron (or the system cron / WP-CLI) in time-boxed
 * ticks; every finished file is a checkpoint, so a killed worker continues where it stopped. Base backups of an
 * incremental/differential chain that are missing on the destination are copied first (never moved: other backups on
 * the source may need them). A move removes the source copy only after the destination copy is verified.
 */
final class TransferService {

	public const OPTION = 'swbi_transfers';
	public const HOOK   = 'swbi_run_transfer';
	public const LOCAL  = 0;

	/** Transient storage errors are retried this many times before the transfer fails. */
	private const MAX_ATTEMPTS = 5;

	/** Finished transfers kept for display. */
	private const KEEP_FINISHED = 20;

	/**
	 * Whether the last tick found another worker running.
	 *
	 * @var bool
	 */
	private bool $busy = false;

	public function __construct(
		private readonly BackupRepository $backups,
		private readonly StorageTargetRepository $targets
	) {
	}

	/**
	 * Validates and queues a transfer.
	 *
	 * @param string   $backup_id Backup id.
	 * @param int      $target_id Destination storage target.
	 * @param int|null $source_id Source location (0 = local staging), null = local copy when available, else a verified target.
	 * @param bool     $move      Remove the source copy after the destination copy is verified.
	 * @return array<string, mixed> The queued transfer.
	 * @throws ConfigurationException When the transfer is not possible (message is translated).
	 * @throws SwbiException With conflict context when the backup is already being transferred.
	 */
	public function start( string $backup_id, int $target_id, ?int $source_id, bool $move ): array {
		$row = $this->backups->find_row( $backup_id );
		if ( null === $row ) {
			throw new ConfigurationException( __( 'Backup not found.', 'sw-backupit' ) );
		}
		if ( 'complete' !== (string) $row['status'] ) {
			throw new ConfigurationException( __( 'Only complete, intact backups can be copied or moved.', 'sw-backupit' ) );
		}
		$destination = $this->targets->find( $target_id );
		if ( null === $destination ) {
			throw new ConfigurationException( __( 'The storage target does not exist.', 'sw-backupit' ) );
		}
		if ( $this->is_verified_on( $backup_id, $target_id ) ) {
			throw new SwbiException( __( 'The backup is already stored on this storage target.', 'sw-backupit' ), array( 'conflict' => true ) );
		}
		$source_id = $source_id ?? $this->default_source( $backup_id );
		if ( null === $source_id || ! $this->is_available( $backup_id, $source_id ) ) {
			throw new ConfigurationException( __( 'The selected source holds no verified copy of the backup.', 'sw-backupit' ) );
		}
		if ( $source_id === $target_id ) {
			throw new ConfigurationException( __( 'Source and destination must be different.', 'sw-backupit' ) );
		}
		if ( $move && self::LOCAL !== $source_id ) {
			$source = $this->targets->find( $source_id );
			if ( null === $source || null !== self::removal_blocker( $source, (string) $row['created_at'] ) ) {
				throw new ConfigurationException( __( 'The backup cannot be removed from the source (immutable storage target or retention lock). Copy it instead.', 'sw-backupit' ) );
			}
		}

		$items = array();
		$total = 0;
		foreach ( $this->chain( $row ) as $base_id ) {
			if ( $this->is_verified_on( $base_id, $target_id ) ) {
				continue;
			}
			$base_source = $this->is_available( $base_id, $source_id ) ? $source_id : $this->default_source( $base_id );
			$base_row    = $this->backups->find_row( $base_id );
			if ( null === $base_source || null === $base_row ) {
				/* translators: %s: backup id */
				throw new ConfigurationException( sprintf( __( 'The base backup %s has no copy that can be transferred.', 'sw-backupit' ), $base_id ) );
			}
			$items[] = self::item( $base_id, $base_source, (int) $base_row['size_bytes'] );
			$total  += (int) $base_row['size_bytes'];
		}
		$items[] = self::item( $backup_id, $source_id, (int) $row['size_bytes'] );
		$total  += (int) $row['size_bytes'];

		$transfer = array(
			'id'          => substr( str_replace( '-', '', Uuid::v4() ), 0, 12 ),
			'backup_id'   => $backup_id,
			'source_id'   => $source_id,
			'target_id'   => $target_id,
			'mode'        => $move ? 'move' : 'copy',
			'status'      => 'queued',
			'error'       => null,
			'items'       => $items,
			'bytes_total' => $total,
			'bytes_done'  => 0,
			'attempts'    => 0,
			'user_id'     => get_current_user_id(),
			'created_at'  => time(),
			'updated_at'  => time(),
		);
		$this->mutate(
			static function ( array $transfers ) use ( $transfer, $backup_id ): array {
				foreach ( $transfers as $other ) {
					if ( $other['backup_id'] === $backup_id && in_array( $other['status'], array( 'queued', 'running' ), true ) ) {
						throw new SwbiException( __( 'The backup is already being copied or moved.', 'sw-backupit' ), array( 'conflict' => true ) );
					}
				}
				$transfers[ $transfer['id'] ] = $transfer;
				return $transfers;
			}
		);
		do_action( 'swbi_backup_transfer_started', $backup_id, $transfer['mode'], $target_id );
		$this->dispatch();
		return $transfer;
	}

	/**
	 * @return list<array<string, mixed>> Transfers, newest first (REST representation).
	 */
	public function all(): array {
		$transfers = array_values( (array) Options::get_fresh( self::OPTION, array() ) );
		usort( $transfers, static fn ( array $a, array $b ): int => (int) $b['created_at'] <=> (int) $a['created_at'] );
		return array_map( array( $this, 'to_rest' ), $transfers );
	}

	public function has_pending(): bool {
		foreach ( (array) Options::get_fresh( self::OPTION, array() ) as $transfer ) {
			if ( in_array( $transfer['status'], array( 'queued', 'running' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Cancels a queued or running transfer; the incomplete copy on the destination is removed by the worker.
	 */
	public function cancel( string $id ): bool {
		$found = false;
		$this->mutate(
			static function ( array $transfers ) use ( $id, &$found ): array {
				if ( isset( $transfers[ $id ] ) && in_array( $transfers[ $id ]['status'], array( 'queued', 'running' ), true ) ) {
					$transfers[ $id ]['status']     = 'cancelled';
					$transfers[ $id ]['updated_at'] = time();
					$found                          = true;
				}
				return $transfers;
			}
		);
		if ( $found ) {
			$this->dispatch();
		}
		return $found;
	}

	/** Queues a worker run and nudges WP-Cron. */
	public function dispatch( int $delay = 0 ): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK );
		}
		if ( 0 === $delay && ! CronManager::is_wp_cron_disabled() && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Processes queued transfers within the time budget. Only one worker runs at a time.
	 *
	 * @param float|null $budget_seconds Time budget (default: the global tick budget).
	 * @return bool Whether work remains.
	 */
	public function tick( ?float $budget_seconds = null ): bool {
		global $wpdb;
		$lock       = 'swbi_transfer_' . substr( md5( DB_NAME . '|' . $wpdb->base_prefix ), 0, 16 );
		$this->busy = '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $this->busy ) {
			return true;
		}
		try {
			$budget = new ResourceBudget( $budget_seconds ?? (float) Settings::all()['limits']['time_budget_seconds'] );
			$this->cleanup_cancelled();
			while ( ! $budget->exhausted() ) {
				$transfer = $this->next();
				if ( null === $transfer ) {
					return false;
				}
				if ( ! $this->process( $transfer, $budget ) ) {
					break; // A temporary storage error: retry later instead of spinning.
				}
			}
			return $this->has_pending();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/** Whether the last tick found another worker running. */
	public function was_busy(): bool {
		return $this->busy;
	}

	/** WP-Cron entry point: one tick, re-queued while work remains. */
	public function run_in_cron(): void {
		if ( $this->tick() ) {
			// Another worker holds the lock: check again later instead of re-spawning WP-Cron right away.
			$this->dispatch( $this->busy ? 60 : $this->retry_delay() );
		}
	}

	/**
	 * Reason why the backup cannot be removed from a storage target, or null.
	 *
	 * @param StorageTarget $target     Storage target.
	 * @param string        $created_at Backup creation time (database format).
	 */
	public static function removal_blocker( StorageTarget $target, string $created_at ): ?string {
		if ( $target->immutable ) {
			return 'immutable';
		}
		$lock_days = (int) ( $target->config['lock_days'] ?? 0 );
		$created   = Time::from_db( $created_at );
		if ( $lock_days > 0 && null !== $created && $created > Time::now()->modify( '-' . $lock_days . ' days' ) ) {
			return 'locked';
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $transfer
	 * @return bool False when the transfer waits for a retry after a temporary error.
	 */
	private function process( array $transfer, ResourceBudget $budget ): bool {
		$id = (string) $transfer['id'];
		$this->update( $id, array( 'status' => 'running' ) );
		try {
			foreach ( $transfer['items'] as $index => $item ) {
				if ( 'done' === $item['status'] ) {
					continue;
				}
				if ( ! $this->copy_item( $transfer, (int) $index, $budget ) ) {
					return true;
				}
				$transfer = $this->find( $id ) ?? $transfer;
			}
			if ( 'move' === $transfer['mode'] ) {
				$this->remove_source( (string) $transfer['backup_id'], (int) $transfer['source_id'] );
			}
			$this->update(
				$id,
				array(
					'status'     => 'completed',
					'error'      => null,
					'bytes_done' => (int) $transfer['bytes_total'],
				)
			);
			do_action( 'swbi_backup_transferred', (string) $transfer['backup_id'], (string) $transfer['mode'], (int) $transfer['target_id'] );
		} catch ( TransferCancelled ) {
			return true;
		} catch ( TransientException $exception ) {
			$attempts = (int) ( $transfer['attempts'] ?? 0 ) + 1;
			$this->update(
				$id,
				array(
					'status'   => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'running',
					'attempts' => $attempts,
					'error'    => CoreMessages::translate( $exception->getMessage() ),
				)
			);
			( new PluginLogger() )->warning(
				'Backup transfer failed temporarily.',
				array(
					'transfer' => $id,
					'attempt'  => $attempts,
				)
			);
			return $attempts >= self::MAX_ATTEMPTS;
		} catch ( \Throwable $throwable ) {
			$message = $throwable instanceof SwbiException ? CoreMessages::translate( $throwable->getMessage() ) : __( 'An unexpected error occurred.', 'sw-backupit' );
			$this->update(
				$id,
				array(
					'status' => 'failed',
					'error'  => $message,
				)
			);
			( new PluginLogger() )->error(
				'Backup transfer failed.',
				array(
					'transfer'  => $id,
					'exception' => $throwable::class,
				)
			);
			do_action( 'swbi_backup_transfer_failed', (string) $transfer['backup_id'], $message );
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $transfer Transfer.
	 * @param int                  $index    Item to copy.
	 * @param ResourceBudget       $budget   Time budget of this tick.
	 * @return bool Whether the item is finished (false: budget exhausted).
	 * @throws ConfigurationException When a storage target was deleted meanwhile (TransferCancelled when the user cancelled).
	 */
	private function copy_item( array $transfer, int $index, ResourceBudget $budget ): bool {
		$id        = (string) $transfer['id'];
		$item      = $transfer['items'][ $index ];
		$backup_id = (string) $item['backup_id'];
		$source_id = (int) $item['source_id'];
		$factory   = new StorageFactory();
		$target    = $this->targets->find( (int) $transfer['target_id'] ) ?? throw new ConfigurationException( 'The storage target does not exist.' );
		$local     = self::LOCAL === $source_id ? Settings::staging_dir() . '/' . $backup_id : null;
		$source    = null;
		if ( null === $local ) {
			$source = $factory->create( $this->targets->find( $source_id ) ?? throw new ConfigurationException( 'The storage target does not exist.' ) );
		}
		$done_before = 0;
		foreach ( $transfer['items'] as $other ) {
			$done_before += 'done' === $other['status'] ? (int) $other['size'] : 0;
		}
		$state = ( new BackupTransfer( $this->work_directory( $id ) ) )->run(
			$backup_id,
			$local,
			$source,
			$factory->create( $target ),
			(array) $item['state'],
			$budget,
			function ( array $state ) use ( $id, $index, $done_before ): void {
				$current = $this->find( $id );
				if ( null === $current || 'cancelled' === $current['status'] ) {
					throw new TransferCancelled();
				}
				$current['items'][ $index ]['state'] = $state;
				$this->update(
					$id,
					array(
						'items'      => $current['items'],
						'bytes_done' => $done_before + (int) $state['bytes_done'],
						'current'    => $current['items'][ $index ]['backup_id'],
						'attempts'   => 0,
					)
				);
			}
		);
		if ( ! (bool) $state['finished'] ) {
			return false;
		}
		$this->backups->set_location( $backup_id, (int) $transfer['target_id'], 'verified', '', $backup_id );
		$current                              = $this->find( $id ) ?? $transfer;
		$current['items'][ $index ]['status'] = 'done';
		$current['items'][ $index ]['state']  = array();
		$this->update( $id, array( 'items' => $current['items'] ) );
		AtomicFile::removeTree( $this->work_directory( $id ) );
		return true;
	}

	/**
	 * Removes the source copy of a moved backup (the destination copy is verified at this point).
	 *
	 * @param string $backup_id Backup id.
	 * @param int    $source_id Source location (0 = local staging).
	 * @throws ConfigurationException When the source copy must be retained.
	 */
	private function remove_source( string $backup_id, int $source_id ): void {
		if ( self::LOCAL === $source_id ) {
			$layout = new BackupLayout( Settings::staging_dir(), $backup_id );
			$layout->removeData( Manifest::read( $layout->root() ) );
		} else {
			$source = $this->targets->find( $source_id );
			$row    = $this->backups->find_row( $backup_id );
			if ( null !== $source && null !== $row ) {
				if ( null !== self::removal_blocker( $source, (string) $row['created_at'] ) ) {
					throw new ConfigurationException( __( 'The backup was copied, but it cannot be removed from the source (immutable storage target or retention lock).', 'sw-backupit' ) );
				}
				( new StorageFactory() )->create( $source )->deleteDirectory( $backup_id );
			}
		}
		$this->backups->remove_location( $backup_id, $source_id );
	}

	/** Removes incomplete destination copies of cancelled transfers and marks them finished. */
	private function cleanup_cancelled(): void {
		foreach ( (array) Options::get_fresh( self::OPTION, array() ) as $transfer ) {
			if ( 'cancelled' !== $transfer['status'] || ! empty( $transfer['cleaned'] ) ) {
				continue;
			}
			$target = $this->targets->find( (int) $transfer['target_id'] );
			foreach ( $transfer['items'] as $item ) {
				if ( 'done' !== $item['status'] && array() !== (array) $item['state'] && null !== $target ) {
					try {
						( new StorageFactory() )->create( $target )->deleteDirectory( (string) $item['backup_id'] );
					} catch ( \Throwable $throwable ) {
						( new PluginLogger() )->warning( 'Incomplete copy of a cancelled transfer could not be removed.', array( 'transfer' => $transfer['id'] ) );
					}
				}
			}
			AtomicFile::removeTree( $this->work_directory( (string) $transfer['id'] ) );
			$this->update( (string) $transfer['id'], array( 'cleaned' => true ) );
		}
	}

	/**
	 * @return array<string, mixed>|null The oldest queued or running transfer.
	 */
	private function next(): ?array {
		$open = array_filter(
			(array) Options::get_fresh( self::OPTION, array() ),
			static fn ( array $transfer ): bool => in_array( $transfer['status'], array( 'queued', 'running' ), true )
		);
		usort( $open, static fn ( array $a, array $b ): int => (int) $a['created_at'] <=> (int) $b['created_at'] );
		return $open[0] ?? null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function find( string $id ): ?array {
		$transfers = (array) Options::get_fresh( self::OPTION, array() );
		return $transfers[ $id ] ?? null;
	}

	/**
	 * @param string               $id      Transfer id.
	 * @param array<string, mixed> $changes Fields to change.
	 */
	private function update( string $id, array $changes ): void {
		$this->mutate(
			static function ( array $transfers ) use ( $id, $changes ): array {
				if ( isset( $transfers[ $id ] ) ) {
					// A cancellation by the user wins over the worker's progress.
					if ( 'cancelled' === $transfers[ $id ]['status'] && isset( $changes['status'] ) ) {
						unset( $changes['status'] );
					}
					$transfers[ $id ] = array_merge( $transfers[ $id ], $changes, array( 'updated_at' => time() ) );
				}
				return $transfers;
			}
		);
	}

	/**
	 * Read-modify-write of the transfer list under a database lock (REST requests and the worker change it concurrently).
	 *
	 * @param callable $change Receives the transfer list (id => transfer) and returns the changed list.
	 * @throws TransientException When the lock cannot be acquired.
	 */
	private function mutate( callable $change ): void {
		global $wpdb;
		$lock = 'swbi_transfers_' . substr( md5( DB_NAME . '|' . $wpdb->base_prefix ), 0, 16 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw new TransientException( 'The transfer list is locked by another process.' );
		}
		try {
			$transfers = $change( (array) Options::get_fresh( self::OPTION, array() ) );
			Options::update( self::OPTION, self::prune( $transfers ), false );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Keeps all open transfers and the newest finished ones.
	 *
	 * @param array<string, array<string, mixed>> $transfers
	 * @return array<string, array<string, mixed>>
	 */
	private static function prune( array $transfers ): array {
		$finished = array_filter( $transfers, static fn ( array $t ): bool => ! in_array( $t['status'], array( 'queued', 'running' ), true ) && ( 'cancelled' !== $t['status'] || ! empty( $t['cleaned'] ) ) );
		uasort( $finished, static fn ( array $a, array $b ): int => (int) $b['updated_at'] <=> (int) $a['updated_at'] );
		foreach ( array_slice( array_keys( $finished ), self::KEEP_FINISHED ) as $old ) {
			unset( $transfers[ $old ] );
		}
		return $transfers;
	}

	/**
	 * @param array<string, mixed> $transfer
	 * @return array<string, mixed>
	 */
	public function to_rest( array $transfer ): array {
		$names = array( self::LOCAL => __( 'Local staging', 'sw-backupit' ) );
		foreach ( array( (int) $transfer['source_id'], (int) $transfer['target_id'] ) as $target_id ) {
			if ( ! isset( $names[ $target_id ] ) ) {
				$names[ $target_id ] = $this->targets->find( $target_id )->name ?? '#' . $target_id;
			}
		}
		return array(
			'id'          => (string) $transfer['id'],
			'backup_id'   => (string) $transfer['backup_id'],
			'mode'        => (string) $transfer['mode'],
			'status'      => (string) $transfer['status'],
			'error'       => $transfer['error'],
			'source_id'   => (int) $transfer['source_id'],
			'source_name' => $names[ (int) $transfer['source_id'] ],
			'target_id'   => (int) $transfer['target_id'],
			'target_name' => $names[ (int) $transfer['target_id'] ],
			'backups'     => array_map( static fn ( array $item ): string => (string) $item['backup_id'], $transfer['items'] ),
			'bytes_total' => (int) $transfer['bytes_total'],
			'bytes_done'  => min( (int) $transfer['bytes_done'], (int) $transfer['bytes_total'] ),
			'created_at'  => gmdate( 'c', (int) $transfer['created_at'] ),
			'updated_at'  => gmdate( 'c', (int) $transfer['updated_at'] ),
		);
	}

	/**
	 * @param array<string, mixed> $row Catalog row.
	 * @return list<string> Base backups the backup needs, oldest first.
	 */
	private function chain( array $row ): array {
		$meta = is_string( $row['meta'] ) ? json_decode( $row['meta'], true ) : array();
		$ids  = is_array( $meta ) && is_array( $meta['requires'] ?? null ) ? $meta['requires'] : array();
		return array_values( array_filter( array_unique( array_map( 'strval', $ids ) ), array( Uuid::class, 'isValid' ) ) );
	}

	private function default_source( string $backup_id ): ?int {
		if ( $this->is_available( $backup_id, self::LOCAL ) ) {
			return self::LOCAL;
		}
		foreach ( $this->backups->locations( $backup_id ) as $location ) {
			if ( 0 !== (int) $location['target_id'] && 'verified' === $location['status'] && null !== $this->targets->find( (int) $location['target_id'] ) ) {
				return (int) $location['target_id'];
			}
		}
		return null;
	}

	private function is_available( string $backup_id, int $source_id ): bool {
		if ( self::LOCAL === $source_id ) {
			return is_file( Settings::staging_dir() . '/' . $backup_id . '/' . Manifest::FILE_NAME ) && BackgroundChecks::has_local_data( $backup_id );
		}
		return $this->is_verified_on( $backup_id, $source_id ) && null !== $this->targets->find( $source_id );
	}

	private function is_verified_on( string $backup_id, int $target_id ): bool {
		foreach ( $this->backups->locations( $backup_id ) as $location ) {
			if ( (int) $location['target_id'] === $target_id && 'verified' === $location['status'] ) {
				return true;
			}
		}
		return false;
	}

	private function work_directory( string $id ): string {
		return Settings::staging_dir() . '/tmp/transfer-' . preg_replace( '/[^a-z0-9]/', '', $id );
	}

	private function retry_delay(): int {
		foreach ( (array) Options::get_fresh( self::OPTION, array() ) as $transfer ) {
			if ( 'running' === $transfer['status'] && (int) ( $transfer['attempts'] ?? 0 ) > 0 ) {
				return 60;
			}
		}
		return 0;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function item( string $backup_id, int $source_id, int $size ): array {
		return array(
			'backup_id' => $backup_id,
			'source_id' => $source_id,
			'size'      => $size,
			'status'    => 'pending',
			'state'     => array(),
		);
	}
}
