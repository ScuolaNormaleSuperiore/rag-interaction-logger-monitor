<?php
/**
 * Integration tests for the Dashboard page and the bar chart.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use RILM\Admin\Bar_Chart;
use RILM\Admin\Dashboard_Page;
use RILM\Admin\Indicator_Texts;
use RILM\Config\Config;
use RILM\Repository\Filters;
use RILM\Repository\Interaction_Repository;
use RILM\Tests\Unit\Support\Fake_Reader;
use WP_UnitTestCase;
use WPDieException;

/**
 * Verifies the figures, the shares, the links, the verdict tables, the charts and the period handling.
 */
class DashboardPageTest extends WP_UnitTestCase {

	/**
	 * Double that records the queries.
	 *
	 * @var Fake_Reader
	 */
	private $reader;

	/**
	 * Sets up an administrator and the time zone.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		update_option( 'timezone_string', 'Europe/Rome' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->reader = new Fake_Reader();
	}

	/**
	 * Restores the request globals.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_GET     = array();
		$_REQUEST = array();
		delete_option( 'timezone_string' );

		parent::tear_down();
	}

	/**
	 * Loads the reader with the answers of a typical period: summary, input verdicts, output verdicts, hourly rows.
	 *
	 * @param array $summary Values replacing the default summary.
	 * @return void
	 */
	private function load( array $summary = array() ): void {
		$this->reader->col  = array( '900', '1100' );
		$this->reader->rows = array(
			array(
				$summary + array(
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
				),
			),
			array(
				array(
					'verdict' => 'prompt_injection',
					'blocks'  => '6',
				),
				array(
					'verdict' => 'hate',
					'blocks'  => '2',
				),
			),
			array(
				array(
					'verdict' => 'toxic',
					'blocks'  => '4',
				),
			),
			array(
				array(
					'hour'          => '2026-10-01 22',
					'turns'         => '120',
					'incomplete'    => '12',
					'input_blocks'  => '5',
					'output_blocks' => '2',
				),
				array(
					'hour'          => '2026-10-02 09',
					'turns'         => '80',
					'incomplete'    => '8',
					'input_blocks'  => '3',
					'output_blocks' => '2',
				),
			),
		);
	}

	/**
	 * Creates the page on the fake reader.
	 *
	 * @param bool $available Whether the log database is usable.
	 * @param string[] $columns   Optional columns present in the table.
	 * @return Dashboard_Page
	 */
	private function page( bool $available = true, array $columns = array() ): Dashboard_Page {
		$repository = $available ? new Interaction_Repository(
			$this->reader,
			new Config(
				array(
					'host' => 'db.example.test',
					'user' => 'reader',
				),
				array( 'password' => 'secret' )
			),
			$columns
		) : null;

		return new Dashboard_Page(
			null,
			static function () use ( $repository ) {
				return $repository;
			},
			static function (): DateTimeImmutable {
				return new DateTimeImmutable( '2026-10-02 14:30:00', new DateTimeZone( 'Europe/Rome' ) );
			}
		);
	}

	/**
	 * Renders the page and returns the output.
	 *
	 * @param Dashboard_Page $page Page.
	 * @return string
	 */
	private function render( Dashboard_Page $page ): string {
		ob_start();

		try {
			$page->render();
		} catch ( \Throwable $exception ) {
			ob_end_clean();
			throw $exception;
		}

		return ob_get_clean();
	}

	/**
	 * Returns the query arguments of every list link of an output, keyed by the text of its row.
	 *
	 * @param string $output Page output.
	 * @return array<int, array<string, string>>
	 */
	private function list_links( string $output ): array {
		preg_match_all( '/<a href="([^"]*page=rilm-interactions[^"]*)"/', $output, $matches );

		$links = array();

		foreach ( $matches[1] as $url ) {
			parse_str( (string) wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY ), $args );
			$links[] = $args;
		}

