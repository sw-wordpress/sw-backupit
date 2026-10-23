<?php
/**
 * Computes the next run of a schedule in its own timezone (DST aware) and returns UTC.
 *
 * @package SWBackupIt
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
		$timezone = new DateTimeZone( self::timezone_name( (string) ( $schedule['timezone'] ?? 'UTC' ) ) );
		$local    = $after->setTimezone( $timezone );
		[ $hour, $minute ] = self::parse_time( (string) ( $schedule['time_of_day'] ?? '02:30' ) );

		switch ( (string) ( $schedule['frequency'] ?? 'daily' ) ) {
			case 'hourly':
				$next = $local->setTime( (int) $local->format( 'G' ), $minute );
				while ( $next <= $local ) {
					$next = $next->modify( '+1 hour' );
				}
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
				$next = DateTimeImmutable::createFromMutable(
					( new CronExpression( $expression ) )->getNextRunDate( $local, 0, false, $timezone->getName() )
				);
				break;
			default:
				$next = $local->setTime( $hour, $minute );
				while ( $next <= $local ) {
					$next = $next->modify( '+1 day' )->setTime( $hour, $minute );
				}
		}

		return $next->setTimezone( new DateTimeZone( 'UTC' ) );
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
