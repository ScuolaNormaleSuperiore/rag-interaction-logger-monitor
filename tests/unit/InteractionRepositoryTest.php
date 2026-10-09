<?php
/**
 * Unit tests for the read-only repository.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\TestCase;
use RILM\Config\Config;
use RILM\Repository\Filters;
use RILM\Repository\Interaction_Repository;
use RILM\Repository\Period;
use RILM\Tests\Unit\Support\Fake_Reader;

/**
 * Verifies the SQL the repository builds: clauses, parameters, whitelists and read-only use.
 */
class InteractionRepositoryTest extends TestCase {

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
		$this->repository = new Interaction_Repository( $this->db, self::config() );
	}

	/**
	 * Valid configuration.
	 *
	 * @return Config
	 */
	private static function config(): Config {
		return new Config(
			array( 'host' => 'db.example.test' ),
			array(
				'user'     => 'reader',
				'password' => 'secret',
			)
		);
	}

	/**
	 * Builds filters at a fixed local time (Europe/Rome, UTC+2).
	 *
	 * @param array $input Raw values.
	 * @return Filters
	 */
	private static function filters( array $input = array() ): Filters {
		// These tests are about the clauses, not the default period: they ask for "today" unless a test says otherwise.
		return Filters::from_array( $input + array( 'period' => 'today' ), new DateTimeImmutable( '2026-10-02 14:30:00', new DateTimeZone( 'Europe/Rome' ) ) );
	}

	/**
	 * The last query recorded.
	 *
	 * @return string
	 */
	private function last_query(): string {
		return (string) end( $this->db->queries );
	}

	/**
	 * Period clause shared by every query.
	 */
	private const PERIOD = "ts >= '2026-10-01 22:00:00.000' AND ts <= '2026-10-02 12:30:00.000'";

	/**
	 * The repository refuses an invalid configuration.
	 *
	 * @return void
	 */
	public function test_invalid_configuration_is_refused(): void {
		$this->expectException( LogicException::class );

		new Interaction_Repository( $this->db, new Config() );
	}

	/**
	 * Counting always applies the period.
	 *
	 * @return void
	 */
	public function test_count_applies_the_period(): void {
		$this->db->var = '7';

		$this->assertSame( 7, $this->repository->count( self::filters() ) );
		$this->assertSame( 'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ' . self::PERIOD, $this->last_query() );
	}

	/**
	 * The list is sorted newest first by default and paginated in the database.
	 *
	 * @return void
	 */
	public function test_find_page_defaults(): void {
		$this->repository->find_page( self::filters() );

		$query = $this->last_query();

		$this->assertStringStartsWith( 'SELECT id, ts, duration_ms, instance, user_id, turn_id, outcome, guard_present, input_verdict, output_verdict, other_plugin_reply, recall_count, recall_top_score, LEFT( question, 1001 ) AS question, LEFT( delivered, 1001 ) AS delivered FROM ' . self::TABLE, $query );
		$this->assertStringContainsString( ' WHERE ' . self::PERIOD . ' ORDER BY ts DESC, id DESC LIMIT 20 OFFSET 0', $query );
	}

	/**
	 * The list never selects the generated answer or the long optional columns.
	 *
	 * @return void
	 */
	public function test_find_page_does_not_select_full_texts(): void {
		$this->repository->find_page( self::filters() );

		$query = $this->last_query();

		$this->assertStringNotContainsString( 'llm_answer', $query );
		$this->assertStringNotContainsString( 'tool_input', $query );
		$this->assertStringNotContainsString( 'tool_output', $query );
		$this->assertStringNotContainsString( 'recall_sources', $query );
		$this->assertStringNotContainsString( 'SELECT *', $query );
	}

	/**
	 * The compact tool indicator only needs the names of the tools used.
	 *
	 * @return void
	 */
	public function test_find_page_selects_tools_used_when_available(): void {
		$repository = new Interaction_Repository( $this->db, self::config(), array( 'tools_used', 'tool_input', 'tool_output' ) );

		$repository->find_page( self::filters() );

		$query = $this->last_query();
		$this->assertStringContainsString( ', tools_used FROM ', $query );
		$this->assertStringNotContainsString( 'tool_input', $query );
		$this->assertStringNotContainsString( 'tool_output', $query );
	}

	/**
	 * Sorting and paging reach SQL only through whitelisted values and integers.
	 *
	 * @return void
	 */
	public function test_sorting_and_paging(): void {
		$this->repository->find_page(
			self::filters(
				array(
					'orderby'  => 'duration_ms',
					'order'    => 'asc',
					'paged'    => '3',
					'per_page' => '50',
				)
			)
		);

		$this->assertStringContainsString( ' ORDER BY duration_ms ASC, id ASC LIMIT 50 OFFSET 100', $this->last_query() );
	}

	/**
	 * A malicious sort column never reaches SQL.
	 *
	 * @return void
	 */
	public function test_malicious_sort_is_ignored(): void {
		$this->repository->find_page( self::filters( array( 'orderby' => 'ts; DROP TABLE x --' ) ) );

		$this->assertStringContainsString( ' ORDER BY ts DESC, id DESC ', $this->last_query() );
		$this->assertStringNotContainsString( 'DROP', $this->last_query() );
	}

	/**
	 * Each filter adds its own clause with prepared values.
	 *
	 * @dataProvider provide_filter_clauses
	 *
	 * @param array  $input    Raw values.
	 * @param string $expected Clause expected after the period.
	 * @return void
	 */
	public function test_filters_add_clauses( array $input, string $expected ): void {
		$this->repository->count( self::filters( $input ) );

		$this->assertSame(
			'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ' . self::PERIOD . ' AND ' . $expected,
			$this->last_query()
		);
	}

	/**
	 * Provides filter input and the clause it produces.
	 *
	 * @return array<string, array{array, string}>
	 */
	public static function provide_filter_clauses(): array {
		return array(
			'outcome'                  => array( array( 'outcome' => 'incomplete' ), "outcome = 'incomplete'" ),
			'instance'                 => array( array( 'instance' => 'site-a' ), "instance = 'site-a'" ),
			'user'                     => array( array( 'user_id' => '42' ), "user_id = '42'" ),
			'guard present'            => array( array( 'guard' => 'present' ), 'guard_present = 1' ),
			'guard absent'             => array( array( 'guard' => 'absent' ), 'guard_present = 0' ),
			'input verdict any'        => array( array( 'input_verdict' => Filters::VERDICT_ANY ), 'input_verdict IS NOT NULL' ),
			'input verdict none'       => array( array( 'input_verdict' => Filters::VERDICT_NONE ), 'input_verdict IS NULL' ),
			'input verdict specific'   => array( array( 'input_verdict' => 'blocked' ), "input_verdict = 'blocked'" ),
			'output verdict any'       => array( array( 'output_verdict' => Filters::VERDICT_ANY ), 'output_verdict IS NOT NULL' ),
			'output verdict none'      => array( array( 'output_verdict' => Filters::VERDICT_NONE ), 'output_verdict IS NULL' ),
			'output verdict specific'  => array( array( 'output_verdict' => 'toxic' ), "output_verdict = 'toxic'" ),
			'other reply yes'          => array( array( 'other_reply' => 'yes' ), 'other_plugin_reply = 1' ),
			'other reply no'           => array( array( 'other_reply' => 'no' ), 'other_plugin_reply = 0' ),
			'other reply not recorded' => array( array( 'other_reply' => 'unknown' ), 'other_plugin_reply IS NULL' ),
			'recall empty'             => array( array( 'recall' => 'empty' ), 'recall_count = 0' ),
		);
	}

	/**
	 * NULL is queried with IS NULL and never with an equality on a value.
	 *
	 * @return void
	 */
	public function test_null_is_distinct_from_explicit_values(): void {
		$this->repository->count( self::filters( array( 'other_reply' => 'unknown' ) ) );
		$this->assertStringNotContainsString( 'other_plugin_reply = ', $this->last_query() );

		$this->repository->count( self::filters( array( 'other_reply' => 'no' ) ) );
		$this->assertStringContainsString( 'other_plugin_reply = 0', $this->last_query() );
		$this->assertStringNotContainsString( 'IS NULL', $this->last_query() );

		$this->repository->count( self::filters( array( 'output_verdict' => Filters::VERDICT_NONE ) ) );
		$this->assertStringContainsString( 'output_verdict IS NULL', $this->last_query() );
	}

	/**
	 * Filters combine with AND, always inside the period.
	 *
	 * @return void
	 */
	public function test_filters_combine(): void {
		$this->repository->count(
			self::filters(
				array(
					'outcome'  => 'generated',
					'guard'    => 'absent',
					'instance' => 'site-a',
				)
			)
		);

		$this->assertSame(
			'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ' . self::PERIOD . " AND outcome = 'generated' AND instance = 'site-a' AND guard_present = 0",
			$this->last_query()
		);
	}

	/**
	 * A filter value with quotes is escaped and cannot close the string.
	 *
	 * @return void
	 */
	public function test_values_are_escaped(): void {
		$this->repository->count( self::filters( array( 'instance' => "x' OR '1'='1" ) ) );

		$this->assertStringContainsString( "instance = 'x\\' OR \\'1\\'=\\'1'", $this->last_query() );
	}

	/**
	 * The search looks in the three texts, inside the period.
	 *
	 * @return void
	 */
	public function test_search_covers_three_columns(): void {
		$this->repository->count( self::filters( array( 'search' => 'hello' ) ) );

		$this->assertSame(
			'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ' . self::PERIOD . " AND ( question LIKE '%hello%' OR llm_answer LIKE '%hello%' OR delivered LIKE '%hello%' )",
			$this->last_query()
		);
	}

	/**
	 * LIKE wildcards typed by the user are escaped, so they match literally.
	 *
	 * @return void
	 */
	public function test_search_wildcards_are_escaped(): void {
		$this->repository->count( self::filters( array( 'search' => '100%_off' ) ) );

		$this->assertStringContainsString( "question LIKE '%100\\\\%\\\\_off%'", $this->last_query() );
	}

	/**
	 * Quotes and backslashes in the search cannot break out of the string.
	 *
	 * @return void
	 */
	public function test_search_quotes_and_backslashes_are_escaped(): void {
		$this->repository->count( self::filters( array( 'search' => "a'b\\c" ) ) );

		$this->assertStringContainsString( "LIKE '%a\\'b\\\\\\\\c%'", $this->last_query() );
	}

	/**
	 * A search that tries SQL injection stays inside the quoted pattern.
	 *
	 * @return void
	 */
	public function test_search_injection_stays_inside_the_pattern(): void {
		$this->repository->count( self::filters( array( 'search' => "x') OR 1=1 --" ) ) );

		$this->assertStringContainsString( "question LIKE '%x\\') OR 1=1 --%'", $this->last_query() );
		$this->assertSame( 3, substr_count( $this->last_query(), "LIKE '%x\\') OR 1=1 --%'" ) );
	}

	/**
	 * Searching a unicode term keeps it intact.
	 *
	 * @return void
	 */
	public function test_search_keeps_unicode(): void {
		$this->repository->count( self::filters( array( 'search' => 'perché' ) ) );

		$this->assertStringContainsString( "LIKE '%perché%'", $this->last_query() );
	}

	/**
	 * The detail query selects one row by a prepared integer id.
	 *
	 * @return void
	 */
	public function test_find_by_id_query(): void {
		$this->repository->find_by_id( 12 );

		$this->assertSame(
			'SELECT id, ts, duration_ms, instance, user_id, turn_id, outcome, guard_present, input_verdict, output_verdict, other_plugin_reply, recall_count, recall_top_score, question, llm_answer, delivered FROM ' . self::TABLE . ' WHERE id = 12 LIMIT 1',
			$this->last_query()
		);
	}

	/**
	 * Ids that are not positive integers never reach the database.
	 *
	 * @dataProvider provide_bad_ids
	 *
	 * @param int $id Candidate id.
	 * @return void
	 */
	public function test_find_by_id_rejects_non_positive_ids( int $id ): void {
		$this->assertNull( $this->repository->find_by_id( $id ) );
		$this->assertSame( array(), $this->db->queries );
	}

	/**
	 * Provides non-positive ids.
	 *
	 * @return array<string, array{int}>
	 */
	public static function provide_bad_ids(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -5 ),
		);
	}

	/**
	 * A missing row gives null, and a found row is mapped with its full texts.
	 *
	 * @return void
	 */
	public function test_find_by_id_result(): void {
		$this->assertNull( $this->repository->find_by_id( 999 ) );

		$long          = str_repeat( 'a', Interaction_Repository::PREVIEW_LENGTH + 500 );
		$this->db->rows = array(
			array(
				array(
					'id'       => '12',
					'question' => $long,
					'outcome'  => 'generated',
				),
			),
		);

		$row = $this->repository->find_by_id( 12 );

		$this->assertNotNull( $row );
		$this->assertSame( 12, $row->id );
		$this->assertSame( $long, $row->question );
		$this->assertFalse( $row->question_truncated );
	}

	/**
	 * The detail selects only the optional columns that exist.
	 *
	 * @return void
	 */
	public function test_find_by_id_selects_present_optional_columns(): void {
		$repository = new Interaction_Repository( $this->db, self::config(), array( 'tool_input', 'recall_sources', 'not_a_column' ) );

		$repository->find_by_id( 5 );

		$query = $this->last_query();

		$this->assertStringContainsString( ', tool_input, recall_sources FROM ', $query );
		$this->assertStringNotContainsString( 'tools_used', $query );
		$this->assertStringNotContainsString( 'tool_output', $query );
		$this->assertStringNotContainsString( 'not_a_column', $query );
	}

	/**
	 * Rows of a page are mapped and the previews are cut.
	 *
	 * @return void
	 */
	public function test_find_page_maps_rows_and_cuts_previews(): void {
		$this->db->rows = array(
			array(
				array(
					'id'        => '2',
					'question'  => str_repeat( 'q', Interaction_Repository::PREVIEW_LENGTH + 1 ),
					'delivered' => 'ok',
					'outcome'   => 'generated',
				),
				array(
					'id'       => '1',
					'question' => null,
					'outcome'  => 'incomplete',
				),
			),
		);

		$rows = $this->repository->find_page( self::filters() );

		$this->assertCount( 2, $rows );
		$this->assertSame( 2, $rows[0]->id );
		$this->assertSame( Interaction_Repository::PREVIEW_LENGTH, mb_strlen( (string) $rows[0]->question ) );
		$this->assertTrue( $rows[0]->question_truncated );
		$this->assertNull( $rows[1]->question );
		$this->assertFalse( $rows[1]->question_truncated );
	}

	/**
	 * Distinct values are read per period from a whitelist of columns.
	 *
	 * @return void
	 */
	public function test_distinct_values(): void {
		$this->db->col = array( 'site-a', 'site-b' );
		$period        = self::filters()->period();

		$this->assertSame( array( 'site-a', 'site-b' ), $this->repository->distinct_values( 'instance', $period ) );
		$this->assertSame(
			'SELECT DISTINCT instance FROM ' . self::TABLE . " WHERE ts >= '2026-10-01 22:00:00.000' AND ts <= '2026-10-02 12:30:00.000' AND instance IS NOT NULL ORDER BY instance ASC LIMIT 200",
			$this->last_query()
		);
	}

	/**
	 * Columns outside the whitelist never reach SQL.
	 *
	 * @return void
	 */
	public function test_distinct_values_rejects_other_columns(): void {
		$period = self::filters()->period();

		$this->assertSame( array(), $this->repository->distinct_values( 'question', $period ) );
		$this->assertSame( array(), $this->repository->distinct_values( 'instance; DROP TABLE x', $period ) );
		$this->assertSame( array(), $this->db->queries );
	}

	/**
	 * Every query the repository can issue is a single SELECT.
	 *
	 * @return void
	 */
	public function test_only_select_statements_are_issued(): void {
		$filters = self::filters(
			array(
				'outcome'        => 'generated',
				'instance'       => 'site-a',
				'user_id'        => '42',
				'guard'          => 'present',
				'input_verdict'  => 'blocked',
				'output_verdict' => Filters::VERDICT_NONE,
				'other_reply'    => 'unknown',
				'recall'         => 'empty',
				'search'         => 'hello',
			)
		);

		$this->repository->count( $filters );
		$this->repository->find_page( $filters );
		$this->repository->find_by_id( 3 );
		$this->repository->distinct_values( 'input_verdict', $filters->period() );
		$this->repository->distinct_values( 'output_verdict', $filters->period() );

		$this->assertCount( 5, $this->db->queries );

		foreach ( $this->db->queries as $query ) {
			$this->assertMatchesRegularExpression( '/^SELECT\b/', $query );
			$this->assertStringNotContainsString( ';', $query );
			$this->assertDoesNotMatchRegularExpression( '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|GRANT|TRUNCATE|REPLACE|LOCK)\b/i', $query );
		}
	}

	/**
	 * Every query carries a time boundary, so no query reads the whole table.
	 *
	 * @return void
	 */
	public function test_list_queries_are_always_bounded_by_the_period(): void {
		$filters = self::filters();

		$this->repository->count( $filters );
		$this->repository->find_page( $filters );
		$this->repository->distinct_values( 'instance', $filters->period() );

		foreach ( $this->db->queries as $query ) {
			$this->assertStringContainsString( "ts >= '2026-10-01 22:00:00.000'", $query );
			$this->assertStringContainsString( "ts <= '2026-10-02 12:30:00.000'", $query );
		}
	}

	/**
	 * The period of a custom interval is converted to UTC before it reaches SQL.
	 *
	 * @return void
	 */
	public function test_custom_period_boundaries_are_utc(): void {
		$this->repository->count(
			self::filters(
				array(
					'period' => Period::CUSTOM,
					'from'   => '2026-09-01T00:00',
					'to'     => '2026-09-30T23:59',
				)
			)
		);

		$this->assertStringContainsString( "ts >= '2026-08-31 22:00:00.000' AND ts <= '2026-09-30 21:59:59.999'", $this->last_query() );
	}
}