		return $links;
	}

	/**
	 * The headline figures are shown.
	 *
	 * @return void
	 */
	public function test_shows_the_headline_figures(): void {
		$this->load();

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<h1>Dashboard</h1>', $output );
		$this->assertMatchesRegularExpression( '/Turns<\/p>\s*<p class="rilm-tile-value"><a href="[^"]*">200/', $output );
		$this->assertMatchesRegularExpression( '/Average duration of completed turns<\/p>\s*<p class="rilm-tile-value">1,235 ms/', $output );
		$this->assertMatchesRegularExpression( '/Median duration of completed turns<\/p>\s*<p class="rilm-tile-value">1,000 ms/', $output );
		$this->assertStringContainsString( 'Completed turns: 178.', $output );
	}

	/**
	 * Each indicator has its count and the share of the right whole.
	 *
	 * @return void
	 */
	public function test_shows_counts_and_shares(): void {
		$this->load();

		$output = $this->render( $this->page() );

		$this->assertMatchesRegularExpression( '/<th scope="row">Generated<\/th><td><a href="[^"]*">150<span[^>]*> \(Generated\)<\/span><\/a><\/td><td>75\.0% of all turns<\/td>/', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">Fast reply<\/th><td><a[^>]*>30[^<]*<span[^>]*>[^<]*<\/span><\/a><\/td><td>15\.0% of all turns<\/td>/', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">Incomplete<\/th><td><a[^>]*>20[^<]*<span[^>]*>[^<]*<\/span><\/a><\/td><td>10\.0% of all turns<\/td>/', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">Input blocked<\/th><td><a[^>]*>8[^<]*<span[^>]*>[^<]*<\/span><\/a><\/td><td>4\.0% of all turns<\/td>/', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">Output blocked<\/th><td><a[^>]*>4[^<]*<span[^>]*>[^<]*<\/span><\/a><\/td><td>2\.0% of all turns<\/td>/', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">Guardrails did not handle the turn<\/th><td><a[^>]*>10[^<]*<span[^>]*>[^<]*<\/span><\/a><\/td><td>5\.0% of all turns<\/td>/', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">Generated without recalled sources<\/th><td><a[^>]*>15[^<]*<span[^>]*>[^<]*<\/span><\/a><\/td><td>10\.0% of generated answers<\/td>/', $output );
	}

	/**
	 * Every count links to the list filtered the same way, inside the period.
	 *
	 * @return void
	 */
	public function test_counts_link_to_the_filtered_list(): void {
		$_GET = array( 'period' => 'month' );

		$this->load();

		$links = $this->list_links( $this->render( $this->page() ) );

		$expected = array(
			array(),
			array( 'outcome' => 'generated' ),
			array( 'outcome' => 'fast_reply' ),
			array( 'outcome' => 'incomplete' ),
			array( 'input_verdict' => Filters::VERDICT_ANY ),
			array( 'output_verdict' => Filters::VERDICT_ANY ),
			array( 'guard' => 'absent' ),
			array(
				'outcome' => 'generated',
				'recall'  => 'empty',
			),
			array( 'input_verdict' => 'prompt_injection' ),
			array( 'input_verdict' => 'hate' ),
			array( 'output_verdict' => 'toxic' ),
		);

		$this->assertCount( count( $expected ), $links );

		foreach ( $expected as $index => $filters ) {
			$this->assertSame( array( 'page' => 'rilm-interactions', 'period' => 'month' ) + $filters, $links[ $index ], 'Link ' . $index );
		}
	}

	/**
	 * Links carry a custom period.
	 *
	 * @return void
	 */
	public function test_links_carry_a_custom_period(): void {
		$_GET = array(
			'period' => 'custom',
			'from'   => '2026-09-01T00:00',
			'to'     => '2026-09-30T23:59',
		);

		$this->load();

		foreach ( $this->list_links( $this->render( $this->page() ) ) as $index => $args ) {
			$this->assertSame( 'custom', $args['period'], 'Link ' . $index );
			$this->assertSame( '2026-09-01T00:00', $args['from'], 'Link ' . $index );
			$this->assertSame( '2026-09-30T23:59', $args['to'], 'Link ' . $index );
		}
	}

	/**
	 * Shares have no link, and no link carries a search.
	 *
	 * @return void
	 */
	public function test_links_are_only_on_counts_and_never_carry_a_search(): void {
		$_GET = array( 'search' => 'secret words' );

		$this->load();

		$output = $this->render( $this->page() );

		$this->assertStringNotContainsString( 'secret', $output );
		$this->assertDoesNotMatchRegularExpression( '/<a [^>]*>[^<]*%/', $output );

		foreach ( $this->list_links( $output ) as $args ) {
			$this->assertArrayNotHasKey( 'search', $args );
		}
	}

	/**
	 * The two verdict tables list each verdict with its count.
	 *
	 * @return void
	 */
	public function test_verdict_tables(): void {
		$this->load();

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<caption>Input verdicts</caption>', $output );
		$this->assertStringContainsString( '<caption>Output verdicts</caption>', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">prompt_injection<\/th><td><a [^>]*>6/', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">hate<\/th><td><a [^>]*>2/', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">toxic<\/th><td><a [^>]*>4/', $output );
	}

	/**
	 * Verdict names are untrusted text and are escaped, in the table and in the link.
	 *
	 * @return void
	 */
	public function test_verdict_names_are_escaped(): void {
		$this->load();
		$this->reader->rows[1] = array(
			array(
				'verdict' => '<script>alert(1)</script>',
				'blocks'  => '3',
			),
		);

		$output = $this->render( $this->page() );

		$this->assertStringNotContainsString( '<script>alert', $output );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $output );
	}

	/**
	 * With no block the verdict tables say so.
	 *
	 * @return void
	 */
	public function test_no_blocks_message(): void {
		$this->load(
			array(
				'input_blocks'  => '0',
				'output_blocks' => '0',
			)
		);
		$this->reader->rows[1] = array();

		$output = $this->render( $this->page() );

		$this->assertSame( 2, substr_count( $output, 'No blocks recorded in the period.' ) );
	}

	/**
	 * An empty period shows dashes for the shares and "Not available" for the durations.
	 *
	 * @return void
	 */
	public function test_empty_period(): void {
		$this->load(
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
		);

		$output = $this->render( $this->page() );

		$this->assertMatchesRegularExpression( '/Turns<\/p>\s*<p class="rilm-tile-value"><a href="[^"]*">0/', $output );
		$this->assertSame( 2, substr_count( $output, 'Not available</p>' ) );
		$this->assertSame( 7, substr_count( $output, '<td>–' ) );
		$this->assertStringContainsString( 'Not available: no turns to compare with.', $output );
		$this->assertStringContainsString( 'No turns in the period.', $output );
		$this->assertStringNotContainsString( '<svg', $output );
		$this->assertCount( 1, $this->reader->queries, 'An empty period costs one query.' );
	}

	/**
	 * A zero share is shown as 0.0%, not as a dash.
	 *
	 * @return void
	 */
	public function test_zero_share_is_not_a_dash(): void {
		$this->load( array( 'no_guardrails' => '0' ) );

		$output = $this->render( $this->page() );

		$this->assertMatchesRegularExpression( '/Guardrails did not handle the turn<\/th><td><a[^>]*>0[^<]*<span[^>]*>[^<]*<\/span><\/a><\/td><td>0\.0% of all turns<\/td>/', $output );
	}

	/**
	 * The default period is the last week, and the period is read in local time.
	 *
	 * @return void
	 */
	public function test_default_period_is_last_week(): void {
		$this->load();

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( "ts >= '2026-09-25 12:30:00.000' AND ts <= '2026-10-02 12:30:00.000'", $this->reader->queries[0] );
		$this->assertMatchesRegularExpression( '/<option value="week" selected=\'selected\'>/', $output );
		$this->assertStringContainsString( 'Europe/Rome', $output );
	}

	/**
	 * Today is still a choice and starts at local midnight.
	 *
	 * @return void
	 */
	public function test_today_can_still_be_chosen(): void {
		$_GET = array( 'period' => 'today' );

		$this->load();

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( "ts >= '2026-10-01 22:00:00.000' AND ts <= '2026-10-02 12:30:00.000'", $this->reader->queries[0] );
		$this->assertMatchesRegularExpression( '/<option value="today" selected=\'selected\'>/', $output );
	}

	/**
	 * Links built on "today" keep it, and links on the default period leave it out.
	 *
	 * @return void
	 */
	public function test_links_keep_today_and_omit_the_default_period(): void {
		$_GET = array( 'period' => 'today' );
		$this->load();

		foreach ( $this->list_links( $this->render( $this->page() ) ) as $index => $args ) {
			$this->assertSame( 'today', $args['period'], 'Link ' . $index );
		}

		$_GET               = array();
		$this->reader       = new Fake_Reader();
		$this->load();

		foreach ( $this->list_links( $this->render( $this->page() ) ) as $index => $args ) {
			$this->assertArrayNotHasKey( 'period', $args, 'Link ' . $index );
		}
	}

	/**
	 * An invalid period falls back to the default and the user is told which one is shown.
	 *
	 * @return void
	 */
	public function test_invalid_period_is_reported(): void {
		$_GET = array( 'period' => 'fortnight' );

		$this->load();

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'The period was ignored because its value is not valid: the default period (Last week) is shown.', $output );
		$this->assertStringContainsString( "ts >= '2026-09-25 12:30:00.000'", $this->reader->queries[0] );
	}

	/**
	 * The period form is a GET form that keeps the page.
	 *
	 * @return void
	 */
	public function test_period_form_is_get(): void {
		$this->load();

		$output = $this->render( $this->page() );

		$this->assertMatchesRegularExpression( '/<form method="get" action="[^"]*admin\.php">/', $output );
		$this->assertStringContainsString( '<input type="hidden" name="page" value="rilm-dashboard" />', $output );
		$this->assertStringNotContainsString( 'method="post"', $output );
		$this->assertStringContainsString( 'for="rilm-period"', $output );
	}

	/**
	 * Three charts are drawn, each with a title, a description and a legend that names the series.
	 *
	 * @return void
	 */
	public function test_three_charts_with_legends(): void {
		$this->load();

		$output = $this->render( $this->page() );

		$this->assertSame( 3, substr_count( $output, '<svg ' ) );
		$this->assertSame( 3, substr_count( $output, 'role="img"' ) );
		$this->assertStringContainsString( '<title id="rilm-chart-turns-title">Turns per day</title>', $output );
		$this->assertStringContainsString( '<title id="rilm-chart-incomplete-title">Incomplete turns per day</title>', $output );
		$this->assertStringContainsString( '<title id="rilm-chart-blocks-title">Blocks per day</title>', $output );
		$this->assertSame( 3, substr_count( $output, '<desc id=' ) );
		$this->assertStringContainsString( 'aria-labelledby="rilm-chart-blocks-title rilm-chart-blocks-desc"', $output );
		$this->assertStringContainsString( '</span> Input blocked</li>', $output );
		$this->assertStringContainsString( '</span> Output blocked</li>', $output );
	}

	/**
	 * The optional tools column adds a daily comparison chart and table column.
	 *
	 * @return void
	 */
	public function test_tools_chart_compares_turns_with_the_total(): void {
		$this->load_with_tools();
		$this->reader->rows[4][0]['tools'] = '30';
		$this->reader->rows[4][1]['tools'] = '20';

		$output = $this->render( $this->page( true, array( 'tools_used' ) ) );

		$this->assertSame( 4, substr_count( $output, '<svg ' ) );
		$this->assertStringContainsString( '<title id="rilm-chart-tools-title">Turns with and without tools per day</title>', $output );
		$this->assertStringContainsString( '</span> Without tools</li>', $output );
		$this->assertStringContainsString( '</span> Turns that used tools</li>', $output );
		$this->assertStringContainsString( '<th scope="col">Turns that used tools</th>', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">2026-10-02<\/th><td>200<\/td><td>20<\/td><td>8<\/td><td>4<\/td><td>50<\/td>/', $output );
	}

	/**
	 * The daily figures are also in a table, so the charts are not the only way to read them.
	 *
	 * @return void
	 */
	public function test_daily_table_has_the_same_numbers(): void {
		$this->load();

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'Show the daily figures as a table', $output );
		$this->assertStringContainsString( '<th scope="col">Day</th>', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">2026-10-02<\/th><td>200<\/td><td>20<\/td><td>8<\/td><td>4<\/td>/', $output );
	}

	/**
	 * The 22:00 UTC hour is already the next day in Rome: both rows land on 2 October.
	 *
	 * @return void
	 */
	public function test_hours_are_grouped_by_local_day(): void {
		$_GET = array( 'period' => 'today' );

		$this->load();

		$output = $this->render( $this->page() );

		$this->assertSame( 1, substr_count( $output, '<th scope="row">2026-10-02</th>' ) );
		$this->assertStringNotContainsString( '<th scope="row">2026-10-01</th>', $output );
	}

	/**
	 * A legend under the indicators table explains every indicator.
	 *
	 * @return void
	 */
	public function test_legend_explains_every_indicator(): void {
		$this->load();

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<h3 id="rilm-indicators-legend">What the indicators mean</h3>', $output );
		$this->assertStringContainsString( '<dl class="rilm-legend-list" aria-labelledby="rilm-indicators-legend">', $output );
		$this->assertSame( 7, substr_count( $output, '<dt>' ) );

		foreach ( Indicator_Texts::dashboard_keys() as $key ) {
			$this->assertStringContainsString( '<dt>' . esc_html( Indicator_Texts::label( $key ) ) . '</dt>', $output, $key );
			$this->assertStringContainsString( esc_html( Indicator_Texts::description( $key ) ), $output, $key );
		}
	}

	/**
	 * The legend comes after the indicators table and before the verdict tables.
	 *
	 * @return void
	 */
	public function test_legend_follows_the_indicators_table(): void {
		$this->load();

		$output = $this->render( $this->page() );

		$table   = strpos( $output, '</table>', (int) strpos( $output, 'rilm-indicators-table' ) );
		$legend  = strpos( $output, 'rilm-indicators-legend' );
		$verdict = strpos( $output, 'Blocks by verdict' );

		$this->assertTrue( $table < $legend && $legend < $verdict );
	}

	/**
	 * Each legend entry has the name of a row of the table.
	 *
	 * @return void
	 */
	public function test_legend_names_match_the_table_rows(): void {
		$this->load();

		$output = $this->render( $this->page() );

		foreach ( Indicator_Texts::dashboard_keys() as $key ) {
			$label = esc_html( Indicator_Texts::label( $key ) );

			$this->assertStringContainsString( '<th scope="row">' . $label . '</th>', $output, $key );
			$this->assertStringContainsString( '<dt>' . $label . '</dt>', $output, $key );
		}
	}

	/**
	 * The legend says what each share is taken of: all turns, or the generated answers for recall.
	 *
	 * @return void
	 */
	public function test_legend_states_the_denominator_of_each_share(): void {
		$this->load();

		$output = $this->render( $this->page() );

		$this->assertSame( 6, substr_count( $output, 'The share is taken of all the turns of the period.' ) );
		$this->assertSame( 1, substr_count( $output, 'The share is taken of the generated answers.' ) );
		$this->assertMatchesRegularExpression( '/<dt>Generated without recalled sources<\/dt><dd>[^<]*The share is taken of the generated answers\.<\/dd>/', $output );
	}

	/**
	 * The Guardrails entry uses the cautious wording, in the legend and in the table.
	 *
	 * @return void
	 */
	public function test_guardrails_wording(): void {
		$this->load();

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'Guardrails did not handle the turn', $output );
		$this->assertStringNotContainsString( 'Guardrails did not run', $output );
		$this->assertStringContainsString( 'left no mark on the turn', $output );
		$this->assertStringContainsString( 'verdicts are always empty', $output );
	}

	/**
	 * With no turns the table is shown, so the legend is too.
	 *
	 * @return void
	 */
	public function test_legend_is_shown_for_an_empty_period(): void {
		$this->load(
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
		);

		$this->assertStringContainsString( 'What the indicators mean', $this->render( $this->page() ) );
	}

	/**
	 * Without the table there is nothing to explain.
	 *
	 * @return void
	 */
	public function test_no_legend_without_the_table(): void {
		$this->assertStringNotContainsString( 'What the indicators mean', $this->render( $this->page( false ) ) );

		$this->reader->rows = array( array() );

		$this->assertStringNotContainsString( 'What the indicators mean', $this->render( $this->page() ) );
	}

	/**
	 * Loads the answers of a period for a table that has the tools column.
	 *
	 * @param array $summary Values replacing the default summary (`tools` is added).
	 * @param array $tools   Rows of the per-tool query: combination and turns.
	 * @return void
	 */
	private function load_with_tools( array $summary = array(), array $tools = array() ): void {
		$this->load( $summary + array( 'tools' => '50' ) );

		// The per-tool query comes after the two verdict queries and before the hourly one.
		array_splice( $this->reader->rows, 3, 0, array( $tools ) );
	}

	/**
	 * With the column, the indicators table has a row for turns that used tools, with a count, a share and a link.
	 *
	 * @return void
	 */
	public function test_tools_row_is_shown_with_the_column(): void {
		$this->load_with_tools(
			array(),
			array(
				array(
					'names' => 'search',
					'turns' => '50',
				),
			)
		);

		$output = $this->render( $this->page( true, array( 'tools_used' ) ) );

		$this->assertMatchesRegularExpression( '/<th scope="row">Turns that used tools<\/th><td><a href="[^"]*tools=yes[^"]*">50<span[^>]*> \(Turns that used tools\)<\/span><\/a><\/td><td>25.0% of all turns<\/td>/', $output );
		$this->assertContains( 'yes', array_column( $this->list_links( $output ), 'tools' ) );
	}

	/**
	 * The legend explains the tools row, and says a tool also means a form.
	 *
	 * @return void
	 */
	public function test_legend_explains_the_tools_row(): void {
		$this->load_with_tools();

		$output = $this->render( $this->page( true, array( 'tools_used' ) ) );

		$this->assertSame( 8, substr_count( $output, '<dt>' ) );
		$this->assertStringContainsString( '<dt>Turns that used tools</dt>', $output );
		$this->assertStringContainsString( esc_html( Indicator_Texts::description( 'tools' ) ), $output );
		$this->assertStringContainsString( 'here a tool also means a form', $output );
	}

	/**
	 * The table of tools lists each name with its turns and a link that reopens the list on that tool.
	 *
	 * @return void
	 */
	public function test_tools_table_has_a_link_per_tool(): void {
		$this->load_with_tools(
			array(),
			array(
				array(
					'names' => 'search, form_contact',
					'turns' => '30',
				),
				array(
					'names' => 'search',
					'turns' => '10',
				),
			)
		);

		$output = $this->render( $this->page( true, array( 'tools_used' ) ) );

		$this->assertStringContainsString( '<h2>Tools and forms</h2>', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">search<\/th><td><a href="[^"]*tool=search[^"]*">40</', $output );
		$this->assertMatchesRegularExpression( '/<th scope="row">form_contact<\/th><td><a href="[^"]*tool=form_contact[^"]*">30</', $output );
		$this->assertStringContainsString( 'counted in each of them', $output );
		$this->assertStringNotContainsString( 'counts below are partial', $output );
	}

	/**
	 * Tool names come from the log: they are escaped in the page and in the link.
	 *
	 * @return void
	 */
	public function test_tool_names_are_escaped(): void {
		$this->load_with_tools(
			array(),
			array(
				array(
					'names' => '<script>alert(1)</script>',
					'turns' => '5',
				),
			)
		);

		$output = $this->render( $this->page( true, array( 'tools_used' ) ) );

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $output );
		$this->assertContains( '<script>alert(1)</script>', array_column( $this->list_links( $output ), 'tool' ) );
	}

	/**
	 * A name with characters that mean something in a URL still opens the list on exactly that name.
	 *
	 * @return void
	 */
	public function test_tool_names_with_url_characters_survive_the_link(): void {
		$name = 'a&b=c d#e+f';

		$this->load_with_tools(
			array(),
			array(
				array(
					'names' => $name,
					'turns' => '5',
				),
			)
		);

		$links = $this->list_links( $this->render( $this->page( true, array( 'tools_used' ) ) ) );

		$this->assertContains( $name, array_column( $links, 'tool' ) );
	}

	/**
	 * Too many combinations: the table says its counts are partial.
	 *
	 * @return void
	 */
	public function test_tools_table_warns_when_partial(): void {
		$rows = array();

		for ( $i = 0; $i <= 500; $i++ ) {
			$rows[] = array(
				'names' => 'tool_' . $i,
				'turns' => '1',
			);
		}

		$this->load_with_tools( array(), $rows );

		$this->assertStringContainsString( 'counts below are partial', $this->render( $this->page( true, array( 'tools_used' ) ) ) );
	}

	/**
	 * No turn used tools: the row shows zero and the table says so instead of listing nothing.
	 *
	 * @return void
	 */
	public function test_no_tool_turns(): void {
		$this->load( array( 'tools' => '0' ) );

		$output = $this->render( $this->page( true, array( 'tools_used' ) ) );

		$this->assertMatchesRegularExpression( '/<th scope="row">Turns that used tools<\/th><td><a [^>]*>0</', $output );
		$this->assertStringContainsString( 'No tool or form ran in this period.', $output );
		$this->assertStringNotContainsString( 'rilm-tools-table', $output );
	}

	/**
	 * A failed per-tool query is reported, and the rest of the page is still shown.
	 *
	 * @return void
	 */
	public function test_failed_tools_query_is_reported(): void {
		$this->load_with_tools();
		$this->reader->rows[3] = false;

		$output = $this->render( $this->page( true, array( 'tools_used' ) ) );

		$this->assertStringContainsString( 'The tools could not be loaded.', $output );
		$this->assertStringContainsString( 'Turns that used tools', $output );
	}

	/**
	 * Without the column there is no row, no table, no legend entry and no query on it.
	 *
	 * @return void
	 */
	public function test_nothing_about_tools_without_the_column(): void {
		$this->load();

		$output = $this->render( $this->page() );

		$this->assertStringNotContainsString( 'Turns that used tools', $output );
		$this->assertStringNotContainsString( 'Tools and forms', $output );
		$this->assertSame( 7, substr_count( $output, '<dt>' ) );
		$this->assertStringNotContainsString( 'tools_used', implode( "\n", $this->reader->queries ) );
	}

	/**
	 * Users without manage_options get nothing.
	 *
	 * @return void
	 */
	public function test_users_without_capability_are_refused(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		try {
			$this->render( $this->page() );
			$this->fail( 'The page must refuse the request.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( array(), $this->reader->queries );
		}
	}

	/**
	 * Without a usable database the page says so.
	 *
	 * @return void
	 */
	public function test_unavailable_database_shows_a_message(): void {
		$output = $this->render( $this->page( false ) );

		$this->assertStringContainsString( 'The interaction log is not available', $output );
		$this->assertStringNotContainsString( 'rilm-indicators-table', $output );
		$this->assertSame( array(), $this->reader->queries );
	}

	/**
	 * A failed summary query is reported, not shown as zeros.
	 *
	 * @return void
	 */
	public function test_failed_summary_is_reported(): void {
		$this->reader->rows = array( array() );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'The figures could not be loaded.', $output );
		$this->assertStringNotContainsString( 'rilm-indicators-table', $output );
	}

	/**
	 * The page only reads: every query is a SELECT and no mail is sent.
	 *
	 * @return void
	 */
	public function test_page_is_read_only(): void {
		$this->load();

		$this->render( $this->page() );

		foreach ( $this->reader->queries as $query ) {
			$this->assertMatchesRegularExpression( '/^SELECT\b/', $query );
		}

		$this->assertSame( 0, did_action( 'wp_mail' ) );
	}

	/**
	 * Shares use one decimal, and the dash replaces a missing one.
	 *
	 * @return void
	 */
	public function test_percent_text(): void {
		$this->assertSame( '75.0%', Dashboard_Page::percent_text( 75.0 ) );
		$this->assertSame( '33.3%', Dashboard_Page::percent_text( 100 / 3 ) );
		$this->assertSame( '0.0%', Dashboard_Page::percent_text( 0.0 ) );
		$this->assertSame( '–', Dashboard_Page::percent_text( null ) );
	}

	/**
	 * Durations are rounded to the millisecond, and a missing one is "Not available".
	 *
	 * @return void
	 */
	public function test_duration_text(): void {
		$this->assertSame( '1,235 ms', Dashboard_Page::duration_text( 1234.5 ) );
		$this->assertSame( '0 ms', Dashboard_Page::duration_text( 0.0 ) );
		$this->assertSame( 'Not available', Dashboard_Page::duration_text( null ) );
	}

	/**
	 * Renders a chart and returns the markup.
	 *
	 * @param string[] $labels Labels.
	 * @param array    $series Series.
	 * @return string
	 */
	private function chart( array $labels, array $series ): string {
		ob_start();
		Bar_Chart::render( 'test-chart', 'Test <chart>', $labels, $series );
		return ob_get_clean();
	}

	/**
	 * A chart has one bar per day and a value in the title of each bar.
	 *
	 * @return void
	 */
	public function test_chart_draws_one_bar_per_day(): void {
		$output = $this->chart(
			array( '2026-10-01', '2026-10-02', '2026-10-03' ),
			array(
				array(
					'name'   => 'Turns',
					'values' => array( 10, 0, 5 ),
				),
			)
		);

		$this->assertSame( 2, substr_count( $output, '<rect ' ), 'A day with no value draws no bar.' );
		$this->assertStringContainsString( '<title>2026-10-01, Turns: 10</title>', $output );
		$this->assertStringContainsString( '<title>2026-10-03, Turns: 5</title>', $output );
		$this->assertStringContainsString( 'The highest value is 10.', $output );
		$this->assertStringContainsString( '2026-10-01</text>', $output );
		$this->assertStringContainsString( '2026-10-03</text>', $output );
	}

	/**
	 * The tallest bar fills the plot area and the others are proportional.
	 *
	 * @return void
	 */
	public function test_chart_heights_are_proportional(): void {
		$output = $this->chart(
			array( 'a', 'b' ),
			array(
				array(
					'name'   => 'Turns',
					'values' => array( 100, 50 ),
				),
			)
		);

		preg_match_all( '/height="([0-9.]+)"/', $output, $heights );

		$this->assertSame( array( '100', '50' ), $heights[1] );
	}

	/**
	 * Stacked series sit one on top of the other, and the stack height is their sum.
	 *
	 * @return void
	 */
	public function test_chart_stacks_series(): void {
		$output = $this->chart(
			array( 'a' ),
			array(
				array(
					'name'   => 'Input',
					'values' => array( 30 ),
				),
				array(
					'name'   => 'Output',
					'values' => array( 70 ),
				),
			)
		);

		preg_match_all( '/<rect class="rilm-bar rilm-bar-(\d)" x="[0-9.]+" y="([0-9.]+)" width="[0-9.]+" height="([0-9.]+)"/', $output, $bars, PREG_SET_ORDER );

		$this->assertCount( 2, $bars );
		$this->assertSame( '0', $bars[0][1] );
		$this->assertSame( '1', $bars[1][1] );
		$this->assertEqualsWithDelta( 100, (float) $bars[0][3] + (float) $bars[1][3], 0.01, 'The stack fills the plot area.' );
		$this->assertEqualsWithDelta( (float) $bars[1][2] + (float) $bars[1][3], (float) $bars[0][2], 0.01, 'The second series rests on the first.' );
		$this->assertStringContainsString( '</span> Input</li>', $output );
		$this->assertStringContainsString( '</span> Output</li>', $output );
	}

	/**
	 * A chart of zeros has no bars and does not divide by zero.
	 *
	 * @return void
	 */
	public function test_chart_of_zeros(): void {
		$output = $this->chart(
			array( 'a', 'b' ),
			array(
				array(
					'name'   => 'Turns',
					'values' => array( 0, 0 ),
				),
			)
		);

		$this->assertStringNotContainsString( '<rect', $output );
		$this->assertStringContainsString( 'The highest value is 0.', $output );
	}

	/**
	 * A chart without days is valid markup with no bar.
	 *
	 * @return void
	 */
	public function test_chart_without_days(): void {
		$output = $this->chart( array(), array() );

		$this->assertStringContainsString( '<svg ', $output );
		$this->assertStringNotContainsString( '<rect', $output );
	}

	/**
	 * Titles, labels and series names are escaped.
	 *
	 * @return void
	 */
	public function test_chart_escapes_text(): void {
		$output = $this->chart(
			array( '<script>x</script>' ),
			array(
				array(
					'name'   => '"><img src=x>',
					'values' => array( 3 ),
				),
			)
		);

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringNotContainsString( '<img src=x>', $output );
		$this->assertStringContainsString( 'Test &lt;chart&gt;', $output );
	}

	/**
	 * Coordinates always use a dot, whatever the locale, so the SVG stays valid.
	 *
	 * @return void
	 */
	public function test_chart_coordinates_use_a_dot(): void {
		$output = $this->chart(
			array( 'a', 'b', 'c' ),
			array(
				array(
					'name'   => 'Turns',
					'values' => array( 1, 2, 3 ),
				),
			)
		);

		$this->assertDoesNotMatchRegularExpression( '/(x|y|width|height)="[0-9]+,[0-9]+"/', $output );
		$this->assertMatchesRegularExpression( '/y="[0-9]+\.[0-9]+"/', $output, 'Fractional coordinates are present and written with a dot.' );
	}
}
