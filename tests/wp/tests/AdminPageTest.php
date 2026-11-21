<?php
/**
 * Admin authorship footer and shipped gettext catalog integration.
 *
 * @package sw-backupit
 */

use SWBackupIt\WordPress\Admin\AdminPage;
use SWBackupIt\WordPress\Support\Capabilities;

class AdminPageTest extends WP_UnitTestCase {

	public function test_footer_uses_the_english_source_and_german_catalog(): void {
		$user = self::factory()->user->create();
		get_user_by( 'id', $user )->add_cap( Capabilities::VIEW );
		wp_set_current_user( $user );
		$source = 'Open source by Stefan Warnat – free to use, free to change, better together.';
		unload_textdomain( 'sw-backupit', true );
		try {
			$this->assert_footer( $source );
			$this->assertTrue( load_textdomain( 'sw-backupit', SWBACKUPIT_DIR . 'languages/sw-backupit-de_DE.mo', 'de_DE' ) );
			$this->assert_footer( 'Open Source von Stefan Warnat – frei nutzbar, frei veränderbar, gemeinsam besser.' );
		} finally {
			unload_textdomain( 'sw-backupit', true );
		}
	}

	private function assert_footer( string $expected ): void {
		ob_start();
		try {
			( new AdminPage() )->render();
			$html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$this->assertSame( 1, substr_count( $html, $expected ) );
		$this->assertStringContainsString( '<p class="description">' . $expected . '</p>', $html );
		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new DOMXPath( $document );
		$this->assertSame( 1, $xpath->query( '//div[@class="wrap swbi-wrap"]/footer[@class="swbi-admin-footer"]' )->length );
	}
}
