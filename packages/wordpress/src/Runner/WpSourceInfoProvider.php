<?php
/**
 * Describes the running WordPress installation to the framework independent core.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Runner;

use SWBackupIt\Core\Backup\SourceInfo;
use SWBackupIt\Core\Backup\SourceInfoProviderInterface;
use SWBackupIt\Core\Database\ConnectionConfig;
use SWBackupIt\Core\Job\Job;
use SWBackupIt\WordPress\Support\Settings;

final class WpSourceInfoProvider implements SourceInfoProviderInterface {

	public function forJob( Job $job ): SourceInfo {
		global $wpdb, $wp_version;
		return new SourceInfo(
			untrailingslashit( ABSPATH ),
			Settings::staging_dir(),
			(string) site_url(),
			(string) home_url(),
			(string) $wp_version,
			(string) $wpdb->base_prefix,
			ConnectionConfig::fromWordPressHost( DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, defined( 'DB_CHARSET' ) ? (string) DB_CHARSET : 'utf8mb4' ),
			is_multisite(),
			array(
				'swbackupit_version' => SWBACKUPIT_VERSION,
				'wp_content_dir'     => str_replace( untrailingslashit( ABSPATH ) . '/', '', untrailingslashit( WP_CONTENT_DIR ) ),
			)
		);
	}
}
