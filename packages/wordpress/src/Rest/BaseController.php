<?php
/**
 * Shared helpers for REST controllers.
 *
 * Every route has an explicit permission_callback with a dedicated capability. Cookie-authenticated
 * requests are CSRF protected by WordPress' REST nonce check (X-WP-Nonce / _wpnonce).
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Rest;

use SWBackupIt\WordPress\Services;

abstract class BaseController {

	public const NAMESPACE = 'swbackupit/v1';

	abstract public function register_routes(): void;

	protected function services(): Services {
		return Services::instance();
	}

	/**
	 * Permission callback factory.
	 *
	 * @return callable(): bool
	 */
	protected function can( string $capability ): callable {
		return static fn (): bool => current_user_can( $capability );
	}

	protected function error( string $code, string $message, int $status = 400, array $params = array() ): \WP_Error {
		$data = array( 'status' => $status );
		if ( array() !== $params ) {
			$data['params'] = $params;
		}
		return new \WP_Error( $code, $message, $data );
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	protected function collection( array $items, int $total, int $page, int $per_page ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'items' => $items,
				'total' => $total,
				'pages' => (int) max( 1, ceil( $total / max( 1, $per_page ) ) ),
			)
		);
	}

	/**
	 * Standard pagination args.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function pagination_args(): array {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Decoded JSON body as array.
	 *
	 * @return array<string, mixed>
	 */
	protected function body( \WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		return is_array( $params ) ? $params : array();
	}

	protected function uuid_arg(): array {
		return array(
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => static fn ( $value ): bool => is_string( $value ) && \SWBackupIt\Core\Support\Uuid::isValid( $value ),
		);
	}

	/**
	 * Maps core exceptions to REST errors without leaking internals.
	 */
	protected function from_exception( \Throwable $throwable ): \WP_Error {
		if ( $throwable instanceof \SWBackupIt\Core\Exception\JobNotFoundException ) {
			return $this->error( 'swbi_not_found', __( 'Job not found.', 'swbackupit' ), 404 );
		}
		if ( $throwable instanceof \SWBackupIt\Core\Exception\SwbiException ) {
			$status = isset( $throwable->context()['conflict'] ) ? 409 : 400;
			return $this->error( 409 === $status ? 'swbi_conflict' : 'swbi_error', $throwable->getMessage(), $status );
		}
		return $this->error( 'swbi_error', __( 'An unexpected error occurred.', 'swbackupit' ), 500 );
	}
}
