<?php
/**
 * Unit tests for the period series.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RILM\Repository\Period;
use RILM\Repository\Period_Series;

/**
 * Verifies the choice of bucket size and the grouping of UTC hours into local buckets,
 * including daylight saving changes.
 */
class PeriodSeriesTest extends TestCase {

	/**
	 * Builds a local date and time in Europe/Rome.
	 *
	 * @param string $text Date and time.
	 * @return DateTimeImmutable
	 */
	private static function rome( string $text ): DateTimeImmutable {
		return new DateTimeImmutable( $text, new DateTimeZone( 'Europe/Rome' ) );
	}

	/**
	 * Builds an hourly row.
	 *
	 * @param string $hour       UTC hour, `Y-m-d H`.
	 * @param int    $turns      Turns.
	 * @param int    $incomplete Incomplete turns.
	 * @param int    $input      Input blocks.
	 * @param int    $output     Output blocks.
	 * @return array
	 */
	private static function hour( string $hour, int $turns, int $incomplete = 0, int $input = 0, int $output = 0 ): array {
		return array(
			'hour'          => $hour,
			'turns'         => $turns,
			'incomplete'    => $incomplete,
			'input_blocks'  => $input,
			'output_blocks' => $output,
		);
	}

	/**
	 * A period of at most 31 days is bucketed by day.
	 *
	 * @return void
	 */
	public function test_granularity_for_short_periods_is_daily(): void {
		$start = self::rome( '2026-01-01 00:00:00' );

		$this->assertSame( Period_Series::GRANULARITY_DAY, Period_Series::granularity_for( Period::custom( $start, $start->modify( '+31 days' ) ) ) );
	}

	/**
	 * A period just past 31 days is bucketed by week.
	 *
	 * @return void
	 */
	public function test_granularity_for_medium_periods_is_weekly(): void {
		$start = self::rome( '2026-01-01 00:00:00' );

		$this->assertSame( Period_Series::GRANULARITY_WEEK, Period_Series::granularity_for( Period::custom( $start, $start->modify( '+32 days' ) ) ) );
		$this->assertSame( Period_Series::GRANULARITY_WEEK, Period_Series::granularity_for( Period::custom( $start, $start->modify( '+190 days' ) ) ) );
	}

	/**
	 * A period past about six months is bucketed by month.
	 *
	 * @return void
	 */
	public function test_granularity_for_long_periods_is_monthly(): void {
		$start = self::rome( '2026-01-01 00:00:00' );

		$this->assertSame( Period_Series::GRANULARITY_MONTH, Period_Series::granularity_for( Period::custom( $start, $start->modify( '+191 days' ) ) ) );
		$this->assertSame( Period_Series::GRANULARITY_MONTH, Period_Series::granularity_for( Period::preset( Period::YEAR, self::rome( '2026-10-02 12:00:00' ) ) ) );
	}

	/**
	 * A caller that already knows the granularity (from its own `granularity_for()` call) can pass
	 * it in, instead of `from_hourly()` computing it again from the same period.
	 *
	 * @return void
	 */
	public function test_explicit_granularity_overrides_the_period_length(): void {
		// Five days would normally be bucketed by day; forcing "month" proves the parameter
		// is honoured rather than silently recomputed from the period.
		$period = Period::custom( self::rome( '2026-03-15 00:00:00' ), self::rome( '2026-03-20 00:00:00' ) );

		$days = Period_Series::from_hourly(
			array( self::hour( '2026-03-16 10', 4 ) ),
			$period,
			false,
			Period_Series::GRANULARITY_MONTH
		);

		$this->assertSame( array( '2026-03-01' ), array_column( $days, 'date' ) );
		$this->assertSame( array( 4 ), array_column( $days, 'turns' ) );
	}

	/**
	 * Every local day of a short period is listed, with zeros where nothing happened.
	 *
	 * @return void
	 */
	public function test_empty_days_are_filled_with_zeros(): void {
		$period = Period::custom( self::rome( '2026-10-01 08:00:00' ), self::rome( '2026-10-04 20:00:00' ) );

		$days = Period_Series::from_hourly( array( self::hour( '2026-10-02 10', 4 ) ), $period );

		$this->assertSame( array( '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04' ), array_column( $days, 'date' ) );
		$this->assertSame( array( 0, 4, 0, 0 ), array_column( $days, 'turns' ) );
	}

