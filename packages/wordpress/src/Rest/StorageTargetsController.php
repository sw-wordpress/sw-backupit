<?php
/**
 * Storage targets. Phase 2 feature: until the storage manager exists the collection is empty and
 * mutating calls answer 501 (explicitly not implemented instead of pretending to work).
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Rest;

use SWBackupIt\WordPress\Support\Capabilities;

final class StorageTargetsController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/storage-targets',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn () => $this->collection( array(), 0, 1, 20 ),
					'permission_callback' => $this->can( Capabilities::VIEW ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'not_implemented' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_STORAGE ),
				),
			)
		);
	}

	public function not_implemented() {
		return $this->error( 'swbi_not_implemented', __( 'External storage targets are planned for a later release.', 'swbackupit' ), 501 );
	}
}
