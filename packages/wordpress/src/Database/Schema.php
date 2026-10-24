<?php
/**
 * Versioned database schema (PLAN.md §21).
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Database;

final class Schema {

	public const VERSION_OPTION = 'swbi_db_version';
	public const CURRENT        = 2;

	public static function table( string $name ): string {
		global $wpdb;
		// Network wide on multisite (base_prefix), identical to the prefix on single sites.
		return $wpdb->base_prefix . 'swbi_' . $name;
	}

	/**
	 * @return list<string> Short table names.
	 */
	public static function tables(): array {
		return array( 'profiles', 'schedules', 'jobs', 'backups', 'backup_locations', 'storage_targets', 'job_events', 'audit' );
	}

	/**
	 * Runs pending migrations. Safe to call repeatedly.
	 *
	 * The schema version is only bumped when every table really exists afterwards: dbDelta() does not report failures,
	 * and a bumped version would otherwise never retry a failed migration.
	 *
	 * @return bool Whether the schema is current.
	 */
	public static function migrate(): bool {
		$installed = (int) \SWBackupIt\WordPress\Support\Options::get( self::VERSION_OPTION, 0 );
		if ( $installed >= self::CURRENT ) {
			return true;
		}
		if ( $installed < 1 ) {
			self::migrate_to_1();
		}
		if ( $installed < 2 ) {
			self::migrate_to_2();
		}
		if ( array() !== self::missing_tables() ) {
			return false;
		}
		\SWBackupIt\WordPress\Support\Options::update( self::VERSION_OPTION, self::CURRENT, true );
		return true;
	}

	/**
	 * @return list<string> Full names of plugin tables that do not exist.
	 */
	public static function missing_tables(): array {
		global $wpdb;
		$missing  = array();
		$suppress = $wpdb->suppress_errors( true );
		foreach ( self::tables() as $name ) {
			$table = self::table( $name );
			// A probe query instead of SHOW TABLES also sees temporary tables (WordPress test suite) and needs no privileges.
			if ( false === $wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 0" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed allowlist + prefix.
				$missing[] = $table;
			}
		}
		$wpdb->suppress_errors( $suppress );
		return $missing;
	}

	public static function needs_migration(): bool {
		return (int) \SWBackupIt\WordPress\Support\Options::get( self::VERSION_OPTION, 0 ) < self::CURRENT;
	}

	/** Version 2: append-only audit log (who did what, PLAN.md §22). */
	private static function migrate_to_2(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->base_prefix . 'swbi_';
		dbDelta(
			"CREATE TABLE {$p}audit (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_login varchar(60) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  action varchar(60) NOT NULL,
  object_type varchar(30) NOT NULL DEFAULT '',
  object_id varchar(64) NOT NULL DEFAULT '',
  context longtext NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY action_created (action,created_at),
  KEY user_id (user_id)
) $charset;"
		);
	}

	private static function migrate_to_1(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->base_prefix . 'swbi_';

		$statements = array(
			"CREATE TABLE {$p}profiles (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  description text NULL,
  type varchar(20) NOT NULL DEFAULT 'full',
  config longtext NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY name (name)
) $charset;",
			"CREATE TABLE {$p}schedules (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  profile_id bigint(20) unsigned NOT NULL,
  name varchar(190) NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  frequency varchar(10) NOT NULL DEFAULT 'daily',
  cron_expression varchar(100) NOT NULL DEFAULT '',
  timezone varchar(64) NOT NULL DEFAULT 'UTC',
  time_of_day varchar(5) NOT NULL DEFAULT '02:30',
  weekday tinyint(1) NOT NULL DEFAULT 1,
  monthday tinyint(2) NOT NULL DEFAULT 1,
  retry_max tinyint(3) unsigned NOT NULL DEFAULT 3,
  retry_backoff int(10) unsigned NOT NULL DEFAULT 600,
  retry_count tinyint(3) unsigned NOT NULL DEFAULT 0,
  next_run_at datetime NULL,
  last_run_at datetime NULL,
  last_status varchar(20) NULL,
  last_error text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY enabled_next (enabled,next_run_at),
  KEY profile_id (profile_id)
) $charset;",
			"CREATE TABLE {$p}jobs (
  id char(36) NOT NULL,
  backup_id char(36) NOT NULL,
  profile_id bigint(20) unsigned NULL,
  schedule_id bigint(20) unsigned NULL,
  trigger_source varchar(20) NOT NULL DEFAULT 'manual',
  type varchar(20) NOT NULL,
  status varchar(20) NOT NULL,
  step varchar(20) NULL,
  data longtext NOT NULL,
  flags tinyint(3) unsigned NOT NULL DEFAULT 0,
  lease_owner varchar(32) NULL,
  lease_expires_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  finished_at datetime NULL,
  created_by bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY backup_id (backup_id),
  KEY status_updated (status,updated_at),
  KEY profile_id (profile_id),
  KEY schedule_id (schedule_id)
) $charset;",
			"CREATE TABLE {$p}backups (
  id char(36) NOT NULL,
  profile_id bigint(20) unsigned NULL,
  job_id char(36) NULL,
  type varchar(20) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'complete',
  created_at datetime NOT NULL,
  size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  file_count bigint(20) unsigned NOT NULL DEFAULT 0,
  volume_count int(10) unsigned NOT NULL DEFAULT 0,
  encrypted tinyint(1) NOT NULL DEFAULT 0,
  protected tinyint(1) NOT NULL DEFAULT 0,
  parent_id char(36) NULL,
  site_url varchar(255) NOT NULL DEFAULT '',
  wp_version varchar(20) NOT NULL DEFAULT '',
  manifest_sha256 char(64) NULL,
  verified_at datetime NULL,
  restore_tested_at datetime NULL,
  meta longtext NULL,
  PRIMARY KEY  (id),
  KEY profile_created (profile_id,created_at),
  KEY parent_id (parent_id),
  KEY status (status)
) $charset;",
			"CREATE TABLE {$p}backup_locations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  backup_id char(36) NOT NULL,
  target_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  remote_path varchar(500) NOT NULL DEFAULT '',
  verified_at datetime NULL,
  error text NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY backup_target (backup_id,target_id),
  KEY target_id (target_id)
) $charset;",
			"CREATE TABLE {$p}storage_targets (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  type varchar(20) NOT NULL,
  config longtext NOT NULL,
  credentials longtext NULL,
  immutable tinyint(1) NOT NULL DEFAULT 0,
  retention longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'unknown',
  message text NULL,
  usage_bytes bigint(20) unsigned NULL,
  last_checked_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY type (type)
) $charset;",
			"CREATE TABLE {$p}job_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id char(36) NOT NULL,
  seq int(10) unsigned NOT NULL,
  created_at datetime NOT NULL,
  severity varchar(10) NOT NULL,
  phase varchar(20) NOT NULL,
  message text NOT NULL,
  context longtext NULL,
  PRIMARY KEY  (id),
  KEY job_seq (job_id,seq),
  KEY created_at (created_at)
) $charset;",
		);
		foreach ( $statements as $sql ) {
			dbDelta( $sql );
		}
	}

	/** Drops all plugin tables (uninstall with explicit user opt-in only). */
	public static function drop_all(): void {
		global $wpdb;
		foreach ( self::tables() as $name ) {
			$table = self::table( $name );
			// Table names are built from the fixed allowlist above plus the WordPress prefix.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
