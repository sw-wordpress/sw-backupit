<?php
/**
 * Run now with another destination, copy/move of backups between storage targets.
 */

use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Runner\BackgroundChecks;
use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Capabilities;

class TransferTest extends WP_UnitTestCase {

	private string $staging = '';

	private int $admin = 0;

	/** @var list<string> */
	private array $remotes = array();

	public function set_up(): void {
		parent::set_up();
		Schema::migrate();
		Capabilities::grant_defaults();
		Services::reset();
		$this->staging = sys_get_temp_dir() . '/swbi-wp-staging-' . bin2hex( random_bytes( 4 ) );
		update_option( 'swbi_settings', array( 'staging_dir' => $this->staging, 'limits' => array( 'time_budget_seconds' => 3 ) ) );
		delete_option( 'swbi_transfers' );
		global $wpdb;
		foreach ( Schema::tables() as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $table ) );
		}
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $this->admin );
		}
		wp_set_current_user( $this->admin );
	}

	public function tear_down(): void {
		\SWBackupIt\Core\Support\AtomicFile::removeTree( $this->staging );
		foreach ( $this->remotes as $remote ) {
			\SWBackupIt\Core\Support\AtomicFile::removeTree( $remote );
		}
		delete_option( 'swbi_transfers' );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed>|null $body
	 */
	private function request( string $method, string $route, ?array $body = null, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/sw-backupit/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		if ( null !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function target( string $name, bool $immutable = false ): array {
		$path            = sys_get_temp_dir() . '/swbi-wp-remote-' . bin2hex( random_bytes( 4 ) );
		$this->remotes[] = $path;
		$target          = $this->request( 'POST', '/storage-targets', array( 'name' => $name, 'type' => 'local', 'immutable' => $immutable, 'config' => array( 'path' => $path ) ) )->get_data();
		$target['path']  = $path;
		return $target;
	}

	private function run_profile( int $profile_id, ?int $target_id = null ): array {
		$response = $this->request( 'POST', '/profiles/' . $profile_id . '/run', null === $target_id ? null : array( 'storage_target_id' => $target_id ) );
		$job      = $response->get_data();
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $job ) );
		for ( $i = 0; $i < 300; $i++ ) {
			$job = $this->request( 'POST', '/jobs/' . $job['id'] . '/tick' )->get_data();
			if ( in_array( $job['status'], array( 'completed', 'failed' ), true ) ) {
				break;
			}
		}
		$this->assertSame( 'completed', $job['status'], wp_json_encode( $job['error'] ) );
		return $job;
	}

	private function profile( string $type = 'files' ): int {
		return (int) $this->request( 'POST', '/profiles', array( 'name' => 'T ' . $type, 'type' => $type, 'include_paths' => array( 'wp-includes/js/jquery' ) ) )->get_data()['id'];
	}

	/**
	 * @return array<int, string> target id => location status
	 */
	private function locations( string $backup_id ): array {
		return wp_list_pluck( $this->request( 'GET', '/backups/' . $backup_id )->get_data()['locations'], 'status', 'target_id' );
	}

	private function finish_transfers(): array {
		$service = Services::instance()->transfers();
		for ( $i = 0; $i < 100 && $service->tick( 5.0 ); $i++ ) {
			continue;
		}
		return $this->request( 'GET', '/transfers' )->get_data();
	}

	public function test_run_now_can_use_another_destination(): void {
		$target  = $this->target( 'Quick' );
		$profile = $this->profile();

		$job = $this->run_profile( $profile, (int) $target['id'] );
		$this->assertSame( 'verified', $this->locations( $job['backup_id'] )[ (int) $target['id'] ] );
		$this->assertFileExists( $target['path'] . '/' . $job['backup_id'] . '/manifest.json' );
		$this->assertSame( array(), $this->request( 'GET', '/profiles/' . $profile )->get_data()['storage_target_ids'], 'the profile itself is unchanged' );

		$local = $this->run_profile( $profile, 0 );
		$this->assertSame( array( 0 ), array_keys( $this->locations( $local['backup_id'] ) ) );

		$this->assertSame( 400, $this->request( 'POST', '/profiles/' . $profile . '/run', array( 'storage_target_id' => 999999 ) )->get_status() );
	}

	public function test_copy_then_move_between_targets(): void {
		$first  = $this->target( 'First' );
		$second = $this->target( 'Second' );
		$job    = $this->run_profile( $this->profile() );
		$id     = $job['backup_id'];

		$queued = $this->request( 'POST', '/backups/' . $id . '/transfer', array( 'target_id' => (int) $first['id'] ) );
		$this->assertSame( 202, $queued->get_status(), wp_json_encode( $queued->get_data() ) );
		$this->assertSame( 0, $queued->get_data()['source_id'], 'the local copy is the default source' );
		$this->assertSame( 409, $this->request( 'POST', '/backups/' . $id . '/transfer', array( 'target_id' => (int) $second['id'] ) )->get_status(), 'one transfer per backup at a time' );
		$transfers = $this->finish_transfers();
		$this->assertSame( 'completed', $transfers[0]['status'], wp_json_encode( $transfers[0] ) );
		$this->assertSame( 'verified', $this->locations( $id )[ (int) $first['id'] ] );
		$this->assertTrue( BackgroundChecks::has_local_data( $id ), 'a copy keeps the source' );
		$this->assertSame( 409, $this->request( 'POST', '/backups/' . $id . '/transfer', array( 'target_id' => (int) $first['id'] ) )->get_status(), 'already stored there' );

		// Move from the first target to the second one: the first copy is removed after verification.
		$moved = $this->request( 'POST', '/backups/' . $id . '/transfer', array( 'target_id' => (int) $second['id'], 'source_id' => (int) $first['id'], 'move' => true ) );
		$this->assertSame( 202, $moved->get_status(), wp_json_encode( $moved->get_data() ) );
		$this->assertSame( 'completed', $this->finish_transfers()[0]['status'] );
		$locations = $this->locations( $id );
		$this->assertSame( 'verified', $locations[ (int) $second['id'] ] );
		$this->assertArrayNotHasKey( (int) $first['id'], $locations );
		$this->assertDirectoryDoesNotExist( $first['path'] . '/' . $id );
		$this->assertFileExists( $second['path'] . '/' . $id . '/manifest.json' );

		// Move the local copy away: only the manifest stays locally, the backup stays in the catalog.
		$this->request( 'POST', '/backups/' . $id . '/transfer', array( 'target_id' => (int) $first['id'], 'source_id' => 0, 'move' => true ) );
		$this->assertSame( 'completed', $this->finish_transfers()[0]['status'] );
		$this->assertFalse( BackgroundChecks::has_local_data( $id ) );
		$this->assertFileExists( \SWBackupIt\WordPress\Support\Settings::staging_dir() . '/' . $id . '/manifest.json' );
		$this->assertArrayNotHasKey( 0, $this->locations( $id ) );
		$this->assertSame( 400, $this->request( 'POST', '/backups/' . $id . '/transfer', array( 'target_id' => (int) $first['id'] + (int) $second['id'] + 1000, 'source_id' => 0 ) )->get_status(), 'unknown target' );
		$third = $this->target( 'Third' );
		$this->assertSame( 400, $this->request( 'POST', '/backups/' . $id . '/transfer', array( 'target_id' => (int) $third['id'], 'source_id' => 0 ) )->get_status(), 'no local source any more' );
	}

	public function test_chain_bases_are_copied_along(): void {
		$target  = $this->target( 'Chain' );
		$profile = $this->profile();
		$full    = $this->run_profile( $profile );
		$this->request( 'PUT', '/profiles/' . $profile, array( 'type' => 'incremental' ) );
		$incremental = $this->run_profile( $profile );

		$transfer = $this->request( 'POST', '/backups/' . $incremental['backup_id'] . '/transfer', array( 'target_id' => (int) $target['id'], 'move' => true ) )->get_data();
		$this->assertSame( array( $full['backup_id'], $incremental['backup_id'] ), $transfer['backups'] );
		$this->assertSame( 'completed', $this->finish_transfers()[0]['status'] );
		$this->assertSame( 'verified', $this->locations( $full['backup_id'] )[ (int) $target['id'] ] );
		$this->assertTrue( BackgroundChecks::has_local_data( $full['backup_id'] ), 'base backups are copied, never moved' );
		$this->assertFalse( BackgroundChecks::has_local_data( $incremental['backup_id'] ) );
	}

	public function test_guards_and_cancel(): void {
		$locked = $this->target( 'Vault', true );
		$other  = $this->target( 'Other' );
		$id     = $this->run_profile( $this->profile(), (int) $locked['id'] )['backup_id'];

		$refused = $this->request( 'POST', '/backups/' . $id . '/transfer', array( 'target_id' => (int) $other['id'], 'source_id' => (int) $locked['id'], 'move' => true ) );
		$this->assertSame( 400, $refused->get_status(), 'an immutable source cannot be emptied' );
		$this->assertSame( 409, $this->request( 'POST', '/backups/' . $id . '/transfer', array( 'target_id' => (int) $locked['id'], 'source_id' => (int) $locked['id'] ) )->get_status(), 'already stored on the destination' );

		$operator = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $operator )->add_cap( Capabilities::MANAGE_STORAGE );
		wp_set_current_user( $operator );
		if ( ! is_multisite() ) {
			$this->assertSame( 403, $this->request( 'POST', '/backups/' . $id . '/transfer', array( 'target_id' => (int) $other['id'], 'move' => true ) )->get_status(), 'moving needs the delete permission' );
		}
		wp_set_current_user( $this->admin );

		$queued = $this->request( 'POST', '/backups/' . $id . '/transfer', array( 'target_id' => (int) $other['id'] ) )->get_data();
		$this->assertSame( 200, $this->request( 'POST', '/transfers/' . $queued['id'] . '/cancel' )->get_status() );
		$this->assertSame( 'cancelled', $this->finish_transfers()[0]['status'] );
		$this->assertDirectoryDoesNotExist( $other['path'] . '/' . $id );
		$this->assertArrayNotHasKey( (int) $other['id'], $this->locations( $id ) );
		$this->assertSame( 404, $this->request( 'POST', '/transfers/' . $queued['id'] . '/cancel' )->get_status() );
	}

	public function test_delete_removes_every_copy_or_none(): void {
		$first  = $this->target( 'A' );
		$vault  = $this->target( 'Vault', true );
		$id     = $this->run_profile( $this->profile(), (int) $first['id'] )['backup_id'];
		$this->request( 'POST', '/backups/' . $id . '/transfer', array( 'target_id' => (int) $vault['id'] ) );
		$this->assertSame( 'completed', $this->finish_transfers()[0]['status'] );

		$refused = $this->request( 'DELETE', '/backups/' . $id, null, array( 'confirm' => $id ) );
		$this->assertSame( 409, $refused->get_status(), 'an immutable copy blocks the deletion' );
		$this->assertFileExists( $first['path'] . '/' . $id . '/manifest.json', 'no copy is removed when one must be retained' );
		$this->assertSame( 'verified', $this->locations( $id )[ (int) $first['id'] ] );

		$other = $this->run_profile( $this->profile(), (int) $first['id'] )['backup_id'];
		$this->assertSame( 200, $this->request( 'DELETE', '/backups/' . $other, null, array( 'confirm' => $other ) )->get_status() );
		$this->assertDirectoryDoesNotExist( $first['path'] . '/' . $other, 'the storage target copy is deleted as well' );
		$this->assertSame( 404, $this->request( 'GET', '/backups/' . $other )->get_status() );
	}
}
