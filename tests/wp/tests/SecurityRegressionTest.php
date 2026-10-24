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
