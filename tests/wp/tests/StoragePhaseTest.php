<?php
/**
 * Phase 2 integration tests of the WordPress adapter: storage targets, encryption keys, notifications, compare, restore wizard.
 */

use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Capabilities;

class StoragePhaseTest extends WP_UnitTestCase {

	private string $staging = '';
	private string $remote  = '';

	public function set_up(): void {
		parent::set_up();
		Schema::migrate();
		Capabilities::grant_defaults();
		Services::reset();
		$this->staging = sys_get_temp_dir() . '/swbi-wp-staging-' . bin2hex( random_bytes( 4 ) );
		$this->remote  = sys_get_temp_dir() . '/swbi-wp-remote-' . bin2hex( random_bytes( 4 ) );
		update_option( 'swbi_settings', array( 'staging_dir' => $this->staging, 'limits' => array( 'time_budget_seconds' => 3 ), 'notifications' => array( 'dedupe_minutes' => 60 ) ) );
		delete_option( 'swbi_notification_log' );
		global $wpdb;
		foreach ( Schema::tables() as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $table ) );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		\SWBackupIt\Core\Support\AtomicFile::removeTree( $this->staging );
		\SWBackupIt\Core\Support\AtomicFile::removeTree( $this->remote );
		foreach ( glob( untrailingslashit( ABSPATH ) . '/swbackupit-restore-*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			\SWBackupIt\Core\Support\AtomicFile::removeTree( $dir );
		}
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

	private function run_profile( int $profile_id ): array {
		$job = $this->request( 'POST', '/profiles/' . $profile_id . '/run' )->get_data();
		for ( $i = 0; $i < 300; $i++ ) {
			$job = $this->request( 'POST', '/jobs/' . $job['id'] . '/tick' )->get_data();
			if ( in_array( $job['status'], array( 'completed', 'failed' ), true ) ) {
				break;
			}
		}
		return $job;
	}

	public function test_storage_target_credentials_are_write_only_and_validated(): void {
		$bad = $this->request( 'POST', '/storage-targets', array( 'name' => 'x', 'type' => 'local', 'config' => array( 'path' => '/tmp/../etc' ) ) );
		$this->assertSame( 400, $bad->get_status() );
		$ssrf = $this->request( 'POST', '/storage-targets', array( 'name' => 'S3', 'type' => 's3', 'config' => array( 'endpoint' => 'https://169.254.169.254', 'bucket' => 'abcdef' ), 'credentials' => array( 'access_key' => 'a', 'secret_key' => 'b' ) ) );
		$this->assertSame( 400, $ssrf->get_status(), 'SSRF to cloud metadata is refused' );

		$created = $this->request( 'POST', '/storage-targets', array( 'name' => 'Local replica', 'type' => 'local', 'config' => array( 'path' => $this->remote ) ) );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );

		$s3 = $this->request( 'POST', '/storage-targets', array( 'name' => 'Bucket', 'type' => 's3', 'config' => array( 'endpoint' => 'https://93.184.216.34', 'bucket' => 'my-bucket', 'region' => 'eu-central-1' ), 'credentials' => array( 'access_key' => 'AKIAEXAMPLE', 'secret_key' => 'SECRETVALUE' ) ) );
		$this->assertSame( 201, $s3->get_status(), wp_json_encode( $s3->get_data() ) );
		$data = $s3->get_data();
		$this->assertTrue( $data['credentials_set'] );
		$this->assertStringNotContainsString( 'SECRETVALUE', (string) wp_json_encode( $this->request( 'GET', '/storage-targets' )->get_data() ) );
		global $wpdb;
		$stored = (string) $wpdb->get_var( 'SELECT credentials FROM ' . Schema::table( 'storage_targets' ) . ' WHERE id = ' . (int) $data['id'] );
		$this->assertStringNotContainsString( 'SECRETVALUE', $stored, 'credentials are encrypted at rest' );
		$this->assertStringStartsWith( 'v1:', $stored );

		// Updating without credentials keeps them.
		$this->request( 'PUT', '/storage-targets/' . $data['id'], array( 'name' => 'Bucket renamed' ) );
		$target = Services::instance()->storage_targets()->find( (int) $data['id'] );
		$this->assertSame( 'SECRETVALUE', $target->credentials['secret_key'] );
		$this->assertSame( 'Bucket renamed', $target->name );
	}

