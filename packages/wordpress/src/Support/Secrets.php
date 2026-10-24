<?php
/**
 * Encrypts stored credentials (storage targets) with libsodium secretbox.
 *
 * The key is stored in its own option and derived with the WordPress salts. Honest scope: this protects
 * credentials against casual database dumps and SQL read access; an attacker who can read both the
 * options table and wp-config.php can still decrypt them. Backup *archive* encryption (separate keys) is a different feature.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary-to-text encoding of key material/ciphertext, not obfuscation.

namespace SWBackupIt\WordPress\Support;

final class Secrets {

	private const OPTION = 'swbi_secret_key';

	public static function encrypt( string $plaintext ): string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			throw new \RuntimeException( 'The sodium extension is required to store credentials.' );
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );

		return 'v1:' . base64_encode( $nonce . $cipher );
	}

	public static function decrypt( string $payload ): ?string {
		if ( ! str_starts_with( $payload, 'v1:' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}
		$raw = base64_decode( substr( $payload, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );

		return false === $plain ? null : $plain;
	}

	private static function key(): string {
		$stored = \SWBackupIt\WordPress\Support\Options::get( self::OPTION );
		if ( ! is_string( $stored ) || '' === $stored ) {
			$stored = base64_encode( random_bytes( 32 ) );
			\SWBackupIt\WordPress\Support\Options::update( self::OPTION, $stored, false );
		}
		$raw = (string) base64_decode( $stored, true );

		return sodium_crypto_generichash( $raw . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
