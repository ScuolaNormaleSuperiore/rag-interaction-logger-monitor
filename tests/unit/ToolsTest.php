<?php
/**
 * Unit tests for the tools filters, the per-tool counts and the tools anomalies.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RILM\Config\Config;
use RILM\Repository\Anomalies;
use RILM\Repository\Dashboard_Report;
use RILM\Repository\Filters;
use RILM\Repository\Interaction_Repository;
use RILM\Repository\Period;
use RILM\Tests\Unit\Support\Fake_Reader;

/**
 * Verifies the SQL and the figures of the `tools_used` features, with and without the column.
 */
class ToolsTest extends TestCase {

	/**
	 * Quoted table of the default configuration.
	 */
	private const TABLE = '`rag-interaction-logger-db`.`ril_interactions`';

	/**
	 * Period clause of the queries (today in Europe/Rome at 14:30).
	 */
	private const PERIOD = "ts >= '2026-10-01 22:00:00.000' AND ts <= '2026-10-02 12:30:00.000'";

	/**
	 * Condition of "tools used".
	 */
	private const USED = "( tools_used IS NOT NULL AND TRIM( tools_used ) <> '' )";

	/**
	 * Double that records the queries.
	 *
	 * @var Fake_Reader
	 */
	private $db;

	/**
	 * Creates the fake reader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = new Fake_Reader();
	}

	/**
	 * Builds a repository on the fake reader.
	 *
	 * @param string[] $columns Optional columns present.
	 * @return Interaction_Repository
	 */
	private function repository( array $columns = array( 'tools_used' ) ): Interaction_Repository {
		return new Interaction_Repository(
			$this->db,
			new Config(
				array(
					'host' => 'db.example.test',
					'user' => 'reader',
				),
				array( 'password' => 'secret' )
			),
			$columns
		);
	}

	/**
	 * Builds filters at a fixed local time.
	 *
	 * @param array $input Raw values.
	 * @return Filters
	 */
	private static function filters( array $input ): Filters {
		return Filters::from_array( $input + array( 'period' => 'today' ), new DateTimeImmutable( '2026-10-02 14:30:00', new DateTimeZone( 'Europe/Rome' ) ) );
	}

	/**
	 * Today in Europe/Rome.
	 *
	 * @return Period
	 */
	private static function period(): Period {
		return self::filters( array() )->period();
	}

	/**
	 * The WHERE condition added after the period by a count with these filters.
	 *
	 * @param Interaction_Repository $repository Repository.
	 * @param array                  $input      Filter input.
	 * @return string
	 */
	private function condition_of( Interaction_Repository $repository, array $input ): string {
		$repository->count( self::filters( $input ) );
		$query = (string) end( $this->db->queries );

		$this->assertSame( 1, preg_match( "/ts <= '[^']*'( AND (.+))?\$/", $query, $match ), $query );

		return $match[2] ?? '';
	}

	/**
	 * The tools filter accepts yes and no, and reports anything else.
	 *
	 * @return void
	 */
	public function test_tools_filter_values(): void {
		$this->assertSame( 'yes', self::filters( array( 'tools' => 'yes' ) )->tools() );
		$this->assertSame( 'no', self::filters( array( 'tools' => 'no' ) )->tools() );
		$this->assertNull( self::filters( array() )->tools() );

		$bad = self::filters( array( 'tools' => 'maybe' ) );

		$this->assertNull( $bad->tools() );
		$this->assertSame( array( 'tools' ), $bad->errors() );
	}

	/**
	 * The tool name is trimmed, cleaned of control characters and limited in length.
	 *
	 * @return void
	 */
	public function test_tool_name_is_validated(): void {
		$this->assertSame( 'search_web', self::filters( array( 'tool' => "  search_web\n" ) )->tool() );
		$this->assertNull( self::filters( array( 'tool' => '   ' ) )->tool() );
		$this->assertSame( 255, mb_strlen( (string) self::filters( array( 'tool' => str_repeat( 'a', 400 ) ) )->tool() ) );
	}

	/**
	 * Both filters are written to URLs; the name counts as an advanced filter and the yes/no choice does not.
	 *
	 * @return void
	 */
	public function test_query_args_and_advanced_count(): void {
		$filters = self::filters(
			array(
				'tools' => 'yes',
				'tool'  => 'search_web',
			)
		);

		$this->assertSame(
			array(
				'period' => 'today',
				'tools'  => 'yes',
				'tool'   => 'search_web',
			),
			$filters->to_query_args()
		);
		$this->assertSame( 1, $filters->advanced_count() );
		$this->assertSame( 0, self::filters( array( 'tools' => 'no' ) )->advanced_count() );
	}

