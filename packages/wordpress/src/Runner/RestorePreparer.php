<?php
/**
 * Prepares the standalone web restore wizard for a backup.
 *
 * Restore itself is never executed inside WordPress (it would overwrite the code that is running it). Instead a copy
 * of the independent restore application is placed in a hard-to-guess directory, protected by a one-time token that
 * is shown once and stored only as a hash, with a short lifetime. The wizard locks and removes itself after use.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Runner;

use SWBackupIt\Core\Exception\SwbiException;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Restore\Backup\BackupSet;
use SWBackupIt\Restore\Web\WebSetup;
use SWBackupIt\WordPress\Support\Settings;

final class RestorePreparer {

	private const PREFIX = 'sw-backupit-restore-';

	/**
	 * @return array{restore_url: string, token: string, token_expires_at: string, target_path: string, warnings: list<string>}
	 * @throws SwbiException When the backup is not available locally.
	 */
	public function prepare( string $backup_id, int $ttl = 3600 ): array {
		$staging = Settings::staging_dir();
		$set     = BackupSet::open( $staging . '/' . $backup_id );
		if ( $set->isLocalCopyRemoved() ) {
			throw new SwbiException( __( 'The local copy of this backup was removed. Download it from a storage target first.', 'sw-backupit' ) );
		}
		$this->cleanup_old();
		$name = self::PREFIX . bin2hex( random_bytes( 8 ) );
		$dir  = untrailingslashit( ABSPATH ) . '/' . $name;
		if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
			throw new SwbiException( __( 'The restore application directory cannot be created in the WordPress directory.', 'sw-backupit' ) );
		}
		$this->install_app( $dir );
		$state = dirname( untrailingslashit( ABSPATH ) ) . '/.swbi-restore-state-' . $name;
		if ( ! wp_is_writable( dirname( $state ) ) || Settings::is_inside_docroot( dirname( $state ) ) ) {
			$state = untrailingslashit( $staging ) . '/restore-state-' . $name;
		}
		$token    = WebSetup::initialize( $dir, $staging, $ttl, $state, array( untrailingslashit( ABSPATH ) ), untrailingslashit( ABSPATH ), strtolower( substr( determine_locale(), 0, 2 ) ) );
		$warnings = array();
		if ( ! is_ssl() ) {
			$warnings[] = __( 'This site is not using HTTPS: the restore token and database password would travel unencrypted.', 'sw-backupit' );
		}
		if ( $set->isEncrypted() ) {
			$warnings[] = __( 'The backup is encrypted: have the key or passphrase ready.', 'sw-backupit' );
		}
		do_action( 'swbi_restore_prepared', $backup_id );
		return array(
			'restore_url'      => trailingslashit( site_url( '/' . $name ) ),
			'token'            => $token,
			'token_expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + $ttl ),
			'target_path'      => untrailingslashit( ABSPATH ),
			'warnings'         => $warnings,
		);
	}

	/** Removes wizard directories older than a day (tokens are short lived anyway). */
	public function cleanup_old(): int {
		$removed = 0;
		foreach ( glob( untrailingslashit( ABSPATH ) . '/' . self::PREFIX . '*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			if ( filemtime( $dir ) < time() - DAY_IN_SECONDS ) {
				AtomicFile::removeTree( $dir );
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Removes every prepared wizard directory and its state directory regardless of age (deactivation/uninstall).
	 *
	 * @return int Number of removed wizard directories.
	 */
	public function remove_all(): int {
		$removed = 0;
		$root    = untrailingslashit( ABSPATH );
		foreach ( glob( $root . '/' . self::PREFIX . '*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			AtomicFile::removeTree( $dir );
			++$removed;
		}
		$states = array_merge(
			glob( dirname( $root ) . '/.swbi-restore-state-' . self::PREFIX . '*', GLOB_ONLYDIR ) ?: array(),
			glob( untrailingslashit( Settings::staging_dir() ) . '/restore-state-' . self::PREFIX . '*', GLOB_ONLYDIR ) ?: array()
		);
		foreach ( $states as $dir ) {
			AtomicFile::removeTree( $dir );
		}
		return $removed;
	}

	/**
	 * Installs the standalone restore application (bundled distribution or, in a development checkout, assembled from the
	 * monorepo) into the directory.
	 *
	 * @param string $dir Target directory.
	 */
	public function install_app( string $dir ): void {
		$bundled = SWBACKUPIT_DIR . 'restore-app';
		if ( is_file( $bundled . '/index.php' ) ) {
			$this->copy_tree( $bundled, $dir );
			return;
		}
		// Development checkout: assemble the distribution from the monorepo (same layout as bin/build-restore.sh).
		$this->copy_tree( SWBACKUPIT_DIR . 'packages/core/src', $dir . '/src/Core' );
		$this->copy_tree( SWBACKUPIT_DIR . 'packages/restore/src', $dir . '/src/Restore' );
		$this->copy_tree( SWBACKUPIT_DIR . 'packages/restore/public', $dir );
		$this->copy_tree( SWBACKUPIT_DIR . 'packages/restore/lang', $dir . '/lang' );
		$this->copy_tree( SWBACKUPIT_DIR . 'packages/restore/bin', $dir . '/bin' );
		$this->copy_tree( SWBACKUPIT_DIR . 'vendor/psr/log/src', $dir . '/vendor/psr-log' );
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions -- generated file inside a directory we just created.
			$dir . '/vendor/autoload.php',
			"<?php\n\ndeclare(strict_types=1);\n\nspl_autoload_register(static function (string \$class): void {\n\tstatic \$map = array(\n\t\t'SWBackupIt\\\\Core\\\\' => __DIR__ . '/../src/Core/',\n\t\t'SWBackupIt\\\\Restore\\\\' => __DIR__ . '/../src/Restore/',\n\t\t'Psr\\\\Log\\\\' => __DIR__ . '/psr-log/',\n\t);\n\tforeach (\$map as \$prefix => \$directory) {\n\t\tif (str_starts_with(\$class, \$prefix)) {\n\t\t\t\$file = \$directory . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php';\n\t\t\tif (is_file(\$file)) {\n\t\t\t\trequire \$file;\n\t\t\t}\n\n\t\t\treturn;\n\t\t}\n\t}\n});\n"
		);
	}

	private function copy_tree( string $from, string $to ): void {
		if ( ! is_dir( $from ) ) {
			throw new SwbiException( __( 'The restore application files are missing from this installation.', 'sw-backupit' ) );
		}
		wp_mkdir_p( $to );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $from, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $item ) {
			$relative = substr( $item->getPathname(), strlen( $from ) + 1 );
			$target   = $to . '/' . $relative;
			if ( $item->isDir() ) {
				wp_mkdir_p( $target );
			} elseif ( $item->isFile() ) {
				copy( $item->getPathname(), $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}
}
