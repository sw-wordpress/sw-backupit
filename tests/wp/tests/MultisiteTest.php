<?php
/**
 * Multisite semantics: plugin data is network wide, a backup covers the whole network (run with phpunit-ms.xml.dist).
 */

use SWBackupIt\Core\Manifest\Manifest;
use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Capabilities;
use SWBackupIt\WordPress\Support\Settings;

class MultisiteTest extends WP_UnitTestCase {

	private string $staging = '';

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Run with phpunit-ms.xml.dist.' );
		}
		Schema::migrate();
		Capabilities::grant_defaults();
		Services::reset();
		$this->staging = sys_get_temp_dir() . '/swbi-ms-staging-' . bin2hex( random_bytes( 4 ) );
		Settings::update( array( 'staging_dir' => $this->staging, 'limits' => array( 'time_budget_seconds' => 3 ) ) );
		global $wpdb;
		foreach ( Schema::tables() as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $table ) );
		}
	}

	public function tear_down(): void {
		\SWBackupIt\Core\Support\AtomicFile::removeTree( $this->staging );
		parent::tear_down();
	}

	public function test_tables_and_settings_are_network_wide(): void {
		global $wpdb;
		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		$this->assertSame( $wpdb->base_prefix . 'swbi_jobs', Schema::table( 'jobs' ) );
		$this->assertNotSame( $wpdb->prefix . 'swbi_jobs', Schema::table( 'jobs' ) );
		$this->assertSame( $this->staging, Settings::staging_dir(), 'settings are read from the network' );
		restore_current_blog();
		$this->assertIsArray( get_site_option( 'swbi_settings' ) );
	}

	public function test_database_backup_covers_all_sites_of_the_network(): void {
		wp_set_current_user( 1 );
		grant_super_admin( 1 );
		$blog_id = self::factory()->blog->create();
		// The WP test suite creates subsite tables as TEMPORARY tables (invisible to the exporter's own connection):
		// create a real table following the subsite naming scheme through a separate connection.
		global $wpdb;
		$marker = $wpdb->base_prefix . $blog_id . '_posts_marker';
		$pdo    = new PDO( 'mysql:host=127.0.0.1;port=' . ( getenv( 'SWBI_TEST_DB_PORT' ) ?: '33077' ) . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD );
		$pdo->exec( "CREATE TABLE IF NOT EXISTS `{$marker}` (id INT PRIMARY KEY)" );
		$profile = Services::instance()->profiles()->create( array( 'name' => 'Network', 'type' => 'database' ) );
		$job     = Services::instance()->job_service()->start( $profile, 'manual', null, false );
		for ( $i = 0; $i < 100; $i++ ) {
			$result = Services::instance()->job_service()->tick( $job->id, 10.0 );
			if ( ! $result->shouldContinue ) {
				break;
			}
		}
		$job = Services::instance()->jobs()->find( $job->id );
		$this->assertSame( 'completed', $job->status->value, wp_json_encode( $job->error ) );
		$manifest = Manifest::read( $this->staging . '/' . $job->backupId );
		$this->assertTrue( (bool) $manifest->section( 'source' )['multisite'] );
		$tables = array_column( (array) $manifest->section( 'database' )['tables'], 'name' );
		$this->assertContains( $wpdb->base_prefix . 'posts', $tables );
		$this->assertContains( $marker, $tables, 'subsite tables are part of the network backup' );
		$pdo->exec( "DROP TABLE IF EXISTS `{$marker}`" );
		$this->assertContains( $wpdb->base_prefix . 'swbi_jobs', $tables );
	}

	public function test_super_admin_has_all_capabilities_and_normal_admin_of_a_subsite_does_not(): void {
		$super = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $super );
		wp_set_current_user( $super );
		$this->assertTrue( current_user_can( Capabilities::RESTORE ) );
		$blog_id = self::factory()->blog->create();
		$admin   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_user_to_blog( $blog_id, $admin, 'administrator' );
		wp_set_current_user( $admin );
		switch_to_blog( $blog_id );
		$this->assertFalse( current_user_can( Capabilities::RESTORE ), 'site admins of a subsite cannot restore or download the network' );
		restore_current_blog();
	}
}
