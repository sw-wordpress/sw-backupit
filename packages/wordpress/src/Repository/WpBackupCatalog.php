<?php
/**
 * Backup catalog for retention in WordPress: catalog table + staging directory + all storage locations.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Repository;

use SWBackupIt\Core\Backup\BackupLayout;
use SWBackupIt\Core\Domain\BackupType;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Retention\BackupCatalogInterface;
use SWBackupIt\Core\Retention\BackupRecord;
use SWBackupIt\Core\Storage\StorageFactory;
use SWBackupIt\WordPress\Support\Settings;
use SWBackupIt\WordPress\Support\Time;

final class WpBackupCatalog implements BackupCatalogInterface {

	public function __construct(
		private readonly BackupRepository $backups,
		private readonly StorageTargetRepository $targets
	) {
	}

	public function records( ?int $profileId = null ): array {
		$records = array();
		$result  = $this->backups->search( null === $profileId ? array() : array( 'profile_id' => $profileId ), 1, 100000 );
		foreach ( $result['items'] as $row ) {
			$created   = Time::from_db( (string) $row['created_at'] );
			$type      = BackupType::tryFrom( (string) $row['type'] );
			$reliable  = null !== $created && null !== $type && 'complete' === $row['status'] && 'corrupt' !== $row['status'];
			$meta      = is_string( $row['meta'] ) ? json_decode( $row['meta'], true ) : array();
			$requires  = is_array( $meta ) && is_array( $meta['requires'] ?? null ) ? array_values( array_filter( $meta['requires'], 'is_string' ) ) : array();
			$records[] = new BackupRecord(
				(string) $row['id'],
				$type ?? BackupType::Full,
				$created ?? Time::now(),
				(int) $row['size_bytes'],
				is_string( $row['parent_id'] ) && '' !== $row['parent_id'] ? $row['parent_id'] : null,
				(bool) $row['protected'],
				$reliable,
				null === $row['profile_id'] ? null : (int) $row['profile_id'],
				$requires
			);
		}
		return $records;
	}

	/**
	 * Deletes every copy. Throws when any storage location cannot be cleaned (the backup then stays in the catalog).
	 *
	 * @param string $backupId Backup id.
	 * @throws SwbiException When a location cannot be cleaned.
	 */
	public function delete( string $backupId ): void {
		foreach ( $this->backups->locations( $backupId ) as $location ) {
			$target_id = (int) $location['target_id'];
			if ( 0 === $target_id ) {
				continue;
			}
			$target = $this->targets->find( $target_id );
			if ( null === $target ) {
				continue;
			}
			if ( $target->immutable ) {
				throw new SwbiException( sprintf( 'Storage target "%s" is immutable; the backup copy is retained.', $target->name ) );
			}
			try {
				( new StorageFactory() )->create( $target )->deleteDirectory( $backupId );
			} catch ( \Throwable $throwable ) {
				throw new SwbiException( sprintf( 'Storage target "%s" could not be cleaned; the backup is kept.', $target->name ), array(), false, $throwable );
			}
		}
		( new BackupLayout( Settings::staging_dir(), $backupId ) )->removeAll();
		$this->backups->delete( $backupId );
	}
}
