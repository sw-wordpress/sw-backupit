<?php
/**
 * WP-CLI retention command.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Cli;

use SWBackupIt\Core\Retention\RetentionExecutor;
use SWBackupIt\Core\Retention\RetentionPlanner;
use SWBackupIt\Core\Retention\RetentionPolicy;
use SWBackupIt\WordPress\Services;
use SWBackupIt\WordPress\Support\Settings;
use SWBackupIt\WordPress\Support\Time;

/**
 * Backup retention.
 */
final class RetentionCommand {

	/**
	 * Applies the retention rules of all profiles (or only shows the plan with --dry-run).
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Only show which backups would be deleted.
	 *
	 * [--format=<format>]
	 * : table or json. Default table.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		$dry     = isset( $assoc_args['dry-run'] );
		$catalog = Services::instance()->catalog();
		$report  = array();
		foreach ( Services::instance()->profiles()->all() as $profile ) {
			$retention = $profile->retention;
			if ( array() === $retention ) {
				$retention = (array) Settings::all()['retention_default'];
			}
			$policy = RetentionPolicy::fromArray( $retention );
			if ( ! $policy->isActive() ) {
				continue;
			}
			$plan   = ( new RetentionPlanner() )->plan( $catalog->records( $profile->id ), $policy, Time::now() );
			$result = ( new RetentionExecutor() )->execute( $plan, $catalog, $dry );
			foreach ( $plan->warnings as $warning ) {
				\WP_CLI::warning( $warning );
			}
			foreach ( $result['deleted'] as $id ) {
				$report[] = array(
					'profile' => $profile->name,
					'backup'  => $id,
					'action'  => $dry ? 'would delete' : 'deleted',
					'reason'  => $plan->delete[ $id ] ?? '',
				);
			}
			foreach ( $result['failed'] as $id => $error ) {
				$report[] = array(
					'profile' => $profile->name,
					'backup'  => $id,
					'action'  => 'FAILED (kept)',
					'reason'  => $error,
				);
			}
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $report, array( 'profile', 'backup', 'action', 'reason' ) );
		\WP_CLI::success( $dry ? 'Dry run finished, nothing was deleted.' : 'Retention applied.' );
	}
}
