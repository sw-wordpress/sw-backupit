<?php
/**
 * WordPress admin integration: menu page hosting the Vue/Quasar single page application.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Admin;

use SWBackupIt\WordPress\Support\Capabilities;

final class AdminPage {

	public const SLUG    = 'sw-backupit';
	private const HANDLE = 'sw-backupit-admin';

	private string $hook = '';

	public function register(): void {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( $this, 'module_tag' ), 10, 2 );
	}

	public function add_menu(): void {
		$this->hook = (string) add_menu_page(
			__( 'sw-backupit', 'sw-backupit' ),
			__( 'sw-backupit', 'sw-backupit' ),
			Capabilities::VIEW,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-backup',
			80
		);
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sw-backupit' ), 403 );
		}
		if ( ! is_readable( SWBACKUPIT_DIR . 'assets/admin/app.js' ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'The sw-backupit admin interface has not been built. Install the release package or run "npm run build" in apps/admin.', 'sw-backupit' ) . '</p></div></div>';
			return;
		}
		echo '<div class="wrap swbi-wrap"><div id="sw-backupit-app"></div><noscript>' . esc_html__( 'sw-backupit requires JavaScript.', 'sw-backupit' ) . '</noscript></div>';
	}

	public function enqueue( string $hook ): void {
		if ( $hook !== $this->hook || ! is_readable( SWBACKUPIT_DIR . 'assets/admin/app.js' ) ) {
			return;
		}
		$version = (string) filemtime( SWBACKUPIT_DIR . 'assets/admin/app.js' );
		wp_enqueue_style( self::HANDLE, SWBACKUPIT_URL . 'assets/admin/app.css', array(), $version );
		wp_enqueue_script( self::HANDLE, SWBACKUPIT_URL . 'assets/admin/app.js', array(), $version, true );
		wp_add_inline_script( self::HANDLE, 'window.swbackupitConfig = ' . wp_json_encode( $this->config() ) . ';', 'before' );
	}

	/**
	 * Configuration contract with the SPA (apps/admin/README.md).
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		return array(
			'restRoot'     => esc_url_raw( rest_url( 'sw-backupit/v1/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'locale'       => determine_locale(),
			'version'      => SWBACKUPIT_VERSION,
			'adminUrl'     => esc_url_raw( is_multisite() ? network_admin_url( 'admin.php?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG ) ),
			'capabilities' => Capabilities::current_user_caps(),
			'siteUrl'      => esc_url_raw( site_url() ),
		);
	}

	public function module_tag( string $tag, string $handle ): string {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		return str_replace( '<script ', '<script type="module" ', $tag );
	}
}
