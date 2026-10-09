<?php
/**
 * Integration tests for the Anomalies page.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use RILM\Admin\Anomalies_Page;
use RILM\Config\Config;
use RILM\Repository\Anomalies;
use RILM\Repository\Filters;
use RILM\Repository\Interaction_Repository;
use RILM\Tests\Unit\Support\Fake_Reader;
use WP_UnitTestCase;
use WPDieException;

/**
 * Verifies the counts, the links to the filtered list and the period handling.
 */
class AnomaliesPageTest extends WP_UnitTestCase {

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
	 * The row of counts the database would return.
	 *
	 * @return array
	 */
	private function counts_row(): array {
		return array(
			'total'          => '1234',
			'incomplete'     => '12',
			'no_guardrails'  => '0',
			'input_blocks'   => '3',
			'output_blocks'  => '4',
			'answers_differ' => '5',
			'zero_recall'    => '6',
		);
	}

	/**
	 * Creates the page on the fake reader.
	 *
	 * @param bool $available Whether the log database is usable.
	 * @param string[] $columns   Optional columns present in the table.
	 * @return Anomalies_Page
	 */
	private function page( bool $available = true, array $columns = array() ): Anomalies_Page {
		$repository = $available ? new Interaction_Repository(
			$this->reader,
			new Config(
				array( 'host' => 'db.example.test' ),
				array(
					'user'     => 'reader',
					'password' => 'secret',
				)
			),
			$columns
		) : null;

		return new Anomalies_Page(
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
	 * @param Anomalies_Page $page Page.
	 * @return string
	 */
	private function render( Anomalies_Page $page ): string {
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
	 * Extracts the interactions list links of the page, as query argument arrays keyed by the anomaly label.
	 *
	 * @param string $output Page output.
	 * @return array<string, array<string, string>>
	 */
	private function links( string $output ): array {
		preg_match_all( '/<th scope="row">([^<]+)<br \/>.*?<a href="([^"]+)">/s', $output, $matches, PREG_SET_ORDER );

		$links = array();

		foreach ( $matches as $match ) {
			$query = wp_parse_url( html_entity_decode( $match[2] ), PHP_URL_QUERY );
			parse_str( (string) $query, $args );
			$links[ html_entity_decode( $match[1] ) ] = $args;
		}

		return $links;
	}

	/**
	 * With the tools column, the page has nine rows: the six usual ones and the three about tools.
	 *
	 * @return void
	 */
	public function test_tools_anomalies_are_shown_with_the_column(): void {
		$this->reader->rows = array(
			array(
				$this->counts_row() + array(
					'tools_incomplete'     => '7',
					'tools_no_guardrails'  => '8',
					'tools_output_blocked' => '9',
				),
			),
		);

		$output = $this->render( $this->page( true, array( 'tools_used' ) ) );

		$this->assertSame( 9, substr_count( $output, 'View interactions<span' ) );
		$this->assertMatchesRegularExpression( '/Tools ran but the turn is incomplete.*?<td>7<\/td>/s', $output );
		$this->assertMatchesRegularExpression( '/Tools ran without Guardrails.*?<td>8<\/td>/s', $output );
		$this->assertMatchesRegularExpression( '/Tools ran and the output was blocked.*?<td>9<\/td>/s', $output );
		$this->assertStringContainsString( 'no check at all', $output );
		$this->assertCount( 1, $this->reader->queries );
	}

	/**
	 * Each tools link reopens the filters that were counted: the tools filter plus the one of its meaning.
	 *
	 * @return void
	 */
	public function test_tools_links_carry_their_filters(): void {
		$this->reader->rows = array( array( $this->counts_row() ) );

		$links = $this->links( $this->render( $this->page( true, array( 'tools_used' ) ) ) );

		$this->assertSame( 'yes', $links['Tools ran but the turn is incomplete']['tools'] );
		$this->assertSame( 'incomplete', $links['Tools ran but the turn is incomplete']['outcome'] );
		$this->assertSame( 'yes', $links['Tools ran without Guardrails']['tools'] );
		$this->assertSame( 'absent', $links['Tools ran without Guardrails']['guard'] );
		$this->assertSame( 'yes', $links['Tools ran and the output was blocked']['tools'] );
		$this->assertSame( Filters::VERDICT_ANY, $links['Tools ran and the output was blocked']['output_verdict'] );
	}

	/**
	 * Without the column the page keeps its six rows and the query never mentions the column.
	 *
	 * @return void
	 */
	public function test_six_anomalies_without_the_column(): void {
		$this->reader->rows = array( array( $this->counts_row() ) );

		$output = $this->render( $this->page() );

		$this->assertSame( 6, substr_count( $output, 'View interactions<span' ) );
		$this->assertStringNotContainsString( 'Tools ran', $output );
		$this->assertStringNotContainsString( 'tools_', $this->reader->queries[0] );
	}

	/**
	 * Each anomaly has a row with its count.
	 *
	 * @return void
	 */
	public function test_shows_the_six_anomalies_with_their_counts(): void {
		$this->reader->rows = array( array( $this->counts_row() ) );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<h1>Anomalies</h1>', $output );
		$this->assertStringContainsString( '1,234 interactions in the period.', $output );

		foreach ( Anomalies_Page::descriptions() as $text ) {
			$this->assertStringContainsString( $text['label'], $output );
			$this->assertStringContainsString( $text['description'], $output );
		}

		$this->assertMatchesRegularExpression( '/Incomplete interactions.*?<td>12<\/td>/s', $output );
		$this->assertMatchesRegularExpression( '/Guardrails did not handle the turn.*?<td>0<\/td>/s', $output );
		$this->assertMatchesRegularExpression( '/Input blocked.*?<td>3<\/td>/s', $output );
		$this->assertMatchesRegularExpression( '/Output blocked.*?<td>4<\/td>/s', $output );
		$this->assertMatchesRegularExpression( '/Answer changed without an output verdict.*?<td>5<\/td>/s', $output );
		$this->assertMatchesRegularExpression( '/Generated without recalled sources.*?<td>6<\/td>/s', $output );
	}

	/**
	 * A single query provides all the counts.
	 *
	 * @return void
	 */
	public function test_uses_one_query(): void {
		$this->reader->rows = array( array( $this->counts_row() ) );

		$this->render( $this->page() );

		$this->assertCount( 1, $this->reader->queries );
		$this->assertStringStartsWith( 'SELECT COUNT(*) AS `total`', $this->reader->queries[0] );
	}

	/**
	 * The default period is today.
	 *
	 * @return void
	 */
	public function test_default_period_is_today(): void {
		$this->reader->rows = array( array( $this->counts_row() ) );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( "ts >= '2026-10-01 22:00:00.000' AND ts <= '2026-10-02 12:30:00.000'", $this->reader->queries[0] );
		$this->assertMatchesRegularExpression( '/<option value="today" selected=\'selected\'>/', $output );
		$this->assertStringContainsString( 'Europe/Rome', $output );
	}

	/**
	 * Today is still a choice and starts at local midnight.
	 *
	 * @return void
	 */
	public function test_today_can_still_be_chosen(): void {
		$_GET               = array( 'period' => 'today' );
		$this->reader->rows = array( array( $this->counts_row() ) );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( "ts >= '2026-10-01 22:00:00.000' AND ts <= '2026-10-02 12:30:00.000'", $this->reader->queries[0] );
		$this->assertMatchesRegularExpression( '/<option value="today" selected=\'selected\'>/', $output );
		$this->assertMatchesRegularExpression( '/<option value="today"/', $output );
	}

	/**
	 * Links built on a non-default period keep it, instead of falling back to the default.
	 *
	 * @return void
	 */
	public function test_links_keep_a_non_default_period(): void {
		$_GET               = array( 'period' => 'week' );
		$this->reader->rows = array( array( $this->counts_row() ) );

		foreach ( $this->links( $this->render( $this->page() ) ) as $label => $args ) {
			$this->assertSame( 'week', $args['period'], $label );
		}
	}

	/**
	 * Links built on the default period leave it out: the list applies the same default.
	 *
	 * @return void
	 */
	public function test_links_on_the_default_period_do_not_repeat_it(): void {
		$this->reader->rows = array( array( $this->counts_row() ) );

		foreach ( $this->links( $this->render( $this->page() ) ) as $label => $args ) {
			$this->assertArrayNotHasKey( 'period', $args, $label );
		}
	}

	/**
	 * The chosen period applies to the counts.
	 *
	 * @return void
	 */
	public function test_chosen_period_applies_to_the_counts(): void {
		$_GET               = array( 'period' => 'month' );
		$this->reader->rows = array( array( $this->counts_row() ) );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( "ts >= '2026-09-02 12:30:00.000'", $this->reader->queries[0] );
		$this->assertMatchesRegularExpression( '/<option value="month" selected=\'selected\'>/', $output );
	}

	/**
	 * A custom period is read in local time and converted to UTC.
	 *
	 * @return void
	 */
	public function test_custom_period_is_converted_to_utc(): void {
		$_GET = array(
			'period' => 'custom',
			'from'   => '2026-09-01T00:00',
			'to'     => '2026-09-30T23:59',
		);

		$this->reader->rows = array( array( $this->counts_row() ) );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( "ts >= '2026-08-31 22:00:00.000' AND ts <= '2026-09-30 21:59:59.999'", $this->reader->queries[0] );
		$this->assertStringContainsString( 'value="2026-09-01T00:00"', $output );
		$this->assertStringContainsString( 'value="2026-09-30T23:59"', $output );
	}

	/**
	 * An invalid period falls back to the default and the user is told which one is shown.
	 *
	 * @return void
	 */
	public function test_invalid_period_is_reported(): void {
		$_GET               = array( 'period' => 'fortnight' );
		$this->reader->rows = array( array( $this->counts_row() ) );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'The period was ignored because its value is not valid: the default period (Today) is shown.', $output );
		$this->assertStringContainsString( "ts >= '2026-10-01 22:00:00.000'", $this->reader->queries[0] );
		$this->assertStringNotContainsString( 'fortnight', $this->reader->queries[0] );
	}

	/**
	 * Each link opens the interactions list with the filters of its anomaly.
	 *
	 * @return void
	 */
	public function test_each_link_carries_the_filters_of_its_anomaly(): void {
		$this->reader->rows = array( array( $this->counts_row() ) );

		$links = $this->links( $this->render( $this->page() ) );

		$this->assertCount( 6, $links );

		foreach ( $links as $label => $args ) {
			$this->assertSame( 'rilm-interactions', $args['page'], $label );
		}

		$this->assertSame( array( 'page' => 'rilm-interactions', 'outcome' => 'incomplete' ), $links['Incomplete interactions'] );
		$this->assertSame( array( 'page' => 'rilm-interactions', 'guard' => 'absent' ), $links['Guardrails did not handle the turn'] );
		$this->assertSame( array( 'page' => 'rilm-interactions', 'input_verdict' => Filters::VERDICT_ANY ), $links['Input blocked'] );
		$this->assertSame( array( 'page' => 'rilm-interactions', 'output_verdict' => Filters::VERDICT_ANY ), $links['Output blocked'] );
		$this->assertSame(
			array(
				'page'           => 'rilm-interactions',
				'outcome'        => 'generated',
				'output_verdict' => Filters::VERDICT_NONE,
				'answers'        => 'differ',
			),
			$links['Answer changed without an output verdict']
		);
		$this->assertSame(
			array(
				'page'    => 'rilm-interactions',
				'outcome' => 'generated',
				'recall'  => 'empty',
			),
			$links['Generated without recalled sources']
		);
	}

	/**
	 * The chosen period is carried into every link.
	 *
	 * @return void
	 */
	public function test_links_carry_the_chosen_period(): void {
		$_GET = array(
			'period' => 'custom',
			'from'   => '2026-09-01T00:00',
			'to'     => '2026-09-30T23:59',
		);

		$this->reader->rows = array( array( $this->counts_row() ) );

		foreach ( $this->links( $this->render( $this->page() ) ) as $label => $args ) {
			$this->assertSame( 'custom', $args['period'], $label );
			$this->assertSame( '2026-09-01T00:00', $args['from'], $label );
			$this->assertSame( '2026-09-30T23:59', $args['to'], $label );
		}
	}

	/**
	 * A link, followed, gives back the filters of the anomaly: the list shows what the count counted.
	 *
	 * @return void
	 */
	public function test_links_reopen_the_same_filters(): void {
		$this->reader->rows = array( array( $this->counts_row() ) );
		$now                = new DateTimeImmutable( '2026-10-02 14:30:00', new DateTimeZone( 'Europe/Rome' ) );

		foreach ( $this->links( $this->render( $this->page() ) ) as $label => $args ) {
			unset( $args['page'] );

			$from_link = Filters::from_array( $args, $now );

			$this->assertSame( array(), $from_link->errors(), $label );
		}

		foreach ( Anomalies::definitions() as $key => $input ) {
			$this->assertSame(
				Filters::from_array( $input, $now )->to_query_args(),
				Filters::from_array( Filters::from_array( $input, $now )->to_query_args(), $now )->to_query_args(),
				$key
			);
		}
	}

	/**
	 * Links never carry a search text, and the page has no search or POST form.
	 *
	 * @return void
	 */
	public function test_no_search_and_no_post_form(): void {
		$_GET = array( 'search' => 'secret words' );

		$this->reader->rows = array( array( $this->counts_row() ) );

		$output = $this->render( $this->page() );

		$this->assertStringNotContainsString( 'secret', $output );
		$this->assertStringNotContainsString( 'secret', $this->reader->queries[0] );
		$this->assertStringNotContainsString( 'method="post"', $output );
		$this->assertStringNotContainsString( 'name="search"', $output );
	}

	/**
	 * The period form is a GET form that keeps the page.
	 *
	 * @return void
	 */
	public function test_period_form_is_get(): void {
		$this->reader->rows = array( array( $this->counts_row() ) );

		$output = $this->render( $this->page() );

		$this->assertMatchesRegularExpression( '/<form method="get" action="[^"]*admin\.php">/', $output );
		$this->assertStringContainsString( '<input type="hidden" name="page" value="rilm-anomalies" />', $output );
		$this->assertStringContainsString( 'for="rilm-period"', $output );
		$this->assertStringContainsString( 'for="rilm-from"', $output );
		$this->assertStringContainsString( 'for="rilm-to"', $output );
	}

	/**
	 * Each link has a hidden label that names its anomaly.
	 *
	 * @return void
	 */
	public function test_links_have_descriptive_labels(): void {
		$this->reader->rows = array( array( $this->counts_row() ) );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'View interactions<span class="screen-reader-text"> (Incomplete interactions)</span>', $output );
		$this->assertStringContainsString( 'View interactions<span class="screen-reader-text"> (Output blocked)</span>', $output );
	}

	/**
	 * Users without manage_options are refused and nothing is queried.
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
	 * Without a usable database the page says so and shows no table.
	 *
	 * @return void
	 */
	public function test_unavailable_database_shows_a_message(): void {
		$output = $this->render( $this->page( false ) );

		$this->assertStringContainsString( 'The interaction log is not available', $output );
		$this->assertStringNotContainsString( '<table', $output );
		$this->assertSame( array(), $this->reader->queries );
	}

	/**
	 * A failed counting query is reported, not shown as zeros.
	 *
	 * @return void
	 */
	public function test_failed_counts_are_reported(): void {
		$this->reader->rows = array( array() );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'The counts could not be loaded.', $output );
		$this->assertStringNotContainsString( '<table class="widefat striped rilm-anomalies-table">', $output );
	}

	/**
	 * Counts of zero are shown as zero.
	 *
	 * @return void
	 */
	public function test_empty_period_shows_zeros(): void {
		$this->reader->rows = array( array( array( 'total' => '0' ) ) );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '0 interactions in the period.', $output );
		$this->assertSame( 6, substr_count( $output, '<td>0</td>' ) );
	}

	/**
	 * The page issues only a SELECT and sends no notification.
	 *
	 * @return void
	 */
	public function test_page_is_read_only(): void {
		$this->reader->rows = array( array( $this->counts_row() ) );

		$this->render( $this->page() );

		foreach ( $this->reader->queries as $query ) {
			$this->assertMatchesRegularExpression( '/^SELECT\b/', $query );
		}

		$this->assertSame( 0, did_action( 'wp_mail' ) );
	}
}
