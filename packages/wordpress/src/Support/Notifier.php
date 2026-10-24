<?php
/**
 * E-mail notifications through wp_mail() with de-duplication (PLAN.md §16).
 *
 * The same event for the same subject is mailed at most once per dedupe window, so repeated retries cannot flood
 * the inbox. Mail bodies never contain credentials, only job/backup identifiers and sanitised messages.
 *
 * @package SWBackupIt
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Support;

final class Notifier {

	private const OPTION = 'swbi_notification_log';

	public const EVENTS = array(
		'backup_success',
		'backup_failed',
		'backup_warnings',
		'upload_failed',
		'backup_overdue',
		'storage_low',
		'restore_success',
		'restore_failed',
	);

	/**
	 * @param string               $event         One of self::EVENTS.
	 * @param string               $subject_key   Identifies the subject for de-duplication (e.g. profile id).
	 * @param string               $subject       Mail subject.
	 * @param string               $message       Mail body (no secrets).
	 * @param array<string, mixed> $notifications Profile notification settings (on_success, on_failure, on_warning, recipients).
	 * @return bool Whether a mail was sent.
	 */
	public function notify( string $event, string $subject_key, string $subject, string $message, array $notifications = array() ): bool {
		if ( ! $this->wanted( $event, $notifications ) ) {
			return false;
		}
		$recipients = $this->recipients( $notifications );
		if ( array() === $recipients || $this->is_duplicate( $event . '|' . $subject_key ) ) {
			return false;
		}
		$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$sent = wp_mail(
			$recipients,
			sprintf( '[%s] %s', $site, $subject ),
			$message . "\n\n" . sprintf(
				/* translators: %s: admin URL */
				__( 'Details: %s', 'swbackupit' ),
				admin_url( 'admin.php?page=swbackupit' )
			)
		);
		if ( $sent ) {
			$this->remember( $event . '|' . $subject_key );
		}
		return (bool) $sent;
	}

	/**
	 * @param string               $event         Event name.
	 * @param array<string, mixed> $notifications Profile notification settings.
	 */
	private function wanted( string $event, array $notifications ): bool {
		$on_success = (bool) ( $notifications['on_success'] ?? false );
		$on_failure = (bool) ( $notifications['on_failure'] ?? true );
		$on_warning = (bool) ( $notifications['on_warning'] ?? true );
		return match ( $event ) {
			'backup_success', 'restore_success' => $on_success,
			'backup_warnings' => $on_warning,
			'backup_failed', 'upload_failed', 'restore_failed', 'backup_overdue', 'storage_low' => $on_failure,
			default => false,
		};
	}

	/**
	 * @param array<string, mixed> $notifications
	 * @return list<string>
	 */
	private function recipients( array $notifications ): array {
		$list = is_array( $notifications['recipients'] ?? null ) ? $notifications['recipients'] : array();
		if ( array() === $list ) {
			$list = (array) ( Settings::all()['notifications']['recipients'] ?? array() );
		}
		if ( array() === $list ) {
			$list = array( (string) get_option( 'admin_email' ) );
		}
		return array_values( array_filter( array_map( 'sanitize_email', array_map( 'strval', $list ) ), 'is_email' ) );
	}

	private function is_duplicate( string $key ): bool {
		$minutes = max( 1, (int) ( Settings::all()['notifications']['dedupe_minutes'] ?? 60 ) );
		$log     = \SWBackupIt\WordPress\Support\Options::get( self::OPTION, array() );
		$at      = is_array( $log ) ? (int) ( $log[ md5( $key ) ] ?? 0 ) : 0;
		return $at > time() - $minutes * MINUTE_IN_SECONDS;
	}

	private function remember( string $key ): void {
		$log                = \SWBackupIt\WordPress\Support\Options::get( self::OPTION, array() );
		$log                = is_array( $log ) ? $log : array();
		$now                = time();
		$log                = array_filter( $log, static fn ( $at ): bool => is_int( $at ) && $at > $now - DAY_IN_SECONDS );
		$log[ md5( $key ) ] = $now;
		\SWBackupIt\WordPress\Support\Options::update( self::OPTION, $log, false );
	}
}
