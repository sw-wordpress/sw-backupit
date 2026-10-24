<?php
/**
 * Encryption key management. The secret of a generated key is returned exactly once, at creation.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Rest;

use SWBackupIt\Core\Crypto\EncryptionKey;
use SWBackupIt\Core\Crypto\StreamCipher;
use SWBackupIt\Core\Exception\CryptoException;
use SWBackupIt\WordPress\Support\Capabilities;
use SWBackupIt\WordPress\Support\WpKeyProvider;

final class EncryptionKeysController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/encryption-keys',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_keys' ),
					'permission_callback' => $this->can( Capabilities::SETTINGS ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->can( Capabilities::SETTINGS ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/encryption-keys/(?P<id>[A-Za-z0-9._-]{1,64})',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => $this->can( Capabilities::SETTINGS ),
			)
		);
	}

	public function list_keys() {
		$items = ( new WpKeyProvider() )->list_ids();
		return $this->collection( $items, count( $items ), 1, max( 1, count( $items ) ) );
	}

	/**
	 * Body: { "id": "prod", "passphrase": "optional" }. Without a passphrase a random 256 bit key is generated.
	 */
	public function create( \WP_REST_Request $request ) {
		$body = $this->body( $request );
		$id   = (string) ( $body['id'] ?? '' );
		try {
			StreamCipher::assertAvailable();
			$provider = new WpKeyProvider();
			if ( null !== $provider->find( $id ) ) {
				return $this->error( 'swbi_conflict', __( 'A key with this id already exists.', 'swbackupit' ), 409 );
			}
			$key    = isset( $body['passphrase'] ) && is_string( $body['passphrase'] ) && '' !== $body['passphrase']
				? EncryptionKey::fromPassphrase( $id, $body['passphrase'] )
				: EncryptionKey::generateRaw( $id );
			$export = $provider->store( $key );
		} catch ( CryptoException $exception ) {
			return $this->error( 'swbi_invalid_param', $exception->getMessage(), 400 );
		}
		do_action( 'swbi_encryption_key_created', $id, get_current_user_id() );
		return new \WP_REST_Response(
			array(
				'id'      => $id,
				'secret'  => $export,
				'warning' => __( 'Store this key safely now (password manager). It is shown only once; without it encrypted backups cannot be restored.', 'swbackupit' ),
			),
			201
		);
	}

	public function delete( \WP_REST_Request $request ) {
		foreach ( $this->services()->profiles()->all() as $profile ) {
			if ( $profile->encryption->enabled && $profile->encryption->keyId === (string) $request['id'] ) {
				return $this->error( 'swbi_conflict', __( 'The key is used by a profile. Change the profile first.', 'swbackupit' ), 409 );
			}
		}
		( new WpKeyProvider() )->delete( (string) $request['id'] );
		do_action( 'swbi_encryption_key_deleted', (string) $request['id'], get_current_user_id() );
		return new \WP_REST_Response( array( 'deleted' => true ) );
	}
}
