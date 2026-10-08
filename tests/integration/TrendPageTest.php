<?php
/**
 * Integration tests for the Daily trend page and the bar chart.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use RILM\Admin\Bar_Chart;
use RILM\Admin\Trend_Page;
use RILM\Config\Config;
use RILM\Repository\Interaction_Repository;
use RILM\Tests\Unit\Support\Fake_Reader;
use WP_UnitTestCase;
use WPDieException;

/**
 * Verifies the daily charts, the daily table, the bar chart and the period handling.
 */
class TrendPageTest extends WP_UnitTestCase {

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
	 * Loads the reader with the answers of a typical period: summary, then hourly rows.
	 *
	 * The page asks for none of the breakdowns (median, verdict and tool counts), so only
	 * the summary and the daily series are ever queried.
	 *
	 * @param array $summary Values replacing the default summary.
	 * @return void
	 */
	private function load( array $summary = array() ): void {
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
	 * Loads the answers of a period for a table that has the tools column.
	 *
	 * @param array $summary Values replacing the default summary (`tools` is added).
	 * @return void
	 */
	private function load_with_tools( array $summary = array() ): void {
		$this->load( $summary + array( 'tools' => '50' ) );
	}

	/**
	 * Creates the page on the fake reader.
	 *
	 * @param bool     $available Whether the log database is usable.
	 * @param string[] $columns   Optional columns present in the table.
	 * @return Trend_Page
	 */
	private function page( bool $available = true, array $columns = array() ): Trend_Page {
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

		return new Trend_Page(
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
	 * @param Trend_Page $page Page.
	 * @return string
	 */
	private function render( Trend_Page $page ): string {
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
	 * The page title names the daily trend.
	 *
	 * @return void
	 */
	public function test_page_title(): void {
		$this->load();

		$this->assertStringContainsString( '<h1>Daily trend</h1>', $this->render( $this->page() ) );
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
		$this->assertStringContainsString( '<input type="hidden" name="page" value="rilm-trend" />', $output );
		$this->assertStringNotContainsString( 'method="post"', $output );
		$this->assertStringContainsString( 'for="rilm-period"', $output );
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
		$this->assertStringNotContainsString( '<svg', $output );
		$this->assertSame( array(), $this->reader->queries );
	}

	/**
	 * A failed summary query is reported, not shown as an empty chart.
	 *
	 * @return void
	 */
	public function test_failed_summary_is_reported(): void {
		$this->reader->rows = array( array() );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'The figures could not be loaded.', $output );
		$this->assertStringNotContainsString( '<svg', $output );
	}

	/**
	 * An empty period shows no charts, just the message, and costs one query.
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

		$this->assertStringContainsString( 'No turns in the period.', $output );
		$this->assertStringNotContainsString( '<svg', $output );
		$this->assertCount( 1, $this->reader->queries, 'An empty period costs one query.' );
	}

	/**
	 * A failed daily series query is reported, so the page does not show an empty chart as if it were real.
	 *
	 * @return void
	 */
	public function test_failed_daily_series_is_reported(): void {
		$this->load( array( 'input_blocks' => '0', 'output_blocks' => '0' ) );

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

		$reader->rows = $this->reader->rows;
		$this->reader = $reader;

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'The daily figures could not be loaded.', $output );
		$this->assertStringNotContainsString( '<svg', $output );
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
		$this->reader->rows[1][0]['tools'] = '30';
		$this->reader->rows[1][1]['tools'] = '20';

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
	 * The page shows none of the breakdowns, so it never queries the median or the verdict and tool counts.
	 *
	 * @return void
	 */
	public function test_breakdowns_are_not_queried(): void {
		$this->load();

		$this->render( $this->page() );

		$this->assertCount( 2, $this->reader->queries, 'Only the summary and the daily series are queried.' );

		foreach ( $this->reader->queries as $query ) {
			$this->assertStringNotContainsString( 'GROUP BY input_verdict', $query );
			$this->assertStringNotContainsString( 'GROUP BY output_verdict', $query );
			$this->assertStringNotContainsString( 'ORDER BY duration_ms', $query );
		}
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
