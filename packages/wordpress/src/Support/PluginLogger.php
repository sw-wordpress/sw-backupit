<?php
/**
 * PSR-3 logger of the WordPress adapter for problems outside a job's own event log (e.g. failing event listeners).
 *
 * Entries go to the PHP error log (prefixed, without secrets: callers only pass event names, exception classes and
 * sanitised messages) and are exposed through the `swbi_log` action so monitoring plugins can pick them up.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Support;

use Psr\Log\AbstractLogger;

final class PluginLogger extends AbstractLogger {

	/**
	 * @param mixed                $level   PSR-3 level.
	 * @param string|\Stringable   $message Message.
	 * @param array<string, mixed> $context Context (scalars only are written).
	 */
	public function log( $level, string|\Stringable $message, array $context = array() ): void {
		$level   = is_string( $level ) ? $level : 'error';
		$scalars = array_filter( $context, static fn ( $value ): bool => is_scalar( $value ) || null === $value );
		error_log( '[SWBackupIt] ' . $level . ': ' . $message . ( array() !== $scalars ? ' ' . wp_json_encode( $scalars ) : '' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational log for failures that have no job log.

		/**
		 * Fires for every entry of the plugin logger (failing event listeners and other problems outside a job log).
		 *
		 * @since 0.1.0
		 *
		 * @param string               $level   PSR-3 level.
		 * @param string               $message Message (English, no secrets).
		 * @param array<string, mixed> $context Scalar context.
		 */
		do_action( 'swbi_log', $level, (string) $message, $scalars );
	}
}
