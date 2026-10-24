<?php
/**
 * Jobs: list, detail, pause/resume/cancel/retry, tick, logs.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Rest;

use SWBackupIt\Core\Domain\Step;
use SWBackupIt\Core\Exception\JobNotFoundException;
use SWBackupIt\WordPress\Support\Capabilities;
use SWBackupIt\WordPress\Support\Settings;

final class JobsController extends BaseController {

	private const RUNNING = array( 'pending', 'preparing', 'scanning', 'exporting', 'archiving', 'encrypting', 'uploading', 'verifying', 'paused', 'retry_waiting' );
	private const DONE    = array( 'completed', 'failed', 'cancelled' );

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/jobs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_jobs' ),
				'permission_callback' => $this->can( Capabilities::VIEW ),
				'args'                => $this->pagination_args() + array(
					'status' => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array( '', 'running', 'finished', 'pending', 'preparing', 'scanning', 'exporting', 'archiving', 'encrypting', 'uploading', 'verifying', 'completed', 'paused', 'retry_waiting', 'failed', 'cancelled' ),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>[0-9a-f-]{36})',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_job' ),
				'permission_callback' => $this->can( Capabilities::VIEW ),
				'args'                => array( 'id' => $this->uuid_arg() ),
			)
		);
		foreach ( array( 'pause', 'resume', 'cancel', 'retry', 'tick' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/jobs/(?P<id>[0-9a-f-]{36})/' . $action,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn ( \WP_REST_Request $request ) => $this->control( $request, $action ),
					'permission_callback' => $this->can( Capabilities::RUN ),
					'args'                => array( 'id' => $this->uuid_arg() ),
				)
			);
		}
		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>[0-9a-f-]{36})/logs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'logs' ),
				'permission_callback' => $this->can( Capabilities::VIEW ),
				'args'                => array(
					'id'       => $this->uuid_arg(),
					'after'    => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'severity' => array(
						'type'    => 'string',
						'default' => 'debug',
						'enum'    => array( 'debug', 'info', 'notice', 'warning', 'error' ),
					),
					'phase'    => array(
						'type'    => 'string',
						'default' => '',
						// Derived from the Step enum, so new steps (e.g. manifest) are always filterable.
						'enum'    => array_merge( array( '', 'init' ), array_map( static fn ( Step $step ): string => $step->value, Step::cases() ) ),
					),
					'limit'    => array(
						'type'    => 'integer',
						'default' => 200,
						'minimum' => 1,
						'maximum' => 1000,
					),
				),
			)
		);
	}

	public function list_jobs( \WP_REST_Request $request ) {
		$status   = (string) $request['status'];
		$statuses = match ( $status ) {
			'' => null,
			'running' => self::RUNNING,
			'finished' => self::DONE,
			default => array( $status ),
		};
		$page     = (int) $request['page'];
		$per_page = (int) $request['per_page'];
		$repo     = $this->services()->jobs();
		$items    = array_map( array( JobPresenter::class, 'to_rest' ), $repo->list( $statuses, $per_page, ( $page - 1 ) * $per_page ) );
		return $this->collection( $items, $repo->count( $statuses ), $page, $per_page );
	}

	public function get_job( \WP_REST_Request $request ) {
		try {
			return new \WP_REST_Response( JobPresenter::to_rest( $this->services()->jobs()->find( (string) $request['id'] ) ) );
		} catch ( \Throwable $throwable ) {
			return $this->from_exception( $throwable );
		}
	}

	private function control( \WP_REST_Request $request, string $action ) {
		$id      = (string) $request['id'];
		$service = $this->services()->job_service();
		try {
			switch ( $action ) {
				case 'pause':
					$service->pause( $id );
					break;
				case 'resume':
					$service->resume( $id );
					break;
				case 'cancel':
					$service->cancel( $id );
					break;
				case 'retry':
					$service->retry( $id );
					break;
				case 'tick':
					$budget = min( 10.0, (float) Settings::all()['limits']['time_budget_seconds'] );
					$service->tick( $id, $budget );
					break;
			}
			return new \WP_REST_Response( JobPresenter::to_rest( $this->services()->jobs()->find( $id ) ) );
		} catch ( JobNotFoundException ) {
			return $this->error( 'swbi_not_found', __( 'Job not found.', 'sw-backupit' ), 404 );
		} catch ( \Throwable $throwable ) {
			return $this->from_exception( $throwable );
		}
	}

	public function logs( \WP_REST_Request $request ) {
		$id = (string) $request['id'];
		try {
			$this->services()->jobs()->find( $id );
		} catch ( JobNotFoundException ) {
			return $this->error( 'swbi_not_found', __( 'Job not found.', 'sw-backupit' ), 404 );
		}
		$result = $this->services()->events()->read( $id, (int) $request['after'], (int) $request['limit'], (string) $request['severity'], '' === (string) $request['phase'] ? null : (string) $request['phase'] );
		return new \WP_REST_Response( $result );
	}
}
