<?php
/**
 * Daily series built from hourly counts.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Repository;

use DateTimeImmutable;
use DateTimeZone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Groups UTC hourly counts into days of the site time zone.
 *
 * Each UTC hour is converted to local time and assigned to its local day, so a day
 * that lasts 23 or 25 hours at a daylight saving change is counted correctly.
 */
class Daily_Series {

	/**
	 * Longest period, in days, whose empty days are listed.
	 *
	 * Beyond it only the days that have data appear, so a very long custom
	 * period cannot produce thousands of empty rows.
	 */
	public const MAX_FILLED_DAYS = 400;

	/**
	 * Builds the daily series of a period.
	 *
	 * @param array<int, array{hour: string, turns: int, incomplete: int, input_blocks: int, output_blocks: int, tools?: int}> $hourly     Rows from `hourly_series()`; `hour` is `Y-m-d H` in UTC.
	 * @param Period                                                                                                           $period     Period the rows belong to.
	 * @param bool                                                                                                             $with_tools Whether the source table has the tools column.
	 * @return array<int, array{date: string, turns: int, incomplete: int, input_blocks: int, output_blocks: int, tools?: int}> One entry per local day, oldest first.
	 */
	public static function from_hourly( array $hourly, Period $period, bool $with_tools = false ): array {
		$zone = $period->start()->getTimezone();
		$days = array();

		$first = $period->start()->setTime( 0, 0, 0, 0 );
		$last  = $period->end()->setTime( 0, 0, 0, 0 );

		// A day-long step on wall time: it follows the local calendar across clock changes.
		if ( $first->modify( '+' . self::MAX_FILLED_DAYS . ' days' ) >= $last ) {
			for ( $day = $first; $day <= $last; $day = $day->modify( '+1 day' ) ) {
				$days[ $day->format( 'Y-m-d' ) ] = self::empty_day( $day->format( 'Y-m-d' ), $with_tools );
			}
		}

		foreach ( $hourly as $row ) {
			$hour = self::parse_hour( (string) ( $row['hour'] ?? '' ) );

			if ( null === $hour ) {
				continue;
			}

			$key = $hour->setTimezone( $zone )->format( 'Y-m-d' );

			if ( ! isset( $days[ $key ] ) ) {
				$days[ $key ] = self::empty_day( $key, $with_tools );
			}

			foreach ( array( 'turns', 'incomplete', 'input_blocks', 'output_blocks' ) as $field ) {
				$days[ $key ][ $field ] += (int) ( $row[ $field ] ?? 0 );
			}

			if ( $with_tools ) {
				$days[ $key ]['tools'] += (int) ( $row['tools'] ?? 0 );
			}
		}

		ksort( $days );

		return array_values( $days );
	}

	/**
	 * Parses an hour bucket (`Y-m-d H`, UTC).
	 *
	 * @param string $hour Bucket text.
	 * @return DateTimeImmutable|null Null when the text is not a valid hour.
	 */
	private static function parse_hour( string $hour ): ?DateTimeImmutable {
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d H', $hour, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();

		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}

		return $date;
	}

	/**
	 * Returns a day with all its counts at zero.
	 *
	 * @param string $date Day, `Y-m-d`.
	 * @param bool   $with_tools Whether the day includes the tools count.
	 * @return array{date: string, turns: int, incomplete: int, input_blocks: int, output_blocks: int, tools?: int}
	 */
	private static function empty_day( string $date, bool $with_tools = false ): array {
		$day = array(
			'date'          => $date,
			'turns'         => 0,
			'incomplete'    => 0,
			'input_blocks'  => 0,
			'output_blocks' => 0,
		);

		if ( $with_tools ) {
			$day['tools'] = 0;
		}

		return $day;
	}
}