	/**
	 * Without the column, the repository says so and the tools filters change nothing in the query.
	 *
	 * @return void
	 */
	public function test_without_the_column_the_filters_are_ignored(): void {
		$repository = $this->repository( array() );

		$this->assertFalse( $repository->supports_tools() );
		$this->assertSame( '', $this->condition_of( $repository, array( 'tools' => 'yes' ) ) );
		$this->assertSame( '', $this->condition_of( $repository, array( 'tool' => 'search_web' ) ) );
		$this->assertStringNotContainsString( 'tools_used', (string) end( $this->db->queries ) );
	}

	/**
	 * With the column, "yes" and "no" cover NULL, the empty string and a value of spaces only.
	 *
	 * @return void
	 */
	public function test_yes_and_no_conditions(): void {
		$repository = $this->repository();

		$this->assertTrue( $repository->supports_tools() );
		$this->assertSame( self::USED, $this->condition_of( $repository, array( 'tools' => 'yes' ) ) );
		$this->assertSame( "( tools_used IS NULL OR TRIM( tools_used ) = '' )", $this->condition_of( $repository, array( 'tools' => 'no' ) ) );
	}

	/**
	 * A name matches only as a whole item of the list, with or without a space after the comma.
	 *
	 * @return void
	 */
	public function test_name_condition_matches_whole_items(): void {
		$condition = $this->condition_of( $this->repository(), array( 'tool' => 'search' ) );

		$this->assertSame( "CONCAT( ',', REPLACE( tools_used, ', ', ',' ), ',' ) LIKE '%,search,%'", $condition );
	}

	/**
	 * The wildcards of the name are literal, and quotes cannot end the string.
	 *
	 * @return void
	 */
	public function test_name_condition_escapes_wildcards_and_quotes(): void {
		$condition = $this->condition_of( $this->repository(), array( 'tool' => "100%_o'k" ) );

		$this->assertStringContainsString( "LIKE '%,100\\\\%\\\\_o\\'k,%'", $condition );
	}

	/**
	 * "No" together with a name is contradictory and is not simplified: both conditions are applied.
	 *
	 * @return void
	 */
	public function test_contradictory_filters_are_both_applied(): void {
		$condition = $this->condition_of(
			$this->repository(),
			array(
				'tools' => 'no',
				'tool'  => 'search',
			)
		);

		$this->assertStringContainsString( "( tools_used IS NULL OR TRIM( tools_used ) = '' ) AND CONCAT(", $condition );
	}

	/**
	 * The per-tool query groups the combinations inside the period and is bounded.
	 *
	 * @return void
	 */
	public function test_tool_counts_query(): void {
		$this->db->rows = array( array() );

		$this->repository()->tool_counts( self::period() );

		$this->assertSame(
			'SELECT tools_used AS names, COUNT(*) AS turns FROM ' . self::TABLE . ' WHERE ' . self::PERIOD
			. " AND tools_used IS NOT NULL AND TRIM( tools_used ) <> '' GROUP BY tools_used ORDER BY turns DESC LIMIT 501",
			$this->db->queries[0]
		);
	}

	/**
	 * Without the column the repository returns nothing and runs no query.
	 *
	 * @return void
	 */
	public function test_tool_counts_without_the_column(): void {
		$this->assertNull( $this->repository( array() )->tool_counts( self::period() ) );
		$this->assertSame( array(), $this->db->queries );
	}

