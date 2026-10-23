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
		$settings                = Settings::all();
		$settings['staging_dir'] = '' === $settings['staging_dir'] ? Settings::staging_dir() : $settings['staging_dir'];
		return new \WP_REST_Response( $settings );
	}

	public function update( \WP_REST_Request $request ) {
		$result = Settings::update( $this->body( $request ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		do_action( 'swbi_settings_updated', get_current_user_id() );
		return $this->get();
	}
}
