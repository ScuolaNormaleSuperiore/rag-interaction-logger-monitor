<?php
/**
 * Unit tests for the dashboard queries and the report.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RILM\Config\Config;
use RILM\Repository\Dashboard_Report;
use RILM\Repository\Interaction_Repository;
use RILM\Repository\Period;
use RILM\Repository\Period_Series;
use RILM\Tests\Unit\Support\Fake_Reader;

/**
 * Verifies the SQL, the median, the shares and the report built from them.
 */
class DashboardQueriesTest extends TestCase {

	/**
	 * Quoted table of the default configuration.
	 */
	private const TABLE = '`rag-interaction-logger-db`.`ril_interactions`';

	/**
	 * Period clause of "today" in Europe/Rome at 14:30.
	 */
	private const PERIOD = "ts >= '2026-10-01 22:00:00.000' AND ts <= '2026-10-02 12:30:00.000'";

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
		$this->repository = $this->repository_on( $this->db );
	}

	/**
	 * Builds a repository on a reader.
	 *
	 * @param Fake_Reader $reader Reader.
	 * @return Interaction_Repository
	 */
	private function repository_on( Fake_Reader $reader ): Interaction_Repository {
		return new Interaction_Repository(
			$reader,
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
	 * A summary row as the database returns it.
	 *
	 * @param array $overrides Values replacing the defaults.
	 * @return array
	 */
	private static function summary_row( array $overrides = array() ): array {
		return $overrides + array(
			'total'         => '200',
			'generated'     => '150',
			'fast_reply'    => '30',
			'incomplete'    => '20',
			'no_guardrails' => '10',
			'input_blocks'  => '8',
			'output_blocks' => '4',
			'zero_recall'   => '15',
			'completed'     => '178',
			'average_ms'    => '1234.5000',
		);
	}

	/**
	 * The dashboard counts come from one query.
	 *
	 * @return void
	 */
	public function test_summary_is_one_query(): void {
		$this->db->rows = array( array( self::summary_row() ) );

		$this->repository->summary( self::period() );

		$this->assertCount( 1, $this->db->queries );
		$this->assertSame(
			'SELECT COUNT(*) AS total'
			. ", SUM( CASE WHEN outcome = 'generated' THEN 1 ELSE 0 END ) AS generated"
			. ", SUM( CASE WHEN outcome = 'fast_reply' THEN 1 ELSE 0 END ) AS fast_reply"
			. ", SUM( CASE WHEN outcome = 'incomplete' THEN 1 ELSE 0 END ) AS incomplete"
			. ', SUM( CASE WHEN guard_present = 0 THEN 1 ELSE 0 END ) AS no_guardrails'
			. ', SUM( CASE WHEN input_verdict IS NOT NULL THEN 1 ELSE 0 END ) AS input_blocks'
			. ', SUM( CASE WHEN output_verdict IS NOT NULL THEN 1 ELSE 0 END ) AS output_blocks'
			. ", SUM( CASE WHEN outcome = 'generated' AND recall_count = 0 THEN 1 ELSE 0 END ) AS zero_recall"
			. ", SUM( CASE WHEN outcome <> 'incomplete' AND duration_ms IS NOT NULL THEN 1 ELSE 0 END ) AS completed"
			. ", AVG( CASE WHEN outcome <> 'incomplete' AND duration_ms IS NOT NULL THEN duration_ms END ) AS average_ms"
			. ' FROM ' . self::TABLE . ' WHERE ' . self::PERIOD,
			$this->db->queries[0]
		);
	}

	/**
	 * The summary gives integers and a float average, and a missing average stays null.
	 *
	 * @return void
	 */
	public function test_summary_types(): void {
		$this->db->rows = array( array( self::summary_row() ) );

		$summary = $this->repository->summary( self::period() );

		$this->assertSame( 200, $summary['total'] );
		$this->assertSame( 150, $summary['generated'] );
		$this->assertSame( 178, $summary['completed'] );
		$this->assertSame( 1234.5, $summary['average_ms'] );

		$this->db->rows = array(
			array(
				self::summary_row(
					array(
						'completed'  => null,
						'average_ms' => null,
					)
				),
			),
		);

		$summary = $this->repository->summary( self::period() );

		$this->assertSame( 0, $summary['completed'] );
		$this->assertNull( $summary['average_ms'], 'No completed turn: the average is unknown, not zero.' );
	}

	/**
	 * A failed summary query gives null.
	 *
	 * @return void
	 */
	public function test_failed_summary_gives_null(): void {
		$this->db->rows = array( array() );

		$this->assertNull( $this->repository->summary( self::period() ) );
	}

	/**
	 * The median is read with ORDER BY and LIMIT/OFFSET on the completed turns of the period.
	 *
	 * @dataProvider provide_median_windows
	 *
	 * @param int $completed Number of completed turns.
	 * @param int $limit     Expected LIMIT.
	 * @param int $offset    Expected OFFSET.
	 * @return void
	 */
	public function test_median_query( int $completed, int $limit, int $offset ): void {
		$this->db->col = array( 10 );

		$this->repository->median_duration( self::period(), $completed );

		$this->assertSame(
			'SELECT duration_ms FROM ' . self::TABLE . ' WHERE ' . self::PERIOD . " AND outcome <> 'incomplete' AND duration_ms IS NOT NULL ORDER BY duration_ms ASC LIMIT " . $limit . ' OFFSET ' . $offset,
			$this->db->queries[0]
		);
	}

	/**
	 * Provides the number of completed turns and the window the median needs.
	 *
	 * @return array<string, array{int, int, int}>
	 */
	public static function provide_median_windows(): array {
		return array(
			'one turn'     => array( 1, 1, 0 ),
			'two turns'    => array( 2, 2, 0 ),
			'three turns'  => array( 3, 1, 1 ),
			'four turns'   => array( 4, 2, 1 ),
			'five turns'   => array( 5, 1, 2 ),
			'six turns'    => array( 6, 2, 2 ),
			'odd, large'   => array( 1001, 1, 500 ),
			'even, large'  => array( 1000, 2, 499 ),
		);
	}

	/**
	 * Without completed turns there is no median and nothing is queried.
	 *
	 * @return void
	 */
	public function test_median_without_completed_turns(): void {
		$this->assertNull( $this->repository->median_duration( self::period(), 0 ) );
		$this->assertSame( array(), $this->db->queries );
	}

	/**
	 * The median of one value is that value, and of two values their mean.
	 *
	 * @return void
	 */
	public function test_median_values(): void {
		$this->db->col = array( '300' );
		$this->assertSame( 300.0, $this->repository->median_duration( self::period(), 3 ) );

		$this->db->col = array( '20', '30' );
		$this->assertSame( 25.0, $this->repository->median_duration( self::period(), 4 ) );
	}

	/**
	 * A failed median query gives null.
	 *
	 * @return void
	 */
	public function test_failed_median_gives_null(): void {
		$this->db->col = array();

		$this->assertNull( $this->repository->median_duration( self::period(), 5 ) );
	}

	/**
	 * Across many sizes and values, the window the repository asks for yields the true median.
	 *
	 * The reader answers the LIMIT/OFFSET window from a sorted list, as a database would.
	 *
	 * @return void
	 */
	public function test_median_matches_the_true_median_for_many_sizes(): void {
		$samples = array(
			array( 5 ),
			array( 5, 9 ),
			array( 9, 1, 5 ),
			array( 4, 4, 4, 4 ),
			array( 100, 1, 50, 2 ),
			array( 7, 3, 9, 1, 5 ),
			array( 10, 20, 30, 40, 50, 60 ),
			array( 1, 1, 1, 100, 100, 100, 100 ),
			array( 0, 0, 5, 5 ),
			array( 2147483647, 1 ),
		);

		foreach ( $samples as $values ) {
			$sorted = $values;
			sort( $sorted );
			$count = count( $sorted );

			$expected = 1 === $count % 2
				? (float) $sorted[ intdiv( $count, 2 ) ]
				: (float) ( ( $sorted[ $count / 2 - 1 ] + $sorted[ $count / 2 ] ) / 2 );

			$reader = new class( $sorted ) extends Fake_Reader {
				/**
				 * Sorted durations the "table" holds.
				 *
				 * @var int[]
				 */
				private $durations;

				/**
				 * Constructor.
				 *
				 * @param int[] $durations Sorted durations.
				 */
				public function __construct( array $durations ) {
					$this->durations = $durations;
				}

				/**
				 * Answers the LIMIT/OFFSET window of the query from the sorted list.
				 *
				 * @param string|null $query Prepared query.
				 * @param int         $x     Column offset.
				 * @return array
				 */
				public function get_col( $query = null, $x = 0 ) {
					preg_match( '/LIMIT (\d+) OFFSET (\d+)$/', (string) $query, $window );

					return array_slice( $this->durations, (int) $window[2], (int) $window[1] );
				}
			};

			$this->assertSame(
				$expected,
				$this->repository_on( $reader )->median_duration( self::period(), $count ),
				'Median of ' . implode( ', ', $values )
			);
		}
	}

	/**
	 * Blocks are counted per verdict, most frequent first.
	 *
	 * @return void
	 */
	public function test_verdict_counts(): void {
		$this->db->rows = array(
			array(
				array(
					'verdict' => 'prompt_injection',
					'blocks'  => '12',
				),
				array(
					'verdict' => 'hate',
					'blocks'  => '3',
				),
			),
		);

		$counts = $this->repository->verdict_counts( 'input_verdict', self::period() );

		$this->assertSame(
			'SELECT input_verdict AS verdict, COUNT(*) AS blocks FROM ' . self::TABLE . ' WHERE ' . self::PERIOD . ' AND input_verdict IS NOT NULL GROUP BY input_verdict ORDER BY blocks DESC, verdict ASC LIMIT 50',
			$this->db->queries[0]
		);
		$this->assertSame(
			array(
				'prompt_injection' => 12,
				'hate'             => 3,
			),
			$counts
		);
	}

	/**
	 * Only the two verdict columns can be counted.
	 *
	 * @dataProvider provide_bad_columns
	 *
	 * @param string $column Column name.
	 * @return void
	 */
	public function test_verdict_counts_reject_other_columns( string $column ): void {
		$this->assertSame( array(), $this->repository->verdict_counts( $column, self::period() ) );
		$this->assertSame( array(), $this->db->queries );
	}

	/**
	 * Provides columns that are not verdicts.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_bad_columns(): array {
		return array(
			'question'  => array( 'question' ),
			'injection' => array( 'input_verdict; DROP TABLE x' ),
			'empty'     => array( '' ),
		);
	}

	/**
	 * The hourly query cuts the hour with LEFT() and has no percent sign that prepare() could misread.
	 *
	 * @return void
	 */
	public function test_hourly_query(): void {
		$this->db->rows = array( array() );

		$this->repository->hourly_series( self::period() );

		$query = $this->db->queries[0];

		$this->assertStringStartsWith( 'SELECT LEFT( ts, 13 ) AS hour, COUNT(*) AS turns', $query );
		$this->assertStringContainsString( 'GROUP BY LEFT( ts, 13 ) ORDER BY hour ASC', $query );
		$this->assertStringContainsString( ' WHERE ' . self::PERIOD, $query );
		$this->assertStringNotContainsString( 'DATE_FORMAT', $query );
		$this->assertStringNotContainsString( '%Y', $query );
		$this->assertStringNotContainsString( '%H', $query );
		$this->assertStringNotContainsString( ';', $query );
	}

	/**
	 * The hourly rows are returned as integers.
	 *
	 * @return void
	 */
	public function test_hourly_rows_are_integers(): void {
		$this->db->rows = array(
			array(
				array(
					'hour'          => '2026-10-02 10',
					'turns'         => '5',
					'incomplete'    => '1',
					'input_blocks'  => null,
					'output_blocks' => '2',
				),
				array( 'turns' => '9' ),
			),
		);

		$this->assertSame(
			array(
				array(
					'hour'          => '2026-10-02 10',
					'turns'         => 5,
					'incomplete'    => 1,
					'input_blocks'  => 0,
					'output_blocks' => 2,
				),
			),
			$this->repository->hourly_series( self::period() ),
			'A row without an hour is dropped.'
		);
	}

	/**
	 * Shares are null when their whole is zero, and a real number otherwise.
	 *
	 * @return void
	 */
	public function test_percent(): void {
		$this->assertNull( Dashboard_Report::percent( 0, 0 ) );
		$this->assertNull( Dashboard_Report::percent( 5, 0 ) );
		$this->assertSame( 0.0, Dashboard_Report::percent( 0, 10 ) );
		$this->assertSame( 50.0, Dashboard_Report::percent( 5, 10 ) );
		$this->assertSame( 100.0, Dashboard_Report::percent( 7, 7 ) );
		$this->assertEqualsWithDelta( 33.3333, Dashboard_Report::percent( 1, 3 ), 0.0001 );
	}

	/**
	 * The report holds the counts, the shares of the right whole, the durations and the daily series.
	 *
	 * @return void
	 */
	public function test_report(): void {
		$this->db->col  = array( '900', '1100' );
		$this->db->rows = array(
			array( self::summary_row( array( 'completed' => '4' ) ) ),
			array(
				array(
					'verdict' => 'injection',
					'blocks'  => '8',
				),
			),
			array(
				array(
					'verdict' => 'toxic',
					'blocks'  => '3',
				),
				array(
					'verdict' => 'pii',
					'blocks'  => '1',
				),
			),
			array(
				array(
					'hour'          => '2026-10-02 08',
					'turns'         => '120',
					'incomplete'    => '12',
					'input_blocks'  => '5',
					'output_blocks' => '2',
				),
				array(
					'hour'          => '2026-10-02 10',
					'turns'         => '80',
					'incomplete'    => '8',
					'input_blocks'  => '3',
					'output_blocks' => '2',
				),
			),
		);

		$report = ( new Dashboard_Report( $this->repository ) )->build( self::period() );

		$this->assertSame( 200, $report['total'] );
		$this->assertSame( 150, $report['outcomes']['generated']['count'] );
		$this->assertSame( 75.0, $report['outcomes']['generated']['percent'] );
		$this->assertSame( 15.0, $report['outcomes']['fast_reply']['percent'] );
		$this->assertSame( 10.0, $report['outcomes']['incomplete']['percent'] );
		$this->assertSame( 5.0, $report['no_guardrails']['percent'] );
		$this->assertSame( 4.0, $report['input_blocks']['percent'] );
		$this->assertSame( 2.0, $report['output_blocks']['percent'] );
		$this->assertSame( array( 'injection' => 8 ), $report['input_blocks']['by_verdict'] );
		$this->assertSame(
			array(
				'toxic' => 3,
				'pii'   => 1,
			),
			$report['output_blocks']['by_verdict']
		);

		$this->assertSame( 15, $report['zero_recall']['count'] );
		$this->assertSame( 150, $report['zero_recall']['generated'] );
		$this->assertSame( 10.0, $report['zero_recall']['percent'], 'Zero recall is a share of the generated answers.' );

		$this->assertSame( 4, $report['duration']['completed'] );
		$this->assertSame( 1234.5, $report['duration']['average_ms'] );
		$this->assertSame( 1000.0, $report['duration']['median_ms'] );

		$this->assertSame( Period_Series::GRANULARITY_DAY, $report['daily_granularity'] );
		$this->assertFalse( $report['daily_failed'] );
		$this->assertSame( array( '2026-10-02' ), array_column( $report['daily'], 'date' ) );
		$this->assertSame( array( 200 ), array_column( $report['daily'], 'turns' ) );
		$this->assertSame( array( 20 ), array_column( $report['daily'], 'incomplete' ) );
		$this->assertSame( array( 8 ), array_column( $report['daily'], 'input_blocks' ) );
		$this->assertSame( array( 4 ), array_column( $report['daily'], 'output_blocks' ) );
	}

	/**
	 * The report names the bucket size the Daily trend page should use, based on the period alone.
	 *
	 * @return void
	 */
	public function test_report_names_the_daily_granularity(): void {
		$this->db->col  = array( '900' );
		$this->db->rows = array(
			array( self::summary_row() ),
			array(),
		);

		$year   = Period::preset( Period::YEAR, new DateTimeImmutable( '2026-10-02 14:30:00', new DateTimeZone( 'Europe/Rome' ) ) );
		$report = ( new Dashboard_Report( $this->repository ) )->build( $year );

		$this->assertSame( Period_Series::GRANULARITY_MONTH, $report['daily_granularity'] );
	}

	/**
	 * The Dashboard page never reads the granularity or the daily series: `with_daily = false`
	 * must not compute either, not even to leave a key nobody on that page consumes.
	 *
	 * @return void
	 */
	public function test_daily_granularity_is_not_computed_when_daily_is_not_requested(): void {
		$this->db->rows = array( array( self::summary_row() ) );

		$report = ( new Dashboard_Report( $this->repository ) )->build( self::period(), false, false );

		$this->assertArrayNotHasKey( 'daily_granularity', $report );
		$this->assertSame( array(), $report['daily'] );
		$this->assertFalse( $report['daily_failed'] );
		$this->assertCount( 1, $this->db->queries, 'with_daily = false must not query the hourly series.' );
	}

	/**
	 * With no turns the shares are null, nothing more is queried and there is no series.
	 *
	 * @return void
	 */
	public function test_empty_period_report(): void {
		$this->db->rows = array(
			array(
				self::summary_row(
					array(
						'total'         => '0',
						'generated'     => null,
						'fast_reply'    => null,
						'incomplete'    => null,
						'no_guardrails' => null,
						'input_blocks'  => null,
						'output_blocks' => null,
						'zero_recall'   => null,
						'completed'     => null,
						'average_ms'    => null,
					)
				),
			),
		);

		$report = ( new Dashboard_Report( $this->repository ) )->build( self::period() );

		$this->assertSame( 0, $report['total'] );
		$this->assertNull( $report['outcomes']['generated']['percent'] );
		$this->assertNull( $report['no_guardrails']['percent'] );
		$this->assertNull( $report['zero_recall']['percent'] );
		$this->assertNull( $report['duration']['average_ms'] );
		$this->assertNull( $report['duration']['median_ms'] );
		$this->assertSame( array(), $report['input_blocks']['by_verdict'] );
		$this->assertSame( array(), $report['daily'] );
		$this->assertFalse( $report['daily_failed'] );
		$this->assertCount( 1, $this->db->queries, 'An empty period costs one query.' );
	}

	/**
	 * Verdicts are looked up only when there are blocks.
	 *
	 * @return void
	 */
	public function test_verdicts_are_not_queried_without_blocks(): void {
		$this->db->rows = array(
			array(
				self::summary_row(
					array(
						'input_blocks'  => '0',
						'output_blocks' => '0',
					)
				),
			),
			array(),
		);

		( new Dashboard_Report( $this->repository ) )->build( self::period() );

		foreach ( $this->db->queries as $query ) {
			$this->assertStringNotContainsString( 'GROUP BY input_verdict', $query );
			$this->assertStringNotContainsString( 'GROUP BY output_verdict', $query );
		}
	}

	/**
	 * Without the breakdowns, the median and the verdict counts are not queried, and the median is null.
	 *
	 * The Daily trend page shows none of them: asking for them there would be wasted queries.
	 *
	 * @return void
	 */
	public function test_breakdowns_are_not_queried_when_not_requested(): void {
		$this->db->rows = array(
			array( self::summary_row() ),
			array(
				array(
					'hour'  => '2026-10-02 10',
					'turns' => '5',
				),
			),
		);

		$report = ( new Dashboard_Report( $this->repository ) )->build( self::period(), false );

		$this->assertNull( $report['duration']['median_ms'] );
		$this->assertSame( array(), $report['input_blocks']['by_verdict'] );
		$this->assertSame( array(), $report['output_blocks']['by_verdict'] );
		$this->assertCount( 2, $this->db->queries, 'Only the summary and the daily series are queried.' );
		$this->assertStringContainsString( 'LEFT( ts, 13 )', $this->db->queries[1] );
	}

	/**
	 * A failed summary makes the whole report null.
	 *
	 * @return void
	 */
	public function test_failed_summary_makes_the_report_null(): void {
		$this->db->rows = array( array() );

		$this->assertNull( ( new Dashboard_Report( $this->repository ) )->build( self::period() ) );
	}

	/**
	 * A failed series query is reported, so the page does not show an empty chart as if it were real.
	 *
	 * @return void
	 */
	public function test_failed_series_is_flagged(): void {
		$this->db->rows = array(
			array( self::summary_row( array( 'input_blocks' => '0', 'output_blocks' => '0' ) ) ),
		);

		$reader = new class() extends Fake_Reader {
			/**
			 * Makes the series query fail.
			 *
			 * @param string|null $query  Prepared query.
			 * @param string      $output Output type.
			 * @return array|null
			 */
			public function get_results( $query = null, $output = 'OBJECT' ) {
				$this->queries[] = $query;

				if ( false !== strpos( (string) $query, 'LEFT( ts, 13 )' ) ) {
					return null;
				}

				return array_shift( $this->rows ) ?? array();
			}
		};

		$reader->rows = $this->db->rows;

		$report = ( new Dashboard_Report( $this->repository_on( $reader ) ) )->build( self::period() );

		$this->assertTrue( $report['daily_failed'] );
		$this->assertSame( array( '2026-10-02' ), array_column( $report['daily'], 'date' ) );
	}

	/**
	 * Every query of the dashboard is a single SELECT bounded by the period.
	 *
	 * @return void
	 */
	public function test_every_query_is_a_bounded_select(): void {
		$this->db->col  = array( '100' );
		$this->db->rows = array(
			array( self::summary_row() ),
			array(),
			array(),
			array(),
		);

		( new Dashboard_Report( $this->repository ) )->build( self::period() );

		$this->assertGreaterThanOrEqual( 4, count( $this->db->queries ) );

		foreach ( $this->db->queries as $query ) {
			$this->assertMatchesRegularExpression( '/^SELECT\b/', $query );
			$this->assertStringContainsString( self::PERIOD, $query );
			$this->assertStringNotContainsString( ';', $query );
			$this->assertDoesNotMatchRegularExpression( '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|GRANT|TRUNCATE|REPLACE|LOCK)\b/i', $query );
		}
	}
}