	/**
	 * An empty result still gives the days of a short period.
	 *
	 * @return void
	 */
	public function test_no_rows_gives_zero_days(): void {
		$period = Period::preset( Period::TODAY, self::rome( '2026-10-02 14:30:00' ) );

		$days = Period_Series::from_hourly( array(), $period );

		$this->assertCount( 1, $days );
		$this->assertSame( '2026-10-02', $days[0]['date'] );
		$this->assertSame( 0, $days[0]['turns'] );
	}

	/**
	 * Hours of the same local day are added up.
	 *
	 * @return void
	 */
	public function test_hours_are_summed_per_day(): void {
		$period = Period::preset( Period::TODAY, self::rome( '2026-10-02 14:30:00' ) );

		$days = Period_Series::from_hourly(
			array(
				self::hour( '2026-10-02 06', 3, 1, 0, 1 ),
				self::hour( '2026-10-02 09', 2, 0, 2, 0 ),
				self::hour( '2026-10-02 11', 5, 2, 1, 1 ),
			),
			$period
		);

		$this->assertCount( 1, $days );
		$this->assertSame(
			array(
				'date'          => '2026-10-02',
				'turns'         => 10,
				'incomplete'    => 3,
				'input_blocks'  => 3,
				'output_blocks' => 2,
			),
			$days[0]
		);
	}

	/**
	 * Tool turns are added with the other hourly figures only when that optional column exists.
	 *
	 * @return void
	 */
	public function test_tool_turns_are_summed_per_day(): void {
		$period = Period::preset( Period::TODAY, self::rome( '2026-10-02 14:30:00' ) );
		$rows   = array(
			self::hour( '2026-10-02 06', 3 ) + array( 'tools' => 1 ),
			self::hour( '2026-10-02 09', 2 ) + array( 'tools' => 2 ),
		);

		$days = Period_Series::from_hourly( $rows, $period, true );

		$this->assertSame( 3, $days[0]['tools'] );
	}

	/**
	 * A UTC hour belongs to the local day it falls in: late evening UTC is already tomorrow in Rome.
	 *
	 * @return void
	 */
	public function test_utc_hours_are_assigned_to_the_local_day(): void {
		$period = Period::custom( self::rome( '2026-10-01 00:00:00' ), self::rome( '2026-10-03 23:59:00' ) );

		$days = Period_Series::from_hourly(
			array(
				self::hour( '2026-10-01 21', 1 ), // 23:00 in Rome: still 1 October.
				self::hour( '2026-10-01 22', 2 ), // Midnight in Rome: 2 October.
				self::hour( '2026-10-02 21', 4 ), // 23:00 in Rome: still 2 October.
				self::hour( '2026-10-02 22', 8 ), // Midnight in Rome: 3 October.
			),
			$period
		);

		$this->assertSame( array( '2026-10-01', '2026-10-02', '2026-10-03' ), array_column( $days, 'date' ) );
		$this->assertSame( array( 1, 6, 8 ), array_column( $days, 'turns' ) );
	}

	/**
	 * The day that lasts 25 hours when summer time ends is counted whole, and the next day starts at local midnight.
	 *
	 * @return void
	 */
	public function test_day_with_25_hours(): void {
		$period = Period::custom( self::rome( '2026-10-24 00:00:00' ), self::rome( '2026-10-26 23:00:00' ) );

		// 25 October 2026 starts at 22:00 UTC on the 24th (UTC+2) and ends at 23:00 UTC on the 25th (UTC+1): 25 hours.
		$rows = array();

		for ( $hour = 0; $hour < 25; $hour++ ) {
			$utc    = ( new DateTimeImmutable( '2026-10-24 22:00:00', new DateTimeZone( 'UTC' ) ) )->modify( '+' . $hour . ' hours' );
			$rows[] = self::hour( $utc->format( 'Y-m-d H' ), 1 );
		}

		$rows[] = self::hour( '2026-10-25 23', 100 ); // Midnight on 26 October in Rome, now UTC+1.

		$days = Period_Series::from_hourly( $rows, $period );

		$this->assertSame( array( '2026-10-24', '2026-10-25', '2026-10-26' ), array_column( $days, 'date' ) );
		$this->assertSame( array( 0, 25, 100 ), array_column( $days, 'turns' ) );
	}

