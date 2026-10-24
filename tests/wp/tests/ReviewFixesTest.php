<?php
/**
 * Regression tests for the WordPress adapter review fixes (capabilities, CLI, watchdog, schedules, verify, download,
 * concurrency, pre-update backups, DST, settings, uninstall).
 */

use SWBackupIt\Core\Backup\JobFactory;
use SWBackupIt\Core\Domain\JobStatus;
use SWBackupIt\Core\Event\Events;
use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Lifecycle;
use SWBackupIt\WordPress\Plugin;
use SWBackupIt\WordPress\Repository\ScheduleCalculator;
use SWBackupIt\WordPress\Rest\BackupsController;
use SWBackupIt\WordPress\Runner\BackgroundChecks;
use SWBackupIt\WordPress\Runner\JobWatchdog;
use SWBackupIt\WordPress\Runner\PreUpdateBackup;
use SWBackupIt\WordPress\Runner\RestoreTestService;
use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Capabilities;
use SWBackupIt\WordPress\Support\Options;
use SWBackupIt\WordPress\Support\Settings;

if ( ! class_exists( 'WP_CLI' ) ) {
	// Minimal stand-in: WP-CLI is not installed in the test environment.
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_CLI {
		/** @var list<string> */
		public static array $lines = array();

		public static function log( string $message ): void {
			self::$lines[] = $message;
		}

		public static function debug( string $message, $group = false ): void {
		}

		public static function success( string $message ): void {
			self::$lines[] = 'Success: ' . $message;
		}

		public static function line( string $message = '' ): void {
			self::$lines[] = $message;
		}

		public static function error( string $message ): void {
			throw new RuntimeException( $message );
		}
	}
}

class ReviewFixesTest extends WP_UnitTestCase {

	private string $staging = '';
	private string $remote  = '';

	/** @var list<array<string, mixed>> */
	private array $mail = array();

