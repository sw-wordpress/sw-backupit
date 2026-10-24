<?php
/**
 * Schedules: CRUD and manual run.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Rest;

use SWBackupIt\WordPress\Repository\ScheduleCalculator;
use SWBackupIt\WordPress\Support\Capabilities;
use SWBackupIt\WordPress\Support\Time;

final class SchedulesController extends BaseController {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/schedules',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_schedules' ),
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
			'/schedules/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_schedule' ),
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
					'args'                => $id_args,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/schedules/(?P<id>\d+)/run',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => $this->can( Capabilities::RUN ),
				'args'                => $id_args,
			)
		);
	}

	public function list_schedules() {
		$repo  = $this->services()->schedules();
		$items = array_map( array( $repo, 'to_rest' ), $repo->all() );
		return $this->collection( $items, count( $items ), 1, max( 1, count( $items ) ) );
	}

	public function get_schedule( \WP_REST_Request $request ) {
		$row = $this->services()->schedules()->find( (int) $request['id'] );
		return null === $row
			? $this->error( 'swbi_not_found', __( 'Schedule not found.', 'swbackupit' ), 404 )
			: new \WP_REST_Response( $this->services()->schedules()->to_rest( $row ) );
	}

	public function create( \WP_REST_Request $request ) {
		$data = $this->validate( $this->body( $request ), null );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$id  = $this->services()->schedules()->create( $data );
		do_action( 'swbi_schedule_saved', (string) $id );
		$row = $this->services()->schedules()->find( $id );
		return new \WP_REST_Response( $this->services()->schedules()->to_rest( (array) $row ), 201 );
	}

	public function update( \WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$current = $this->services()->schedules()->find( $id );
		if ( null === $current ) {
			return $this->error( 'swbi_not_found', __( 'Schedule not found.', 'swbackupit' ), 404 );
		}
		$data = $this->validate( $this->body( $request ), $current );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$this->services()->schedules()->update( $id, $data );
		do_action( 'swbi_schedule_saved', (string) $id );
		return new \WP_REST_Response( $this->services()->schedules()->to_rest( (array) $this->services()->schedules()->find( $id ) ) );
	}

	public function delete( \WP_REST_Request $request ) {
		$deleted = $this->services()->schedules()->delete( (int) $request['id'] );
		if ( $deleted ) {
			do_action( 'swbi_schedule_deleted', (string) (int) $request['id'] );
		}
		return $deleted
			? new \WP_REST_Response( array( 'deleted' => true ) )
			: $this->error( 'swbi_not_found', __( 'Schedule not found.', 'swbackupit' ), 404 );
	}

	public function run( \WP_REST_Request $request ) {
		$row = $this->services()->schedules()->find( (int) $request['id'] );
		if ( null === $row ) {
			return $this->error( 'swbi_not_found', __( 'Schedule not found.', 'swbackupit' ), 404 );
		}
		$job_id = $this->services()->schedule_runner()->start( $row, 'manual' );
		if ( null === $job_id ) {
			$fresh = (array) $this->services()->schedules()->find( (int) $row['id'] );
			return $this->error( 'swbi_conflict', (string) ( $fresh['last_error'] ?? __( 'The schedule could not be started.', 'swbackupit' ) ), 409 );
		}
		return new \WP_REST_Response( JobPresenter::to_rest( $this->services()->jobs()->find( $job_id ) ), 201 );
	}

	/**
	 * Validates input against the (optional) current row and returns the DB fields.
	 *
	 * @param array<string, mixed>      $input
	 * @param array<string, mixed>|null $current
	 * @return array<string, mixed>|\WP_Error
	 */
	private function validate( array $input, ?array $current ) {
		$merged = $current ?? array(
			'name'            => '',
			'profile_id'      => 0,
			'enabled'         => 1,
			'frequency'       => 'daily',
			'cron_expression' => '',
			'timezone'        => (string) \SWBackupIt\WordPress\Support\Settings::all()['cron']['timezone'],
			'time_of_day'     => '02:30',
			'weekday'         => 1,
			'monthday'        => 1,
			'retry_max'       => 3,
			'retry_backoff'   => 600,
		);
		foreach ( array( 'name', 'frequency', 'cron_expression', 'timezone', 'time_of_day' ) as $key ) {
			if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) ) {
				$merged[ $key ] = sanitize_text_field( $input[ $key ] );
			}
		}
		foreach ( array( 'profile_id', 'weekday', 'monthday' ) as $key ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$merged[ $key ] = (int) $input[ $key ];
			}
		}
		if ( isset( $input['enabled'] ) ) {
			$merged['enabled'] = rest_sanitize_boolean( $input['enabled'] ) ? 1 : 0;
		}
		if ( isset( $input['retry'] ) && is_array( $input['retry'] ) ) {
			$merged['retry_max']     = isset( $input['retry']['max_attempts'] ) ? (int) $input['retry']['max_attempts'] : $merged['retry_max'];
			$merged['retry_backoff'] = isset( $input['retry']['backoff_seconds'] ) ? (int) $input['retry']['backoff_seconds'] : $merged['retry_backoff'];
		}

		$errors = array();
		if ( '' === trim( (string) $merged['name'] ) ) {
			$errors['name'] = __( 'Name is required.', 'swbackupit' );
		}
		if ( null === $this->services()->profiles()->find( (int) $merged['profile_id'] ) ) {
			$errors['profile_id'] = __( 'Profile does not exist.', 'swbackupit' );
		}
		if ( ! in_array( $merged['frequency'], array( 'hourly', 'daily', 'weekly', 'monthly', 'cron' ), true ) ) {
			$errors['frequency'] = __( 'Unknown frequency.', 'swbackupit' );
		}
		if ( 'cron' === $merged['frequency'] && ! ScheduleCalculator::is_valid_cron( (string) $merged['cron_expression'] ) ) {
			$errors['cron_expression'] = __( 'Invalid cron expression.', 'swbackupit' );
		}
		if ( ! ScheduleCalculator::is_valid_timezone( (string) $merged['timezone'] ) ) {
			$errors['timezone'] = __( 'Unknown timezone.', 'swbackupit' );
		}
		if ( 1 !== preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', (string) $merged['time_of_day'] ) ) {
			$errors['time_of_day'] = __( 'Time must be HH:MM.', 'swbackupit' );
		}
		if ( $merged['weekday'] < 0 || $merged['weekday'] > 6 || $merged['monthday'] < 1 || $merged['monthday'] > 31 ) {
			$errors['day'] = __( 'Weekday or day of month out of range.', 'swbackupit' );
		}
		if ( $merged['retry_max'] < 0 || $merged['retry_max'] > 10 || $merged['retry_backoff'] < 30 || $merged['retry_backoff'] > 86400 ) {
			$errors['retry'] = __( 'Retry settings are out of range.', 'swbackupit' );
		}
		if ( array() !== $errors ) {
			return $this->error( 'swbi_invalid_param', __( 'The schedule is invalid.', 'swbackupit' ), 400, $errors );
		}

		$data = array(
			'name'            => (string) $merged['name'],
			'profile_id'      => (int) $merged['profile_id'],
			'enabled'         => (int) $merged['enabled'],
			'frequency'       => (string) $merged['frequency'],
			'cron_expression' => (string) $merged['cron_expression'],
			'timezone'        => (string) $merged['timezone'],
			'time_of_day'     => (string) $merged['time_of_day'],
			'weekday'         => (int) $merged['weekday'],
			'monthday'        => (int) $merged['monthday'],
			'retry_max'       => (int) $merged['retry_max'],
			'retry_backoff'   => (int) $merged['retry_backoff'],
			'next_run_at'     => $merged['enabled'] ? Time::to_db( ScheduleCalculator::next_run( $merged, Time::now() ) ) : null,
		);
		if ( null === $current || (int) $current['enabled'] !== (int) $merged['enabled'] || $current['frequency'] !== $merged['frequency'] ) {
			$data['retry_count'] = 0;
		}
		return $data;
	}
}
