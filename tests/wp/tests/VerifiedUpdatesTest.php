<?php
/**
 * End-to-end signed GitHub releases with isolated HTTP and disposable signing keys.
 * Real upgrader tests only ever touch the private WordPress under tests/wp/vendor.
 */

use SWBackupIt\WordPress\Update\Manifest;
use SWBackupIt\WordPress\Update\Package;
use SWBackupIt\WordPress\Update\Provider;
use SWBackupIt\WordPress\Update\Service;
use SWBackupIt\WordPress\Update\Store;
use SWBackupIt\WordPress\Update\Transport;

final class SWBI_Verified_Update_Exit extends Error {}

final class VerifiedUpdatesTest extends WP_UnitTestCase {

	private const REPO = 'sw-wordpress/sw-backupit';

	private int $admin_id;
	private string $secret;
	/** @var array<string,string> */
	private array $keys;
	/** @var array<string,mixed> */
	private array $release;
	/** @var array<string,mixed> */
	private array $manifest;
	private string $raw;
	private string $signature;
	private string $zip;
	/** @var list<string> */
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();
		$_REQUEST       = array();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		if ( is_multisite() ) {
			grant_super_admin( $this->admin_id );
		}
		set_current_screen( is_multisite() ? 'plugins-network' : 'plugins' );
		delete_site_option( Store::STATE );
		delete_site_option( Store::LOCK );
		delete_option( Store::STATE );
		delete_option( Store::LOCK );
		$keypair      = sodium_crypto_sign_keypair();
		$this->secret = sodium_crypto_sign_secretkey( $keypair );
		$this->keys   = array( 'test' => base64_encode( sodium_crypto_sign_publickey( $keypair ) ) );
		add_filter( 'swbi_verified_release_public_keys', fn () => $this->keys );
		$this->zip = wp_tempnam( 'swbi-signed-test.zip' );
		$this->build_zip( $this->zip, array( 'sw-backupit/sw-backupit.php' => $this->main_file( '9.0.0' ) ) );
		$package         = $this->package_for( $this->zip, '9.0.0' );
		$this->release   = array(
			'id'         => 42,
			'tag_name'   => 'v9.0.0',
			'draft'      => false,
			'prerelease' => false,
			'assets'     => array(
				array( 'id' => 55, 'name' => $package['filename'], 'size' => $package['bytes'] ),
				array( 'id' => 56, 'name' => 'sw-plugin-release.json' ),
				array( 'id' => 57, 'name' => 'sw-plugin-release.json.sig' ),
			),
		);
		$this->manifest  = array(
			'schema'        => 1,
			'repository'    => Manifest::source()['repository'],
			'repository_id' => Manifest::source()['repository_id'],
			'release_id'    => 42,
			'tag'           => 'v9.0.0',
			'commit'        => str_repeat( 'a', 40 ),
			'issued_at'     => time(),
			'packages'      => array( $package ),
		);
		$this->sign();
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->requests[] = $url;
				if ( str_contains( $url, 'api.wordpress.org/' ) ) {
					$body = wp_json_encode( array( 'plugins' => array(), 'themes' => array(), 'no_update' => array(), 'translations' => array() ) );
				} elseif ( ! empty( $args['stream'] ) ) {
					copy( $this->zip, $args['filename'] );
					$body = '';
				} elseif ( str_ends_with( $url, '/releases/latest' ) ) {
					$body = wp_json_encode( $this->release );
				} elseif ( str_ends_with( $url, '.json.sig' ) ) {
					$body = $this->signature;
				} else {
					$body = $this->raw;
				}
				return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => 200 ) );
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		if ( is_multisite() ) {
			revoke_super_admin( $this->admin_id );
		}
		wp_delete_file( $this->zip );
		set_current_screen( 'front' );
		$_REQUEST = array();
		$_POST    = array();
		parent::tear_down();
	}

	private function main_file( string $version, string $requires_php = '8.2', string $requires_wp = '6.0' ): string {
		return "<?php\n/**\n * Plugin Name: Signed test plugin\n * Version: $version\n * Requires PHP: $requires_php\n * Requires at least: $requires_wp\n */\n";
	}

	/** @param array<string,string> $entries */
	private function build_zip( string $file, array $entries ): void {
		$archive = new ZipArchive();
		$archive->open( $file, ZipArchive::OVERWRITE );
		foreach ( $entries as $name => $content ) {
			$archive->addFromString( $name, $content );
		}
		$archive->close();
	}

	/** @return array<string,mixed> */
	private function package_for( string $file, string $version ): array {
		return array(
			'slug'         => 'sw-backupit',
			'version'      => $version,
			'asset_id'     => 55,
			'filename'     => "sw-backupit-$version.zip",
			'bytes'        => filesize( $file ),
			'sha256'       => hash_file( 'sha256', $file ),
			'requires_php' => '8.2',
			'requires_wp'  => '6.0',
			'main_file'    => 'sw-backupit.php',
		);
	}

	private function sign(): void {
		$this->raw       = (string) wp_json_encode( $this->manifest );
		$this->signature = (string) wp_json_encode( array( 'key_id' => 'test', 'signature' => base64_encode( sodium_crypto_sign_detached( $this->raw, $this->secret ) ) ) );
	}

	private function file(): string {
		return (string) array_key_first( Provider::descriptors() );
	}

	private function package_url(): string {
		return Manifest::url( $this->manifest, $this->manifest['packages'][0] );
	}

	/** Allows the next manual check by moving the persistent attempt timestamp into the past. */
	private function allow_next_check(): void {
		$state                 = Store::read();
		$state['last_attempt'] = time() - 61;
		Store::write( $state );
	}

	// ---- configuration and identity ----------------------------------------------------------------------------

	public function test_plugin_identity_is_bound_to_this_repository_and_header(): void {
		$source = Manifest::source();
		$this->assertSame( self::REPO, $source['repository'] );
		$this->assertSame( 1432510453, $source['repository_id'] );
		$this->assertSame( array( 'sw-backupit' ), $source['allowed_slugs'] );
		// The test WordPress loads the checkout from outside its plugin directory, so only the tail is stable here.
		$this->assertStringEndsWith( 'sw-backupit.php', $this->file() );
		$this->assertSame( SWBACKUPIT_VERSION, Provider::descriptors()[ $this->file() ]['version'] );
		$header = (string) file_get_contents( SWBACKUPIT_FILE );
		$this->assertMatchesRegularExpression( '~^ \* Update URI:\s+https://github.com/' . self::REPO . '$~m', $header );
		$this->assertSame( 'swbi_verified_github_updates_v1', Store::STATE );
		$this->assertSame( 'swbi_verified_github_updates_lock_v1', Store::LOCK );
		// The shipped key ring holds public Ed25519 keys only (32 bytes), never a 64-byte secret key.
		foreach ( (array) require SWBACKUPIT_DIR . 'packages/wordpress/src/Update/Config/trusted-keys.php' as $id => $key ) {
			$this->assertMatchesRegularExpression( '/^[a-zA-Z0-9_-]{1,64}$/', (string) $id );
			$this->assertSame( 32, strlen( (string) base64_decode( (string) $key, true ) ), "Key $id is not a 32-byte public key." );
		}
	}

	public function test_missing_key_blocks_everything_and_never_contacts_github(): void {
		// Independent of whatever key ring the checkout ships.
		remove_all_filters( 'swbi_verified_release_public_keys' );
		add_filter( 'swbi_verified_release_public_keys', '__return_empty_array' );
		$this->assertSame( array(), Service::keys() );
		$this->assertSame( 'updates_not_configured', Service::check( true )->get_error_code() );
		$this->assertCount( 0, $this->requests );
		$this->assertStringContainsString( 'blocked', Provider::status_text() );
		$this->assertStringContainsString( 'blocked', Provider::row_meta( array(), $this->file() )[0] );
	}

	public function test_foreign_global_key_constant_is_ignored(): void {
		if ( ! defined( 'SW_PLUGIN_RELEASE_PUBLIC_KEYS' ) ) {
			define( 'SW_PLUGIN_RELEASE_PUBLIC_KEYS', array( 'foreign' => base64_encode( random_bytes( 32 ) ) ) );
		}
		$this->assertArrayNotHasKey( 'foreign', Service::keys() );
		$this->assertIsArray( Service::check() );
	}

	// ---- checking policy --------------------------------------------------------------------------------------

	public function test_signed_release_is_cached_and_displayed_without_additional_http(): void {
		$this->assertIsArray( Service::check() );
		$this->assertCount( 3, $this->requests );
		$file   = $this->file();
		$offers = Provider::offers( (object) array( 'checked' => array( $file => SWBACKUPIT_VERSION ) ) );
		$this->assertSame( '9.0.0', $offers->response[ $file ]->new_version );
		$this->assertFalse( $offers->response[ $file ]->autoupdate );
		$this->assertCount( 3, $this->requests );
		$this->assertWPError( Service::check() );
		delete_site_transient( 'update_plugins' );
		delete_transient( 'update_plugins' );
		$this->assertWPError( Service::check( true ), 'Deleting transients must not lift the persistent limit.' );
		$this->assertCount( 3, $this->requests );
	}

	public function test_failed_requests_count_as_attempts_and_manual_checks_need_sixty_seconds(): void {
		$this->release['draft'] = true;
		$this->assertWPError( Service::check() );
		$this->assertWPError( Service::check() );
		$this->assertWPError( Service::check( true ), 'Manual check within 60 s of the failed attempt.' );
		$this->assertCount( 1, $this->requests );
		$this->allow_next_check();
		$this->release['draft'] = false;
		$this->assertIsArray( Service::check( true ) );
		$this->assertCount( 4, $this->requests );
	}

	public function test_daily_check_after_a_day_is_allowed_again(): void {
		$this->assertIsArray( Service::check() );
		$state                 = Store::read();
		$state['last_attempt'] = time() - DAY_IN_SECONDS - 1;
		Store::write( $state );
		$this->assertIsArray( Service::check() );
		$this->assertCount( 6, $this->requests );
	}

	public function test_frontend_unprivileged_and_cron_never_check_or_load_remote_offers(): void {
		set_current_screen( 'front' );
		$this->assertSame( 'updates_forbidden', Service::check( true )->get_error_code() );
		$value = (object) array( 'checked' => array() );
		$this->assertSame( $value, Provider::offers( $value ) );
		set_current_screen( is_multisite() ? 'plugins-network' : 'plugins' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( Service::check( true ) );
		wp_set_current_user( $this->admin_id );
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertWPError( Service::check( true ) );
		$this->assertCount( 0, $this->requests );
	}

	public function test_frontend_filters_add_no_database_queries(): void {
		set_current_screen( 'front' );
		$value  = (object) array( 'checked' => array() );
		$before = get_num_queries();
		$this->assertSame( $value, Provider::offers( $value ) );
		$this->assertSame( $before, get_num_queries() );
	}

	public function test_file_modifications_disabled_blocks_checks(): void {
		add_filter( 'file_mod_allowed', '__return_false' );
		$this->assertWPError( Service::check( true ) );
		$this->assertCount( 0, $this->requests );
	}

	public function test_busy_lock_and_lost_lease_are_reported(): void {
		$lock = Store::acquire();
		$this->assertSame( 'updates_busy', Service::check( true )->get_error_code() );
		Store::release( $lock );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( str_ends_with( $url, '/releases/latest' ) ) {
					// Another process steals the lease while the request is in flight.
					delete_site_option( Store::LOCK );
					delete_option( Store::LOCK );
				}
				return $pre;
			},
			1,
			3
		);
		$this->assertSame( 'updates_lease_expired', Service::check( true )->get_error_code() );
		$this->assertArrayNotHasKey( 'manifest', Store::read() );
	}

	public function test_malformed_github_responses_fail_closed(): void {
		foreach ( array( 'not json', '{"id":"x"}' ) as $body ) {
			$this->release = array();
			remove_all_filters( 'pre_http_request' );
			add_filter( 'pre_http_request', static fn () => array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => 200 ) ) );
			$this->assertWPError( Service::check( true ) );
			$this->allow_next_check();
		}
		$this->assertArrayNotHasKey( 'manifest', Store::read() );
	}

	public function test_missing_signature_asset_and_http_errors_fail_closed(): void {
		array_pop( $this->release['assets'] );
		$this->assertSame( 'missing_signature_asset', Service::check( true )->get_error_code() );
		$this->allow_next_check();
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', static fn () => array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 404 ) ) );
		$this->assertSame( 'update_http_error', Service::check( true )->get_error_code() );
		$this->allow_next_check();
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', static fn () => new WP_Error( 'down', 'down' ) );
		$this->assertSame( 'update_network_error', Service::check( true )->get_error_code() );
		$this->assertStringContainsString( 'reached', Store::read()['error'] );
	}

	// ---- signature and metadata ---------------------------------------------------------------------------------

	public function test_unknown_key_and_wrong_signature_fail_closed(): void {
		$this->keys = array( 'test' => base64_encode( random_bytes( 32 ) ) );
		$this->assertWPError( Service::check() );
		$this->assertArrayNotHasKey( 'manifest', Store::read() );
		$this->allow_next_check();
		$this->keys = array( 'other-id' => $this->keys['test'] );
		$this->assertWPError( Service::check( true ) );
	}

	public function test_a_single_changed_byte_invalidates_the_signature(): void {
		$this->raw = str_replace( '9.0.0', '9.0.1', $this->raw );
		$this->assertWPError( Service::check() );
		$this->assertArrayNotHasKey( 'manifest', Store::read() );
	}

	public function test_oversized_or_garbled_signature_envelopes_are_rejected(): void {
		$this->assertWPError( Manifest::verify( $this->raw, str_repeat( 'a', 2000 ), $this->keys, $this->release ) );
		$this->assertWPError( Manifest::verify( $this->raw, '[]', $this->keys, $this->release ) );
		$this->assertWPError( Manifest::verify( $this->raw, '{"key_id":"test","signature":"@@"}', $this->keys, $this->release ) );
		$this->assertWPError( Manifest::verify( str_repeat( 'a', Manifest::MAX_BYTES + 1 ), $this->signature, $this->keys, $this->release ) );
	}

	/**
	 * @dataProvider invalid_manifests
	 * @param mixed $value Replacement value.
	 */
	public function test_signed_but_invalid_metadata_is_rejected( string $field, $value ): void {
		$this->manifest[ $field ] = $value;
		$this->sign();
		$this->assertWPError( Service::check() );
		$this->assertArrayNotHasKey( 'manifest', Store::read() );
	}

	/** @return array<string,array{0:string,1:mixed}> */
	public static function invalid_manifests(): array {
		return array(
			'repository'     => array( 'repository', 'attacker/repo' ),
			'repository ID'  => array( 'repository_id', 1 ),
			'release ID'     => array( 'release_id', 1 ),
			'schema'         => array( 'schema', 2 ),
			'tag'            => array( 'tag', 'v9.0.1' ),
			'tag format'     => array( 'tag', 'latest' ),
			'commit'         => array( 'commit', 'abc' ),
			'future'         => array( 'issued_at', time() + 3600 ),
			'no packages'    => array( 'packages', array() ),
			'package object' => array( 'packages', array( 'a' => 1 ) ),
		);
	}

	/**
	 * @dataProvider invalid_packages
	 * @param mixed $value Replacement value.
	 */
	public function test_signed_but_invalid_package_entries_are_rejected( string $field, $value ): void {
		$this->manifest['packages'][0][ $field ] = $value;
		$this->sign();
		$this->assertWPError( Service::check() );
	}

	/** @return array<string,array{0:string,1:mixed}> */
	public static function invalid_packages(): array {
		return array(
			'slug not allowed' => array( 'slug', 'other-plugin' ),
			'version'          => array( 'version', '9.0.1' ),
			'filename'         => array( 'filename', 'sw-backupit-9.0.0.zip.exe' ),
			'main file'        => array( 'main_file', 'other.php' ),
			'asset id'         => array( 'asset_id', 99 ),
			'asset id type'    => array( 'asset_id', '55' ),
			'size'             => array( 'bytes', 1 ),
			'huge size'        => array( 'bytes', 52428801 ),
			'sha256'           => array( 'sha256', 'xyz' ),
			'requires php'     => array( 'requires_php', 'latest' ),
			'requires wp'      => array( 'requires_wp', null ),
		);
	}

	public function test_release_state_and_duplicate_package_entries_are_rejected(): void {
		$this->manifest['packages'][] = $this->manifest['packages'][0];
		$this->sign();
		$this->assertWPError( Service::check() );
		$this->allow_next_check();
		$this->manifest['packages'] = array( $this->manifest['packages'][0] );
		$this->sign();
		$this->release['prerelease'] = true;
		$this->assertWPError( Service::check( true ) );
		$this->allow_next_check();
		$this->release['prerelease'] = false;
		$this->release['assets'][0]['size'] = 3;
		$this->assertWPError( Service::check( true ) );
		$this->assertArrayNotHasKey( 'manifest', Store::read() );
	}

	public function test_even_validly_signed_duplicate_json_keys_are_rejected(): void {
		$this->raw       = str_replace( '"schema":1', '"schema":2,"schema":1', $this->raw );
		$this->signature = (string) wp_json_encode( array( 'key_id' => 'test', 'signature' => base64_encode( sodium_crypto_sign_detached( $this->raw, $this->secret ) ) ) );
		$this->assertWPError( Service::check() );
	}

	public function test_signed_rollback_cannot_replace_a_previously_verified_release(): void {
		$this->assertIsArray( Service::check() );
		$this->allow_next_check();
		$this->manifest['packages'][0]['version']  = '8.0.0';
		$this->manifest['packages'][0]['filename'] = 'sw-backupit-8.0.0.zip';
		$this->manifest['tag']                     = 'v8.0.0';
		$this->release['tag_name']                 = 'v8.0.0';
		$this->release['assets'][0]['name']        = 'sw-backupit-8.0.0.zip';
		$this->sign();
		$this->assertSame( 'release_rollback', Service::check( true )->get_error_code() );
		$this->assertSame( '9.0.0', Store::read()['highest']['sw-backupit'] );
		// A tampered state must not weaken the check either.
		$state                     = Store::read();
		$state['highest']['sw-backupit'] = '10.0.0';
		Store::write( $state );
		$this->assertSame( 'release_rollback', Service::verified_snapshot()->get_error_code() );
	}

	// ---- storage and lock ---------------------------------------------------------------------------------------

	public function test_lock_owner_cannot_release_another_request_lock(): void {
		$lock = Store::acquire();
		$this->assertIsArray( $lock );
		$this->assertFalse( Store::acquire() );
		Store::release( array( 'token' => 'wrong', 'expires' => 0 ) );
		$this->assertFalse( Store::acquire() );
		$this->assertTrue( Store::owns( $lock ) );
		Store::release( $lock );
		$this->assertFalse( Store::owns( $lock ) );
		$this->assertIsArray( $next = Store::acquire() );
		Store::release( $lock );
		$this->assertSame( $next, get_site_option( Store::LOCK ) );
	}

	public function test_expired_lock_is_taken_over_and_new_owner_is_preserved(): void {
		$old = array( 'token' => 'expired', 'expires' => time() - 10 );
		add_site_option( Store::LOCK, $old );
		$this->assertIsArray( $new = Store::acquire() );
		Store::release( $old );
		$this->assertSame( $new, get_site_option( Store::LOCK ) );
		$this->assertFalse( Store::owns( $old ) );
	}

	public function test_state_survives_and_is_not_autoloaded(): void {
		$this->assertIsArray( Service::check() );
		global $wpdb;
		$table = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
		$this->assertNotEmpty( Store::read()['manifest'] );
		if ( ! is_multisite() ) {
			$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$table} WHERE option_name = %s", Store::STATE ) );
			$this->assertNotContains( $autoload, array( 'yes', 'on', 'auto' ) );
		}
		update_site_option( Store::STATE, 'garbage' );
		$this->assertSame( array(), Store::read() );
	}

	// ---- download boundary ----------------------------------------------------------------------------------------

	public function test_core_download_boundary_returns_only_verified_zip_and_rejects_tampered_urls(): void {
		$this->assertIsArray( Service::check() );
		$file                 = $this->file();
		$_REQUEST['action']   = 'upgrade-plugin';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'upgrade-plugin_' . $file );
		$url                  = $this->package_url();
		$download             = apply_filters( 'upgrader_pre_download', false, $url, new stdClass(), array( 'plugin' => $file ) );
		$this->assertIsString( $download );
		$this->assertSame( $this->manifest['packages'][0]['sha256'], hash_file( 'sha256', $download ) );
		wp_delete_file( $download );
		$this->assertWPError( apply_filters( 'upgrader_pre_download', false, 'https://evil.invalid/a.zip', new stdClass(), array( 'plugin' => $file ) ) );
		$this->assertWPError( apply_filters( 'upgrader_pre_download', false, $url . '?x=1', new stdClass(), array( 'plugin' => $file ) ) );
		$this->assertWPError( apply_filters( 'upgrader_pre_download', false, $url, new stdClass(), array() ), 'Known provider URL without hook_extra.' );
		$_REQUEST['action'] = 'other-action';
		$this->assertWPError( apply_filters( 'upgrader_pre_download', false, $url, new stdClass(), array( 'plugin' => $file ) ) );
	}

	public function test_old_cached_offers_can_never_be_installed_automatically(): void {
		$this->assertIsArray( Service::check() );
		$file = $this->file();
		$this->assertFalse( apply_filters( 'auto_update_plugin', true, (object) array( 'plugin' => $file ) ) );
		$this->assertFalse( apply_filters( 'auto_update_plugin', true, (object) array( 'plugin' => $file, 'new_version' => '1.0.1' ) ) );
		$this->assertTrue( apply_filters( 'auto_update_plugin', true, (object) array( 'plugin' => 'other/other.php' ) ) );
		$this->assertTrue( apply_filters( 'auto_update_plugin', true, (object) array() ) );
		// Even a background (cron) request holding a perfectly valid cached offer cannot fetch the package.
		set_current_screen( 'front' );
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertSame( 'manual_update_required', apply_filters( 'upgrader_pre_download', false, $this->package_url(), new stdClass(), array( 'plugin' => $file ) )->get_error_code() );
		$this->assertCount( 3, $this->requests );
	}

	public function test_automatic_upgrader_skin_cannot_download_even_with_a_valid_nonce(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$this->assertIsArray( Service::check() );
		$file                 = $this->file();
		$_REQUEST['action']   = 'upgrade-plugin';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'upgrade-plugin_' . $file );
		$upgrader             = (object) array( 'skin' => new Automatic_Upgrader_Skin() );
		$this->assertSame( 'manual_update_required', apply_filters( 'upgrader_pre_download', false, $this->package_url(), $upgrader, array( 'plugin' => $file ) )->get_error_code() );
		$this->assertCount( 3, $this->requests );
	}

	public function test_invalid_native_update_nonce_never_downloads_a_package(): void {
		$this->assertIsArray( Service::check() );
		$file     = $this->file();
		$_REQUEST = array( 'action' => 'upgrade-plugin', '_wpnonce' => 'forged' );
		$this->assertWPError( apply_filters( 'upgrader_pre_download', false, $this->package_url(), new stdClass(), array( 'plugin' => $file ) ) );
		$_REQUEST = array( 'action' => 'upgrade-plugin', '_wpnonce' => wp_create_nonce( 'upgrade-plugin_other/other.php' ) );
		$this->assertWPError( apply_filters( 'upgrader_pre_download', false, $this->package_url(), new stdClass(), array( 'plugin' => $file ) ) );
		$_REQUEST = array();
		$this->assertWPError( apply_filters( 'upgrader_pre_download', false, $this->package_url(), new stdClass(), array( 'plugin' => $file ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( apply_filters( 'upgrader_pre_download', false, $this->package_url(), new stdClass(), array( 'plugin' => $file ) ) );
		$this->assertCount( 3, $this->requests );
	}

	public function test_expired_snapshot_and_corrupt_zip_are_rejected_before_core_installation(): void {
		$this->assertIsArray( Service::check() );
		$state                 = Store::read();
		$state['last_success'] = time() - 8 * DAY_IN_SECONDS;
		Store::write( $state );
		$this->assertSame( 'stale_update', Service::verified_snapshot()->get_error_code() );
		$offers = Provider::offers( (object) array( 'checked' => array( $this->file() => '1.0.0' ), 'response' => array() ) );
		$this->assertArrayNotHasKey( $this->file(), $offers->response );
		$state['last_success'] = time();
		Store::write( $state );
		file_put_contents( $this->zip, 'tampered package' );
		$descriptors = Provider::descriptors();
		$descriptor  = reset( $descriptors );
		$this->assertWPError( Service::download( $this->package_url(), $descriptor ) );
		$this->assertSame( 'untrusted_package', Service::download( $this->package_url(), array( 'slug' => 'sw-backupit', 'version' => '9.0.0' ) )->get_error_code(), 'No downgrade or same-version install.' );
	}

	public function test_incompatible_system_requirements_hide_offers(): void {
		$this->manifest['packages'][0]['requires_php'] = '99.0';
		$this->sign();
		$this->assertIsArray( Service::check() );
		$offers = Provider::offers( (object) array( 'checked' => array( $this->file() => '1.0.0' ), 'response' => array() ) );
		$this->assertArrayNotHasKey( $this->file(), $offers->response );
		$this->assertFalse( Service::compatible( $this->manifest['packages'][0] ) );
	}

	// ---- package inspection ---------------------------------------------------------------------------------------

	/** @dataProvider unsafe_entries */
	public function test_zip_paths_are_rejected_even_with_a_matching_hash( string $entry, bool $symlink = false ): void {
		$zip = new ZipArchive();
		$zip->open( $this->zip );
		$zip->addFromString( $entry, 'outside' );
		if ( $symlink ) {
			$zip->setExternalAttributesName( $entry, ZipArchive::OPSYS_UNIX, 0120777 << 16 );
		}
		$zip->close();
		$this->assertWPError( Package::verify( $this->zip, $this->package_for( $this->zip, '9.0.0' ) ) );
	}

	/** @return array<int,array{0:string,1?:bool}> */
	public static function unsafe_entries(): array {
		return array(
			array( 'sw-backupit/../outside.php' ),
			array( '/absolute.php' ),
			array( 'sw-backupit\\outside.php' ),
			array( 'sw-backupit/C:/outside' ),
			array( 'sw-backupit/link', true ),
			array( 'Sw-Backupit/SW-BACKUPIT.PHP' ),
			array( 'other-root/file.php' ),
			array( 'sw-backupit//double.php' ),
		);
	}

	public function test_valid_package_passes_and_headers_must_match_the_manifest(): void {
		$package = $this->package_for( $this->zip, '9.0.0' );
		$this->assertTrue( Package::verify( $this->zip, $package ) );
		foreach ( array( 'version' => '9.0.1', 'requires_php' => '8.3', 'requires_wp' => '6.9' ) as $field => $value ) {
			$changed           = $package;
			$changed[ $field ] = $value;
			$this->assertSame( 'unsafe_update_zip', Package::verify( $this->zip, $changed )->get_error_code(), $field );
		}
		$wrong           = $package;
		$wrong['sha256'] = str_repeat( '0', 64 );
		$this->assertSame( 'invalid_update_file', Package::verify( $this->zip, $wrong )->get_error_code() );
		$wrong          = $package;
		$wrong['bytes'] = 1;
		$this->assertSame( 'invalid_update_file', Package::verify( $this->zip, $wrong )->get_error_code() );
		$this->assertSame( 'invalid_update_file', Package::verify( $this->zip . '.missing', $package )->get_error_code() );
	}

	public function test_missing_main_file_garbage_archive_and_decompression_bombs_are_rejected(): void {
		$this->build_zip( $this->zip, array( 'sw-backupit/readme.txt' => 'x' ) );
		$this->assertSame( 'unsafe_update_zip', Package::verify( $this->zip, $this->package_for( $this->zip, '9.0.0' ) )->get_error_code() );
		$this->build_zip( $this->zip, array( 'sw-backupit/sw-backupit.php' => $this->main_file( '9.0.0' ), 'sw-backupit/bomb.bin' => str_repeat( "\0", 3 * 1024 * 1024 ) ) );
		$this->assertSame( 'unsafe_update_zip', Package::verify( $this->zip, $this->package_for( $this->zip, '9.0.0' ) )->get_error_code() );
		file_put_contents( $this->zip, 'not a zip' );
		$this->assertSame( 'invalid_update_zip', Package::verify( $this->zip, $this->package_for( $this->zip, '9.0.0' ) )->get_error_code() );
	}

	// ---- transport ------------------------------------------------------------------------------------------------

	public function test_redirects_and_size_limits_fail_closed(): void {
		remove_all_filters( 'pre_http_request' );
		$seen = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$seen ) {
				$seen[] = $url;
				return array( 'headers' => array( 'location' => 'https://127.0.0.1/private' ), 'body' => '', 'response' => array( 'code' => 302 ) );
			},
			10,
			3
		);
		$this->assertWPError( Transport::fetch( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', 1024 ) );
		$this->assertCount( 1, $seen );
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', static fn () => array( 'headers' => array(), 'body' => str_repeat( 'a', 1025 ), 'response' => array( 'code' => 200 ) ) );
		$this->assertSame( 'update_too_large', Transport::fetch( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', 1024 )->get_error_code() );
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', static fn () => array( 'headers' => array( 'location' => 'https://api.github.com/repos/' . self::REPO . '/x' ), 'body' => '', 'response' => array( 'code' => 301 ) ) );
		$this->assertSame( 'update_redirect_limit', Transport::fetch( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', 1024 )->get_error_code() );
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', static fn () => array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 302 ) ) );
		$this->assertSame( 'unsafe_update_url', Transport::fetch( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', 1024 )->get_error_code() );
	}

	public function test_allowed_redirect_to_github_asset_host_is_followed_and_streamed_files_are_limited(): void {
		remove_all_filters( 'pre_http_request' );
		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->requests[] = $url;
				if ( str_starts_with( $url, 'https://github.com/' ) ) {
					return array( 'headers' => array( 'location' => 'https://release-assets.githubusercontent.com/a' ), 'body' => '', 'response' => array( 'code' => 302 ) );
				}
				file_put_contents( $args['filename'], str_repeat( 'z', 100 ) );
				return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200 ) );
			},
			10,
			3
		);
		$file = wp_tempnam( 'swbi-transport.zip' );
		$this->assertSame( $file, Transport::fetch( 'https://github.com/' . self::REPO . '/releases/download/v1/a.zip', 100, $file ) );
		$this->assertWPError( Transport::fetch( 'https://github.com/' . self::REPO . '/releases/download/v1/a.zip', 99, $file ) );
		wp_delete_file( $file );
		$this->assertCount( 4, $this->requests );
	}

	/** @dataProvider urls */
	public function test_url_allow_list( string $url, bool $allowed ): void {
		$this->assertSame( $allowed, Transport::allowed( $url ) );
	}

	/** @return array<string,array{0:string,1:bool}> */
	public static function urls(): array {
		$r = self::REPO;
		return array(
			'api latest'        => array( "https://api.github.com/repos/$r/releases/latest", true ),
			'asset download'    => array( "https://github.com/$r/releases/download/v1/a.zip", true ),
			'objects host'      => array( 'https://objects.githubusercontent.com/x', true ),
			'asset host'        => array( 'https://release-assets.githubusercontent.com/x', true ),
			'http'              => array( "http://api.github.com/repos/$r/releases/latest", false ),
			'other repository'  => array( 'https://api.github.com/repos/sw-wordpress/churchtools-suite/releases/latest', false ),
			'prefix trick'      => array( "https://api.github.com/repos/{$r}evil/releases/latest", false ),
			'other host'        => array( "https://evil.example/repos/$r/releases", false ),
			'lookalike host'    => array( "https://github.com.evil.example/$r/releases/download/a", false ),
			'userinfo'          => array( "https://user@github.com/$r/releases/download/a", false ),
			'port'              => array( "https://github.com:8443/$r/releases/download/a", false ),
			'fragment'          => array( "https://github.com/$r/releases/download/a#x", false ),
			'traversal'         => array( "https://github.com/$r/releases/download/../../x", false ),
			'encoded traversal' => array( "https://github.com/$r/releases/download/%2e%2e/x", false ),
			'backslash'         => array( "https://github.com/$r/releases/download/a%5Cb", false ),
			'other path'        => array( "https://github.com/$r/archive/main.zip", false ),
			'garbage'           => array( 'not a url', false ),
		);
	}

	// ---- signer / real build tooling -------------------------------------------------------------------------------

	/**
	 * @param array<int,string>    $args CLI arguments after the script.
	 * @param array<string,string> $env  Environment.
	 * @return array{0:int,1:string}
	 */
	private function run_signer( array $args, array $env ): array {
		$process = proc_open(
			array_merge( array( PHP_BINARY, SWBACKUPIT_DIR . 'bin/release-manifest.php' ), $args ),
			array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ),
			$pipes,
			null,
			$env
		);
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( proc_close( $process ), $output );
	}

	public function test_release_signer_and_runtime_verifier_agree(): void {
		$directory = get_temp_dir() . 'swbi-sign-' . wp_generate_uuid4();
		wp_mkdir_p( $directory );
		copy( $this->zip, $directory . '/' . $this->manifest['packages'][0]['filename'] );
		$metadata = $directory . '/release.json';
		file_put_contents( $metadata, wp_json_encode( $this->release ) );
		$env = array( 'SW_PLUGIN_RELEASE_SIGNING_KEY' => base64_encode( $this->secret ), 'SW_PLUGIN_RELEASE_KEY_ID' => 'test' );
		try {
			[ $exit, $output ] = $this->run_signer( array( 'sign', $metadata, $directory, str_repeat( 'a', 40 ) ), $env );
			$this->assertSame( 0, $exit, $output );
			$manifest = Manifest::verify( (string) file_get_contents( $directory . '/sw-plugin-release.json' ), (string) file_get_contents( $directory . '/sw-plugin-release.json.sig' ), $this->keys, $this->release );
			$this->assertIsArray( $manifest );
			$this->assertEquals( $this->manifest['packages'][0], $manifest['packages'][0] );
			$this->assertStringNotContainsString( base64_encode( $this->secret ), $output );
			// Without the signing secret nothing is signed.
			unlink( $directory . '/sw-plugin-release.json' );
			[ $exit ] = $this->run_signer( array( 'sign', $metadata, $directory, str_repeat( 'a', 40 ) ), array() );
			$this->assertNotSame( 0, $exit );
			$this->assertFileDoesNotExist( $directory . '/sw-plugin-release.json' );
			// An extra ZIP that is not part of the release, a wrong commit or wrong slug are refused.
			copy( $this->zip, $directory . '/other-plugin-1.0.0.zip' );
			[ $exit ] = $this->run_signer( array( 'sign', $metadata, $directory, str_repeat( 'a', 40 ) ), $env );
			$this->assertNotSame( 0, $exit );
			unlink( $directory . '/other-plugin-1.0.0.zip' );
			[ $exit ] = $this->run_signer( array( 'sign', $metadata, $directory, 'not-a-sha' ), $env );
			$this->assertNotSame( 0, $exit );
			[ $exit ] = $this->run_signer( array( 'bogus' ), $env );
			$this->assertNotSame( 0, $exit );
		} finally {
			foreach ( glob( $directory . '/*' ) ?: array() as $file ) {
				wp_delete_file( $file );
			}
			rmdir( $directory );
		}
	}

	public function test_signer_refuses_unrelated_zip_name_and_version_mismatch(): void {
		$directory = get_temp_dir() . 'swbi-sign-' . wp_generate_uuid4();
		wp_mkdir_p( $directory );
		$env = array( 'SW_PLUGIN_RELEASE_SIGNING_KEY' => base64_encode( $this->secret ), 'SW_PLUGIN_RELEASE_KEY_ID' => 'test' );
		try {
			copy( $this->zip, $directory . '/sw-backupit-9.1.0.zip' ); // Header says 9.0.0.
			$this->release['assets'] = array( array( 'id' => 5, 'name' => 'sw-backupit-9.1.0.zip', 'size' => filesize( $this->zip ) ) );
			file_put_contents( $directory . '/release.json', wp_json_encode( $this->release ) );
			[ $exit, $output ] = $this->run_signer( array( 'sign', $directory . '/release.json', $directory, str_repeat( 'a', 40 ) ), $env );
			$this->assertNotSame( 0, $exit );
			$this->assertStringContainsString( 'mismatch', $output );
			$this->assertStringNotContainsString( base64_encode( $this->secret ), $output );
		} finally {
			foreach ( glob( $directory . '/*' ) ?: array() as $file ) {
				wp_delete_file( $file );
			}
			rmdir( $directory );
		}
	}

	public function test_public_key_export_preserves_existing_keys_and_rejects_conflicts(): void {
		$ring = wp_tempnam( 'swbi-keys.php' );
		$env  = array( 'SW_PLUGIN_RELEASE_SIGNING_KEY' => base64_encode( $this->secret ), 'SW_PLUGIN_RELEASE_KEY_ID' => 'test' );
		try {
			$old = base64_encode( random_bytes( 32 ) );
			file_put_contents( $ring, "<?php\nreturn array( 'old-2026' => '$old' );\n" );
			[ $exit, $output ] = $this->run_signer( array( 'public-key', $ring ), $env );
			$this->assertSame( 0, $exit, $output );
			$keys = require $ring;
			$this->assertSame( array( 'old-2026', 'test' ), array_keys( $keys ) );
			$this->assertSame( $old, $keys['old-2026'] );
			$this->assertSame( $this->keys['test'], $keys['test'] );
			$this->assertStringNotContainsString( base64_encode( $this->secret ), (string) file_get_contents( $ring ) );
			// Same ID with a different public key is a conflict.
			$other = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
			[ $exit ] = $this->run_signer( array( 'public-key', $ring ), array( 'SW_PLUGIN_RELEASE_SIGNING_KEY' => base64_encode( $other ), 'SW_PLUGIN_RELEASE_KEY_ID' => 'test' ) );
			$this->assertNotSame( 0, $exit );
			// Missing key material aborts.
			[ $exit ] = $this->run_signer( array( 'public-key', $ring ), array() );
			$this->assertNotSame( 0, $exit );
			[ $exit ] = $this->run_signer( array( 'public-key', $ring . '.missing' ), $env );
			$this->assertNotSame( 0, $exit );
		} finally {
			wp_delete_file( $ring );
		}
	}

	// ---- native WordPress upgrader (private WP only) ---------------------------------------------------------------

	/** @dataProvider core_modes */
	public function test_native_wordpress_upgrader_installs_verified_package_only_in_private_wp( string $mode ): void {
		$this->assertStringStartsWith( SWBACKUPIT_DIR . 'tests/wp/vendor/', (string) realpath( WP_PLUGIN_DIR ) );
		$root = WP_PLUGIN_DIR . '/sw-backupit';
		$this->assertDirectoryDoesNotExist( $root );
		wp_mkdir_p( $root );
		$buffer_level = ob_get_level();
		ob_start();
		try {
			$file = 'sw-backupit/sw-backupit.php';
			add_filter( 'swbi_verified_update_descriptors', static fn () => array( $file => array( 'slug' => 'sw-backupit', 'version' => '1.0.0' ) ) );
			file_put_contents( $root . '/sw-backupit.php', "<?php\n/*\nPlugin Name: Old test plugin\nVersion: 1.0.0\n*/\n" );
			$this->assertIsArray( Service::check() );
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			WP_Filesystem();
			set_site_transient( 'update_plugins', (object) array( 'checked' => array( $file => '1.0.0' ) ) );
			$this->assertArrayHasKey( $file, get_site_transient( 'update_plugins' )->response ?? array() );
			$bulk                 = 'bulk' === $mode;
			$_REQUEST['action']   = $bulk ? 'update-selected' : ( 'ajax' === $mode ? 'update-plugin' : 'upgrade-plugin' );
			$_REQUEST['_wpnonce'] = wp_create_nonce( $bulk ? 'bulk-update-plugins' : ( 'ajax' === $mode ? 'updates' : 'upgrade-plugin_' . $file ) );
			$upgrader             = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
			$result               = $bulk ? $upgrader->bulk_upgrade( array( $file ), array( 'clear_update_cache' => false ) ) : $upgrader->upgrade( $file, array( 'clear_update_cache' => false ) );
			if ( $bulk ) {
				$this->assertIsArray( $result );
				$this->assertIsArray( $result[ $file ] );
				$result = true;
			}
			$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() : 'Native upgrade failed' );
			$this->assertStringContainsString( 'Version: 9.0.0', (string) file_get_contents( $root . '/sw-backupit.php' ) );
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			wp_delete_file( $root . '/sw-backupit.php' );
			if ( is_dir( $root ) ) {
				rmdir( $root );
			}
		}
	}

	/** @return array<string,array{0:string}> */
	public static function core_modes(): array {
		return array( 'single' => array( 'single' ), 'bulk' => array( 'bulk' ), 'ajax' => array( 'ajax' ) );
	}

	public function test_native_upgrader_rejects_tampered_package_and_leaves_the_plugin_untouched(): void {
		$this->assertStringStartsWith( SWBACKUPIT_DIR . 'tests/wp/vendor/', (string) realpath( WP_PLUGIN_DIR ) );
		$root = WP_PLUGIN_DIR . '/sw-backupit';
		$this->assertDirectoryDoesNotExist( $root );
		wp_mkdir_p( $root );
		$buffer_level = ob_get_level();
		ob_start();
		try {
			$file = 'sw-backupit/sw-backupit.php';
			add_filter( 'swbi_verified_update_descriptors', static fn () => array( $file => array( 'slug' => 'sw-backupit', 'version' => '1.0.0' ) ) );
			$old = "<?php\n/*\nPlugin Name: Old test plugin\nVersion: 1.0.0\n*/\n";
			file_put_contents( $root . '/sw-backupit.php', $old );
			$this->assertIsArray( Service::check() );
			// The served ZIP is replaced after the signed manifest was cached: hash mismatch.
			$this->build_zip( $this->zip, array( 'sw-backupit/sw-backupit.php' => $this->main_file( '9.0.0' ) . "// evil\n" ) );
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			WP_Filesystem();
			set_site_transient( 'update_plugins', (object) array( 'checked' => array( $file => '1.0.0' ) ) );
			$_REQUEST['action']   = 'upgrade-plugin';
			$_REQUEST['_wpnonce'] = wp_create_nonce( 'upgrade-plugin_' . $file );
			$upgrader             = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
			$result               = $upgrader->upgrade( $file, array( 'clear_update_cache' => false ) );
			$this->assertNotTrue( $result );
			$this->assertSame( $old, file_get_contents( $root . '/sw-backupit.php' ) );
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			wp_delete_file( $root . '/sw-backupit.php' );
			if ( is_dir( $root ) ) {
				rmdir( $root );
			}
		}
	}

	// ---- WordPress presentation ----------------------------------------------------------------------------------

	public function test_wordpress_details_and_update_uri_use_only_cached_signed_metadata(): void {
		$this->assertIsArray( Service::check() );
		$file    = $this->file();
		$uri     = 'https://github.com/' . self::REPO;
		$offer   = Provider::update_uri( false, array( 'UpdateURI' => $uri, 'Version' => SWBACKUPIT_VERSION ), $file );
		$this->assertSame( '9.0.0', $offer->new_version );
		$details = Provider::details( false, 'plugin_information', (object) array( 'slug' => 'sw-backupit' ) );
		$this->assertSame( '9.0.0', $details->version );
		$this->assertSame( $this->package_url(), $details->download_link );
		$original = (object) array( 'foreign' => true );
		$this->assertSame( $original, Provider::update_uri( $original, array( 'UpdateURI' => 'https://evil.invalid', 'Version' => '1' ), $file ) );
		$this->assertSame( $original, Provider::update_uri( $original, array( 'UpdateURI' => $uri, 'Version' => '1' ), 'other/other.php' ) );
		$this->assertSame( $original, Provider::details( $original, 'plugin_information', (object) array( 'slug' => 'other-plugin' ) ) );
		$this->assertSame( $original, Provider::details( $original, 'query_plugins', (object) array( 'slug' => 'sw-backupit' ) ) );
		$this->assertSame( $original, Provider::details( $original, 'plugin_information', 'nope' ) );
		$this->assertCount( 3, $this->requests );
		$this->assertStringContainsString( 'Manual', Provider::automatic_html( '', $file ) );
		$this->assertSame( 'unchanged', Provider::automatic_html( 'unchanged', 'foreign/plugin.php' ) );
	}

	public function test_update_uri_without_cached_release_reports_no_offer(): void {
		$this->assertFalse( Provider::update_uri( false, array( 'UpdateURI' => 'https://github.com/' . self::REPO, 'Version' => '1' ), $this->file() ) );
		$this->assertSame( 'x', Provider::details( 'x', 'plugin_information', (object) array( 'slug' => 'sw-backupit' ) ) );
	}

	public function test_same_or_older_cached_version_is_listed_as_no_update(): void {
		$this->assertIsArray( Service::check() );
		$file   = $this->file();
		$offers = Provider::offers( (object) array( 'checked' => array( $file => '9.0.0' ) ) );
		$this->assertArrayHasKey( $file, $offers->no_update );
		$this->assertArrayNotHasKey( $file, $offers->response ?? array() );
	}

	public function test_row_meta_shows_button_only_for_our_row_and_authorised_users(): void {
		$file = $this->file();
		$meta = Provider::row_meta( array( 'x' ), $file );
		$this->assertCount( 2, $meta );
		$this->assertStringContainsString( 'id="swbi-verified-updates-check"', $meta[1] );
		$this->assertStringContainsString( 'No verified update check yet', $meta[1] );
		$this->assertSame( array( 'x' ), Provider::row_meta( array( 'x' ), 'other/other.php' ) );
		$this->assertIsArray( Service::check() );
		$state          = Store::read();
		$state['error'] = '<b>bad</b>';
		Store::write( $state );
		$html = Provider::row_meta( array(), $file )[0];
		$this->assertStringContainsString( 'Last verified check:', $html );
		$this->assertStringNotContainsString( '<b>bad</b>', $html );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( array( 'x' ), Provider::row_meta( array( 'x' ), $file ) );
	}

	public function test_script_is_enqueued_only_for_authorised_sessions_with_nonce_and_due_flag(): void {
		wp_deregister_script( Provider::HANDLE );
		wp_dequeue_script( Provider::HANDLE );
		set_current_screen( 'front' );
		Provider::enqueue();
		$this->assertFalse( wp_script_is( Provider::HANDLE, 'enqueued' ) );
		set_current_screen( is_multisite() ? 'plugins-network' : 'plugins' );
		Provider::enqueue();
		$this->assertTrue( wp_script_is( Provider::HANDLE, 'enqueued' ) );
		$inline = (string) wp_scripts()->get_data( Provider::HANDLE, 'before' )[1];
		$this->assertStringContainsString( 'window.swbiVerifiedUpdates=', $inline );
		$this->assertStringContainsString( '"action":"swbi_check_github_updates"', $inline );
		$this->assertStringContainsString( '"due":true', $inline, 'Due once a key exists and nothing was attempted yet.' );
		$this->assertStringContainsString( wp_create_nonce( Provider::NONCE ), $inline );
		$this->assertStringNotContainsString( 'churchtools', strtolower( $inline ) );
		$this->assertFileExists( SWBACKUPIT_DIR . 'assets/js/swbackupit-updates.js' );
	}

	public function test_due_flag_is_false_after_an_attempt_or_without_key(): void {
		$this->assertIsArray( Service::check() );
		wp_deregister_script( Provider::HANDLE );
		wp_dequeue_script( Provider::HANDLE );
		Provider::enqueue();
		$this->assertStringContainsString( '"due":false', (string) wp_scripts()->get_data( Provider::HANDLE, 'before' )[1] );
	}

	// ---- AJAX ---------------------------------------------------------------------------------------------------------

	/** @return mixed */
	private function ajax( string $nonce ) {
		$_POST    = array( 'nonce' => $nonce, 'manual' => '1' );
		$_REQUEST = $_POST;
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', static fn () => static function ( $message ) {
			echo $message; // phpcs:ignore
			throw new SWBI_Verified_Update_Exit();
		} );
		ob_start();
		try {
			do_action( 'wp_ajax_swbi_check_github_updates' );
		} catch ( SWBI_Verified_Update_Exit $exception ) {
			// Capture WordPress's terminating response without ending the test process.
		}
		return json_decode( (string) ob_get_clean(), true, 32, JSON_THROW_ON_ERROR );
	}

	public function test_check_ajax_rejects_invalid_nonce_before_http(): void {
		$this->assertSame( -1, $this->ajax( 'invalid' ) );
		$this->assertSame( -1, $this->ajax( wp_create_nonce( 'other_action' ) ) );
		$this->assertCount( 0, $this->requests );
	}

	public function test_check_ajax_rejects_users_without_wordpress_update_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $this->ajax( wp_create_nonce( Provider::NONCE ) )['success'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_authorized_check_ajax_checks_but_never_installs(): void {
		// The shared AJAX endpoint has a site-admin screen even when called by a network admin.
		set_current_screen( 'dashboard' );
		$this->assertTrue( $this->ajax( wp_create_nonce( Provider::NONCE ) )['success'] );
		$this->assertCount( 3, $this->requests );
		$second = $this->ajax( wp_create_nonce( Provider::NONCE ) );
		$this->assertFalse( $second['success'] );
		$this->assertNotEmpty( $second['data']['message'] );
		$this->assertCount( 3, $this->requests );
		$this->assertNotContains( 'wp-content/plugins', array_map( static fn ( $u ) => (string) wp_parse_url( $u, PHP_URL_HOST ), $this->requests ) );
	}

	public function test_multisite_site_admin_context_cannot_check_without_network_ajax(): void {
		if ( ! is_multisite() ) {
			$this->assertTrue( Service::interactive() );
			return;
		}
		set_current_screen( 'dashboard' );
		$this->assertWPError( Service::check( true ) );
		$this->assertCount( 0, $this->requests );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertTrue( Service::interactive(), 'Network admin through admin-ajax.php (no WP_NETWORK_ADMIN marker).' );
		revoke_super_admin( $this->admin_id );
		$this->assertFalse( Service::interactive() );
		$this->assertWPError( Service::check( true ) );
		$this->assertCount( 0, $this->requests );
		// State is network wide in site options.
		grant_super_admin( $this->admin_id );
		$this->assertIsArray( Service::check( true ) );
		$this->assertNotEmpty( get_network_option( get_current_network_id(), Store::STATE )['manifest'] );
	}

	// ---- lifecycle and coexistence ------------------------------------------------------------------------------

	public function test_migration_removes_only_own_automatic_selection_and_cached_offers_once(): void {
		$file = $this->file();
		update_site_option( 'auto_update_plugins', array( $file, 'other/other.php' ) );
		set_site_transient( 'update_plugins', (object) array(
			'checked'   => array( $file => '1.0.0' ),
			'response'  => array( $file => (object) array( 'new_version' => '9' ), 'other/other.php' => (object) array( 'new_version' => '2' ) ),
			'no_update' => array(),
		) );
		delete_option( Provider::MIGRATED );
		Provider::migrate();
		$this->assertSame( array( 'other/other.php' ), get_site_option( 'auto_update_plugins' ) );
		$this->assertArrayNotHasKey( $file, get_site_transient( 'update_plugins' )->response );
		$this->assertArrayHasKey( 'other/other.php', get_site_transient( 'update_plugins' )->response );
		$this->assertSame( 1, (int) get_option( Provider::MIGRATED ) );
		// Subsequent calls are no-ops, even if a selection reappears.
		update_site_option( 'auto_update_plugins', array( $file ) );
		Provider::migrate();
		$this->assertSame( array( $file ), get_site_option( 'auto_update_plugins' ) );
	}

	public function test_deactivation_clears_only_own_offers_and_automatic_selections_before_guards_disappear(): void {
		$this->assertIsArray( Service::check() );
		$file = $this->file();
		update_site_option( 'auto_update_plugins', array( $file, 'other/other.php' ) );
		set_site_transient( 'update_plugins', (object) array(
			'checked'  => array( $file => '1.0.0', 'other/other.php' => '1.0.0' ),
			'response' => array( 'other/other.php' => (object) array( 'new_version' => '2.0.0' ) ),
		) );
		$this->assertArrayHasKey( $file, get_site_transient( 'update_plugins' )->response );
		try {
			\SWBackupIt\WordPress\Lifecycle::deactivate();
			$this->assertSame( array( 'other/other.php' ), get_site_option( 'auto_update_plugins' ) );
			$this->assertArrayNotHasKey( $file, get_site_transient( 'update_plugins' )->response );
			$this->assertArrayHasKey( 'other/other.php', get_site_transient( 'update_plugins' )->response );
			$this->assertFalse( has_filter( 'site_transient_update_plugins', array( Provider::class, 'offers' ) ) );
		} finally {
			Provider::init();
		}
		$this->assertNotFalse( has_filter( 'site_transient_update_plugins', array( Provider::class, 'offers' ) ) );
	}

	public function test_uninstall_removes_state_lock_and_migration_flag(): void {
		$this->assertIsArray( Service::check() );
		update_option( Provider::MIGRATED, 1 );
		add_site_option( Store::LOCK, array( 'token' => 'x', 'expires' => time() + 60 ) );
		try {
			Provider::uninstall();
			$this->assertSame( array(), Store::read() );
			$this->assertFalse( get_site_option( Store::LOCK ) );
			$this->assertFalse( get_option( Provider::MIGRATED ) );
		} finally {
			Provider::init();
		}
	}

	public function test_hooks_are_registered_also_outside_the_backend_and_with_late_priority(): void {
		$this->assertSame( PHP_INT_MAX, has_filter( 'auto_update_plugin', array( Provider::class, 'automatic' ) ) );
		$this->assertSame( PHP_INT_MAX, has_filter( 'upgrader_pre_download', array( Provider::class, 'download_guard' ) ) );
		foreach ( array( 'plugins_api', 'update_plugins_github.com', 'pre_set_site_transient_update_plugins', 'site_transient_update_plugins', 'plugin_row_meta', 'plugin_auto_update_setting_html' ) as $hook ) {
			$this->assertTrue( (bool) has_filter( $hook ), $hook );
		}
		$this->assertNotFalse( has_action( 'wp_ajax_swbi_check_github_updates' ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_swbi_check_github_updates' ) );
	}

	public function test_coexistence_with_another_sw_plugin_leaves_foreign_updates_untouched(): void {
		$other_uri = 'https://github.com/sw-wordpress/other-sw-plugin';
		add_filter( 'update_plugins_github.com', static fn ( $reply, $data, $file ) => 'other-sw-plugin/other-sw-plugin.php' === $file ? (object) array( 'new_version' => '3.0.0' ) : $reply, 5, 3 );
		add_filter( 'upgrader_pre_download', static fn ( $reply, $url ) => str_contains( $url, 'other-sw-plugin' ) ? '/tmp/foreign.zip' : $reply, 5, 2 );
		add_filter( 'swbi_verified_release_public_keys', static fn ( $keys ) => $keys + array( 'other-sw-plugin-2026' => base64_encode( random_bytes( 32 ) ) ), 20 );
		$this->assertIsArray( Service::check() );
		$foreign = array( 'other-sw-plugin/other-sw-plugin.php' => (object) array( 'new_version' => '3.0.0', 'package' => $other_uri . '/releases/download/3/a.zip' ) );
		$offers  = Provider::offers( (object) array( 'checked' => array( $this->file() => '1.0.0', 'other-sw-plugin/other-sw-plugin.php' => '2.0.0' ), 'response' => $foreign, 'no_update' => array() ) );
		$this->assertSame( $foreign, $offers->response['other-sw-plugin/other-sw-plugin.php'] ? array( 'other-sw-plugin/other-sw-plugin.php' => $offers->response['other-sw-plugin/other-sw-plugin.php'] ) : array() );
		$this->assertArrayHasKey( $this->file(), $offers->response );
		$this->assertSame( '3.0.0', apply_filters( 'update_plugins_github.com', false, array( 'UpdateURI' => $other_uri, 'Version' => '2' ), 'other-sw-plugin/other-sw-plugin.php' )->new_version );
		$this->assertSame( '/tmp/foreign.zip', apply_filters( 'upgrader_pre_download', false, $other_uri . '/releases/download/3/a.zip', new stdClass(), array( 'plugin' => 'other-sw-plugin/other-sw-plugin.php' ) ) );
		$this->assertTrue( apply_filters( 'auto_update_plugin', true, (object) array( 'plugin' => 'other-sw-plugin/other-sw-plugin.php' ) ) );
		$this->assertSame( 'unchanged', apply_filters( 'plugin_auto_update_setting_html', 'unchanged', 'other-sw-plugin/other-sw-plugin.php', array() ) );
		$this->assertSame( array(), Provider::row_meta( array(), 'other-sw-plugin/other-sw-plugin.php' ) );
		// Our options never share a name with the ChurchTools Suite reference implementation or the generic SW names.
		$this->assertStringStartsWith( 'swbi_', Store::STATE );
		$this->assertStringStartsWith( 'swbi_', Store::LOCK );
		$this->assertStringStartsWith( 'swbi_', Provider::MIGRATED );
		$this->assertStringStartsWith( 'swbi-', Provider::HANDLE );
		$this->assertStringStartsWith( 'swbi_', Provider::AJAX_ACTION );
		$this->assertStringStartsWith( 'swbi_', Provider::NONCE );
	}
}
