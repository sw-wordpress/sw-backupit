<?php
/**
 * Computes the next run of a schedule in its own timezone (DST aware) and returns UTC.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Repository;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ScheduleCalculator {

	/**
	 * @param array<string, mixed> $schedule frequency, cron_expression, timezone, time_of_day, weekday, monthday
	 * @throws InvalidArgumentException On an invalid timezone or cron expression.
	 */
	public static function next_run( array $schedule, DateTimeImmutable $after ): DateTimeImmutable {
		$timezone          = new DateTimeZone( self::timezone_name( (string) ( $schedule['timezone'] ?? 'UTC' ) ) );
		$local             = $after->setTimezone( $timezone );
		[ $hour, $minute ] = self::parse_time( (string) ( $schedule['time_of_day'] ?? '02:30' ) );

		switch ( (string) ( $schedule['frequency'] ?? 'daily' ) ) {
			case 'hourly':
				$next = self::next_hourly( $after, $timezone, $minute );
				break;
			case 'weekly':
				$weekday = max( 0, min( 6, (int) ( $schedule['weekday'] ?? 1 ) ) );
				$next    = $local->setTime( $hour, $minute );
				while ( $next <= $local || (int) $next->format( 'w' ) !== $weekday ) {
					$next = $next->modify( '+1 day' )->setTime( $hour, $minute );
				}
				break;
			case 'monthly':
				$monthday = max( 1, min( 31, (int) ( $schedule['monthday'] ?? 1 ) ) );
				$next     = self::monthly_candidate( $local, $monthday, $hour, $minute );
				while ( $next <= $local ) {
					$next = self::monthly_candidate( $next->modify( 'first day of next month' ), $monthday, $hour, $minute );
				}
				break;
			case 'cron':
				$expression = trim( (string) ( $schedule['cron_expression'] ?? '' ) );
				if ( ! CronExpression::isValidExpression( $expression ) ) {
					throw new InvalidArgumentException( 'Invalid cron expression.' );
				}
				$next = self::next_cron( new CronExpression( $expression ), $local, $timezone );
				break;
			default:
				$next = $local->setTime( $hour, $minute );
				while ( $next <= $local ) {
					$next = $next->modify( '+1 day' )->setTime( $hour, $minute );
				}
		}

		return $next->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Next instant whose local minute is $minute. Stepping over real (UTC) time means every local hour that exists is
	 * used exactly once: the repeated hour on a DST fall-back runs again, the skipped hour on spring-forward is skipped.
	 *
	 * @throws InvalidArgumentException Never for real timezones (defensive bound of the search).
	 */
	private static function next_hourly( DateTimeImmutable $after, DateTimeZone $timezone, int $minute ): DateTimeImmutable {
		$start = intdiv( $after->getTimestamp(), 60 ) * 60 + 60;
		// Offsets are whole minutes, so a match is at most 60 minutes (plus a DST shift) away.
		for ( $timestamp = $start; $timestamp < $start + 3 * 3600; $timestamp += 60 ) {
			$candidate = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone );
			if ( (int) $candidate->format( 'i' ) === $minute ) {
				return $candidate;
			}
		}
		throw new InvalidArgumentException( 'No hourly run found.' );
	}

	/**
	 * Cron expressions with a fixed hour describe a wall-clock time that must run once per day. On a DST fall-back that
	 * local time exists twice; the second (repeated) occurrence is skipped, like classic cron does. Expressions with a
	 * wildcard hour (every n minutes/hours) keep running through the repeated hour.
	 */
	private static function next_cron( CronExpression $cron, DateTimeImmutable $local, DateTimeZone $timezone ): DateTimeImmutable {
		$fixed_hour = ! str_starts_with( (string) $cron->getExpression( CronExpression::HOUR ), '*' );
		$current    = $local;
		$next       = $local;
		for ( $i = 0; $i < 10; $i++ ) {
			$next = DateTimeImmutable::createFromMutable( $cron->getNextRunDate( $current, 0, false, $timezone->getName() ) )->setTimezone( $timezone );
			if ( ! $fixed_hour || ! self::is_repeated_wall_time( $next, $timezone ) ) {
				return $next;
			}
			$current = $next;
		}
		return $next;
	}

	/** Whether the same local wall-clock time already occurred earlier with a larger UTC offset (DST fall-back). */
	private static function is_repeated_wall_time( DateTimeImmutable $moment, DateTimeZone $timezone ): bool {
		$timestamp = $moment->getTimestamp();
		$before    = $timezone->getOffset( new DateTimeImmutable( '@' . ( $timestamp - 3 * 3600 ) ) );
		$shift     = $before - $moment->getOffset();
		if ( $shift <= 0 ) {
			return false;
		}
		$earlier = ( new DateTimeImmutable( '@' . ( $timestamp - $shift ) ) )->setTimezone( $timezone );
		return $earlier->format( 'Y-m-d H:i' ) === $moment->format( 'Y-m-d H:i' );
	}

	public static function is_valid_cron( string $expression ): bool {
		return CronExpression::isValidExpression( trim( $expression ) );
	}

	public static function is_valid_timezone( string $timezone ): bool {
		return in_array( $timezone, timezone_identifiers_list(), true ) || 'UTC' === $timezone;
	}

	private static function timezone_name( string $name ): string {
		return self::is_valid_timezone( $name ) ? $name : 'UTC';
	}

	/**
	 * @return array{int, int}
	 */
	private static function parse_time( string $time ): array {
		if ( 1 === preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $match ) ) {
			return array( (int) $match[1], (int) $match[2] );
		}
		return array( 2, 30 );
	}

	private static function monthly_candidate( DateTimeImmutable $reference, int $day, int $hour, int $minute ): DateTimeImmutable {
		$days_in_month = (int) $reference->format( 't' );
		return $reference->setDate( (int) $reference->format( 'Y' ), (int) $reference->format( 'n' ), min( $day, $days_in_month ) )->setTime( $hour, $minute );
	}
}
