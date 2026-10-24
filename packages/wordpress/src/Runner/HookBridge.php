<?php
/**
 * Bridges framework independent core events to WordPress actions/filters.
 *
 * - Every core event `x` fires the action `swbi_x` (isolated: listener errors never affect backup state).
 * - Vetoable events (before_*) additionally apply the filter `swbi_allow_x`; returning false or a WP_Error
 *   aborts the operation cleanly before any state was changed.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Runner;

use SWBackupIt\Core\Event\EventDispatcherInterface;
use SWBackupIt\Core\Event\Events;
use SWBackupIt\Core\Event\FailurePolicy;

final class HookBridge {

	private const EVENTS = array(
		Events::BEFORE_BACKUP,
		Events::AFTER_BACKUP,
		Events::BACKUP_FAILED,
		Events::BEFORE_DATABASE_EXPORT,
		Events::AFTER_DATABASE_EXPORT,
		Events::BEFORE_UPLOAD,
		Events::AFTER_UPLOAD,
		Events::BEFORE_RESTORE,
		Events::AFTER_RESTORE,
		Events::RESTORE_FAILED,
		Events::BEFORE_STEP,
		Events::AFTER_STEP,
		Events::STEP_FAILED,
	);

	public function register( EventDispatcherInterface $dispatcher ): void {
		foreach ( self::EVENTS as $event ) {
			if ( in_array( $event, Events::VETOABLE, true ) ) {
				$dispatcher->listen(
					$event,
					static function ( array $payload ) use ( $event ): void {
						/**
						 * Filters whether the operation may proceed.
						 *
						 * @since 0.1.0
						 *
						 * @param bool|\WP_Error       $allowed Return false or a WP_Error to veto.
						 * @param array<string, mixed> $payload Read-only event data.
						 */
						$allowed = apply_filters( 'swbi_allow_' . $event, true, $payload );
						if ( false === $allowed || is_wp_error( $allowed ) ) {
							throw new \RuntimeException( is_wp_error( $allowed ) ? $allowed->get_error_message() : 'Vetoed by filter.' );
						}
					},
					FailurePolicy::Blocking
				);
			}
			$dispatcher->listen(
				$event,
				static function ( array $payload ) use ( $event ): void {
					/**
					 * Fires for every SWBackupIt core event.
					 *
					 * @since 0.1.0
					 *
					 * @param array<string, mixed> $payload Read-only event data.
					 */
					do_action( 'swbi_' . $event, $payload );
				}
			);
		}
	}
}
