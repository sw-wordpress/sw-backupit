<?php
/**
 * Lazy service container of the WordPress adapter (no global state beyond this one accessor).
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress;

use SWBackupIt\Core\Backup\BackupRunner;
use SWBackupIt\Core\Backup\BackupRunnerFactory;
use SWBackupIt\Core\Event\EventDispatcher;
use SWBackupIt\Core\Event\EventDispatcherInterface;
use SWBackupIt\Core\Storage\StorageFactory;
use SWBackupIt\Core\Storage\StorageManager;
use SWBackupIt\WordPress\Repository\BackupRepository;
use SWBackupIt\WordPress\Repository\ProfileRepository;
use SWBackupIt\WordPress\Repository\ScheduleRepository;
use SWBackupIt\WordPress\Repository\StorageTargetRepository;
use SWBackupIt\WordPress\Repository\WpBackupCatalog;
use SWBackupIt\WordPress\Repository\WpdbJobEventStore;
use SWBackupIt\WordPress\Repository\WpdbJobRepository;
use SWBackupIt\WordPress\Runner\CronManager;
use SWBackupIt\WordPress\Runner\HookBridge;
use SWBackupIt\WordPress\Runner\JobService;
use SWBackupIt\WordPress\Runner\LifecycleListener;
use SWBackupIt\WordPress\Runner\ScheduleRunner;
use SWBackupIt\WordPress\Runner\WpSourceInfoProvider;
use SWBackupIt\WordPress\Support\Notifier;
use SWBackupIt\WordPress\Support\Settings;
use SWBackupIt\WordPress\Support\WpKeyProvider;

final class Services {

	private static ?Services $instance = null;

	/** @var array<string, object> */
	private array $cache = array();

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/** Test helper: drops cached services. */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * @template T of object
	 * @param string        $id      Class name used as cache key.
	 * @param callable(): T $factory
	 * @return T
	 */
	private function get( string $id, callable $factory ): object {
		return $this->cache[ $id ] ??= $factory();
	}

	public function jobs(): WpdbJobRepository {
		return $this->get( WpdbJobRepository::class, static fn () => new WpdbJobRepository() );
	}

	public function events(): WpdbJobEventStore {
		return $this->get(
			WpdbJobEventStore::class,
			static fn () => new WpdbJobEventStore( (int) Settings::all()['logging']['max_events_per_job'] )
		);
	}

	public function profiles(): ProfileRepository {
		return $this->get( ProfileRepository::class, static fn () => new ProfileRepository() );
	}

	public function schedules(): ScheduleRepository {
		return $this->get( ScheduleRepository::class, static fn () => new ScheduleRepository() );
	}

	public function backups(): BackupRepository {
		return $this->get( BackupRepository::class, static fn () => new BackupRepository() );
	}

	public function storage_targets(): StorageTargetRepository {
		return $this->get( StorageTargetRepository::class, static fn () => new StorageTargetRepository() );
	}

	public function keys(): WpKeyProvider {
		return $this->get( WpKeyProvider::class, static fn () => new WpKeyProvider() );
	}

	public function storage_manager(): StorageManager {
		return $this->get( StorageManager::class, fn () => new StorageManager( $this->storage_targets(), new StorageFactory() ) );
	}

	public function catalog(): WpBackupCatalog {
		return $this->get( WpBackupCatalog::class, fn () => new WpBackupCatalog( $this->backups(), $this->storage_targets() ) );
	}

	public function notifier(): Notifier {
		return $this->get( Notifier::class, static fn () => new Notifier() );
	}

	public function cron(): CronManager {
		return $this->get( CronManager::class, static fn () => new CronManager() );
	}

	public function dispatcher(): EventDispatcherInterface {
		return $this->get(
			EventDispatcher::class,
			function (): EventDispatcher {
				// Isolated listener failures (e.g. catalog registration, notifications) must be visible, not swallowed.
				$dispatcher = new EventDispatcher( new \SWBackupIt\WordPress\Support\PluginLogger() );
				( new HookBridge() )->register( $dispatcher );
				( new LifecycleListener( $this->jobs(), $this->backups(), $this->schedules(), $this->notifier() ) )->register( $dispatcher );
				return $dispatcher;
			}
		);
	}

	public function runner(): BackupRunner {
		return $this->get(
			BackupRunner::class,
			fn (): BackupRunner => new BackupRunner(
				$this->jobs(),
				$this->events(),
				new WpSourceInfoProvider(),
				BackupRunnerFactory::defaultSteps(),
				$this->dispatcher(),
				new \SWBackupIt\Core\Support\SystemClock(),
				(string) Settings::all()['logging']['level'],
				$this->keys(),
				$this->storage_manager(),
				$this->catalog()
			)
		);
	}

	public function job_service(): JobService {
		return $this->get( JobService::class, fn () => new JobService( $this->runner(), $this->jobs(), $this->cron(), $this->schedules() ) );
	}

	public function schedule_runner(): ScheduleRunner {
		return $this->get( ScheduleRunner::class, fn () => new ScheduleRunner( $this->schedules(), $this->profiles(), $this->job_service() ) );
	}
}
