<?php
/**
 * Unit tests for the anomaly counts and the "answers differ" condition.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RILM\Config\Config;
use RILM\Repository\Anomalies;
use RILM\Repository\Filters;
use RILM\Repository\Interaction_Repository;
use RILM\Repository\Period;
use RILM\Tests\Unit\Support\Fake_Reader;

/**
 * Verifies the single counting query and that it agrees with the list.
 */
class AnomalyCountsTest extends TestCase {

	/**
	 * Quoted table of the default configuration.
	 */
	private const TABLE = '`rag-interaction-logger-db`.`ril_interactions`';

	/**
	 * Double that records the queries.
	 *
	 * @var Fake_Reader
	 */
	private $db;

	/**
	 * Repository under test.
	 *
	 * @var Interaction_Repository
	 */
	private $repository;

	/**
	 * Creates the repository on a fake reader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db         = new Fake_Reader();
		$this->repository = new Interaction_Repository(
			$this->db,
			new Config(
				array(
					'host' => 'db.example.test',
					'user' => 'reader',
				),
				array( 'password' => 'secret' )
			)
		);
	}

	/**
	 * Today in Europe/Rome, from local midnight to 14:30.
	 *
	 * @return Period
	 */
	private static function period(): Period {
		return Period::preset( Period::TODAY, new DateTimeImmutable( '2026-10-02 14:30:00', new DateTimeZone( 'Europe/Rome' ) ) );
	}

	/**
	 * Builds filters at a fixed local time.
	 *
	 * @param array $input Raw values.
	 * @return Filters
	 */
	private static function filters( array $input ): Filters {
		return Filters::from_array( $input, new DateTimeImmutable( '2026-10-02 14:30:00', new DateTimeZone( 'Europe/Rome' ) ) );
	}

	/**
	 * The counts come from one query with one conditional sum per anomaly.
	 *
	 * @return void
	 */
	public function test_one_query_counts_every_anomaly(): void {
		$this->db->rows = array( array( array( 'total' => '10' ) ) );

		$this->repository->anomaly_counts( self::period() );

		$this->assertCount( 1, $this->db->queries );
		$this->assertSame(
			'SELECT COUNT(*) AS total'
			. ", SUM( CASE WHEN outcome = 'incomplete' THEN 1 ELSE 0 END ) AS incomplete"
			. ', SUM( CASE WHEN guard_present = 0 THEN 1 ELSE 0 END ) AS no_guardrails'
			. ', SUM( CASE WHEN input_verdict IS NOT NULL THEN 1 ELSE 0 END ) AS input_blocks'
			. ', SUM( CASE WHEN output_verdict IS NOT NULL THEN 1 ELSE 0 END ) AS output_blocks'
			. ", SUM( CASE WHEN outcome = 'generated' AND output_verdict IS NULL AND NOT ( llm_answer <=> delivered ) THEN 1 ELSE 0 END ) AS answers_differ"
			. ", SUM( CASE WHEN outcome = 'generated' AND recall_count = 0 THEN 1 ELSE 0 END ) AS zero_recall"
			. ' FROM ' . self::TABLE
			. " WHERE ts >= '2026-10-01 22:00:00.000' AND ts <= '2026-10-02 12:30:00.000'",
			$this->db->queries[0]
		);
	}

	/**
	 * The query is bounded by the period and is a single SELECT.
	 *
	 * @return void
	 */
	public function test_counting_is_bounded_and_read_only(): void {
		$this->repository->anomaly_counts( self::period() );

		$query = $this->db->queries[0];

		$this->assertMatchesRegularExpression( '/^SELECT\b/', $query );
		$this->assertStringContainsString( "ts >= '2026-10-01 22:00:00.000' AND ts <= '2026-10-02 12:30:00.000'", $query );
		$this->assertStringNotContainsString( ';', $query );
	}

	/**
	 * The counts are returned as integers, and a NULL sum (no row in the period) is zero.
	 *
	 * @return void
	 */
	public function test_counts_are_integers(): void {
		$this->db->rows = array(
			array(
				array(
					'total'          => '120',
					'incomplete'     => '5',
					'no_guardrails'  => null,
					'input_blocks'   => '0',
					'output_blocks'  => '7',
					'answers_differ' => '3',
					'zero_recall'    => null,
				),
			),
		);

		$this->assertSame(
			array(
				'total'          => 120,
				'incomplete'     => 5,
				'no_guardrails'  => 0,
				'input_blocks'   => 0,
				'output_blocks'  => 7,
				'answers_differ' => 3,
				'zero_recall'    => 0,
			),
			$this->repository->anomaly_counts( self::period() )
		);
	}

	/**
	 * An empty period (the sums are NULL) gives zeros, not a failure.
	 *
	 * @return void
	 */
	public function test_empty_period_gives_zeros(): void {
		$this->db->rows = array( array( array( 'total' => '0' ) ) );

		$counts = $this->repository->anomaly_counts( self::period() );

		$this->assertNotNull( $counts );
		$this->assertSame( 0, $counts['total'] );
		$this->assertSame( 0, $counts['incomplete'] );
		$this->assertSame( 0, $counts['zero_recall'] );
	}

	/**
	 * A failed query gives null, so the page can say the counts are unavailable.
	 *
	 * @return void
	 */
	public function test_failed_query_gives_null(): void {
		$this->db->rows = array( array() );

		$this->assertNull( $this->repository->anomaly_counts( self::period() ) );
	}

	/**
	 * Each count uses the same condition as the list for the same filters.
	 *
	 * @return void
	 */
	public function test_counts_use_the_conditions_of_the_list(): void {
		$this->repository->anomaly_counts( self::period() );
		$counting = $this->db->queries[0];

		foreach ( Anomalies::definitions() as $key => $input ) {
			$this->repository->count( self::filters( $input ) );
			$listing = (string) end( $this->db->queries );

			// What the list adds after the period is the condition of the anomaly.
			$this->assertSame( 1, preg_match( "/ts <= '[^']*' AND (.+)\$/", $listing, $match ), $key );
			$this->assertStringContainsString( 'SUM( CASE WHEN ' . $match[1] . ' THEN 1 ELSE 0 END ) AS ' . $key, $counting, $key );
		}
	}

	/**
	 * The answers condition treats NULL on both sides as equal and NULL against text as different.
	 *
	 * @return void
	 */
	public function test_answers_condition_is_null_safe(): void {
		$this->repository->count( self::filters( array( 'answers' => 'differ' ) ) );

		$query = $this->db->queries[0];

		$this->assertStringContainsString( 'NOT ( llm_answer <=> delivered )', $query );
		$this->assertStringNotContainsString( 'llm_answer <> delivered', $query );
		$this->assertStringNotContainsString( 'llm_answer != delivered', $query );
	}

	/**
	 * Without the filter the condition is absent.
	 *
	 * @return void
	 */
	public function test_answers_condition_is_absent_by_default(): void {
		$this->repository->count( self::filters( array() ) );

		$this->assertStringNotContainsString( '<=>', $this->db->queries[0] );
	}
}
