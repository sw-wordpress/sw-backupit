<?php
/**
 * Regression tests for security measures of the REST API (PLAN.md §24).
 */

use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Capabilities;
use SWBackupIt\WordPress\Support\Settings;

class SecurityRegressionTest extends WP_UnitTestCase {

	private string $staging = '';
	private string $remote  = '';

	public function set_up(): void {
		parent::set_up();
		Schema::migrate();
		Capabilities::grant_defaults();
		Services::reset();
		$this->staging = sys_get_temp_dir() . '/swbi-wp-sec-' . bin2hex( random_bytes( 4 ) );
		$this->remote  = sys_get_temp_dir() . '/swbi-wp-secremote-' . bin2hex( random_bytes( 4 ) );
		Settings::update( array( 'staging_dir' => $this->staging, 'limits' => array( 'time_budget_seconds' => 3 ) ) );
		global $wpdb;
		foreach ( Schema::tables() as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $table ) );
		}
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin ); // Network wide plugin: managed by super admins.
		}
		wp_set_current_user( $admin );
	}

	public function tear_down(): void {
		\SWBackupIt\Core\Support\AtomicFile::removeTree( $this->staging );
		\SWBackupIt\Core\Support\AtomicFile::removeTree( $this->remote );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed>|null $body
	 */
	private function request( string $method, string $route, ?array $body = null, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/sw-backupit/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_public_staging_is_refused_before_jobs_or_restore_write_files(): void {
		$public = untrailingslashit( ABSPATH ) . '/swbi-public-regression-' . bin2hex( random_bytes( 4 ) );
		$profile = Services::instance()->profiles()->create( array( 'name' => 'Unsafe staging', 'type' => 'files' ) );
		foreach ( array( false, true ) as $cached ) {
			\SWBackupIt\WordPress\Support\Options::update( 'swbi_settings', array( 'staging_dir' => $cached ? '' : $public ) );
			\SWBackupIt\WordPress\Support\Options::update( 'swbi_staging_dir', $public );
			$this->assertSame( $public, Settings::staging_dir(), 'reading settings must stay safe for health checks' );
			$actions = array(
				static fn () => Services::instance()->job_service()->start( $profile, 'manual', null, false ),
				static fn () => ( new \SWBackupIt\WordPress\Runner\WpSourceInfoProvider() )->forJob( ( new \SWBackupIt\Core\Backup\JobFactory() )->create( $profile ) ),
				static fn () => ( new \SWBackupIt\WordPress\Runner\RestorePreparer() )->prepare( wp_generate_uuid4() ),
			);
			foreach ( $actions as $action ) {
				try {
					$action();
					$this->fail( 'Public staging must be rejected.' );
				} catch ( \SWBackupIt\Core\Exception\ConfigurationException $exception ) {
					$this->assertStringContainsString( 'web root', $exception->getMessage() );
				}
			}
			$this->assertDirectoryDoesNotExist( $public );
		}
		\SWBackupIt\WordPress\Support\Options::update( 'swbi_settings', array( 'staging_dir' => $public, 'allow_public_paths' => true ) );
		$this->assertSame( $public, Settings::staging_dir_for_writes(), 'the explicit unsafe override is respected' );
		Settings::update( array( 'staging_dir' => $this->staging, 'allow_public_paths' => false ) );
		$this->assertSame( $this->staging, Settings::staging_dir_for_writes() );
	}

	public function test_subdirectory_install_never_caches_a_public_automatic_fallback(): void {
		$previous = $_SERVER['DOCUMENT_ROOT'] ?? null;
		$_SERVER['DOCUMENT_ROOT'] = dirname( untrailingslashit( ABSPATH ) );
		\SWBackupIt\WordPress\Support\Options::update( 'swbi_settings', array() );
		\SWBackupIt\WordPress\Support\Options::delete( 'swbi_staging_dir' );
		try {
			$dir = Settings::staging_dir();
			$this->assertTrue( Settings::is_inside_docroot( $dir ) );
			$this->assertSame( '', \SWBackupIt\WordPress\Support\Options::get( 'swbi_staging_dir', '' ) );
			$this->expectException( \SWBackupIt\Core\Exception\ConfigurationException::class );
			Settings::staging_dir_for_writes();
		} finally {
			if ( null === $previous ) {
				unset( $_SERVER['DOCUMENT_ROOT'] );
			} else {
				$_SERVER['DOCUMENT_ROOT'] = $previous;
			}
		}
	}

	public function test_staging_refusal_fails_queued_jobs_and_returns_rest_errors(): void {
		$profile = Services::instance()->profiles()->create( array( 'name' => 'Staging refusal', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ) ) );
		$finished = Services::instance()->job_service()->start( $profile, 'manual', null, false );
		for ( $i = 0; $i < 100; $i++ ) {
			$result = Services::instance()->job_service()->tick( $finished->id, 10.0 );
			if ( ! $result->shouldContinue ) {
				break;
			}
		}
		$this->assertSame( 'completed', $result->status->value );
		$queued = Services::instance()->job_service()->start( $profile, 'manual', null, false );
		$public = untrailingslashit( ABSPATH ) . '/swbi-refusal-' . bin2hex( random_bytes( 4 ) );
		\SWBackupIt\WordPress\Support\Options::update( 'swbi_settings', array( 'staging_dir' => $public ) );
		$files = $this->request( 'GET', '/backups/' . $finished->backupId . '/files' );
		$this->assertSame( 404, $files->get_status() );
		$this->assertSame( 'swbi_backup_unavailable', $files->get_data()['code'] );
		$compare = $this->request( 'POST', '/backups/' . $finished->backupId . '/compare' );
		$this->assertSame( 422, $compare->get_status() );
		$this->assertSame( 'swbi_compare_failed', $compare->get_data()['code'] );
		$result = Services::instance()->job_service()->tick( $queued->id, 10.0 );
		$this->assertSame( 'failed', $result->status->value );
		$saved = Services::instance()->jobs()->find( $queued->id );
		$this->assertSame( 'failed', $saved->status->value );
		$this->assertNotEmpty( $saved->error );
		$this->assertDirectoryDoesNotExist( $public );
	}

	public function test_view_only_users_can_list_files_but_cannot_compare(): void {
		$profile = Services::instance()->profiles()->create(
			array(
				'name'          => 'Compare permission',
				'type'          => 'files',
				'include_paths' => array( 'wp-includes/js/jquery' ),
			)
		);
		$job     = Services::instance()->job_service()->start( $profile, 'manual', null, false );
		for ( $i = 0; $i < 100; $i++ ) {
			$result = Services::instance()->job_service()->tick( $job->id, 10.0 );
			if ( ! $result->shouldContinue ) {
				break;
			}
		}
		$job = Services::instance()->jobs()->find( $job->id );
		$this->assertSame( 'completed', $job->status->value );
		$viewer = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user   = new WP_User( $viewer );
		$user->add_cap( Capabilities::VIEW );
		wp_set_current_user( $viewer );
		$this->assertSame( 200, $this->request( 'GET', '/backups/' . $job->backupId . '/files' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/backups/' . $job->backupId . '/compare' )->get_status() );
		$user->add_cap( Capabilities::DOWNLOAD );
		if ( is_multisite() ) {
			grant_super_admin( $viewer );
		}
		wp_set_current_user( 0 );
		wp_set_current_user( $viewer );
		$this->assertSame( 200, $this->request( 'POST', '/backups/' . $job->backupId . '/compare' )->get_status() );
	}

	public function test_delegated_profile_operators_cannot_select_storage_targets(): void {
		$profile = Services::instance()->profiles()->create( array( 'name' => 'Operator', 'type' => 'files' ) );
		$operator = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user = new WP_User( $operator );
		$user->add_cap( Capabilities::MANAGE_PROFILES );
		$user->add_cap( Capabilities::RUN );
		wp_set_current_user( $operator );
		foreach ( array( 0, 1, 99999 ) as $target ) {
			$this->assertSame( 403, $this->request( 'POST', '/profiles/' . $profile->id . '/run', array( 'storage_target_id' => $target ) )->get_status() );
		}
		$this->assertSame( 403, $this->request( 'PUT', '/profiles/' . $profile->id, array( 'storage_target_ids' => array( 1 ) ) )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/profiles', array( 'name' => 'Denied', 'storage_target_ids' => array( 1 ) ) )->get_status() );
		if ( ! is_multisite() ) {
			$this->assertSame( 200, $this->request( 'PUT', '/profiles/' . $profile->id, array( 'description' => 'Still editable', 'storage_target_ids' => array() ) )->get_status() );
			$user->add_cap( Capabilities::MANAGE_STORAGE );
			wp_set_current_user( 0 );
			wp_set_current_user( $operator );
			$this->assertSame( 200, $this->request( 'PUT', '/profiles/' . $profile->id, array( 'storage_target_ids' => array( 1 ) ) )->get_status() );
		}
	}

	public function test_malformed_identifiers_never_reach_the_database_layer(): void {
		foreach ( array( '/jobs/not-a-uuid', '/jobs/..%2F..%2Fetc', "/backups/' OR 1=1 --", '/backups/../../etc/passwd/files', '/profiles/abc', '/schedules/1;DROP TABLE x' ) as $route ) {
			$status = $this->request( 'GET', $route )->get_status();
			$this->assertContains( $status, array( 404, 400 ), $route );
		}
	}

	public function test_sql_injection_attempts_in_filters_are_harmless(): void {
		global $wpdb;
		$wpdb->suppress_errors( false );
		$response = $this->request( 'GET', '/backups', null, array( 'search' => "x' OR '1'='1' --", 'type' => 'full', 'profile_id' => 0 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['total'] );
		$this->assertSame( 400, $this->request( 'GET', '/backups', null, array( 'type' => "full' OR 1=1" ) )->get_status(), 'enums are validated' );
		$audit = $this->request( 'GET', '/audit', null, array( 'action' => "x' OR 1=1 --" ) );
		$this->assertSame( 200, $audit->get_status() );
		$this->assertSame( 0, $audit->get_data()['total'] );
		$this->assertSame( '', (string) $wpdb->last_error );
	}

	public function test_stored_markup_in_profile_fields_is_neutralised(): void {
		$created = $this->request( 'POST', '/profiles', array( 'name' => '<script>alert(1)</script>Prod', 'description' => '<img src=x onerror=alert(1)>desc', 'type' => 'files' ) )->get_data();
		$this->assertStringNotContainsString( '<script', $created['name'] );
		$this->assertStringNotContainsString( 'onerror', $created['description'] . $created['name'] );
	}

	public function test_download_cannot_be_abused_for_path_traversal_or_unknown_backups(): void {
		$this->assertSame( 400, $this->request( 'GET', '/backups/' . wp_generate_uuid4() . '/download', null, array( 'part' => '../../etc/passwd' ) )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/backups/' . wp_generate_uuid4() . '/download' )->get_status() );
	}

	public function test_secrets_never_leak_into_jobs_logs_or_api_responses(): void {
		$secret = 'TOPSECRET-PASSWORD-123';
		$target = $this->request( 'POST', '/storage-targets', array( 'name' => 'T', 'type' => 'local', 'config' => array( 'path' => $this->remote ) ) )->get_data();
		$profile = $this->request( 'POST', '/profiles', array( 'name' => 'S', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ), 'storage_target_ids' => array( (int) $target['id'] ) ) )->get_data();
		$job     = $this->request( 'POST', '/profiles/' . $profile['id'] . '/run' )->get_data();
		for ( $i = 0; $i < 200; $i++ ) {
			$job = $this->request( 'POST', '/jobs/' . $job['id'] . '/tick' )->get_data();
			if ( in_array( $job['status'], array( 'completed', 'failed' ), true ) ) {
				break;
			}
		}
		$this->assertSame( 'completed', $job['status'] );

		// An S3 target with a secret: its credentials must stay out of every other output.
		$this->request( 'POST', '/storage-targets', array( 'name' => 'S3', 'type' => 's3', 'config' => array( 'endpoint' => 'https://93.184.216.34', 'bucket' => 'my-bucket' ), 'credentials' => array( 'access_key' => 'AKIAEXAMPLE', 'secret_key' => $secret ) ) );
		global $wpdb;
		$dump = '';
		foreach ( array( 'jobs', 'job_events', 'backups', 'profiles', 'audit' ) as $table ) {
			$dump .= (string) wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . Schema::table( $table ), ARRAY_A ) );
		}
		$this->assertStringNotContainsString( $secret, $dump );
		$responses = (string) wp_json_encode( array( $this->request( 'GET', '/storage-targets' )->get_data(), $this->request( 'GET', '/jobs' )->get_data(), $this->request( 'GET', '/health' )->get_data(), $this->request( 'GET', '/settings' )->get_data() ) );
		$this->assertStringNotContainsString( $secret, $responses );
		$this->assertStringNotContainsString( 'AKIAEXAMPLE', $responses );
		$public = (string) wp_json_encode( array( $this->request( 'GET', '/jobs' )->get_data(), $this->request( 'GET', '/backups' )->get_data() ) );
		$this->assertStringNotContainsString( $this->staging, $public, 'internal paths are not exposed by job/backup listings' );
	}
}
