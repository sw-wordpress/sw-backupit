<?php
/**
 * Default scope ("everything except cache") and translations.
 */

use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Capabilities;
use SWBackupIt\WordPress\Support\CoreMessages;
use SWBackupIt\WordPress\Support\DefaultProfiles;

class DefaultsAndLanguagesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::migrate();
		Capabilities::grant_defaults();
		Services::reset();
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

	/**
	 * @param array<string, mixed>|null $body
	 */
	private function request( string $method, string $route, ?array $body = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/swbackupit/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_activation_seeds_everything_profile_and_content_profile_once(): void {
		\SWBackupIt\WordPress\Lifecycle::activate();
		\SWBackupIt\WordPress\Lifecycle::activate(); // Idempotent: no duplicates.
		$profiles = $this->request( 'GET', '/profiles' )->get_data()['items'];
		$this->assertCount( 2, $profiles );
		$by_type = array_column( $profiles, null, 'type' );
		$full    = $by_type['full'];
		$this->assertSame( array(), $full['include_paths'], 'whole WordPress directory' );
		$this->assertSame( DefaultProfiles::CACHE_EXCLUDES, $full['exclude_paths'], 'only caches are excluded' );
		$this->assertSame( array(), $full['exclude_patterns'], 'logs and everything else are part of the copy' );
		$content = $by_type['incremental'];
		$this->assertSame( array( 'wp-content/uploads', 'wp-content/themes', 'wp-content/plugins' ), $content['include_paths'] );
	}

	public function test_new_profiles_default_to_everything_without_cache(): void {
		$created = $this->request( 'POST', '/profiles', array( 'name' => 'Mine' ) )->get_data();
		$this->assertSame( 'full', $created['type'] );
		$this->assertSame( array(), $created['include_paths'] );
		$this->assertSame( DefaultProfiles::CACHE_EXCLUDES, $created['exclude_paths'] );
		$explicit = $this->request( 'POST', '/profiles', array( 'name' => 'Explicit', 'type' => 'files', 'exclude_paths' => array( 'wp-content/big' ) ) )->get_data();
		$this->assertSame( array( 'wp-content/big' ), $explicit['exclude_paths'], 'explicit input wins' );
	}

	public function test_cache_directories_are_really_left_out_and_everything_else_is_in(): void {
		$staging = sys_get_temp_dir() . '/swbi-def-' . bin2hex( random_bytes( 4 ) );
		\SWBackupIt\WordPress\Support\Settings::update( array( 'staging_dir' => $staging ) );
		$profile = Services::instance()->profiles()->create( array_merge( DefaultProfiles::full(), array( 'type' => 'files', 'include_paths' => array( 'wp-includes/js/jquery' ), 'exclude_paths' => array( 'wp-includes/js/jquery/ui' ) ) ) );
		$job     = Services::instance()->job_service()->start( $profile, 'manual', null, false );
		do {
			$result = Services::instance()->job_service()->tick( $job->id, 10.0 );
		} while ( $result->shouldContinue );
		$paths = array();
		foreach ( ( new \SWBackupIt\Core\Scanner\FileIndexReader( $staging . '/' . $job->backupId . '/files.ndjson' ) )->read() as [ $entry ] ) {
			$paths[] = $entry['p'];
		}
		$this->assertContains( 'wp-includes/js/jquery/jquery.min.js', $paths );
		$this->assertSame( array(), array_filter( $paths, static fn ( string $p ): bool => str_starts_with( $p, 'wp-includes/js/jquery/ui' ) ) );
		\SWBackupIt\Core\Support\AtomicFile::removeTree( $staging );
	}

	public function test_translations_load_for_shipped_languages_and_regional_variants(): void {
		$dir = dirname( __DIR__, 3 ) . '/languages';
		foreach ( array(
			'de_DE' => null,
			'fr_FR' => null,
			'pl_PL' => null,
			'it_IT' => null,
		) as $locale => $expected ) {
			unload_textdomain( 'swbackupit' );
			$this->assertTrue( load_textdomain( 'swbackupit', $dir . '/swbackupit-' . $locale . '.mo', $locale ), $locale );
			$name = DefaultProfiles::full()['name'];
			$this->assertNotSame( 'Full backup (everything, without cache)', $name, $locale . ' is translated' );
			if ( null !== $expected ) {
				$this->assertSame( $expected, $name );
			}
		}
		// Regional variant falls back to the language we ship.
		$plugin = \SWBackupIt\WordPress\Plugin::instance();
		$this->assertSame( $dir . '/swbackupit-de_DE.mo', $plugin->fallback_mofile( $dir . '/swbackupit-de_CH.mo', 'swbackupit' ) );
		$this->assertSame( $dir . '/swbackupit-fr_FR.mo', $plugin->fallback_mofile( $dir . '/swbackupit-fr_CA.mo', 'swbackupit' ) );
		$this->assertSame( $dir . '/swbackupit-ja.mo', $plugin->fallback_mofile( $dir . '/swbackupit-ja.mo', 'swbackupit' ), 'unknown languages stay English' );
		unload_textdomain( 'swbackupit' );
	}

	public function test_core_messages_are_translated_in_rest_errors(): void {
		$dir = dirname( __DIR__, 3 ) . '/languages';
		load_textdomain( 'swbackupit', $dir . '/swbackupit-de_DE.mo', 'de_DE' );
		$translated = CoreMessages::translate( 'Archive volume checksum mismatch.' );
		$this->assertNotSame( 'Archive volume checksum mismatch.', $translated );
		$this->assertSame( 'Message that is not in the catalog', CoreMessages::translate( 'Message that is not in the catalog' ) );
		unload_textdomain( 'swbackupit' );
	}
}