	public function set_up(): void {
		parent::set_up();
		Schema::migrate();
		Capabilities::grant_defaults();
		Services::reset();
		$this->staging = sys_get_temp_dir() . '/swbi-wp-fix-' . bin2hex( random_bytes( 4 ) );
		$this->remote  = sys_get_temp_dir() . '/swbi-wp-fixremote-' . bin2hex( random_bytes( 4 ) );
		Settings::update( array( 'staging_dir' => $this->staging, 'limits' => array( 'time_budget_seconds' => 3 ) ) );
		delete_transient( 'swbi_pre_update_lock' );
		Options::delete( 'swbi_notification_log' );
		global $wpdb;
		foreach ( Schema::tables() as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $table ) );
		}
		$this->mail = array();
		add_filter(
			'pre_wp_mail',
			function ( $return, $atts ) {
				$this->mail[] = $atts;
				return true;
			},
			10,
			2
		);
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user );
		}
		wp_set_current_user( $user );
	}

	public function tear_down(): void {
		\SWBackupIt\Core\Support\AtomicFile::removeTree( $this->staging );
		\SWBackupIt\Core\Support\AtomicFile::removeTree( $this->remote );
		unset( $_SERVER['DOCUMENT_ROOT'] );
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

	/**
	 * Runs a small files backup to completion.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} Profile and job (REST representation).
	 */
	private function backup( array $profile_overrides = array() ): array {
		$profile = $this->request( 'POST', '/profiles', $profile_overrides + array( 'name' => 'Fix', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ) ) )->get_data();
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

	private function age_job( string $id, string $when ): void {
		$job            = Services::instance()->jobs()->find( $id );
		$job->updatedAt = new DateTimeImmutable( $when, new DateTimeZone( 'UTC' ) );
		Services::instance()->jobs()->save( $job );
	}

	/**
	 * @return array<string, mixed> Schedule row.
	 */
	private function schedule( string $frequency = 'daily' ): array {
		$profile = $this->request( 'POST', '/profiles', array( 'name' => 'Sched', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ) ) )->get_data();
		$created = $this->request( 'POST', '/schedules', array( 'name' => 'S', 'profile_id' => $profile['id'], 'frequency' => $frequency ) )->get_data();
		return (array) Services::instance()->schedules()->find( (int) $created['id'] );
	}

	private function make_due( int $schedule_id ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'schedules' ), array( 'next_run_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $schedule_id ) );
	}

	// 1. Capabilities.

	public function test_schema_bump_grants_capabilities_on_the_site(): void {
		$role = get_role( 'administrator' );
		foreach ( Capabilities::all() as $cap ) {
			$role->remove_cap( $cap );
		}
		Options::delete( Schema::VERSION_OPTION );
		Plugin::instance()->maybe_migrate();
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::RESTORE ) );
		$this->assertSame( Schema::CURRENT, (int) Options::get( Schema::VERSION_OPTION ) );
	}

	// 2. WP-CLI subcommand names.

	public function test_cli_methods_with_underscores_declare_their_subcommand_name(): void {
		$files = glob( dirname( __DIR__, 3 ) . '/packages/wordpress/src/Cli/*Command.php' ) ?: array();
		$this->assertNotEmpty( $files );
		$checked = 0;
		foreach ( $files as $file ) {
			$class = 'SWBackupIt\\WordPress\\Cli\\' . basename( $file, '.php' );
			foreach ( ( new ReflectionClass( $class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
				if ( $method->isStatic() || str_starts_with( $method->name, '__' ) || ! str_contains( $method->name, '_' ) ) {
					continue;
				}
				$expected = str_replace( '_', '-', rtrim( $method->name, '_' ) );
				$this->assertMatchesRegularExpression( '/@subcommand\s+' . preg_quote( $expected, '/' ) . '\s/', (string) $method->getDocComment(), $class . '::' . $method->name . ' would not be reachable as "' . $expected . '"' );
				++$checked;
			}
		}
		$this->assertGreaterThanOrEqual( 6, $checked, 'list (4x), run-due, test-restore' );
	}

	// 3. Watchdog failures run through the failure contract.

	public function test_watchdog_failure_notifies_and_schedules_the_retry(): void {
		$row    = $this->schedule();
		$job_id = Services::instance()->schedule_runner()->start( $row, 'schedule', false );
		$this->assertSame( 'running', Services::instance()->schedules()->find( (int) $row['id'] )['last_status'] );
		$fired = 0;
		add_action(
			'swbi_backup_failed',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);
		$this->age_job( (string) $job_id, '-3 days' );
		$this->assertSame( 1, ( new JobWatchdog() )->run()['failed'] );
		$this->assertSame( JobStatus::Failed, Services::instance()->jobs()->find( (string) $job_id )->status );
		$this->assertSame( 1, $fired, 'the public failure hook fired' );
		$schedule = Services::instance()->schedules()->find( (int) $row['id'] );
		$this->assertSame( 'failed', $schedule['last_status'] );
		$this->assertSame( 1, (int) $schedule['retry_count'], 'the schedule retry was planned' );
		$this->assertCount( 1, $this->mail, 'the administrator was notified' );
	}

	// 4. Cancelling a scheduled job.

	public function test_cancelling_a_scheduled_job_updates_the_schedule_status(): void {
		$row    = $this->schedule();
		$job_id = (string) Services::instance()->schedule_runner()->start( $row, 'schedule', false );
		Services::instance()->job_service()->cancel( $job_id );
		$this->assertSame( 'cancelled', Services::instance()->schedules()->find( (int) $row['id'] )['last_status'] );

		// Cancel applied by the runner between two work units (a worker held the job when cancel was requested).
		$job_id = (string) Services::instance()->schedule_runner()->start( $row, 'schedule', false );
		$this->assertSame( 'running', Services::instance()->schedules()->find( (int) $row['id'] )['last_status'] );
		Services::instance()->jobs()->requestCancel( $job_id );
		$this->assertSame( JobStatus::Cancelled, Services::instance()->job_service()->tick( $job_id, 1.0 )->status );
		$this->assertSame( 'cancelled', Services::instance()->schedules()->find( (int) $row['id'] )['last_status'] );
	}

	// 5. Verification without local data.

	public function test_verification_skips_backups_whose_local_copy_was_removed(): void {
		[ , $job ] = $this->backup();
		$id        = $job['backup_id'];
		foreach ( glob( $this->staging . '/' . $id . '/volumes/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		touch( $this->staging . '/' . $id . '/.local-copy-removed' );
		$this->assertSame( $id, ( new BackgroundChecks() )->verify_oldest() );
		$this->assertSame( 'complete', $this->request( 'GET', '/backups/' . $id )->get_data()['status'] );
		$this->assertCount( 0, $this->mail );
		$response = $this->request( 'POST', '/backups/' . $id . '/verify' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'swbi_no_local_copy', $response->get_data()['code'] );
		$this->assertSame( 'complete', $this->request( 'GET', '/backups/' . $id )->get_data()['status'] );
	}

	public function test_verification_skips_manifest_only_imports_but_still_detects_lost_data(): void {
		$target    = $this->request( 'POST', '/storage-targets', array( 'name' => 'Replica', 'type' => 'local', 'config' => array( 'path' => $this->remote ) ) )->get_data();
		[ , $job ] = $this->backup( array( 'storage_target_ids' => array( (int) $target['id'] ) ) );
		$id        = $job['backup_id'];
		foreach ( glob( $this->staging . '/' . $id . '/volumes/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		unlink( $this->staging . '/' . $id . '/' . \SWBackupIt\Core\Manifest\Manifest::read( $this->staging . '/' . $id )->indexFileName() );
		$this->assertFalse( BackgroundChecks::has_local_data( $id ), 'only the manifest is local, a verified replica exists' );
		( new BackgroundChecks() )->verify_oldest();
		$this->assertSame( 'complete', $this->request( 'GET', '/backups/' . $id )->get_data()['status'] );
		$this->assertCount( 0, $this->mail );

		// Without any verified replica the missing data is a real defect.
		[ , $local ] = $this->backup();
		foreach ( glob( $this->staging . '/' . $local['backup_id'] . '/volumes/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		$this->assertTrue( BackgroundChecks::has_local_data( $local['backup_id'] ) );
		$verify = $this->request( 'POST', '/backups/' . $local['backup_id'] . '/verify' );
		$this->assertNotSame( 409, $verify->get_status() );
		if ( 200 === $verify->get_status() ) {
			$this->assertFalse( $verify->get_data()['ok'] );
			$this->assertSame( 'corrupt', $this->request( 'GET', '/backups/' . $local['backup_id'] )->get_data()['status'] );
		}
	}

	// 6. Download of encrypted artifacts.

	public function test_download_serves_the_stored_ciphertext_of_encrypted_backups(): void {
		$this->assertSame( 201, $this->request( 'POST', '/encryption-keys', array( 'id' => 'dl-key' ) )->get_status() );
		[ , $job ] = $this->backup( array( 'encryption' => array( 'enabled' => true, 'key_id' => 'dl-key' ) ) );
		$dir       = $this->staging . '/' . $job['backup_id'];
		$stored    = glob( $dir . '/volumes/*.swbe' ) ?: array();
		$this->assertCount( 1, $stored, 'volumes are stored encrypted' );

		$controller = new BackupsController();
		$request    = new WP_REST_Request( 'GET', '/swbackupit/v1/backups/' . $job['backup_id'] . '/download' );
		$request->set_param( 'id', $job['backup_id'] );
		$request->set_param( 'volume', 1 );
		$request->set_param( 'part', 'volume' );
		$path = $controller->download_path( $request );
		$this->assertSame( realpath( $stored[0] ), realpath( (string) $path ) );

		$request->set_param( 'part', 'index' );
		$this->assertStringEndsWith( '.swbe', (string) $controller->download_path( $request ) );

		$level = ob_get_level();
		ob_start();
		ob_start();
		echo 'noise from another plugin';
		$controller->stream( fopen( $stored[0], 'rb' ), $stored[0], $level + 1 );
		$sent = (string) ob_get_clean();
		$this->assertSame( $level, ob_get_level() );
		$this->assertSame( hash_file( 'sha256', $stored[0] ), hash( 'sha256', $sent ), 'byte identical, no buffered noise' );
	}

	// 7. + 8. Concurrency.

	public function test_paused_jobs_count_as_in_progress_and_resume_and_retry_check_conflicts(): void {
		$profile = Services::instance()->profiles()->create( array( 'name' => 'P', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ) ) );
		$service = Services::instance()->job_service();
		$first   = $service->start( $profile, 'manual', null, false );
		$service->pause( $first->id );
		$this->assertSame( JobStatus::Paused, Services::instance()->jobs()->find( $first->id )->status );
		try {
			$service->start( $profile, 'manual', null, false );
			$this->fail( 'a paused backup blocks a new one' );
		} catch ( SwbiException $exception ) {
			$this->assertArrayHasKey( 'conflict', $exception->context() );
		}

		// Another job got in (e.g. created before the fix): resuming must not run two backups in parallel.
		$other = ( new JobFactory() )->create( $profile, 'manual', null );
		Services::instance()->jobs()->create( $other );
		try {
			$service->resume( $first->id );
			$this->fail( 'resume checks for other running backups' );
		} catch ( SwbiException $exception ) {
			$this->assertArrayHasKey( 'conflict', $exception->context() );
		}
		$this->assertSame( JobStatus::Paused, Services::instance()->jobs()->find( $first->id )->status );

		$failed = Services::instance()->jobs()->find( $first->id );
		Services::instance()->runner()->stateMachine()->fail( $failed, array( 'code' => 'x', 'message' => 'x', 'retryable' => true ) );
		Services::instance()->jobs()->save( $failed );
		try {
			$service->retry( $first->id );
			$this->fail( 'retry checks for other running backups' );
		} catch ( SwbiException $exception ) {
			$this->assertArrayHasKey( 'conflict', $exception->context() );
		}
		Services::instance()->job_service()->cancel( $other->id );
		$service->retry( $first->id );
		$this->assertSame( JobStatus::RetryWaiting, Services::instance()->jobs()->find( $first->id )->status );
	}

	public function test_start_is_serialised_by_a_database_lock(): void {
		global $wpdb;
		$lock = 'swbi_job_start_' . substr( md5( DB_NAME . '|' . $wpdb->base_prefix ), 0, 16 );
		$pdo  = new PDO( 'mysql:host=127.0.0.1;port=' . ( getenv( 'SWBI_TEST_DB_PORT' ) ?: '33077' ) . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD );
		$this->assertSame( '1', (string) $pdo->query( "SELECT GET_LOCK('{$lock}', 0)" )->fetchColumn() );
		$profile = Services::instance()->profiles()->create( array( 'name' => 'L', 'type' => 'files' ) );
		try {
			Services::instance()->job_service()->start( $profile, 'manual', null, false );
			$this->fail( 'a concurrent start holds the lock' );
		} catch ( SwbiException $exception ) {
			$this->assertArrayHasKey( 'conflict', $exception->context() );
		} finally {
			$pdo->query( "SELECT RELEASE_LOCK('{$lock}')" );
		}
		$this->assertSame( 0, Services::instance()->jobs()->count() );
		Services::instance()->job_service()->start( $profile, 'manual', null, false );
		$this->assertSame( 1, Services::instance()->jobs()->count() );
	}

	public function test_due_schedule_is_claimed_once(): void {
		$row = $this->schedule();
		$this->make_due( (int) $row['id'] );
		$due = Services::instance()->schedules()->due( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
		$this->assertCount( 1, $due );
		$this->assertCount( 1, Services::instance()->schedule_runner()->run_due( false ) );
		// A second watcher that read the same due row before the first one advanced it loses the claim.
		$this->assertFalse( Services::instance()->schedules()->claim( (int) $row['id'], (string) $due[0]['next_run_at'], gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );
		$this->assertSame( 1, Services::instance()->jobs()->count() );
	}

	public function test_wp_cron_watcher_leaves_schedules_to_the_system_cron_in_system_mode(): void {
		Settings::update( array( 'cron' => array( 'mode' => 'system' ) ) );
		$row = $this->schedule();
		$this->make_due( (int) $row['id'] );
		set_transient( 'swbi_health_check', 1, 60 );
		Plugin::instance()->cron_watch();
		$this->assertSame( 0, Services::instance()->jobs()->count(), 'WP-Cron did not start the schedule' );

		// The system cron command starts it, runs the upkeep and the overdue maintenance.
		Options::update( Plugin::MAINTENANCE_OPTION, time() - 2 * DAY_IN_SECONDS );
		WP_CLI::$lines = array();
		( new \SWBackupIt\WordPress\Cli\ScheduleCommand() )->run_due( array(), array() );
		$this->assertContains( 'Success: 1 schedule(s) started.', WP_CLI::$lines, implode( "\n", WP_CLI::$lines ) );
		$this->assertGreaterThan( time() - 60, (int) Options::get( Plugin::MAINTENANCE_OPTION ) );

		// A job waiting for its retry is left for a later run: the command never sleeps (no process pile-up).
		$waiting              = ( new JobFactory() )->create( Services::instance()->profiles()->create( array( 'name' => 'W', 'type' => 'files' ) ), 'manual', null );
		$waiting->status      = JobStatus::RetryWaiting;
		$waiting->nextRetryAt = new DateTimeImmutable( '+1 hour', new DateTimeZone( 'UTC' ) );
		Services::instance()->jobs()->create( $waiting );
		$started = microtime( true );
		( new \SWBackupIt\WordPress\Cli\ScheduleCommand() )->run_due( array(), array() );
		$this->assertLessThan( 30, microtime( true ) - $started );
		$this->assertSame( JobStatus::RetryWaiting, Services::instance()->jobs()->find( $waiting->id )->status );
	}

	public function test_cli_waits_for_a_job_held_by_another_worker_instead_of_failing(): void {
		$profile = Services::instance()->profiles()->create( array( 'name' => 'C', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ) ) );
		$job     = Services::instance()->job_service()->start( $profile, 'manual', null, false );
		$this->assertTrue( Services::instance()->jobs()->acquireLease( $job->id, 'other-worker', 2, new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) ) );
		$command = new \SWBackupIt\WordPress\Cli\BackupCommand();
		$method  = new ReflectionMethod( $command, 'run_job' );
		$method->setAccessible( true );
		$this->assertSame( JobStatus::Pending, $method->invoke( $command, $job->id, false ), 'system cron mode: leave it' );
		$this->assertSame( JobStatus::Completed, $method->invoke( $command, $job->id ), 'interactive: wait for the lease and finish' );
	}

	// 9. Pre-update backups.

	public function test_pre_update_backup_ignores_installs_and_handles_bulk_updates(): void {
		$this->assertNull( PreUpdateBackup::update_type( array( 'type' => 'plugin', 'action' => 'install' ) ) );
		$this->assertNull( PreUpdateBackup::update_type( array( 'type' => 'theme', 'action' => 'install' ) ) );
		$this->assertNull( PreUpdateBackup::update_type( array( 'type' => 'translation', 'action' => 'update' ) ) );
		$this->assertSame( 'plugin', PreUpdateBackup::update_type( array( 'plugin' => 'akismet/akismet.php', 'temp_backup' => array() ) ), 'Plugin_Upgrader::bulk_upgrade()' );
		$this->assertSame( 'theme', PreUpdateBackup::update_type( array( 'theme' => 'twentytwenty' ) ), 'Theme_Upgrader::bulk_upgrade()' );
		$this->assertSame( 'plugin', PreUpdateBackup::update_type( array( 'plugin' => 'a/a.php', 'type' => 'plugin', 'action' => 'update' ) ) );

		$profile = $this->request( 'POST', '/profiles', array( 'name' => 'PreUpdate', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ) ) )->get_data();
		Settings::update( array( 'pre_update_backup' => array( 'plugins' => true, 'core' => true, 'profile_id' => (int) $profile['id'], 'wait_seconds' => 30 ) ) );
		$hook = new PreUpdateBackup();
		$this->assertTrue( $hook->before_install( true, array( 'type' => 'plugin', 'action' => 'install' ) ) );
		$this->assertTrue( $hook->before_install( true, array( 'type' => 'core', 'action' => 'update' ) ), 'core is handled in upgrader_pre_download' );
		$this->assertSame( 0, Services::instance()->jobs()->count(), 'installing a new plugin needs no backup' );
		$this->assertTrue( $hook->before_install( true, array( 'plugin' => 'akismet/akismet.php' ) ) );
		$this->assertSame( 1, Services::instance()->jobs()->count(), 'bulk plugin updates are backed up' );
		$this->assertNotFalse( has_filter( 'upgrader_pre_download' ), 'core updates are hooked where Core_Upgrader really passes' );
	}

	public function test_pre_update_backup_reuses_only_intact_recent_backups(): void {
		[ $profile, $job ] = $this->backup();
		Settings::update( array( 'pre_update_backup' => array( 'plugins' => true, 'profile_id' => (int) $profile['id'], 'wait_seconds' => 30 ) ) );
		Services::instance()->backups()->mark_verified( $job['backup_id'], 'corrupt' );
		( new PreUpdateBackup() )->before_install( true, array( 'type' => 'plugin', 'action' => 'update' ) );
		$this->assertSame( 2, Services::instance()->jobs()->count(), 'a corrupt recent backup is not reused' );

		delete_transient( 'swbi_pre_update_lock' );
		( new PreUpdateBackup() )->before_install( true, array( 'type' => 'plugin', 'action' => 'update' ) );
		$this->assertSame( 2, Services::instance()->jobs()->count(), 'the new intact backup is reused' );
	}

	// 10. DST.

	/**
	 * @return list<string> UTC run times.
	 */
	private function runs( array $schedule, string $from, int $count ): array {
		$at  = new DateTimeImmutable( $from, new DateTimeZone( 'UTC' ) );
		$out = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$at    = ScheduleCalculator::next_run( $schedule + array( 'timezone' => 'Europe/Berlin' ), $at );
			$out[] = $at->format( 'm-d H:i' );
		}
		return $out;
	}

	public function test_schedules_run_once_per_wall_clock_time_across_dst_changes(): void {
		// Fall back: 2026-10-25 03:00 CEST -> 02:00 CET (02:30 local exists at 00:30 and 01:30 UTC).
		$this->assertSame( array( '10-25 00:30', '10-26 01:30', '10-27 01:30' ), $this->runs( array( 'frequency' => 'cron', 'cron_expression' => '30 2 * * *' ), '2026-10-24 12:00', 3 ) );
		$this->assertSame( array( '10-26 01:30', '10-27 01:30' ), $this->runs( array( 'frequency' => 'cron', 'cron_expression' => '30 2 * * *' ), '2026-10-25 00:30', 2 ), 'the repeated 02:30 (01:30 UTC) is skipped' );
		$this->assertSame( array( '10-25 00:30', '10-26 01:30', '10-27 01:30' ), $this->runs( array( 'frequency' => 'daily', 'time_of_day' => '02:30' ), '2026-10-24 12:00', 3 ) );
		$this->assertSame( array( '10-25 00:30', '11-01 01:30' ), $this->runs( array( 'frequency' => 'weekly', 'weekday' => 0, 'time_of_day' => '02:30' ), '2026-10-20 12:00', 2 ) );
		$this->assertSame( array( '10-25 00:30', '11-25 01:30' ), $this->runs( array( 'frequency' => 'monthly', 'monthday' => 25, 'time_of_day' => '02:30' ), '2026-09-26 12:00', 2 ) );
		$this->assertSame(
			array( '10-24 23:30', '10-25 00:30', '10-25 01:30', '10-25 02:30' ),
			$this->runs( array( 'frequency' => 'hourly', 'time_of_day' => '00:30' ), '2026-10-24 23:00', 4 ),
			'hourly: both 02:30 (CEST and CET) run, nothing is skipped'
		);
		$this->assertSame( 4, count( array_unique( $this->runs( array( 'frequency' => 'cron', 'cron_expression' => '*/30 * * * *' ), '2026-10-25 00:10', 4 ) ) ), 'wildcard hours keep running' );

		// Spring forward: 2026-03-29 02:00 CET -> 03:00 CEST (02:30 local does not exist).
		$this->assertSame( array( '03-29 01:30', '03-30 00:30' ), $this->runs( array( 'frequency' => 'daily', 'time_of_day' => '02:30' ), '2026-03-28 12:00', 2 ) );
		$this->assertSame( array( '03-29 01:30', '03-30 00:30' ), $this->runs( array( 'frequency' => 'cron', 'cron_expression' => '30 2 * * *' ), '2026-03-28 12:00', 2 ) );
		$this->assertSame( array( '03-29 01:30', '04-05 00:30' ), $this->runs( array( 'frequency' => 'weekly', 'weekday' => 0, 'time_of_day' => '02:30' ), '2026-03-27 12:00', 2 ) );
		$this->assertSame( array( '03-29 01:30', '04-29 00:30' ), $this->runs( array( 'frequency' => 'monthly', 'monthday' => 29, 'time_of_day' => '02:30' ), '2026-03-01 12:00', 2 ) );
		$this->assertSame(
			array( '03-29 00:30', '03-29 01:30', '03-29 02:30' ),
			$this->runs( array( 'frequency' => 'hourly', 'time_of_day' => '00:30' ), '2026-03-29 00:00', 3 ),
			'hourly: 01:30 CET, (02:30 does not exist), 04:30 CEST'
		);
	}

	// 11. Log phases.

	public function test_job_logs_can_be_filtered_by_every_step_phase(): void {
		$profile = Services::instance()->profiles()->create( array( 'name' => 'Logs', 'type' => 'files' ) );
		$job     = Services::instance()->job_service()->start( $profile, 'manual', null, false );
		foreach ( array( 'manifest', 'init', 'verify_remote' ) as $phase ) {
			$this->assertSame( 200, $this->request( 'GET', '/jobs/' . $job->id . '/logs', null, array( 'phase' => $phase ) )->get_status(), $phase );
		}
		$this->assertSame( 400, $this->request( 'GET', '/jobs/' . $job->id . '/logs', null, array( 'phase' => 'bogus' ) )->get_status() );
	}

	// Further fixes.

	public function test_listener_failures_are_logged_not_swallowed(): void {
		$logged = array();
		add_action(
			'swbi_log',
			static function ( string $level, string $message, array $context ) use ( &$logged ): void {
				$logged[] = array( $level, $message, $context['event'] ?? '' );
			},
			10,
			3
		);
		$error_log = ini_set( 'error_log', '/dev/null' );
		$dispatcher = Services::instance()->dispatcher();
		$dispatcher->listen(
			Events::AFTER_UPLOAD,
			static function (): void {
				throw new RuntimeException( 'listener broke' );
			}
		);
		$dispatcher->dispatch( Events::AFTER_UPLOAD, array( 'job_id' => 'x' ) );
		ini_set( 'error_log', (string) $error_log );
		$this->assertCount( 1, $logged );
		$this->assertSame( Events::AFTER_UPLOAD, $logged[0][2] );
	}

	public function test_manual_run_of_a_schedule_does_not_trigger_the_schedule_retry(): void {
		$row  = $this->schedule();
		$next = (string) $row['next_run_at'];
		add_filter( 'swbi_allow_before_backup', '__return_false' );
		$job_id = (string) Services::instance()->schedule_runner()->start( $row, 'manual', false );
		Services::instance()->job_service()->tick( $job_id, 2.0 );
		remove_filter( 'swbi_allow_before_backup', '__return_false' );
		$this->assertSame( JobStatus::Failed, Services::instance()->jobs()->find( $job_id )->status );
		$schedule = Services::instance()->schedules()->find( (int) $row['id'] );
		$this->assertSame( 'failed', $schedule['last_status'] );
		$this->assertSame( $next, (string) $schedule['next_run_at'], 'timing untouched' );
		$this->assertSame( 0, (int) $schedule['retry_count'] );
	}

	public function test_editing_a_schedule_keeps_a_pending_retry_unless_timing_changes(): void {
		$row   = $this->schedule();
		$retry = gmdate( 'Y-m-d H:i:s', time() + 600 );
		Services::instance()->schedules()->update(
			(int) $row['id'],
			array(
				'next_run_at' => $retry,
				'retry_count' => 1,
			)
		);
		$this->request( 'PUT', '/schedules/' . $row['id'], array( 'name' => 'Renamed' ) );
		$fresh = Services::instance()->schedules()->find( (int) $row['id'] );
		$this->assertSame( 'Renamed', $fresh['name'] );
		$this->assertSame( $retry, $fresh['next_run_at'] );
		$this->assertSame( 1, (int) $fresh['retry_count'] );

		$this->request( 'PUT', '/schedules/' . $row['id'], array( 'time_of_day' => '04:15' ) );
		$fresh = Services::instance()->schedules()->find( (int) $row['id'] );
		$this->assertNotSame( $retry, $fresh['next_run_at'] );
		$this->assertSame( 0, (int) $fresh['retry_count'] );
	}

	public function test_migration_version_is_only_bumped_when_all_tables_exist(): void {
		global $wpdb;
		$audit = Schema::table( 'audit' );
		$hide  = static fn ( string $query ): string => str_contains( $query, "FROM `{$audit}` LIMIT 0" ) ? str_replace( $audit, $audit . '_missing', $query ) : $query;
		add_filter( 'query', $hide );
		Options::delete( Schema::VERSION_OPTION );
		$this->assertFalse( Schema::migrate() );
		$this->assertSame( 0, (int) Options::get( Schema::VERSION_OPTION, 0 ) );
		remove_filter( 'query', $hide );
		$this->assertTrue( Schema::migrate() );
		$this->assertSame( Schema::CURRENT, (int) Options::get( Schema::VERSION_OPTION ) );
		$this->assertSame( array(), Schema::missing_tables() );
		$this->assertNotEmpty( $wpdb->base_prefix );
	}

	public function test_settings_parse_booleans_and_do_not_pin_the_automatic_staging_directory(): void {
		$saved = Settings::update( array( 'delete_data_on_uninstall' => 'false', 'restore_test' => array( 'enabled' => '0' ) ) );
		$this->assertFalse( $saved['delete_data_on_uninstall'] );
		$this->assertFalse( $saved['restore_test']['enabled'] );
		$this->assertTrue( Settings::update( array( 'delete_data_on_uninstall' => 'true' ) )['delete_data_on_uninstall'] );

		Settings::update( array( 'staging_dir' => '' ) );
		Options::update( 'swbi_staging_dir', $this->staging );
		$get = $this->request( 'GET', '/settings' )->get_data();
		$this->assertSame( $this->staging, $get['staging_dir'] );
		$this->assertTrue( $get['staging_dir_auto'] );
		$put = $this->request( 'PUT', '/settings', $get );
		$this->assertSame( 200, $put->get_status() );
		$this->assertTrue( $put->get_data()['staging_dir_auto'], 'posting the resolved directory back keeps it automatic' );
		$this->assertSame( '', Settings::all()['staging_dir'] );
	}

	public function test_backup_directories_inside_the_web_root_are_refused(): void {
		$public = untrailingslashit( ABSPATH ) . '/wp-content/swbi-public-' . bin2hex( random_bytes( 3 ) );
		$this->assertWPError( Settings::update( array( 'staging_dir' => $public ) ) );
		$response = $this->request( 'POST', '/storage-targets', array( 'name' => 'Public', 'type' => 'local', 'config' => array( 'path' => $public ) ) );
		$this->assertSame( 400, $response->get_status() );

		// WordPress in a subdirectory: a sibling of ABSPATH is still below the document root.
		$_SERVER['DOCUMENT_ROOT'] = dirname( untrailingslashit( ABSPATH ) );
		$this->assertTrue( Settings::is_inside_docroot( dirname( untrailingslashit( ABSPATH ) ) . '/swbi-sibling-not-yet-created/x' ) );
		unset( $_SERVER['DOCUMENT_ROOT'] );
		$this->assertFalse( Settings::is_inside_docroot( $this->staging ) );

		// Explicitly allowed: accepted, but the health check reports an error.
		$this->assertIsArray( Settings::update( array( 'allow_public_paths' => true, 'staging_dir' => $public ) ) );
		$checks = wp_list_pluck( $this->request( 'GET', '/health' )->get_data()['checks'], 'status', 'id' );
		$this->assertSame( 'error', $checks['staging_public'] );
		Settings::update( array( 'staging_dir' => $this->staging, 'allow_public_paths' => false ) );
	}

	public function test_deactivation_and_uninstall_clean_up(): void {
		$wizard = untrailingslashit( ABSPATH ) . '/swbackupit-restore-' . bin2hex( random_bytes( 8 ) );
		mkdir( $wizard );
		file_put_contents( $wizard . '/index.php', '<?php' );
		Lifecycle::deactivate();
		$this->assertDirectoryDoesNotExist( $wizard, 'fresh wizard directories are removed on deactivation' );

		mkdir( $wizard );
		Settings::update( array( 'delete_data_on_uninstall' => true ) );
		foreach ( array( 'swbi_encryption_keys', 'swbi_staging_dir', 'swbi_notification_log', 'swbi_last_cron_tick', 'swbi_secret_key' ) as $option ) {
			Options::update( $option, 'x' );
		}
		Lifecycle::uninstall();
		$this->assertDirectoryDoesNotExist( $wizard );
		foreach ( array( 'swbi_settings', 'swbi_encryption_keys', 'swbi_staging_dir', 'swbi_notification_log', 'swbi_last_cron_tick', 'swbi_secret_key', 'swbi_db_version' ) as $option ) {
			$this->assertFalse( Options::get( $option ), $option );
		}
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::VIEW ) );
	}

	public function test_restore_test_results_are_mailed_with_the_profile_settings(): void {
		[ $profile, $job ] = $this->backup( array( 'notifications' => array( 'on_success' => true, 'on_failure' => true ) ) );
		$notify            = new ReflectionMethod( RestoreTestService::class, 'notify' );
		$notify->setAccessible( true );
		$notify->invoke( new RestoreTestService(), $job['backup_id'], 'passed' );
		$notify->invoke( new RestoreTestService(), $job['backup_id'], 'failed' );
		$subjects = wp_list_pluck( $this->mail, 'subject' );
		$this->assertCount( 3, $subjects, 'backup success + restore success + restore failure' );
		$this->assertStringContainsString( 'succeeded', $subjects[1] );
		$this->assertStringContainsString( 'failed', $subjects[2] );
		$this->assertNotEmpty( $profile );
	}
}
