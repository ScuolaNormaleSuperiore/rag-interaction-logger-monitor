<?php
/**
 * Time period of a query.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Repository;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A closed time interval chosen in the WordPress time zone and queried in UTC.
 *
 * Interactions are stored in UTC: the boundaries entered or computed in local
 * time are converted before they reach SQL.
 */
class Period {

	/**
	 * From local midnight to now.
	 */
	public const TODAY = 'today';

	/**
	 * Last 7 days up to now.
	 */
	public const WEEK = 'week';

	/**
	 * Last month up to now.
	 */
	public const MONTH = 'month';

	/**
	 * Last 3 months up to now.
	 */
	public const THREE_MONTHS = '3months';

	/**
	 * Last 6 months up to now.
	 */
	public const SIX_MONTHS = '6months';

	/**
	 * Last year up to now.
	 */
	public const YEAR = 'year';

	/**
	 * Start and end chosen by the user.
	 */
	public const CUSTOM = 'custom';

	/**
	 * Preset keys, in display order.
	 */
	public const PRESETS = array( self::TODAY, self::WEEK, self::MONTH, self::THREE_MONTHS, self::SIX_MONTHS, self::YEAR );

	/**
	 * Preset used when no period is chosen, or when the chosen one is not valid.
	 */
	public const DEFAULT_PRESET = self::WEEK;

	/**
	 * Length of each moving preset, in days or in months.
	 */
	private const START_OFFSETS = array(
		self::WEEK         => array( 'days', 7 ),
		self::MONTH        => array( 'months', 1 ),
		self::THREE_MONTHS => array( 'months', 3 ),
		self::SIX_MONTHS   => array( 'months', 6 ),
		self::YEAR         => array( 'months', 12 ),
	);

	/**
	 * Format of the UTC boundaries, matching the `DATETIME(3)` column.
	 */
	private const SQL_FORMAT = 'Y-m-d H:i:s.v';

	/**
	 * Preset key or CUSTOM.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Start of the interval, in the local time zone.
	 *
	 * @var DateTimeImmutable
	 */
	private $start;

	/**
	 * End of the interval, in the local time zone.
	 *
	 * @var DateTimeImmutable
	 */
	private $end;

	/**
	 * Constructor.
	 *
	 * @param string            $key   Preset key or CUSTOM.
	 * @param DateTimeImmutable $start Start of the interval.
	 * @param DateTimeImmutable $end   End of the interval.
	 */
	private function __construct( string $key, DateTimeImmutable $start, DateTimeImmutable $end ) {
		$this->key   = $key;
		$this->start = $start;
		$this->end   = $end;
	}

	/**
	 * Builds one of the presets.
	 *
	 * @param string            $key Preset key.
	 * @param DateTimeImmutable $now Current time in the local time zone.
	 * @return self|null Null when the key is not a preset.
	 */
	public static function preset( string $key, DateTimeImmutable $now ): ?self {
		if ( self::TODAY === $key ) {
			return new self( $key, $now->setTime( 0, 0, 0, 0 ), $now );
		}

		if ( ! isset( self::START_OFFSETS[ $key ] ) ) {
			return null;
		}

		list( $unit, $amount ) = self::START_OFFSETS[ $key ];

		$start = 'days' === $unit
			? $now->modify( '-' . $amount . ' days' )
			: self::minus_months( $now, $amount );

		return new self( $key, $start, $now );
	}

	/**
	 * Goes back a number of calendar months, keeping the time of day.
	 *
	 * When the day does not exist in the target month it becomes the last day of
	 * that month: 30 March minus one month is 28 February, not 2 March.
	 *
	 * @param DateTimeImmutable $date   Starting date.
	 * @param int               $months Months to go back.
	 * @return DateTimeImmutable
	 */
	private static function minus_months( DateTimeImmutable $date, int $months ): DateTimeImmutable {
		$day    = (int) $date->format( 'j' );
		$target = $date->modify( 'first day of -' . $months . ' months' );
		$last   = (int) $target->format( 't' );

		return $target->setDate( (int) $target->format( 'Y' ), (int) $target->format( 'n' ), min( $day, $last ) );
	}

	/**
	 * Builds a custom interval.
	 *
	 * @param DateTimeImmutable $start Start of the interval.
	 * @param DateTimeImmutable $end   End of the interval.
	 * @return self
	 * @throws InvalidArgumentException When the end is before the start.
	 */
	public static function custom( DateTimeImmutable $start, DateTimeImmutable $end ): self {
		if ( $end < $start ) {
			throw new InvalidArgumentException( 'The end of the period is before its start.' );
		}

		return new self( self::CUSTOM, $start, $end );
	}

	/**
	 * Parses a local date and time typed by the user.
	 *
	 * Accepts `YYYY-MM-DDTHH:MM`, `YYYY-MM-DD HH:MM` and the same with seconds.
	 *
	 * @param string       $value         Text to parse.
	 * @param DateTimeZone $zone          Local time zone.
	 * @param bool         $end_of_minute Whether a value without seconds stands for the end of that minute.
	 * @return DateTimeImmutable|null Null when the text is not a valid date and time.
	 */
	public static function parse_local( string $value, DateTimeZone $zone, bool $end_of_minute = false ): ?DateTimeImmutable {
		$value = trim( $value );

		foreach ( array( 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i' ) as $format ) {
			$date = DateTimeImmutable::createFromFormat( '!' . $format, $value, $zone );
			$errs = DateTimeImmutable::getLastErrors();

			if ( false === $date || ( is_array( $errs ) && ( $errs['warning_count'] > 0 || $errs['error_count'] > 0 ) ) ) {
				continue;
			}

			// Without seconds the whole minute belongs to the interval when it is the end.
			if ( $end_of_minute && false === strpos( $format, ':s' ) ) {
				$date = $date->setTime( (int) $date->format( 'H' ), (int) $date->format( 'i' ), 59, 999000 );
			}

			return $date;
		}

		return null;
	}

	/**
	 * Returns the preset key, or CUSTOM.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Returns the start of the interval in the local time zone.
	 *
	 * @return DateTimeImmutable
	 */
	public function start(): DateTimeImmutable {
		return $this->start;
	}

	/**
	 * Returns the end of the interval in the local time zone.
	 *
	 * @return DateTimeImmutable
	 */
	public function end(): DateTimeImmutable {
		return $this->end;
	}

	/**
	 * Returns the start of the interval in UTC, ready for SQL.
	 *
	 * @return string
	 */
	public function start_utc(): string {
		return $this->start->setTimezone( new DateTimeZone( 'UTC' ) )->format( self::SQL_FORMAT );
	}

	/**
	 * Returns the end of the interval in UTC, ready for SQL.
	 *
	 * @return string
	 */
	public function end_utc(): string {
		return $this->end->setTimezone( new DateTimeZone( 'UTC' ) )->format( self::SQL_FORMAT );
	}
}