	/**
	 * The day that lasts 23 hours when summer time starts is counted whole.
	 *
	 * @return void
	 */
	public function test_day_with_23_hours(): void {
		$period = Period::custom( self::rome( '2026-03-28 00:00:00' ), self::rome( '2026-03-30 23:00:00' ) );

		// 29 March 2026 runs from 23:00 UTC on the 28th to 22:00 UTC on the 29th (excluded).
		$rows = array();

		for ( $hour = 0; $hour < 23; $hour++ ) {
			$utc    = ( new DateTimeImmutable( '2026-03-28 23:00:00', new DateTimeZone( 'UTC' ) ) )->modify( '+' . $hour . ' hours' );
			$rows[] = self::hour( $utc->format( 'Y-m-d H' ), 1 );
		}

		$rows[] = self::hour( '2026-03-29 22', 50 ); // Midnight on 30 March in Rome.

		$days = Period_Series::from_hourly( $rows, $period );

		$this->assertSame( array( '2026-03-28', '2026-03-29', '2026-03-30' ), array_column( $days, 'date' ) );
		$this->assertSame( array( 0, 23, 50 ), array_column( $days, 'turns' ) );
	}

	/**
	 * Days are returned oldest first whatever the order of the rows.
	 *
	 * @return void
	 */
	public function test_days_are_sorted(): void {
		$period = Period::custom( self::rome( '2026-10-01 00:00:00' ), self::rome( '2026-10-03 23:00:00' ) );

		$days = Period_Series::from_hourly(
			array(
				self::hour( '2026-10-03 10', 3 ),
				self::hour( '2026-10-01 10', 1 ),
			),
			$period
		);

		$this->assertSame( array( '2026-10-01', '2026-10-02', '2026-10-03' ), array_column( $days, 'date' ) );
	}

	/**
	 * Rows with a malformed hour are ignored.
	 *
	 * @dataProvider provide_bad_hours
	 *
	 * @param string $hour Malformed hour.
	 * @return void
	 */
	public function test_malformed_hours_are_ignored( string $hour ): void {
		$period = Period::preset( Period::TODAY, self::rome( '2026-10-02 14:30:00' ) );

		$days = Period_Series::from_hourly( array( self::hour( $hour, 99 ) ), $period );

		$this->assertSame( array( 0 ), array_column( $days, 'turns' ) );
	}

	/**
	 * Provides malformed hours.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_bad_hours(): array {
		return array(
			'text'           => array( 'garbage' ),
			'empty'          => array( '' ),
			'impossible day' => array( '2026-02-30 10' ),
			'impossible hr'  => array( '2026-10-02 99' ),
			'with minutes'   => array( '2026-10-02 10:30' ),
		);
	}

	/**
	 * A data row outside the listed days (cannot happen for a correct query) still gets its own day.
	 *
	 * @return void
	 */
	public function test_unexpected_day_is_kept(): void {
		$period = Period::preset( Period::TODAY, self::rome( '2026-10-02 14:30:00' ) );

		$days = Period_Series::from_hourly( array( self::hour( '2026-09-30 10', 2 ) ), $period );

		$this->assertSame( array( '2026-09-30', '2026-10-02' ), array_column( $days, 'date' ) );
	}

	/**
	 * Hours of the same local week are grouped under the Monday that starts it.
	 *
	 * @return void
	 */
	public function test_week_buckets_group_by_monday(): void {
		// Two months: past the daily tier, still within the weekly one.
		$period = Period::custom( self::rome( '2026-01-01 00:00:00' ), self::rome( '2026-02-28 00:00:00' ) );

		$days = Period_Series::from_hourly(
			array(
				self::hour( '2026-01-14 10', 3 ), // Wednesday 14 January, Rome UTC+1: week of Monday 12 January.
				self::hour( '2026-01-19 10', 2 ), // Monday 19 January, Rome UTC+1: starts its own week.
			),
			$period
		);

		$by_date = array_combine( array_column( $days, 'date' ), $days );

		$this->assertArrayHasKey( '2026-01-12', $by_date );
		$this->assertArrayHasKey( '2026-01-19', $by_date );
		$this->assertSame( 3, $by_date['2026-01-12']['turns'] );
		$this->assertSame( 2, $by_date['2026-01-19']['turns'] );
	}

