<?php
/**
 * Unit tests for the daily series.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RILM\Repository\Daily_Series;
use RILM\Repository\Period;

/**
 * Verifies grouping of UTC hours into local days, including daylight saving changes.
 */
class DailySeriesTest extends TestCase {

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
	 * Every local day of the period is listed, with zeros where nothing happened.
	 *
	 * @return void
	 */
	public function test_empty_days_are_filled_with_zeros(): void {
		$period = Period::custom( self::rome( '2026-10-01 08:00:00' ), self::rome( '2026-10-04 20:00:00' ) );

		$days = Daily_Series::from_hourly( array( self::hour( '2026-10-02 10', 4 ) ), $period );

		$this->assertSame( array( '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04' ), array_column( $days, 'date' ) );
		$this->assertSame( array( 0, 4, 0, 0 ), array_column( $days, 'turns' ) );
	}

	/**
	 * An empty result still gives the days of the period.
	 *
	 * @return void
	 */
	public function test_no_rows_gives_zero_days(): void {
		$period = Period::preset( Period::TODAY, self::rome( '2026-10-02 14:30:00' ) );

		$days = Daily_Series::from_hourly( array(), $period );

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

		$days = Daily_Series::from_hourly(
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
	 * A UTC hour belongs to the local day it falls in: late evening UTC is already tomorrow in Rome.
	 *
	 * @return void
	 */
	public function test_utc_hours_are_assigned_to_the_local_day(): void {
		$period = Period::custom( self::rome( '2026-10-01 00:00:00' ), self::rome( '2026-10-03 23:59:00' ) );

		$days = Daily_Series::from_hourly(
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

		$days = Daily_Series::from_hourly( $rows, $period );

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

		$days = Daily_Series::from_hourly( $rows, $period );

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

		$days = Daily_Series::from_hourly(
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

		$days = Daily_Series::from_hourly( array( self::hour( $hour, 99 ) ), $period );

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
	 * A very long period lists only the days that have data, instead of thousands of empty ones.
	 *
	 * @return void
	 */
	public function test_very_long_period_is_not_filled(): void {
		$period = Period::custom( self::rome( '2020-01-01 00:00:00' ), self::rome( '2026-10-02 00:00:00' ) );

		$this->assertSame( array(), Daily_Series::from_hourly( array(), $period ) );

		$days = Daily_Series::from_hourly( array( self::hour( '2024-05-05 10', 7 ) ), $period );

		$this->assertSame( array( '2024-05-05' ), array_column( $days, 'date' ) );
		$this->assertSame( array( 7 ), array_column( $days, 'turns' ) );
	}

	/**
	 * A period of exactly the limit is still filled.
	 *
	 * @return void
	 */
	public function test_period_at_the_limit_is_filled(): void {
		$start  = self::rome( '2026-01-01 00:00:00' );
		$period = Period::custom( $start, $start->modify( '+' . Daily_Series::MAX_FILLED_DAYS . ' days' ) );

		$this->assertCount( Daily_Series::MAX_FILLED_DAYS + 1, Daily_Series::from_hourly( array(), $period ) );
	}

	/**
	 * A data row outside the listed days (cannot happen for a correct query) still gets its own day.
	 *
	 * @return void
	 */
	public function test_unexpected_day_is_kept(): void {
		$period = Period::preset( Period::TODAY, self::rome( '2026-10-02 14:30:00' ) );

		$days = Daily_Series::from_hourly( array( self::hour( '2026-09-30 10', 2 ) ), $period );

		$this->assertSame( array( '2026-09-30', '2026-10-02' ), array_column( $days, 'date' ) );
	}
}
