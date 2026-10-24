<?php
/**
 * Phase 4: audit log, restore tests, pre-update backups, watchdog, immutable storage, background verification.
 */

use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Runner\BackgroundChecks;
use SWBackupIt\WordPress\Runner\JobWatchdog;
use SWBackupIt\WordPress\Runner\PreUpdateBackup;
use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Capabilities;
use SWBackupIt\WordPress\Support\Settings;

class EnterprisePhaseTest extends WP_UnitTestCase {

	private string $staging = '';
	private string $remote  = '';

	public function set_up(): void {
		parent::set_up();
		Schema::migrate();
		Capabilities::grant_defaults();
		Services::reset();
		$this->staging = sys_get_temp_dir() . '/swbi-wp-ent-' . bin2hex( random_bytes( 4 ) );
		$this->remote  = sys_get_temp_dir() . '/swbi-wp-entremote-' . bin2hex( random_bytes( 4 ) );
		Settings::update( array( 'staging_dir' => $this->staging, 'limits' => array( 'time_budget_seconds' => 3 ) ) );
		delete_transient( 'swbi_pre_update_lock' );
		global $wpdb;
		foreach ( Schema::tables() as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $table ) );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator', 'user_login' => 'auditor' ) ) );
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
		$request = new WP_REST_Request( $method, '/swbackupit/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function backup( array $profile_overrides = array() ): array {
		$profile = $this->request( 'POST', '/profiles', $profile_overrides + array( 'name' => 'Ent', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ) ) )->get_data();
		$job     = $this->request( 'POST', '/profiles/' . $profile['id'] . '/run' )->get_data();
		for ( $i = 0; $i < 300; $i++ ) {
			$job = $this->request( 'POST', '/jobs/' . $job['id'] . '/tick' )->get_data();
			if ( in_array( $job['status'], array( 'completed', 'failed' ), true ) ) {
				break;
			}
		}
		$this->assertSame( 'completed', $job['status'], wp_json_encode( $job['error'] ) );
		return array( $profile, $job );
	}

	public function test_audit_log_records_who_did_what_without_secrets(): void {
		[ $profile, $job ] = $this->backup();
		$this->request( 'PUT', '/settings', array( 'logging' => array( 'level' => 'debug' ) ) );
		$this->request( 'POST', '/encryption-keys', array( 'id' => 'audit-key', 'passphrase' => 'super secret passphrase' ) );
		$this->request( 'POST', '/backups/' . $job['backup_id'] . '/protect', array( 'protected' => true ) );
		$this->request( 'POST', '/backups/' . $job['backup_id'] . '/protect', array( 'protected' => false ) );
		$this->request( 'DELETE', '/backups/' . $job['backup_id'], null, array( 'confirm' => $job['backup_id'] ) );

		$entries = $this->request( 'GET', '/audit', null, array( 'per_page' => 100 ) )->get_data()['items'];
		$actions = wp_list_pluck( $entries, 'action' );
		foreach ( array( 'profile_saved', 'backup_started', 'settings_updated', 'encryption_key_created', 'backup_protected', 'backup_deleted' ) as $expected ) {
			$this->assertContains( $expected, $actions, $expected );
		}
		$deleted = array_values( array_filter( $entries, static fn ( array $e ): bool => 'backup_deleted' === $e['action'] ) )[0];
		$this->assertSame( 'auditor', $deleted['user'] );
		$this->assertSame( $job['backup_id'], $deleted['object_id'] );
		$settings = array_values( array_filter( $entries, static fn ( array $e ): bool => 'settings_updated' === $e['action'] ) )[0];
		$this->assertSame( array( 'logging' ), $settings['context']['changed'] );
		$this->assertStringNotContainsString( 'super secret', (string) wp_json_encode( $entries ), 'secrets never reach the audit log' );

		// Only users with the settings capability can read it.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->request( 'GET', '/audit' )->get_status() );
	}

	public function test_restore_test_endpoint_documents_the_result_and_touches_nothing(): void {
		[ $profile, $job ] = $this->backup( array( 'type' => 'database' ) );
		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		$result = $this->request( 'POST', '/backups/' . $job['backup_id'] . '/restore-test' );
		$this->assertSame( 200, $result->get_status(), wp_json_encode( $result->get_data() ) );
		$data = $result->get_data();
		$this->assertContains( $data['status'], array( 'passed', 'partial' ), wp_json_encode( $data ) );
		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) );
		$backup = $this->request( 'GET', '/backups/' . $job['backup_id'] )->get_data();
		$this->assertNotNull( $backup['restore_tested_at'] );
		$this->assertSame( 404, $this->request( 'POST', '/backups/' . wp_generate_uuid4() . '/restore-test' )->get_status() );
		$this->assertContains( 'restore_tested', wp_list_pluck( $this->request( 'GET', '/audit' )->get_data()['items'], 'action' ) );
	}

	public function test_pre_update_backup_runs_once_never_loops_and_never_blocks_by_default(): void {
		$profile = $this->request( 'POST', '/profiles', array( 'name' => 'PreUpdate', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ) ) )->get_data();
		Settings::update( array( 'pre_update_backup' => array( 'plugins' => true, 'profile_id' => (int) $profile['id'], 'wait_seconds' => 30 ) ) );
		$hook = new PreUpdateBackup();
		$this->assertTrue( $hook->before_install( true, array( 'type' => 'theme' ) ), 'themes are not enabled: nothing happens' );
		$this->assertSame( 0, $this->request( 'GET', '/jobs' )->get_data()['total'] );

		$this->assertTrue( $hook->before_install( true, array( 'type' => 'plugin' ) ) );
		$jobs = $this->request( 'GET', '/jobs' )->get_data();
		$this->assertSame( 1, $jobs['total'] );
		$this->assertSame( 'pre_update', $jobs['items'][0]['trigger'] );
		$this->assertSame( 'completed', $jobs['items'][0]['status'] );
		// A bulk update triggers the hook for every plugin: no second backup.
		$this->assertTrue( $hook->before_install( true, array( 'type' => 'plugin' ) ) );
		$this->assertTrue( $hook->before_install( true, array( 'type' => 'plugin' ) ) );
		$this->assertSame( 1, $this->request( 'GET', '/jobs' )->get_data()['total'] );
		// Errors from other filters are passed through untouched.
		$error = new WP_Error( 'x', 'y' );
		$this->assertSame( $error, $hook->before_install( $error, array( 'type' => 'plugin' ) ) );
	}

	public function test_failed_pre_update_backup_blocks_only_when_explicitly_configured(): void {
		$profile = $this->request( 'POST', '/profiles', array( 'name' => 'PreUpdate', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ) ) )->get_data();
		add_filter( 'swbi_allow_before_backup', '__return_false' );
		Settings::update( array( 'pre_update_backup' => array( 'core' => true, 'profile_id' => (int) $profile['id'], 'block_on_failure' => false ) ) );
		$this->assertTrue( ( new PreUpdateBackup() )->before_install( true, array( 'type' => 'core' ) ), 'default: the update is not blocked' );

		delete_transient( 'swbi_pre_update_lock' );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'jobs' ) );
		Settings::update( array( 'pre_update_backup' => array( 'block_on_failure' => true ) ) );
		$result = ( new PreUpdateBackup() )->before_install( true, array( 'type' => 'core' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swbi_pre_update_backup_failed', $result->get_error_code() );
		remove_filter( 'swbi_allow_before_backup', '__return_false' );
	}

	public function test_watchdog_restarts_lost_jobs_and_fails_dead_ones(): void {
		$profile = Services::instance()->profiles()->create( array( 'name' => 'W', 'type' => 'files' ) );
		$lost    = Services::instance()->job_service()->start( $profile, 'manual', null, false );
		$age = static function ( string $id, string $when ): void {
			$job            = Services::instance()->jobs()->find( $id );
			$job->updatedAt = new DateTimeImmutable( $when, new DateTimeZone( 'UTC' ) );
			Services::instance()->jobs()->save( $job );
		};
		$age( $lost->id, '-15 minutes' );
		$result = ( new JobWatchdog() )->run();
		$this->assertSame( 1, $result['restarted'] );
		$this->assertNotFalse( wp_next_scheduled( 'swbi_run_job', array( $lost->id ) ), 'a background run was queued again' );

		$age( $lost->id, '-3 days' );
		$result = ( new JobWatchdog() )->run();
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( JobStatus::Failed, Services::instance()->jobs()->find( $lost->id )->status );
	}

	public function test_immutable_and_time_locked_targets_keep_backups_during_retention(): void {
		$target = $this->request( 'POST', '/storage-targets', array( 'name' => 'WORM', 'type' => 'local', 'config' => array( 'path' => $this->remote, 'lock_days' => 30 ), 'immutable' => false ) )->get_data();
		[ $profile, $job ] = $this->backup( array( 'storage_target_ids' => array( (int) $target['id'] ) ) );
		$catalog = Services::instance()->catalog();
		$this->expectException( \SWBackupIt\Core\Exception\SwbiException::class );
		try {
			$catalog->delete( $job['backup_id'] );
		} finally {
			$this->assertFileExists( $this->remote . '/' . $job['backup_id'] . '/manifest.json', 'the locked replica survived' );
			$this->assertNotNull( Services::instance()->backups()->find_row( $job['backup_id'] ), 'the catalog entry is kept as well' );
		}
	}

	public function test_immutable_flag_blocks_deletion_through_the_api_catalog(): void {
		$target = $this->request( 'POST', '/storage-targets', array( 'name' => 'Immutable', 'type' => 'local', 'config' => array( 'path' => $this->remote ), 'immutable' => true ) )->get_data();
		$this->assertTrue( $target['immutable'] );
		[ , $job ] = $this->backup( array( 'storage_target_ids' => array( (int) $target['id'] ) ) );
		$this->expectException( \SWBackupIt\Core\Exception\SwbiException::class );
		Services::instance()->catalog()->delete( $job['backup_id'] );
	}

	public function test_background_verification_detects_a_damaged_backup(): void {
		[ , $job ] = $this->backup();
		$checked = ( new BackgroundChecks() )->verify_oldest();
		$this->assertSame( $job['backup_id'], $checked );
		$this->assertSame( 'verified', $this->request( 'GET', '/backups/' . $job['backup_id'] )->get_data()['status'] );

		$mail = array();
		add_filter(
			'pre_wp_mail',
			static function ( $return, $atts ) use ( &$mail ) {
				$mail[] = $atts;
				return true;
			},
			10,
			2
		);
		$volumes = glob( $this->staging . '/' . $job['backup_id'] . '/volumes/*.zip' ) ?: array();
		file_put_contents( $volumes[0], 'damage', FILE_APPEND );
		( new BackgroundChecks() )->verify_oldest();
		$this->assertSame( 'corrupt', $this->request( 'GET', '/backups/' . $job['backup_id'] )->get_data()['status'] );
		$this->assertCount( 1, $mail, 'the administrator is told about the damaged backup' );
	}
}
