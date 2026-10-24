<?php
/**
 * Plugin bootstrap: wires WordPress hooks. All business logic lives in services.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress;

use SWBackupIt\WordPress\Admin\AdminPage;
use SWBackupIt\WordPress\Database\Schema;
use SWBackupIt\WordPress\Repository\WpdbJobEventStore;
use SWBackupIt\WordPress\Rest\BackupsController;
use SWBackupIt\WordPress\Rest\EncryptionKeysController;
use SWBackupIt\WordPress\Rest\HealthController;
use SWBackupIt\WordPress\Rest\JobsController;
use SWBackupIt\WordPress\Rest\ProfilesController;
use SWBackupIt\WordPress\Rest\SchedulesController;
use SWBackupIt\WordPress\Rest\SettingsController;
use SWBackupIt\WordPress\Rest\StorageTargetsController;
use SWBackupIt\WordPress\Runner\CronManager;
use SWBackupIt\WordPress\Support\Settings;

final class Plugin {

	public const MAINTENANCE_OPTION = 'swbi_last_maintenance';

	private static ?Plugin $instance = null;
	private bool $booted             = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Support\Capabilities::register();
		( new Support\AuditLogger() )->register();
		( new Runner\PreUpdateBackup() )->register();
		add_filter( 'load_textdomain_mofile', array( $this, 'fallback_mofile' ), 10, 2 );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_migrate' ), 5 );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
		add_filter( 'cron_schedules', array( CronManager::class, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( CronManager::HOOK_RUN_JOB, array( $this, 'cron_run_job' ) );
		add_action( CronManager::HOOK_WATCH, array( $this, 'cron_watch' ) );
		add_action( CronManager::HOOK_MAINTAIN, array( $this, 'cron_maintain' ) );
		add_action( 'init', array( $this, 'ensure_cron' ), 20 );

		if ( is_admin() ) {
			( new AdminPage() )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			foreach ( array(
				'profile'   => Cli\ProfileCommand::class,
				'backup'    => Cli\BackupCommand::class,
				'job'       => Cli\JobCommand::class,
				'schedule'  => Cli\ScheduleCommand::class,
				'catalog'   => Cli\CatalogCommand::class,
				'storage'   => Cli\StorageCommand::class,
				'retention' => Cli\RetentionCommand::class,
			) as $name => $class ) {
				\WP_CLI::add_command( 'swbackupit ' . $name, $class );
			}
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'swbackupit', false, dirname( plugin_basename( SWBACKUPIT_FILE ) ) . '/languages' );
	}

	/**
	 * Regional variants (de_AT, de_CH, de_DE_formal, fr_CA, it_CH ...) use the translation of the language we ship.
	 *
	 * @param string $mofile Path WordPress is about to load.
	 * @param string $domain Text domain.
	 */
	public function fallback_mofile( string $mofile, string $domain ): string {
		if ( 'swbackupit' !== $domain || is_readable( $mofile ) ) {
			return $mofile;
		}
		$shipped  = array(
			'de' => 'de_DE',
			'fr' => 'fr_FR',
			'pl' => 'pl_PL',
			'it' => 'it_IT',
		);
		$language = substr( basename( $mofile, '.mo' ), strlen( 'swbackupit-' ), 2 );
		$fallback = isset( $shipped[ $language ] ) ? dirname( $mofile ) . '/swbackupit-' . $shipped[ $language ] . '.mo' : '';
		return '' !== $fallback && is_readable( $fallback ) ? $fallback : $mofile;
	}

	/** Cheap option comparison; migrations themselves are small and idempotent. */
	public function maybe_migrate(): void {
		if ( Schema::needs_migration() && Schema::migrate() ) {
			// grant_defaults() only touches the main site on multisite: subsite administrators never get the capabilities.
			\SWBackupIt\WordPress\Support\Capabilities::grant_defaults();
		}
	}

	public function ensure_cron(): void {
		if ( ! wp_doing_ajax() ) {
			( new CronManager() )->ensure_watcher();
		}
	}

	public function register_rest(): void {
		foreach ( array(
			new HealthController(),
			new ProfilesController(),
			new SchedulesController(),
			new JobsController(),
			new BackupsController(),
			new StorageTargetsController(),
			new EncryptionKeysController(),
			new Rest\AuditController(),
			new SettingsController(),
		) as $controller ) {
			$controller->register_routes();
		}
	}

	public function cron_run_job( string $job_id ): void {
		Services::instance()->job_service()->run_in_cron( $job_id );
	}

	public function cron_watch(): void {
		\SWBackupIt\WordPress\Support\Options::update( CronManager::TICK_OPTION, time(), false );
		// In "system" cron mode the system cron (`wp swbackupit schedule run-due`) owns the schedules; starting them here
		// as well would race with it.
		if ( 'system' !== (string) Settings::all()['cron']['mode'] ) {
			Services::instance()->schedule_runner()->run_due();
		}
		$this->background_upkeep();
	}

	/**
	 * Light periodic work shared by the WP-Cron watcher and the system cron command: watchdog, health notifications and
	 * the daily maintenance when it is overdue.
	 */
	public function background_upkeep(): void {
		if ( false === get_transient( 'swbi_health_check' ) ) {
			set_transient( 'swbi_health_check', 1, 10 * MINUTE_IN_SECONDS );
			( new \SWBackupIt\WordPress\Runner\HealthMonitor() )->notify();
		}
		( new Runner\JobWatchdog() )->run();
	}

	/** Runs the daily maintenance when the last run is more than a day ago (system cron setups without WP-Cron). */
	public function maintain_if_due(): bool {
		$last = (int) \SWBackupIt\WordPress\Support\Options::get( self::MAINTENANCE_OPTION, 0 );
		if ( $last > time() - DAY_IN_SECONDS ) {
			return false;
		}
		$this->cron_maintain();
		return true;
	}

	public function cron_maintain(): void {
		\SWBackupIt\WordPress\Support\Options::update( self::MAINTENANCE_OPTION, time(), false );
		WpdbJobEventStore::purge_older_than( (int) Settings::all()['logging']['retention_days'] );
		Support\AuditLogger::purge_older_than( (int) ( Settings::all()['logging']['audit_retention_days'] ?? 365 ) );
		( new \SWBackupIt\WordPress\Runner\RestorePreparer() )->cleanup_old();
		( new \SWBackupIt\WordPress\Runner\HealthMonitor() )->notify();
		( new Runner\BackgroundChecks() )->verify_oldest();
		( new Runner\BackgroundChecks() )->restore_test_if_due();
	}
}