	public function test_subscribers_cannot_manage_storage_or_keys(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->request( 'POST', '/storage-targets', array( 'name' => 'x', 'type' => 'local', 'config' => array( 'path' => $this->remote ) ) )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/encryption-keys' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/backups/' . wp_generate_uuid4() . '/restore/prepare' )->get_status() );
	}

	public function test_encrypted_backup_is_replicated_and_wizard_can_be_prepared(): void {
		$target = $this->request( 'POST', '/storage-targets', array( 'name' => 'Replica', 'type' => 'local', 'config' => array( 'path' => $this->remote ) ) )->get_data();
		$this->assertTrue( $this->request( 'POST', '/storage-targets/' . $target['id'] . '/test' )->get_data()['ok'] );

		$key = $this->request( 'POST', '/encryption-keys', array( 'id' => 'site-key' ) );
		$this->assertSame( 201, $key->get_status() );
		$this->assertStringStartsWith( 'base64:', $key->get_data()['secret'] );
		$this->assertSame( 409, $this->request( 'POST', '/encryption-keys', array( 'id' => 'site-key' ) )->get_status() );
		$this->assertStringNotContainsString( 'base64:', (string) wp_json_encode( $this->request( 'GET', '/encryption-keys' )->get_data() ), 'secrets are never listed' );

		$profile = $this->request( 'POST', '/profiles', array(
			'name' => 'Encrypted', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ),
			'encryption' => array( 'enabled' => true, 'key_id' => 'site-key' ), 'storage_target_ids' => array( (int) $target['id'] ), 'success_policy' => 'all',
			'retention' => array( 'max_count' => 1 ),
		) )->get_data();
		$job = $this->run_profile( (int) $profile['id'] );
		$this->assertSame( 'completed', $job['status'], wp_json_encode( $job['error'] ) );

		$backup = $this->request( 'GET', '/backups' )->get_data()['items'][0];
		$this->assertTrue( $backup['encrypted'] );
		$statuses = wp_list_pluck( $backup['locations'], 'status', 'target_id' );
		$this->assertSame( 'verified', $statuses[ (int) $target['id'] ] );
		$this->assertFileExists( $this->remote . '/' . $backup['id'] . '/manifest.json' );

		// Browsing needs the key; the stored key makes it work.
		$files = $this->request( 'GET', '/backups/' . $backup['id'] . '/files', null, array( 'path' => 'wp-includes/js/jquery' ) );
		$this->assertSame( 200, $files->get_status() );

		$compare = $this->request( 'POST', '/backups/' . $backup['id'] . '/compare', array( 'mode' => 'sha256' ) );
		$this->assertSame( 200, $compare->get_status(), wp_json_encode( $compare->get_data() ) );
		$this->assertSame( 0, $compare->get_data()['modified'] );

		$prepared = $this->request( 'POST', '/backups/' . $backup['id'] . '/restore/prepare' );
		$this->assertSame( 200, $prepared->get_status(), wp_json_encode( $prepared->get_data() ) );
		$data = $prepared->get_data();
		$this->assertMatchesRegularExpression( '#/swbackupit-restore-[0-9a-f]{16}/$#', $data['restore_url'] );
		$dir = untrailingslashit( ABSPATH ) . '/' . basename( rtrim( $data['restore_url'], '/' ) );
		$this->assertFileExists( $dir . '/index.php' );
		$config = file_get_contents( $dir . '/config.php' );
		$this->assertStringNotContainsString( $data['token'], (string) $config, 'only the token hash is stored' );
		$this->assertStringContainsString( hash( 'sha256', $data['token'] ), (string) $config );
		$this->assertSame( 501, $this->request( 'POST', '/backups/' . $backup['id'] . '/restore' )->get_status(), 'restore never runs inside WordPress' );

		// Disaster recovery: forget the catalog, rebuild it from the storage target.
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'backups' ) );
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'backup_locations' ) );
		$discovered = $this->request( 'GET', '/storage-targets/' . $target['id'] . '/discover' )->get_data();
		$this->assertSame( $backup['id'], $discovered['items'][0]['backup_id'] );
		$this->assertFalse( $discovered['items'][0]['already_imported'] );
		$import = $this->request( 'POST', '/storage-targets/' . $target['id'] . '/import', array( 'backup_ids' => array( $backup['id'] ) ) );
		$this->assertSame( 1, $import->get_data()['imported'] );
		$this->assertSame( 1, $this->request( 'GET', '/backups' )->get_data()['total'] );
	}

	public function test_failure_notification_is_sent_once_and_deduplicated(): void {
		$sent = array();
		add_filter(
			'pre_wp_mail',
			static function ( $return, $atts ) use ( &$sent ) {
				$sent[] = $atts;
				return true;
			},
			10,
			2
		);
		add_filter( 'swbi_allow_before_backup', '__return_false' );
		$profile = $this->request( 'POST', '/profiles', array( 'name' => 'Failing', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ), 'notifications' => array( 'on_failure' => true, 'recipients' => array( 'ops@example.com' ) ) ) )->get_data();
		$this->run_profile( (int) $profile['id'] );
		$this->run_profile( (int) $profile['id'] );
		$this->assertCount( 1, $sent, 'the second identical failure within the dedupe window is not mailed again' );
		$this->assertSame( array( 'ops@example.com' ), (array) $sent[0]['to'] );
		$this->assertStringContainsString( 'Failing', $sent[0]['subject'] );
		$this->assertStringNotContainsString( 'password', strtolower( (string) $sent[0]['message'] ) );
		remove_filter( 'swbi_allow_before_backup', '__return_false' );
	}

	public function test_retention_cli_semantics_via_catalog_respects_protected_backups(): void {
		$profile = $this->request( 'POST', '/profiles', array( 'name' => 'Ret', 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ), 'retention' => array( 'max_count' => 2 ) ) )->get_data();
		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$job   = $this->run_profile( (int) $profile['id'] );
			$ids[] = $job['backup_id'];
			global $wpdb;
			$wpdb->update( Schema::table( 'backups' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 10 - $i ) * DAY_IN_SECONDS ) ), array( 'id' => $job['backup_id'] ) );
			if ( 0 === $i ) {
				$this->request( 'POST', '/backups/' . $job['backup_id'] . '/protect', array( 'protected' => true ) );
			}
		}
		$remaining = wp_list_pluck( $this->request( 'GET', '/backups' )->get_data()['items'], 'id' );
		$this->assertContains( $ids[0], $remaining, 'protected backup survives retention' );
		$this->assertContains( $ids[2], $remaining );
	}
}
