<?php
/**
 * Default backup scope: EVERYTHING is backed up (the whole WordPress directory and the complete database), only caches are
 * excluded, so a 100 % copy of the site is always available. Seeded once on activation when no profile exists.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Support;

use SWBackupIt\WordPress\Services;

final class DefaultProfiles {

	/**
	 * Cache and temporary directories that are never worth backing up. The backup staging area is excluded automatically.
	 *
	 * @var list<string>
	 */
	public const CACHE_EXCLUDES = array(
		'wp-content/cache',
		'wp-content/uploads/cache',
		'wp-content/et-cache',
		'wp-content/litespeed',
		'wp-content/upgrade',
	);

	/**
	 * Content directories refreshed by the lightweight incremental profile.
	 *
	 * @var list<string>
	 */
	public const CONTENT_PATHS = array(
		'wp-content/uploads',
		'wp-content/themes',
		'wp-content/plugins',
	);

	/**
	 * @return array<string, mixed> Profile input for the "everything" backup.
	 */
	public static function full(): array {
		return array(
			'name'          => __( 'Full backup (everything, without cache)', 'swbackupit' ),
			'description'   => __( 'Complete copy of the WordPress directory and the database. Only cache directories are excluded.', 'swbackupit' ),
			'type'          => 'full',
			'include_paths' => array(),
			'exclude_paths' => self::CACHE_EXCLUDES,
			'retention'     => array( 'max_count' => 5 ),
		);
	}

	/**
	 * @return array<string, mixed> Profile input for the content-only incremental backup.
	 */
	public static function content(): array {
		return array(
			'name'          => __( 'Content update (uploads, themes, plugins)', 'swbackupit' ),
			'description'   => __( 'Incremental backup of uploads, themes and plugins (plus the database); only changed files are stored.', 'swbackupit' ),
			'type'          => 'incremental',
			'include_paths' => self::CONTENT_PATHS,
			'exclude_paths' => self::CACHE_EXCLUDES,
			'retention'     => array( 'max_count' => 14 ),
		);
	}

	/**
	 * Creates the two default profiles when the site has none yet.
	 *
	 * @return int Number of profiles created.
	 */
	public static function seed_if_empty(): int {
		$profiles = Services::instance()->profiles();
		if ( array() !== $profiles->all() ) {
			return 0;
		}
		$profiles->create( self::full() );
		$profiles->create( self::content() );
		return 2;
	}

	/**
	 * Fills defaults into a profile that is being created through the API: everything is backed up, caches excluded.
	 *
	 * @param array<string, mixed> $input Client input.
	 * @return array<string, mixed>
	 */
	public static function apply_creation_defaults( array $input ): array {
		if ( ! array_key_exists( 'type', $input ) ) {
			$input['type'] = 'full';
		}
		if ( ! array_key_exists( 'exclude_paths', $input ) ) {
			$input['exclude_paths'] = self::CACHE_EXCLUDES;
		}
		return $input;
	}
}