	/**
	 * Combinations are split and summed per name: spaces trimmed, empties dropped, a repeated name counted once per turn.
	 *
	 * @return void
	 */
	public function test_tool_counts_are_split_and_summed(): void {
		$this->db->rows = array(
			array(
				array(
					'names' => 'search, calendar',
					'turns' => '5',
				),
				array(
					'names' => 'search',
					'turns' => '3',
				),
				array(
					'names' => 'calendar,,  ,search,search',
					'turns' => '2',
				),
				array(
					'names' => 'form_contact',
					'turns' => '10',
				),
			),
		);

		$result = $this->repository()->tool_counts( self::period() );

		$this->assertSame(
			array(
				'form_contact' => 10,
				'search'       => 10,
				'calendar'     => 7,
			),
			$result['counts']
		);
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * Equal counts are ordered by name, so the table does not change between loads.
	 *
	 * @return void
	 */
	public function test_ties_are_ordered_by_name(): void {
		$this->db->rows = array(
			array(
				array(
					'names' => 'b, a, c',
					'turns' => '4',
				),
			),
		);

		$this->assertSame( array( 'a', 'b', 'c' ), array_keys( $this->repository()->tool_counts( self::period() )['counts'] ) );
	}

	/**
	 * More combinations than the cap are cut and reported, and the extra one is not counted.
	 *
	 * @return void
	 */
	public function test_tool_counts_are_capped(): void {
		$rows = array();

		for ( $i = 0; $i <= 500; $i++ ) {
			$rows[] = array(
				'names' => 'tool_' . $i,
				'turns' => '1',
			);
		}

		$this->db->rows = array( $rows );

		$result = $this->repository()->tool_counts( self::period() );

		$this->assertTrue( $result['truncated'] );
		$this->assertCount( 500, $result['counts'] );
		$this->assertArrayNotHasKey( 'tool_500', $result['counts'] );
	}

	/**
	 * A failed query gives null.
	 *
	 * @return void
	 */
	public function test_failed_tool_counts_give_null(): void {
		$this->db->rows = array( false );

		$this->assertNull( $this->repository()->tool_counts( self::period() ) );
	}

	/**
	 * The three tools anomalies exist only with the column, after the six others.
	 *
	 * @return void
	 */
	public function test_tools_anomalies_need_the_column(): void {
		$this->assertCount( 6, Anomalies::definitions() );
		$this->assertCount( 6, Anomalies::definitions( array( 'tool_input', 'recall_sources' ) ) );

		$with = Anomalies::definitions( array( 'tools_used' ) );

		$this->assertSame(
			array( 'tools_incomplete', 'tools_no_guardrails', 'tools_output_blocked' ),
			array_slice( array_keys( $with ), 6 )
		);
		$this->assertSame( array_slice( Anomalies::definitions(), 0, 6, true ), array_slice( $with, 0, 6, true ) );
	}

	/**
	 * Each tools anomaly is the tools filter plus the filter of its meaning, and all are valid.
	 *
	 * @return void
	 */
	public function test_tools_anomaly_definitions(): void {
		$definitions = Anomalies::definitions( array( 'tools_used' ) );

		$this->assertSame(
			array(
				'tools'   => 'yes',
				'outcome' => 'incomplete',
			),
			$definitions['tools_incomplete']
		);
		$this->assertSame(
			array(
				'tools' => 'yes',
				'guard' => 'absent',
			),
			$definitions['tools_no_guardrails']
		);
		$this->assertSame(
			array(
				'tools'          => 'yes',
				'output_verdict' => Filters::VERDICT_ANY,
			),
			$definitions['tools_output_blocked']
		);

		foreach ( $definitions as $key => $input ) {
			$this->assertSame( array(), self::filters( $input )->errors(), $key );
		}
	}

	/**
	 * Without the column the counting query is the one of the six anomalies and never mentions the column.
	 *
	 * @return void
	 */
	public function test_anomaly_counts_without_the_column(): void {
		$this->repository( array() )->anomaly_counts( self::period() );

		$this->assertStringNotContainsString( 'tools_used', $this->db->queries[0] );
		$this->assertStringNotContainsString( 'tools_', $this->db->queries[0] );
	}

	/**
	 * With the column there is still one query, and every one of the nine counts uses the condition of its list.
	 *
	 * @return void
	 */
	public function test_nine_counts_match_their_lists(): void {
		$repository = $this->repository();
		$repository->anomaly_counts( self::period() );

		$this->assertCount( 1, $this->db->queries );

		$counting = $this->db->queries[0];

		foreach ( Anomalies::definitions( array( 'tools_used' ) ) as $key => $input ) {
			$this->assertStringContainsString( 'SUM( CASE WHEN ' . $this->condition_of( $repository, $input ) . ' THEN 1 ELSE 0 END ) AS ' . $key, $counting, $key );
		}

		$this->assertCount( 9, Anomalies::definitions( array( 'tools_used' ) ) );
	}

	/**
	 * The counts of the tools anomalies are returned as integers.
	 *
	 * @return void
	 */
	public function test_tools_anomaly_counts_are_integers(): void {
		$this->db->rows = array(
			array(
				array(
					'total'            => '50',
					'tools_incomplete' => '2',
					'tools_no_guardrails' => null,
				),
			),
		);

		$counts = $this->repository()->anomaly_counts( self::period() );

		$this->assertSame( 2, $counts['tools_incomplete'] );
		$this->assertSame( 0, $counts['tools_no_guardrails'] );
		$this->assertSame( 0, $counts['tools_output_blocked'] );
	}

	/**
	 * The summary adds the tools count in the same query, only with the column.
	 *
	 * @return void
	 */
	public function test_summary_counts_turns_with_tools(): void {
		$this->db->rows = array( array( array( 'total' => '10', 'tools' => '4' ) ) );

		$summary = $this->repository()->summary( self::period() );

		$this->assertCount( 1, $this->db->queries );
		$this->assertStringContainsString( 'SUM( CASE WHEN ' . self::USED . ' THEN 1 ELSE 0 END ) AS tools', $this->db->queries[0] );
		$this->assertSame( 4, $summary['tools'] );

		$this->db->queries = array();
		$this->db->rows    = array( array( array( 'total' => '10' ) ) );

		$summary = $this->repository( array() )->summary( self::period() );

		$this->assertArrayNotHasKey( 'tools', $summary );
		$this->assertStringNotContainsString( 'tools_used', $this->db->queries[0] );
	}

	/**
	 * The report holds the share of turns with tools and the counts per tool.
	 *
	 * @return void
	 */
	public function test_report_with_tools(): void {
		$this->db->rows = array(
			array( array( 'total' => '40', 'generated' => '30', 'tools' => '10' ) ),
			array(
				array(
					'names' => 'search, calendar',
					'turns' => '6',
				),
				array(
					'names' => 'search',
					'turns' => '4',
				),
			),
		);

		$report = ( new Dashboard_Report( $this->repository() ) )->build( self::period() );

		$this->assertSame( 10, $report['tools']['count'] );
		$this->assertSame( 25.0, $report['tools']['percent'] );
		$this->assertSame(
			array(
				'search'   => 10,
				'calendar' => 6,
			),
			$report['tools']['by_name']
		);
		$this->assertFalse( $report['tools']['by_name_cut'] );
		$this->assertFalse( $report['tools']['by_name_failed'] );
	}

	/**
	 * With no turn that used tools, the per-tool query is not run.
	 *
	 * @return void
	 */
	public function test_report_without_tool_turns_skips_the_breakdown(): void {
		$this->db->rows = array( array( array( 'total' => '40', 'tools' => '0' ) ) );

		$report = ( new Dashboard_Report( $this->repository() ) )->build( self::period() );

		$this->assertSame( 0, $report['tools']['count'] );
		$this->assertSame( array(), $report['tools']['by_name'] );
		$this->assertFalse( $report['tools']['by_name_failed'] );
		$this->assertStringNotContainsString( 'GROUP BY tools_used', implode( "\n", $this->db->queries ) );
	}

	/**
	 * An empty period has no share, not a share of zero.
	 *
	 * @return void
	 */
	public function test_report_percent_with_no_turns(): void {
		$this->db->rows = array( array( array( 'total' => '0', 'tools' => '0' ) ) );

		$report = ( new Dashboard_Report( $this->repository() ) )->build( self::period() );

		$this->assertNull( $report['tools']['percent'] );
	}

	/**
	 * A failed breakdown is reported, and does not hide the count.
	 *
	 * @return void
	 */
	public function test_report_flags_a_failed_breakdown(): void {
		$this->db->rows = array(
			array( array( 'total' => '40', 'tools' => '10' ) ),
			false,
		);

		$report = ( new Dashboard_Report( $this->repository() ) )->build( self::period() );

		$this->assertSame( 10, $report['tools']['count'] );
		$this->assertTrue( $report['tools']['by_name_failed'] );
	}

	/**
	 * Without the column the report has no tools part.
	 *
	 * @return void
	 */
	public function test_report_without_the_column(): void {
		$this->db->rows = array( array( array( 'total' => '40' ) ) );

		$report = ( new Dashboard_Report( $this->repository( array() ) ) )->build( self::period() );

		$this->assertArrayNotHasKey( 'tools', $report );
	}
}
