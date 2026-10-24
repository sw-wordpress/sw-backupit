<?php
/**
 * UTC datetime helpers (all timestamps are stored in UTC, CODE-STYLE §5).
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Support;

use DateTimeImmutable;
use DateTimeZone;

final class Time {

	public static function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	public static function to_db( ?DateTimeImmutable $date ): ?string {
		return $date?->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	public static function from_db( ?string $value ): ?DateTimeImmutable {
		if ( null === $value || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}

	/** ISO-8601 UTC string for REST responses. */
	public static function to_iso( ?string $db_value ): ?string {
		$date = self::from_db( $db_value );
		return $date?->format( 'Y-m-d\TH:i:s\Z' );
	}
}
