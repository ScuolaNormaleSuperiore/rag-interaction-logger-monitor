<?php
/**
 * Time series built from hourly counts, bucketed by day, week or month.
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
 * Groups UTC hourly counts into buckets of the site time zone.
 *
 * The bucket grows with the length of the period, so a chart never has to draw more
 * than a few dozen bars: a day at most a month, a week up to about six months, a
 * month beyond that. Each UTC hour is converted to local time before it is assigned
 * to a bucket, so a day that lasts 23 or 25 hours at a daylight saving change is
 * still counted correctly.
 */
class Period_Series {

	/**
	 * One bar per day.
	 */
	public const GRANULARITY_DAY = 'day';

	/**
	 * One bar per week, starting on Monday.
	 */
	public const GRANULARITY_WEEK = 'week';

	/**
	 * One bar per calendar month.
	 */
	public const GRANULARITY_MONTH = 'month';

	/**
	 * Longest period, in days, still bucketed by day.
	 */
	private const DAY_GRANULARITY_MAX_DAYS = 31;

	/**
	 * Longest period, in days, still bucketed by week (about six months).
	 */
	private const WEEK_GRANULARITY_MAX_DAYS = 190;

	/**
	 * Longest length of a bucket, in days: only used to size the fill safeguard below.
	 *
	 * The month figure is its longest possible length (31 days), not its average, so the
	 * safeguard never rejects a period that is genuinely within `MAX_FILLED_BUCKETS`.
	 */
	private const BUCKET_LENGTH_DAYS = array(
		self::GRANULARITY_DAY   => 1,
		self::GRANULARITY_WEEK  => 7,
		self::GRANULARITY_MONTH => 31,
	);

	/**
	 * Longest period, in buckets, whose empty buckets are listed.
	 *
	 * Beyond it only the buckets that have data appear, so an extreme custom period
	 * cannot produce hundreds of empty rows. In practice this only ever applies to
	 * the month bucket, since the day and week tiers never span this many buckets.
	 * Because months vary in length, the real cutoff can be a little past this count.
	 */
	public const MAX_FILLED_BUCKETS = 400;

	/**
	 * Chooses the bucket size for a period: finer for a short period, coarser for a long one.
	 *
	 * @param Period $period Period to bucket.
	 * @return string One of the GRANULARITY_* constants.
	 */
	public static function granularity_for( Period $period ): string {
		$span_days = self::span_days( $period );

		if ( $span_days <= self::DAY_GRANULARITY_MAX_DAYS ) {
			return self::GRANULARITY_DAY;
		}

		if ( $span_days <= self::WEEK_GRANULARITY_MAX_DAYS ) {
			return self::GRANULARITY_WEEK;
		}

		return self::GRANULARITY_MONTH;
	}

	/**
	 * Builds the time series of a period, bucketed by `granularity_for()`.
	 *
	 * @param array<int, array{hour: string, turns: int, incomplete: int, input_blocks: int, output_blocks: int, tools?: int}> $hourly      Rows from `hourly_series()`; `hour` is `Y-m-d H` in UTC.
	 * @param Period                                                                                                           $period      Period the rows belong to.
	 * @param bool                                                                                                             $with_tools  Whether the source table has the tools column.
	 * @param string|null                                                                                                      $granularity Bucket size, when the caller already knows it from `granularity_for()`; computed from `$period` otherwise.
	 * @return array<int, array{date: string, turns: int, incomplete: int, input_blocks: int, output_blocks: int, tools?: int}> One entry per bucket, oldest first. `date` is the first day of the bucket.
	 */
	public static function from_hourly( array $hourly, Period $period, bool $with_tools = false, ?string $granularity = null ): array {
		$granularity = $granularity ?? self::granularity_for( $period );
		$zone        = $period->start()->getTimezone();
		$buckets     = array();

		$first = self::bucket_start( $period->start()->setTime( 0, 0, 0, 0 ), $granularity );
		$last  = self::bucket_start( $period->end()->setTime( 0, 0, 0, 0 ), $granularity );

		$max_span_days = self::MAX_FILLED_BUCKETS * self::BUCKET_LENGTH_DAYS[ $granularity ];

		// A day-long step on wall time: it follows the local calendar across clock changes.
		if ( $first->modify( '+' . $max_span_days . ' days' ) >= $last ) {
			for ( $bucket = $first; $bucket <= $last; $bucket = self::bucket_step( $bucket, $granularity ) ) {
				$buckets[ $bucket->format( 'Y-m-d' ) ] = self::empty_bucket( $bucket->format( 'Y-m-d' ), $with_tools );
			}
		}

		foreach ( $hourly as $row ) {
			$hour = self::parse_hour( (string) ( $row['hour'] ?? '' ) );

			if ( null === $hour ) {
				continue;
			}

			$local_midnight = $hour->setTimezone( $zone )->setTime( 0, 0, 0, 0 );
			$key            = self::bucket_start( $local_midnight, $granularity )->format( 'Y-m-d' );

			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = self::empty_bucket( $key, $with_tools );
			}

			foreach ( array( 'turns', 'incomplete', 'input_blocks', 'output_blocks' ) as $field ) {
				$buckets[ $key ][ $field ] += (int) ( $row[ $field ] ?? 0 );
			}

			if ( $with_tools ) {
				$buckets[ $key ]['tools'] += (int) ( $row['tools'] ?? 0 );
			}
		}

