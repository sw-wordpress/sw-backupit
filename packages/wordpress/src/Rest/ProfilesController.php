<?php
/**
 * Backup profiles: CRUD, duplicate, run.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Rest;

use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\WordPress\Support\Capabilities;
use SWBackupIt\WordPress\Support\DefaultProfiles;

final class ProfilesController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/profiles',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_profiles' ),
					'permission_callback' => $this->can( Capabilities::VIEW ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_PROFILES ),
				),
			)
		);
		$id_args = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/profiles/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_profile' ),
					'permission_callback' => $this->can( Capabilities::VIEW ),
					'args'                => $id_args,
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_PROFILES ),
					'args'                => $id_args,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_PROFILES ),
					'args'                => $id_args + array(
						'confirm' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/profiles/(?P<id>\d+)/duplicate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate' ),
				'permission_callback' => $this->can( Capabilities::MANAGE_PROFILES ),
				'args'                => $id_args,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/profiles/(?P<id>\d+)/run',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => $this->can( Capabilities::RUN ),
				'args'                => $id_args,
			)
		);
	}

	public function list_profiles() {
		$repo  = $this->services()->profiles();
		$items = array_map( array( $repo, 'to_rest' ), $repo->all() );
		return $this->collection( $items, count( $items ), 1, max( 1, count( $items ) ) );
	}

	public function get_profile( \WP_REST_Request $request ) {
		$profile = $this->services()->profiles()->find( (int) $request['id'] );
		return null === $profile
			? $this->error( 'swbi_not_found', __( 'Profile not found.', 'swbackupit' ), 404 )
			: new \WP_REST_Response( $this->services()->profiles()->to_rest( $profile ) );
	}

	public function create( \WP_REST_Request $request ) {
		try {
			$profile = $this->services()->profiles()->create( DefaultProfiles::apply_creation_defaults( $this->sanitize( $this->body( $request ) ) ) );
		} catch ( ConfigurationException $exception ) {
			return $this->error( 'swbi_invalid_param', $exception->getMessage(), 400 );
		}
		do_action( 'swbi_profile_saved', (string) $profile->id );
		return new \WP_REST_Response( $this->services()->profiles()->to_rest( $profile ), 201 );
	}

	public function update( \WP_REST_Request $request ) {
		try {
			$profile = $this->services()->profiles()->update( (int) $request['id'], $this->sanitize( $this->body( $request ) ) );
		} catch ( ConfigurationException $exception ) {
			return $this->error( 'swbi_invalid_param', $exception->getMessage(), 400 );
		}
		if ( null !== $profile ) {
			do_action( 'swbi_profile_saved', (string) $profile->id );
		}
		return null === $profile
			? $this->error( 'swbi_not_found', __( 'Profile not found.', 'swbackupit' ), 404 )
			: new \WP_REST_Response( $this->services()->profiles()->to_rest( $profile ) );
	}

	public function delete( \WP_REST_Request $request ) {
		if ( ! rest_sanitize_boolean( $request['confirm'] ) ) {
			return $this->error( 'swbi_confirmation_required', __( 'Deleting a profile requires confirmation.', 'swbackupit' ), 400 );
		}
		$id = (int) $request['id'];
		foreach ( $this->services()->schedules()->all() as $schedule ) {
			if ( (int) $schedule['profile_id'] === $id ) {
				return $this->error( 'swbi_conflict', __( 'The profile is used by a schedule. Delete the schedule first.', 'swbackupit' ), 409 );
			}
		}
		if ( $this->services()->profiles()->delete( $id ) ) {
			do_action( 'swbi_profile_deleted', (string) $id );
		}
		return null === $this->services()->profiles()->find( $id )
			? new \WP_REST_Response( array( 'deleted' => true ) )
			: $this->error( 'swbi_not_found', __( 'Profile not found.', 'swbackupit' ), 404 );
	}

	public function duplicate( \WP_REST_Request $request ) {
		$copy = $this->services()->profiles()->duplicate( (int) $request['id'] );
		return null === $copy
			? $this->error( 'swbi_not_found', __( 'Profile not found.', 'swbackupit' ), 404 )
			: new \WP_REST_Response( $this->services()->profiles()->to_rest( $copy ), 201 );
	}

	public function run( \WP_REST_Request $request ) {
		$profile = $this->services()->profiles()->find( (int) $request['id'] );
		if ( null === $profile ) {
			return $this->error( 'swbi_not_found', __( 'Profile not found.', 'swbackupit' ), 404 );
		}
		try {
			$job = $this->services()->job_service()->start( $profile, 'manual' );
		} catch ( \Throwable $throwable ) {
			return $this->from_exception( $throwable );
		}
		return new \WP_REST_Response( JobPresenter::to_rest( $job ), 201 );
	}

	/**
	 * Drops read-only fields; deep validation happens in the core profile model.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function sanitize( array $input ): array {
		unset( $input['id'], $input['created_at'], $input['updated_at'] );
		// Absolute external paths need an explicit, separate approval feature (not available yet).
		if ( ! empty( $input['external_paths'] ) ) {
			$input['external_paths'] = array();
		}
		foreach ( array( 'name', 'description' ) as $key ) {
			if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) ) {
				$input[ $key ] = 'description' === $key ? sanitize_textarea_field( $input[ $key ] ) : sanitize_text_field( $input[ $key ] );
			}
		}
		return $input;
	}
}
