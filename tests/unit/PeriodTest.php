<?php
/**
 * Unit tests for the time period.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RILM\Repository\Period;

/**
 * Verifies presets, local to UTC conversion (including daylight saving time) and parsing.
 */
class PeriodTest extends TestCase {

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
	 * Presets give the expected UTC boundaries.
	 *
	 * @dataProvider provide_presets
	 *
	 * @param string $now   Local current time.
	 * @param string $key   Preset key.
	 * @param string $start Expected start in UTC.
	 * @param string $end   Expected end in UTC.
	 * @return void
	 */
	public function test_presets_are_converted_to_utc( string $now, string $key, string $start, string $end ): void {
		$period = Period::preset( $key, self::rome( $now ) );

		$this->assertNotNull( $period );
		$this->assertSame( $key, $period->key() );
		$this->assertSame( $start, $period->start_utc() );
		$this->assertSame( $end, $period->end_utc() );
	}

	/**
	 * Provides presets with the expected boundaries.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function provide_presets(): array {
		return array(
			'today in summer time'          => array( '2026-10-02 14:30:00', Period::TODAY, '2026-10-01 22:00:00.000', '2026-10-02 12:30:00.000' ),
			'today in winter time'          => array( '2026-12-15 10:00:00', Period::TODAY, '2026-12-14 23:00:00.000', '2026-12-15 09:00:00.000' ),
			'today when summer time ends'   => array( '2026-10-25 14:00:00', Period::TODAY, '2026-10-24 22:00:00.000', '2026-10-25 13:00:00.000' ),
			'today when summer time starts' => array( '2026-03-29 14:00:00', Period::TODAY, '2026-03-28 23:00:00.000', '2026-03-29 12:00:00.000' ),
			'last week'                     => array( '2026-10-02 14:30:00', Period::WEEK, '2026-09-25 12:30:00.000', '2026-10-02 12:30:00.000' ),
			'last month'                    => array( '2026-10-02 14:30:00', Period::MONTH, '2026-09-02 12:30:00.000', '2026-10-02 12:30:00.000' ),
			'last month across clock change' => array( '2026-11-02 12:00:00', Period::MONTH, '2026-10-02 10:00:00.000', '2026-11-02 11:00:00.000' ),
			'last 3 months'                 => array( '2026-10-02 14:30:00', Period::THREE_MONTHS, '2026-07-02 12:30:00.000', '2026-10-02 12:30:00.000' ),
			'last 6 months'                 => array( '2026-10-02 14:30:00', Period::SIX_MONTHS, '2026-04-02 12:30:00.000', '2026-10-02 12:30:00.000' ),
			'last year'                     => array( '2026-10-02 14:30:00', Period::YEAR, '2025-10-02 12:30:00.000', '2026-10-02 12:30:00.000' ),
			'month end does not overflow'   => array( '2026-03-30 10:00:00', Period::MONTH, '2026-02-28 09:00:00.000', '2026-03-30 08:00:00.000' ),
			'leap day a year later'         => array( '2028-02-29 10:00:00', Period::YEAR, '2027-02-28 09:00:00.000', '2028-02-29 09:00:00.000' ),
		);
	}

	/**
	 * Unknown preset keys are rejected.
	 *
	 * @return void
	 */
	public function test_unknown_preset_returns_null(): void {
		$this->assertNull( Period::preset( 'fortnight', self::rome( '2026-10-02 14:30:00' ) ) );
		$this->assertNull( Period::preset( Period::CUSTOM, self::rome( '2026-10-02 14:30:00' ) ) );
	}

	/**
	 * Every preset key listed in PRESETS builds a period.
	 *
	 * @return void
	 */
	public function test_every_listed_preset_exists(): void {
		foreach ( Period::PRESETS as $key ) {
			$this->assertNotNull( Period::preset( $key, self::rome( '2026-10-02 14:30:00' ) ), $key );
		}
	}

	/**
	 * A custom interval is accepted when the end is not before the start.
	 *
	 * @return void
	 */
	public function test_custom_period(): void {
		$period = Period::custom( self::rome( '2026-10-01 08:00:00' ), self::rome( '2026-10-01 08:00:00' ) );

		$this->assertSame( Period::CUSTOM, $period->key() );
		$this->assertSame( '2026-10-01 06:00:00.000', $period->start_utc() );
		$this->assertSame( '2026-10-01 06:00:00.000', $period->end_utc() );
	}

	/**
	 * A custom interval that ends before it starts is refused.
	 *
	 * @return void
	 */
	public function test_custom_period_with_end_before_start_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Period::custom( self::rome( '2026-10-02 08:00:00' ), self::rome( '2026-10-01 08:00:00' ) );
	}

	/**
	 * Local date and time text is parsed in the given zone.
	 *
	 * @dataProvider provide_valid_dates
	 *
	 * @param string $text     Text typed by the user.
	 * @param bool   $end      Whether it is the end of the interval.
	 * @param string $expected Expected UTC value.
	 * @return void
	 */
	public function test_parse_local_accepts_valid_dates( string $text, bool $end, string $expected ): void {
		$date = Period::parse_local( $text, new DateTimeZone( 'Europe/Rome' ), $end );

		$this->assertNotNull( $date );
		$this->assertSame( $expected, $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s.v' ) );
	}

	/**
	 * Provides valid texts with the expected UTC value.
	 *
	 * @return array<string, array{string, bool, string}>
	 */
	public static function provide_valid_dates(): array {
		return array(
			'datetime-local start'      => array( '2026-10-01T08:30', false, '2026-10-01 06:30:00.000' ),
			'datetime-local end'        => array( '2026-10-01T08:30', true, '2026-10-01 06:30:59.999' ),
			'with seconds is not moved' => array( '2026-10-01T08:30:15', true, '2026-10-01 06:30:15.000' ),
			'space separator'           => array( '2026-10-01 08:30', false, '2026-10-01 06:30:00.000' ),
			'winter time'               => array( '2026-12-01T08:30', false, '2026-12-01 07:30:00.000' ),
			'surrounding spaces'        => array( '  2026-10-01T08:30  ', false, '2026-10-01 06:30:00.000' ),
		);
	}

	/**
	 * Impossible or malformed dates are refused.
	 *
	 * @dataProvider provide_invalid_dates
	 *
	 * @param string $text Text typed by the user.
	 * @return void
	 */
	public function test_parse_local_rejects_invalid_dates( string $text ): void {
		$this->assertNull( Period::parse_local( $text, new DateTimeZone( 'Europe/Rome' ) ) );
	}

	/**
	 * Provides invalid texts.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_invalid_dates(): array {
		return array(
			'empty'            => array( '' ),
			'words'            => array( 'yesterday' ),
			'impossible day'   => array( '2026-02-30T10:00' ),
			'impossible month' => array( '2026-13-01T10:00' ),
			'impossible hour'  => array( '2026-10-01T25:00' ),
			'date only'        => array( '2026-10-01' ),
			'sql injection'    => array( "2026-10-01T10:00' OR '1'='1" ),
		);
	}
}
