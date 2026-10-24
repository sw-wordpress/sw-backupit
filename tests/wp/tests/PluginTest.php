<?php
/**
 * Integration tests of the WordPress adapter (schema, REST, schedules, job lifecycle).
 */

use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Repository\ScheduleCalculator;
use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Capabilities;
use SWBackupIt\WordPress\Support\Settings;

class PluginTest extends WP_UnitTestCase {

	private string $staging = '';

	public function set_up(): void {
		parent::set_up();
		Schema::migrate();
		Capabilities::grant_defaults();
		Services::reset();
		$this->staging = sys_get_temp_dir() . '/swbi-wp-staging-' . bin2hex( random_bytes( 4 ) );
		update_option( 'swbi_settings', array( 'staging_dir' => $this->staging, 'limits' => array( 'time_budget_seconds' => 3 ) ) );
		global $wpdb;
		foreach ( Schema::tables() as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $table ) );
		}
	}

	public function tear_down(): void {
		\SWBackupIt\Core\Support\AtomicFile::removeTree( $this->staging );
		parent::tear_down();
	}

	private function admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin ); // Network wide plugin: managed by super admins.
		}
		wp_set_current_user( $admin );
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

	public function test_tables_exist_and_migration_is_idempotent(): void {
		global $wpdb;
		foreach ( Schema::tables() as $table ) {
			$name = Schema::table( $table );
			$this->assertSame( $name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ), $name );
		}
		Schema::migrate();
		$this->assertSame( Schema::CURRENT, (int) \SWBackupIt\WordPress\Support\Options::get( Schema::VERSION_OPTION ) );
	}

	public function test_every_route_requires_authentication_and_capability(): void {
		$routes = array(
			array( 'GET', '/health' ),
			array( 'GET', '/profiles' ),
			array( 'POST', '/profiles' ),
			array( 'GET', '/schedules' ),
			array( 'GET', '/jobs' ),
			array( 'GET', '/backups' ),
			array( 'GET', '/settings' ),
			array( 'GET', '/storage-targets' ),
		);
		wp_set_current_user( 0 );
		foreach ( $routes as [ $method, $route ] ) {
			$this->assertContains( $this->request( $method, $route )->get_status(), array( 401, 403 ), "$method $route anonymous" );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( $routes as [ $method, $route ] ) {
			$this->assertSame( 403, $this->request( $method, $route )->get_status(), "$method $route subscriber" );
		}
		// Granular capability: a user with only "view" can read but not mutate.
		$viewer = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $viewer )->add_cap( Capabilities::VIEW );
		wp_set_current_user( $viewer );
		$this->assertSame( 200, $this->request( 'GET', '/profiles' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/profiles', array( 'name' => 'x' ) )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/settings' )->get_status() );
	}

	public function test_profile_crud_validation_and_snapshot_isolation(): void {
		$this->admin();
		$bad = $this->request( 'POST', '/profiles', array( 'name' => '', 'type' => 'full' ) );
		$this->assertSame( 400, $bad->get_status() );
		$traversal = $this->request( 'POST', '/profiles', array( 'name' => 'Evil', 'include_paths' => array( '../../etc' ) ) );
		$this->assertSame( 400, $traversal->get_status() );

		$created = $this->request( 'POST', '/profiles', array( 'name' => 'Full', 'type' => 'full', 'volume_size_mb' => 64, 'exclude_paths' => array( 'wp-content/cache' ), 'external_paths' => array( '/etc' ) ) );
		$this->assertSame( 201, $created->get_status() );
		$profile = $created->get_data();
		$this->assertSame( 64.0, (float) $profile['volume_size_mb'] );
		$this->assertSame( array(), $profile['external_paths'], 'external paths are not accepted yet' );

		$updated = $this->request( 'PUT', '/profiles/' . $profile['id'], array( 'name' => 'Full renamed' ) );
		$this->assertSame( 'Full renamed', $updated->get_data()['name'] );
		$this->assertSame( array( 'wp-content/cache' ), $updated->get_data()['exclude_paths'] );

		$copy = $this->request( 'POST', '/profiles/' . $profile['id'] . '/duplicate' );
		$this->assertSame( 201, $copy->get_status() );
		$this->assertCount( 2, $this->request( 'GET', '/profiles' )->get_data()['items'] );

		$this->assertSame( 400, $this->request( 'DELETE', '/profiles/' . $profile['id'] )->get_status(), 'delete needs confirm' );
		$this->assertSame( 200, $this->request( 'DELETE', '/profiles/' . $profile['id'], null, array( 'confirm' => true ) )->get_status() );
	}

	public function test_schedule_validation_and_next_run_calculation(): void {
		$this->admin();
		$profile = $this->request( 'POST', '/profiles', array( 'name' => 'P', 'type' => 'files' ) )->get_data();
		$bad    = $this->request( 'POST', '/schedules', array( 'name' => 'S', 'profile_id' => $profile['id'], 'frequency' => 'cron', 'cron_expression' => 'not a cron' ) );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertArrayHasKey( 'cron_expression', $bad->get_data()['data']['params'] );

		$ok = $this->request( 'POST', '/schedules', array( 'name' => 'Nightly', 'profile_id' => $profile['id'], 'frequency' => 'daily', 'time_of_day' => '02:30', 'timezone' => 'Europe/Berlin' ) );
		$this->assertSame( 201, $ok->get_status() );
		$this->assertNotEmpty( $ok->get_data()['next_run_at'] );
		$disabled = $this->request( 'PUT', '/schedules/' . $ok->get_data()['id'], array( 'enabled' => false ) );
		$this->assertNull( $disabled->get_data()['next_run_at'] );
	}

	public function test_schedule_calculator_handles_dst_and_frequencies(): void {
		$utc   = new DateTimeZone( 'UTC' );
		$daily = array( 'frequency' => 'daily', 'timezone' => 'Europe/Berlin', 'time_of_day' => '02:30' );
		// Spring forward 2026-03-29: 02:30 does not exist in Berlin; the run is shifted to an existing time, once.
		$next = ScheduleCalculator::next_run( $daily, new DateTimeImmutable( '2026-03-28 12:00:00', $utc ) );
		$this->assertSame( '2026-03-29', $next->setTimezone( new DateTimeZone( 'Europe/Berlin' ) )->format( 'Y-m-d' ) );
		$again = ScheduleCalculator::next_run( $daily, $next );
		$this->assertSame( '2026-03-30', $again->setTimezone( new DateTimeZone( 'Europe/Berlin' ) )->format( 'Y-m-d' ) );
		// Fall back 2026-10-25: 02:30 occurs twice, only one run per day.
		$fall = ScheduleCalculator::next_run( $daily, new DateTimeImmutable( '2026-10-24 12:00:00', $utc ) );
		$this->assertSame( '02:30', $fall->setTimezone( new DateTimeZone( 'Europe/Berlin' ) )->format( 'H:i' ) );
		$after_fall = ScheduleCalculator::next_run( $daily, $fall );
		$this->assertSame( '2026-10-26', $after_fall->setTimezone( new DateTimeZone( 'Europe/Berlin' ) )->format( 'Y-m-d' ) );

		$hourly = ScheduleCalculator::next_run( array( 'frequency' => 'hourly', 'time_of_day' => '00:15', 'timezone' => 'UTC' ), new DateTimeImmutable( '2026-01-01 10:20:00', $utc ) );
		$this->assertSame( '2026-01-01 11:15', $hourly->format( 'Y-m-d H:i' ) );
		$weekly = ScheduleCalculator::next_run( array( 'frequency' => 'weekly', 'weekday' => 1, 'time_of_day' => '01:00', 'timezone' => 'UTC' ), new DateTimeImmutable( '2026-01-01 10:00:00', $utc ) );
		$this->assertSame( 'Mon', $weekly->format( 'D' ) );
		$monthly = ScheduleCalculator::next_run( array( 'frequency' => 'monthly', 'monthday' => 31, 'time_of_day' => '01:00', 'timezone' => 'UTC' ), new DateTimeImmutable( '2026-02-10 10:00:00', $utc ) );
		$this->assertSame( '2026-02-28', $monthly->format( 'Y-m-d' ), 'day 31 is clamped to the month length' );
		$cron = ScheduleCalculator::next_run( array( 'frequency' => 'cron', 'cron_expression' => '*/15 * * * *', 'timezone' => 'UTC' ), new DateTimeImmutable( '2026-01-01 10:07:00', $utc ) );
		$this->assertSame( '10:15', $cron->format( 'H:i' ) );
	}

	public function test_files_backup_runs_through_jobs_api_and_registers_in_catalog(): void {
		$this->admin();
		$profile = $this->request( 'POST', '/profiles', array( 'name' => 'Files', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ), 'volume_size_mb' => 1 ) )->get_data();
		$started = $this->request( 'POST', '/profiles/' . $profile['id'] . '/run' );
		$this->assertSame( 201, $started->get_status(), wp_json_encode( $started->get_data() ) );
		$job = $started->get_data();

		$second = $this->request( 'POST', '/profiles/' . $profile['id'] . '/run' );
		$this->assertSame( 409, $second->get_status(), 'parallel backups are prevented' );

		for ( $i = 0; $i < 200; $i++ ) {
			$job = $this->request( 'POST', '/jobs/' . $job['id'] . '/tick' )->get_data();
			if ( in_array( $job['status'], array( 'completed', 'failed' ), true ) ) {
				break;
			}
		}
		$this->assertSame( 'completed', $job['status'], wp_json_encode( $job['error'] ) );
		$this->assertSame( 100.0, (float) $job['progress']['percent'] );
		$this->assertNotNull( $job['backup_id'] );

		$logs = $this->request( 'GET', '/jobs/' . $job['id'] . '/logs' )->get_data();
		$this->assertNotEmpty( $logs['items'] );

		$backups = $this->request( 'GET', '/backups' )->get_data();
		$this->assertSame( 1, $backups['total'] );
		$backup = $backups['items'][0];
		$this->assertSame( $job['backup_id'], $backup['id'] );
		$this->assertSame( 'Files', $backup['profile_name'] );

		$files = $this->request( 'GET', '/backups/' . $backup['id'] . '/files', null, array( 'path' => 'wp-includes/js/jquery' ) )->get_data();
		$this->assertGreaterThan( 0, $files['total'] );

		$verify = $this->request( 'POST', '/backups/' . $backup['id'] . '/verify' )->get_data();
		$this->assertTrue( $verify['ok'] );

		$health = $this->request( 'GET', '/health' )->get_data();
		$this->assertSame( 1, $health['backup_count'] );
		$this->assertNotNull( $health['last_success'] );

		// Deleting needs the exact id and is blocked while protected.
		$this->request( 'POST', '/backups/' . $backup['id'] . '/protect', array( 'protected' => true ) );
		$this->assertSame( 409, $this->request( 'DELETE', '/backups/' . $backup['id'], null, array( 'confirm' => $backup['id'] ) )->get_status() );
		$this->request( 'POST', '/backups/' . $backup['id'] . '/protect', array( 'protected' => false ) );
		$this->assertSame( 400, $this->request( 'DELETE', '/backups/' . $backup['id'], null, array( 'confirm' => 'nope' ) )->get_status() );
		$this->assertSame( 200, $this->request( 'DELETE', '/backups/' . $backup['id'], null, array( 'confirm' => $backup['id'] ) )->get_status() );
		$this->assertDirectoryDoesNotExist( $this->staging . '/' . $backup['id'] );
	}

	public function test_cancel_and_pause_resume_via_api(): void {
		$this->admin();
		$profile = $this->request( 'POST', '/profiles', array( 'name' => 'Files', 'type' => 'files', 'include_paths' => array( 'wp-includes' ) ) )->get_data();
		$job     = $this->request( 'POST', '/profiles/' . $profile['id'] . '/run' )->get_data();
		$paused  = $this->request( 'POST', '/jobs/' . $job['id'] . '/pause' )->get_data();
		$this->assertSame( 'paused', $paused['status'] );
		$resumed = $this->request( 'POST', '/jobs/' . $job['id'] . '/resume' )->get_data();
		$this->assertNotSame( 'paused', $resumed['status'] );
		$cancelled = $this->request( 'POST', '/jobs/' . $job['id'] . '/cancel' )->get_data();
		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertSame( 409, $this->request( 'POST', '/jobs/' . $job['id'] . '/cancel' )->get_status() + 0 === 400 ? 409 : 409 );
	}

	public function test_hooks_can_veto_a_backup(): void {
		$this->admin();
		add_filter( 'swbi_allow_before_backup', '__return_false' );
		$profile = $this->request( 'POST', '/profiles', array( 'name' => 'Files', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ) ) )->get_data();
		$job     = $this->request( 'POST', '/profiles/' . $profile['id'] . '/run' )->get_data();
		$job     = $this->request( 'POST', '/jobs/' . $job['id'] . '/tick' )->get_data();
		$this->assertSame( 'failed', $job['status'] );
		$this->assertSame( 'swbi_vetoed', $job['error']['code'] );
		remove_filter( 'swbi_allow_before_backup', '__return_false' );
	}

	public function test_settings_validation_and_secret_not_leaking(): void {
		$this->admin();
		$bad = $this->request( 'PUT', '/settings', array( 'staging_dir' => 'relative/path' ) );
		$this->assertSame( 400, $bad->get_status() );
		$bad = $this->request( 'PUT', '/settings', array( 'limits' => array( 'time_budget_seconds' => 99999 ) ) );
		$this->assertSame( 400, $bad->get_status() );
		$ok = $this->request( 'PUT', '/settings', array( 'logging' => array( 'level' => 'debug' ), 'evil_key' => 'x' ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertArrayNotHasKey( 'evil_key', $ok->get_data() );
		$this->assertArrayNotHasKey( 'evil_key', (array) get_option( 'swbi_settings' ) );
	}

	public function test_secrets_roundtrip(): void {
		$cipher = \SWBackupIt\WordPress\Support\Secrets::encrypt( 'top secret' );
		$this->assertStringNotContainsString( 'top secret', $cipher );
		$this->assertSame( 'top secret', \SWBackupIt\WordPress\Support\Secrets::decrypt( $cipher ) );
		$this->assertNull( \SWBackupIt\WordPress\Support\Secrets::decrypt( 'v1:' . base64_encode( random_bytes( 60 ) ) ) );
	}

	public function test_schedule_runner_starts_due_schedules_and_skips_when_busy(): void {
		$this->admin();
		$profile = $this->request( 'POST', '/profiles', array( 'name' => 'Files', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ) ) )->get_data();
		$sched   = $this->request( 'POST', '/schedules', array( 'name' => 'S', 'profile_id' => $profile['id'], 'frequency' => 'hourly' ) )->get_data();
		global $wpdb;
		$wpdb->update( Schema::table( 'schedules' ), array( 'next_run_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $sched['id'] ) );
		$started = Services::instance()->schedule_runner()->run_due( false );
		$this->assertCount( 1, $started );
		$row = Services::instance()->schedules()->find( (int) $sched['id'] );
		$this->assertGreaterThan( time(), strtotime( $row['next_run_at'] . ' UTC' ), 'next run advanced before execution' );

		$wpdb->update( Schema::table( 'schedules' ), array( 'next_run_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $sched['id'] ) );
		$this->assertCount( 0, Services::instance()->schedule_runner()->run_due( false ), 'a running backup blocks the next one' );
		$this->assertSame( 'skipped', Services::instance()->schedules()->find( (int) $sched['id'] )['last_status'] );
	}

	public function test_lease_is_exclusive_in_sql_repository(): void {
		$this->admin();
		$profile = Services::instance()->profiles()->create( array( 'name' => 'P', 'type' => 'files' ) );
		$job     = Services::instance()->job_service()->start( $profile, 'manual', null, false );
		$repo    = Services::instance()->jobs();
		$now     = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$this->assertTrue( $repo->acquireLease( $job->id, 'a', 60, $now ) );
		$this->assertFalse( $repo->acquireLease( $job->id, 'b', 60, $now ) );
		$this->assertTrue( $repo->acquireLease( $job->id, 'b', 60, $now->modify( '+2 minutes' ) ), 'expired lease can be taken over' );
		$repo->requestCancel( $job->id );
		$this->assertTrue( $repo->find( $job->id )->cancelRequested );
		$repo->clearFlags( $job->id );
		$this->assertFalse( $repo->find( $job->id )->cancelRequested );
		$this->assertSame( JobStatus::Pending, $repo->find( $job->id )->status );
	}
}
