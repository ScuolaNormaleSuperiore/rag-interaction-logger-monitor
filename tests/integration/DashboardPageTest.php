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
	 * @return Dashboard_Page
	 */
	private function page( bool $available = true ): Dashboard_Page {
		$repository = $available ? new Interaction_Repository(
			$this->reader,
			new Config(
				array(
					'host' => 'db.example.test',
					'user' => 'reader',
				),
				array( 'password' => 'secret' )
			)
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
		$this->assertMatchesRegularExpression( '/<th scope="row">Guardrails did not run<\/th><td><a[^>]*>10[^<]*<span[^>]*>[^<]*<\/span><\/a><\/td><td>5\.0% of all turns<\/td>/', $output );
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

		$this->assertMatchesRegularExpression( '/Guardrails did not run<\/th><td><a[^>]*>0[^<]*<span[^>]*>[^<]*<\/span><\/a><\/td><td>0\.0% of all turns<\/td>/', $output );
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
