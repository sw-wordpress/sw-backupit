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

	/** Cheap option comparison; migrations themselves are small and idempotent. */
	public function maybe_migrate(): void {
		if ( Schema::needs_migration() ) {
			Schema::migrate();
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
		Services::instance()->schedule_runner()->run_due();
		if ( false === get_transient( 'swbi_health_check' ) ) {
			set_transient( 'swbi_health_check', 1, 10 * MINUTE_IN_SECONDS );
			( new \SWBackupIt\WordPress\Runner\HealthMonitor() )->notify();
		}
	}

	public function cron_maintain(): void {
		WpdbJobEventStore::purge_older_than( (int) Settings::all()['logging']['retention_days'] );
		( new \SWBackupIt\WordPress\Runner\RestorePreparer() )->cleanup_old();
		( new \SWBackupIt\WordPress\Runner\HealthMonitor() )->notify();
	}
}
