<?php
/**
 * Unit tests for the predefined anomalies.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RILM\Repository\Anomalies;
use RILM\Repository\Filters;

/**
 * Verifies the filters each anomaly stands for.
 */
class AnomaliesTest extends TestCase {

	/**
	 * Builds the filters of an anomaly.
	 *
	 * @param string $key Anomaly key.
	 * @return Filters
	 */
	private static function filters( string $key ): Filters {
		return Filters::from_array( Anomalies::definitions()[ $key ], new DateTimeImmutable( '2026-10-02 14:30:00', new DateTimeZone( 'Europe/Rome' ) ) );
	}

	/**
	 * The six anomalies of the specification exist, in display order.
	 *
	 * @return void
	 */
	public function test_the_six_anomalies_exist(): void {
		$this->assertSame(
			array( 'incomplete', 'no_guardrails', 'input_blocks', 'output_blocks', 'answers_differ', 'zero_recall' ),
			array_keys( Anomalies::definitions() )
		);
	}

	/**
	 * Every definition is valid: none of its values is dropped.
	 *
	 * @return void
	 */
	public function test_every_definition_is_valid(): void {
		foreach ( array_keys( Anomalies::definitions() ) as $key ) {
			$this->assertSame( array(), self::filters( $key )->errors(), $key );
		}
	}

	/**
	 * A definition never carries a period or a search: the page adds the period, and the search stays out of URLs.
	 *
	 * @return void
	 */
	public function test_definitions_have_no_period_or_search(): void {
		foreach ( Anomalies::definitions() as $key => $input ) {
			foreach ( array( 'period', 'from', 'to', 'search', 'paged', 'orderby' ) as $forbidden ) {
				$this->assertArrayNotHasKey( $forbidden, $input, $key );
			}
		}
	}

	/**
	 * Incomplete interactions.
	 *
	 * @return void
	 */
	public function test_incomplete(): void {
		$filters = self::filters( Anomalies::INCOMPLETE );

		$this->assertSame( 'incomplete', $filters->outcome() );
		$this->assertNull( $filters->guard() );
	}

	/**
	 * Guardrails absent.
	 *
	 * @return void
	 */
	public function test_no_guardrails(): void {
		$this->assertSame( Filters::GUARD_ABSENT, self::filters( Anomalies::NO_GUARDRAILS )->guard() );
	}

	/**
	 * Input and output blocks mean "any verdict recorded".
	 *
	 * @return void
	 */
	public function test_blocks(): void {
		$this->assertSame( Filters::VERDICT_ANY, self::filters( Anomalies::INPUT_BLOCKS )->input_verdict() );
		$this->assertNull( self::filters( Anomalies::INPUT_BLOCKS )->output_verdict() );
		$this->assertSame( Filters::VERDICT_ANY, self::filters( Anomalies::OUTPUT_BLOCKS )->output_verdict() );
		$this->assertNull( self::filters( Anomalies::OUTPUT_BLOCKS )->input_verdict() );
	}

	/**
	 * Generated answers that changed without an output verdict.
	 *
	 * @return void
	 */
	public function test_answers_differ(): void {
		$filters = self::filters( Anomalies::ANSWERS_DIFFER );

		$this->assertSame( 'generated', $filters->outcome() );
		$this->assertSame( Filters::VERDICT_NONE, $filters->output_verdict() );
		$this->assertTrue( $filters->answers_differ() );
	}

	/**
	 * Generated interactions with an empty recall.
	 *
	 * @return void
	 */
	public function test_zero_recall(): void {
		$filters = self::filters( Anomalies::ZERO_RECALL );

		$this->assertSame( 'generated', $filters->outcome() );
		$this->assertTrue( $filters->recall_empty() );
	}

	/**
	 * Every definition survives the round trip through URL arguments, so each link reopens the same view.
	 *
	 * @return void
	 */
	public function test_definitions_round_trip_through_url_arguments(): void {
		$now = new DateTimeImmutable( '2026-10-02 14:30:00', new DateTimeZone( 'Europe/Rome' ) );

		foreach ( Anomalies::definitions() as $key => $input ) {
			$args  = Filters::from_array( $input, $now )->to_query_args();
			$again = Filters::from_array( array_map( 'strval', $args ), $now )->to_query_args();

			$this->assertNotSame( array(), $args, $key );
			$this->assertSame( $args, $again, $key );
		}
	}
}
