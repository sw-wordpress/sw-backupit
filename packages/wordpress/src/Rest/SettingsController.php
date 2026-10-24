<?php
/**
 * Settings.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Rest;

use SWBackupIt\WordPress\Support\Capabilities;
use SWBackupIt\WordPress\Support\Settings;

final class SettingsController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => $this->can( Capabilities::SETTINGS ),
				),
				array(
					'methods'             => 'PUT, PATCH, POST',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $this->can( Capabilities::SETTINGS ),
				),
			)
		);
	}

	public function get() {
		$settings = Settings::all();
		// Shows the resolved directory; staging_dir_auto tells that it is chosen automatically (posting it back keeps it so).
		$settings['staging_dir_auto'] = '' === $settings['staging_dir'];
		$settings['staging_dir']      = Settings::staging_dir();
		return new \WP_REST_Response( $settings );
	}

	public function update( \WP_REST_Request $request ) {
		$before = Settings::all();
		$result = Settings::update( $this->body( $request ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$changed = array_keys( array_filter( $result, static fn ( $value, $key ): bool => ( $before[ $key ] ?? null ) !== $value, ARRAY_FILTER_USE_BOTH ) );
		do_action( 'swbi_settings_updated', $changed );
		return $this->get();
	}
}
