<?php
/**
 * Encryption keys for backups.
 *
 * Lookup order: wp-config.php constant SWBACKUPIT_KEY_<ID> or environment variable (keys that live OUTSIDE the WordPress
 * database - recommended), then keys stored in the options table (encrypted with the credential secret). A backup must
 * never depend on a secret that exists only in the database it protects, so the UI makes users export new keys.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Support;

use SWBackupIt\Core\Crypto\EncryptionKey;
use SWBackupIt\Core\Crypto\EnvironmentKeyProvider;
use SWBackupIt\Core\Crypto\KeyProviderInterface;

final class WpKeyProvider implements KeyProviderInterface {

	private const OPTION = 'swbi_encryption_keys';

	public function find( string $keyId ): ?EncryptionKey {
		$constant = 'SWBACKUPIT_KEY_' . strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '_', $keyId ) );
		if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== constant( $constant ) ) {
			return EncryptionKey::parse( $keyId, (string) constant( $constant ) );
		}
		$from_env = ( new EnvironmentKeyProvider() )->find( $keyId );
		if ( null !== $from_env ) {
			return $from_env;
		}
		$stored = $this->stored();
		if ( isset( $stored[ $keyId ] ) ) {
			$plain = Secrets::decrypt( $stored[ $keyId ] );
			return null === $plain ? null : EncryptionKey::parse( $keyId, $plain );
		}
		return null;
	}

	/**
	 * Key ids known to this site (never the secrets).
	 *
	 * @return list<array{id: string, source: string}>
	 */
	public function list_ids(): array {
		$ids = array();
		foreach ( array_keys( $this->stored() ) as $id ) {
			$ids[] = array(
				'id'     => (string) $id,
				'source' => 'database',
			);
		}
		return $ids;
	}

	/**
	 * Stores a key in the database and returns its export string. The caller must show it to the user exactly once.
	 */
	public function store( EncryptionKey $key ): string {
		$stored                = $this->stored();
		$stored[ $key->keyId ] = Secrets::encrypt( $key->export() );
		update_option( self::OPTION, $stored, false );
		return $key->export();
	}

	public function delete( string $key_id ): void {
		$stored = $this->stored();
		unset( $stored[ $key_id ] );
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * @return array<string, string>
	 */
	private function stored(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? array_filter( $value, 'is_string' ) : array();
	}
}