	/**
	 * Empty weeks between two weeks with data are still listed, with zeros.
	 *
	 * @return void
	 */
	public function test_week_tier_fills_empty_weeks_with_zeros(): void {
		$period = Period::custom( self::rome( '2026-01-01 00:00:00' ), self::rome( '2026-02-28 00:00:00' ) );

		$days = Period_Series::from_hourly( array( self::hour( '2026-01-19 10', 2 ) ), $period );

		$by_date = array_combine( array_column( $days, 'date' ), $days );

		// 2 February is the Monday two weeks after 19 January.
		$this->assertArrayHasKey( '2026-02-02', $by_date );
		$this->assertSame( 0, $by_date['2026-02-02']['turns'] );
	}

	/**
	 * Hours of the same local month are grouped under its first day.
	 *
	 * @return void
	 */
	public function test_month_buckets_group_by_calendar_month(): void {
		// More than about six months: bucketed by month.
		$period = Period::custom( self::rome( '2026-01-01 00:00:00' ), self::rome( '2026-10-02 00:00:00' ) );

		$days = Period_Series::from_hourly(
			array(
				self::hour( '2026-03-15 10', 4 ), // March, before daylight saving starts: Rome UTC+1.
				self::hour( '2026-03-20 10', 1 ), // Same month, same bucket.
				self::hour( '2026-07-01 00', 2 ), // July, daylight saving: Rome 02:00, still 1 July.
			),
			$period
		);

		$by_date = array_combine( array_column( $days, 'date' ), $days );

		$this->assertArrayHasKey( '2026-03-01', $by_date );
		$this->assertArrayHasKey( '2026-07-01', $by_date );
		$this->assertSame( 5, $by_date['2026-03-01']['turns'] );
		$this->assertSame( 2, $by_date['2026-07-01']['turns'] );

		// April has no data but is still listed, with zeros.
		$this->assertArrayHasKey( '2026-04-01', $by_date );
		$this->assertSame( 0, $by_date['2026-04-01']['turns'] );
	}

	/**
	 * A period comfortably inside the fill limit is still fully listed, even bucketed by month.
	 *
	 * @return void
	 */
	public function test_long_month_period_is_still_filled(): void {
		$start  = self::rome( '2000-01-01 00:00:00' );
		$period = Period::custom( $start, $start->modify( '+350 months' ) );

		$this->assertCount( 351, Period_Series::from_hourly( array(), $period ) );
	}

	/**
	 * A period far beyond the fill limit lists only the buckets that have data.
	 *
	 * @return void
	 */
	public function test_very_long_period_is_not_filled(): void {
		$start  = self::rome( '2000-01-01 00:00:00' );
		$period = Period::custom( $start, $start->modify( '+900 months' ) );

		$this->assertSame( array(), Period_Series::from_hourly( array(), $period ) );

		$days = Period_Series::from_hourly( array( self::hour( '2024-05-05 10', 7 ) ), $period );

		$this->assertSame( array( '2024-05-01' ), array_column( $days, 'date' ) );
		$this->assertSame( array( 7 ), array_column( $days, 'turns' ) );
	}

	/**
	 * An unrecognized granularity throws instead of silently rounding down and stepping as a day.
	 *
	 * `bucket_start()` and `bucket_step()` must stay in lockstep for every granularity; neither has
	 * a `default` arm, so a tier added to one but forgotten in the other fails loudly as soon as it
	 * is used, instead of quietly misaligning the buckets it produces.
	 *
	 * @return void
	 */
	public function test_unknown_granularity_is_rejected_not_rounded_as_a_day(): void {
		$bucket_start = new \ReflectionMethod( Period_Series::class, 'bucket_start' );
		$bucket_step  = new \ReflectionMethod( Period_Series::class, 'bucket_step' );
		$midnight     = self::rome( '2026-01-01 00:00:00' );

		try {
			$bucket_start->invoke( null, $midnight, 'quarter' );
			$this->fail( 'bucket_start() must reject an unrecognized granularity.' );
		} catch ( \UnhandledMatchError $exception ) {
			$this->assertStringContainsString( 'quarter', $exception->getMessage() );
		}

		try {
			$bucket_step->invoke( null, $midnight, 'quarter' );
			$this->fail( 'bucket_step() must reject an unrecognized granularity.' );
		} catch ( \UnhandledMatchError $exception ) {
			$this->assertStringContainsString( 'quarter', $exception->getMessage() );
		}
	}
}
