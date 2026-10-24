<?php
/**
 * Read-only access to the audit log.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Rest;

use SWBackupIt\WordPress\Support\AuditLogger;
use SWBackupIt\WordPress\Support\Capabilities;

final class AuditController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/audit',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_entries' ),
				'permission_callback' => $this->can( Capabilities::SETTINGS ),
				'args'                => $this->pagination_args() + array(
					'action'      => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'object_type' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'user_id'     => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
	}

	public function list_entries( \WP_REST_Request $request ) {
		$result = AuditLogger::search(
			array(
				'action'      => (string) $request['action'],
				'object_type' => (string) $request['object_type'],
				'user_id'     => (int) $request['user_id'],
			),
			(int) $request['page'],
			(int) $request['per_page']
		);
		return $this->collection( $result['items'], $result['total'], (int) $request['page'], (int) $request['per_page'] );
	}
}