		ksort( $buckets );

		return array_values( $buckets );
	}

	/**
	 * Returns the whole days between the start and the end of a period, both at local midnight.
	 *
	 * @param Period $period Period to measure.
	 * @return int
	 */
	private static function span_days( Period $period ): int {
		$first = $period->start()->setTime( 0, 0, 0, 0 );
		$last  = $period->end()->setTime( 0, 0, 0, 0 );

		return (int) $first->diff( $last )->days;
	}

	/**
	 * Rounds a local midnight down to the start of its bucket: itself for a day, its Monday for a
	 * week, the first of its month for a month.
	 *
	 * No `default` arm: this must stay in lockstep with `bucket_step()` for every granularity, so
	 * a value neither of them handles throws here instead of silently rounding down as a day.
	 *
	 * @param DateTimeImmutable $local_midnight Local midnight to round down.
	 * @param string            $granularity    One of the GRANULARITY_* constants.
	 * @return DateTimeImmutable
	 */
	private static function bucket_start( DateTimeImmutable $local_midnight, string $granularity ): DateTimeImmutable {
		return match ( $granularity ) {
			self::GRANULARITY_WEEK  => $local_midnight->modify( '-' . ( (int) $local_midnight->format( 'N' ) - 1 ) . ' days' ),
			self::GRANULARITY_MONTH => $local_midnight->modify( 'first day of this month' ),
			self::GRANULARITY_DAY   => $local_midnight,
		};
	}

	/**
	 * Steps one bucket forward on the local calendar.
	 *
	 * No `default` arm, for the same reason as `bucket_start()`.
	 *
	 * @param DateTimeImmutable $bucket      Start of a bucket.
	 * @param string            $granularity One of the GRANULARITY_* constants.
	 * @return DateTimeImmutable
	 */
	private static function bucket_step( DateTimeImmutable $bucket, string $granularity ): DateTimeImmutable {
		return match ( $granularity ) {
			self::GRANULARITY_WEEK  => $bucket->modify( '+1 week' ),
			self::GRANULARITY_MONTH => $bucket->modify( '+1 month' ),
			self::GRANULARITY_DAY   => $bucket->modify( '+1 day' ),
		};
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
	 * Returns a bucket with all its counts at zero.
	 *
	 * @param string $date       First day of the bucket, `Y-m-d`.
	 * @param bool   $with_tools Whether the bucket includes the tools count.
	 * @return array{date: string, turns: int, incomplete: int, input_blocks: int, output_blocks: int, tools?: int}
	 */
	private static function empty_bucket( string $date, bool $with_tools = false ): array {
		$bucket = array(
			'date'          => $date,
			'turns'         => 0,
			'incomplete'    => 0,
			'input_blocks'  => 0,
			'output_blocks' => 0,
		);

		if ( $with_tools ) {
			$bucket['tools'] = 0;
		}

		return $bucket;
	}
}
