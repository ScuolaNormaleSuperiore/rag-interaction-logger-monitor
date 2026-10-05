<?php
/**
 * Integration tests for the Interactions page.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use RILM\Admin\Interactions_Page;
use RILM\Config\Config;
use RILM\Repository\Interaction_Repository;
use RILM\Tests\Unit\Support\Fake_Reader;
use WP_UnitTestCase;
use WPDieException;

/**
 * Verifies the filter form, the search handling, the table output and its escaping.
 */
class InteractionsPageTest extends WP_UnitTestCase {

	/**
	 * Double that records the queries.
	 *
	 * @var Fake_Reader
	 */
	private $reader;

	/**
	 * Sets up an administrator, the screen and a repository on a fake reader.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		update_option( 'timezone_string', 'Europe/Rome' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'toplevel_page_rilm-dashboard' );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'example.org';
		$_SERVER['REQUEST_URI']    = '/wp-admin/admin.php?page=rilm-interactions';

		$this->reader      = new Fake_Reader();
		$this->reader->var = '45';
		$this->reader->col = array( 'site-a', 'site-b' );
	}

	/**
	 * Restores the request globals.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_GET                      = array();
		$_POST                     = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		delete_option( 'timezone_string' );

		parent::tear_down();
	}

	/**
	 * A row as the driver returns it.
	 *
	 * @param array $overrides Values replacing the defaults.
	 * @return array
	 */
	private function row( array $overrides = array() ): array {
		return $overrides + array(
			'id'                 => '7',
			'ts'                 => '2026-10-02 12:30:45.123',
			'duration_ms'        => '1500',
			'instance'           => 'site-a',
			'user_id'            => '42',
			'turn_id'            => 'abc',
			'outcome'            => 'generated',
			'question'           => 'What is the opening time?',
			'delivered'          => 'From 9 to 5.',
			'guard_present'      => '1',
			'input_verdict'      => null,
			'output_verdict'     => null,
			'other_plugin_reply' => null,
			'recall_count'       => '2',
			'recall_top_score'   => '0.5',
		);
	}

	/**
	 * Creates a page on the fake reader, recording redirects instead of exiting.
	 *
	 * @param bool $available Whether the log database is usable.
	 * @return Interactions_Page
	 */
	private function page( bool $available = true ): Interactions_Page {
		$repository = $available ? new Interaction_Repository(
			$this->reader,
			new Config(
				array( 'host' => 'db.example.test' ),
				array(
					'user'     => 'reader',
					'password' => 'secret',
				)
			)
		) : null;

		return new class( null, static function () use ( $repository ) {
			return $repository;
		}, static function (): DateTimeImmutable {
			return new DateTimeImmutable( '2026-10-02 14:30:00', new DateTimeZone( 'Europe/Rome' ) );
		} ) extends Interactions_Page {
			/**
			 * URL the page tried to redirect to.
			 *
			 * @var string|null
			 */
			public $redirected = null;

			/**
			 * Records the target instead of redirecting.
			 *
			 * @param string $url Target URL.
			 * @return void
			 */
			protected function redirect( string $url ): void {
				$this->redirected = $url;
			}
		};
	}

	/**
	 * Renders a page and returns the output; output buffers are closed even when the page dies.
	 *
	 * @param Interactions_Page $page Page.
	 * @return string
	 */
	private function render( Interactions_Page $page ): string {
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
	 * Simulates a POST of the filter form with a valid nonce.
	 *
	 * @param array $fields Form fields.
	 * @return void
	 */
	private function post( array $fields ): void {
		$nonce = wp_create_nonce( Interactions_Page::NONCE_ACTION );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = $fields + array( Interactions_Page::NONCE_FIELD => $nonce );
		$_REQUEST                  = $_POST;
	}

	/**
	 * The table shows the rows with local dates, texts as text and NULL as "None".
	 *
	 * @return void
	 */
	public function test_get_request_shows_the_rows(): void {
		$this->reader->rows = array( array( $this->row() ) );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<h1>Interactions</h1>', $output );
		$this->assertStringContainsString( '2026-10-02 14:30:45', $output, 'The UTC timestamp is shown in the site time zone.' );
		$this->assertStringContainsString( 'What is the opening time?', $output );
		$this->assertStringContainsString( 'From 9 to 5.', $output );
		$this->assertStringContainsString( 'Generated', $output );
		$this->assertStringContainsString( 'site-a', $output );
		$this->assertStringContainsString( '1,500 ms', $output );
		$this->assertStringContainsString( 'Present', $output );
		$this->assertStringContainsString( '>None<', $output );
		$this->assertStringContainsString( 'Europe/Rome', $output, 'The time zone is declared.' );
	}

	/**
	 * Without a search, nothing about it reaches the queries.
	 *
	 * @return void
	 */
	public function test_get_request_does_not_search(): void {
		$_GET['search'] = 'secret words';

		$this->render( $this->page() );

		foreach ( $this->reader->queries as $query ) {
			$this->assertStringNotContainsString( 'LIKE', $query );
			$this->assertStringNotContainsString( 'secret', $query );
		}
	}

	/**
	 * GET filters reach the query and the form shows them selected.
	 *
	 * @return void
	 */
	public function test_get_filters_are_applied(): void {
		$_GET = array(
			'outcome'  => 'incomplete',
			'guard'    => 'absent',
			'instance' => 'site-b',
		);

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( "outcome = 'incomplete'", $this->reader->queries[0] );
		$this->assertStringContainsString( 'guard_present = 0', $this->reader->queries[0] );
		$this->assertStringContainsString( "instance = 'site-b'", $this->reader->queries[0] );
		$this->assertMatchesRegularExpression( '/<option value="incomplete" selected=\'selected\'>/', $output );
		$this->assertMatchesRegularExpression( '/<option value="site-b" selected=\'selected\'>/', $output );
	}

	/**
	 * The "answers differ" filter is a checkbox of the form and reaches the query.
	 *
	 * @return void
	 */
	public function test_answers_differ_filter_is_in_the_form_and_applied(): void {
		$_GET = array( 'answers' => 'differ' );

		$output = $this->render( $this->page() );

		$this->assertMatchesRegularExpression( '/<input type="checkbox" id="rilm-answers" name="answers" value="differ" checked=\'checked\' \/>/', $output );
		$this->assertStringContainsString( 'for="rilm-answers"', $output );
		$this->assertStringContainsString( 'Only interactions where the generated and delivered answers differ', $output );
		$this->assertStringContainsString( 'NOT ( llm_answer <=> delivered )', $this->reader->queries[0] );
	}

	/**
	 * Without the filter the checkbox is unchecked and the condition is absent.
	 *
	 * @return void
	 */
	public function test_answers_differ_filter_is_off_by_default(): void {
		$output = $this->render( $this->page() );

		$this->assertMatchesRegularExpression( '/<input type="checkbox" id="rilm-answers" name="answers" value="differ" \/>/', $output );
		$this->assertStringNotContainsString( '<=>', $this->reader->queries[0] );
	}

	/**
	 * A POST that keeps the filter redirects to a URL that still carries it.
	 *
	 * @return void
	 */
	public function test_redirect_keeps_the_answers_filter(): void {
		$this->post(
			array(
				'answers' => 'differ',
				'search'  => '',
			)
		);

		$page = $this->page();
		$page->handle_request();

		$this->assertStringContainsString( 'answers=differ', (string) $page->redirected );
	}

	/**
	 * Without a chosen period the list shows the last week.
	 *
	 * @return void
	 */
	public function test_default_period_is_last_week(): void {
		$output = $this->render( $this->page() );

		$this->assertStringContainsString( "ts >= '2026-09-25 12:30:00.000' AND ts <= '2026-10-02 12:30:00.000'", $this->reader->queries[0] );
		$this->assertMatchesRegularExpression( '/<option value="week" selected=\'selected\'>/', $output );
	}

	/**
	 * Today is still in the period select, and choosing it starts at local midnight.
	 *
	 * @return void
	 */
	public function test_today_can_still_be_chosen(): void {
		$_GET = array( 'period' => 'today' );

		$output = $this->render( $this->page() );

		$this->assertMatchesRegularExpression( '/<option value="today" selected=\'selected\'>Today<\/option>/', $output );
		$this->assertStringContainsString( "ts >= '2026-10-01 22:00:00.000' AND ts <= '2026-10-02 12:30:00.000'", $this->reader->queries[0] );
	}

	/**
	 * The select offers every period, whichever is the default.
	 *
	 * @return void
	 */
	public function test_period_select_offers_every_period(): void {
		$output = $this->render( $this->page() );

		foreach ( array( 'today', 'week', 'month', '3months', '6months', 'year', 'custom' ) as $value ) {
			$this->assertStringContainsString( '<option value="' . $value . '"', $output, $value );
		}
	}

	/**
	 * The redirect for an empty search keeps "today", which is not the default, and omits the default period.
	 *
	 * @return void
	 */
	public function test_redirect_keeps_today_and_omits_the_default_period(): void {
		$this->post(
			array(
				'period' => 'today',
				'search' => '',
			)
		);

		$page = $this->page();
		$page->handle_request();

		$this->assertStringContainsString( 'period=today', (string) $page->redirected );

		$this->post(
			array(
				'period' => 'week',
				'search' => '',
			)
		);

		$page = $this->page();
		$page->handle_request();

		$this->assertStringNotContainsString( 'period=', (string) $page->redirected );
	}

	/**
	 * Splits a rendered page into what comes before, inside and after the advanced section.
	 *
	 * @param string $output Page output.
	 * @return array{0: string, 1: string, 2: string}
	 */
	private function split_advanced( string $output ): array {
		$start = strpos( $output, '<details class="rilm-advanced"' );
		$this->assertNotFalse( $start, 'The advanced section is missing.' );

		$end = strpos( $output, '</details>', (int) $start );
		$this->assertNotFalse( $end, 'The advanced section is not closed.' );

		return array(
			substr( $output, 0, (int) $start ),
			substr( $output, (int) $start, (int) $end - (int) $start ),
			substr( $output, (int) $end ),
		);
	}

	/**
	 * Period, search, Guardrails and the input verdict stay outside the advanced section.
	 *
	 * @return void
	 */
	public function test_main_filters_are_always_visible(): void {
		list( $before, $inside, $after ) = $this->split_advanced( $this->render( $this->page() ) );

		foreach ( array( 'period', 'from', 'to', 'search', 'guard', 'input_verdict' ) as $name ) {
			$this->assertStringContainsString( 'id="rilm-' . $name . '"', $before, $name );
			$this->assertStringNotContainsString( 'id="rilm-' . $name . '"', $inside, $name );
			$this->assertStringNotContainsString( 'id="rilm-' . $name . '"', $after, $name );
		}
	}

	/**
	 * Every other control is inside the advanced section.
	 *
	 * @return void
	 */
	public function test_other_controls_are_in_the_advanced_section(): void {
		list( $before, $inside, $after ) = $this->split_advanced( $this->render( $this->page() ) );

		foreach ( array( 'outcome', 'instance', 'user_id', 'output_verdict', 'other_reply', 'recall', 'answers', 'orderby', 'order', 'per_page' ) as $name ) {
			$this->assertStringContainsString( 'id="rilm-' . $name . '"', $inside, $name );
			$this->assertStringNotContainsString( 'id="rilm-' . $name . '"', $before, $name );
			$this->assertStringNotContainsString( 'id="rilm-' . $name . '"', $after, $name );
		}
	}

	/**
	 * The section is closed, and its title shows no count, when nothing in it is active.
	 *
	 * @return void
	 */
	public function test_advanced_section_is_closed_by_default(): void {
		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<details class="rilm-advanced">', $output );
		$this->assertStringContainsString( '<summary>Advanced filters</summary>', $output );
	}

	/**
	 * Any active advanced filter opens the section and is counted in its title.
	 *
	 * @dataProvider provide_advanced_request_filters
	 *
	 * @param array $query Query arguments holding one advanced filter.
	 * @return void
	 */
	public function test_active_advanced_filter_opens_the_section( array $query ): void {
		$_GET = $query;

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<details class="rilm-advanced" open>', $output );
		$this->assertStringContainsString( '<summary>Advanced filters (1 active)</summary>', $output );
	}

	/**
	 * Provides one advanced filter at a time.
	 *
	 * @return array<string, array{array}>
	 */
	public static function provide_advanced_request_filters(): array {
		return array(
			'outcome'        => array( array( 'outcome' => 'generated' ) ),
			'instance'       => array( array( 'instance' => 'site-a' ) ),
			'user'           => array( array( 'user_id' => '42' ) ),
			'output verdict' => array( array( 'output_verdict' => '__any__' ) ),
			'other reply'    => array( array( 'other_reply' => 'yes' ) ),
			'empty recall'   => array( array( 'recall' => 'empty' ) ),
			'answers differ' => array( array( 'answers' => 'differ' ) ),
		);
	}

	/**
	 * A sort order or page size that is not the default opens the section too, without being counted.
	 *
	 * @dataProvider provide_view_settings
	 *
	 * @param array $query Query arguments holding one view setting.
	 * @return void
	 */
	public function test_custom_view_opens_the_section_without_a_count( array $query ): void {
		$_GET = $query;

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<details class="rilm-advanced" open>', $output );
		$this->assertStringContainsString( '<summary>Advanced filters</summary>', $output );
	}

	/**
	 * Provides view settings that differ from the defaults.
	 *
	 * @return array<string, array{array}>
	 */
	public static function provide_view_settings(): array {
		return array(
			'sort by'  => array( array( 'orderby' => 'duration_ms' ) ),
			'order'    => array( array( 'order' => 'ASC' ) ),
			'per page' => array( array( 'per_page' => '50' ) ),
		);
	}

	/**
	 * The title counts every active advanced filter.
	 *
	 * @return void
	 */
	public function test_title_counts_every_active_filter(): void {
		$_GET = array(
			'outcome' => 'incomplete',
			'user_id' => '42',
			'recall'  => 'empty',
			'answers' => 'differ',
		);

		$this->assertStringContainsString( '<summary>Advanced filters (4 active)</summary>', $this->render( $this->page() ) );
	}

	/**
	 * Filters that are always visible neither open the section nor count in its title.
	 *
	 * @return void
	 */
	public function test_main_filters_do_not_open_the_section(): void {
		$_GET = array(
			'period'        => 'week',
			'guard'         => 'absent',
			'input_verdict' => '__any__',
		);

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<details class="rilm-advanced">', $output );
		$this->assertStringContainsString( '<summary>Advanced filters</summary>', $output );
	}

	/**
	 * With a search active the page has the same layout and still opens the section.
	 *
	 * @return void
	 */
	public function test_search_mode_has_the_same_layout(): void {
		$this->post(
			array(
				'search'  => 'opening',
				'outcome' => 'incomplete',
			)
		);

		list( $before, $inside ) = $this->split_advanced( $this->render( $this->page() ) );

		$this->assertStringContainsString( 'id="rilm-search"', $before );
		$this->assertStringContainsString( '<details class="rilm-advanced" open>', $before . $inside );
		$this->assertStringContainsString( '<summary>Advanced filters (1 active)</summary>', $inside );
	}

	/**
	 * The advanced fields belong to the form, so a closed section still submits them.
	 *
	 * @return void
	 */
	public function test_advanced_section_is_inside_the_form(): void {
		$output = $this->render( $this->page() );

		$form_start = strpos( $output, '<form method="post"' );
		$details    = strpos( $output, '<details class="rilm-advanced"' );
		$details_end = strpos( $output, '</details>', (int) $details );
		$form_end   = strpos( $output, '</form>', (int) $form_start );

		$this->assertTrue( $form_start < $details && $details_end < $form_end );
		$this->assertSame( 1, substr_count( $output, '<form method="post"' ), 'There is a single form.' );
	}

	/**
	 * Apply and Reset come after the advanced section and before the table, and Apply is still the first submit button.
	 *
	 * @return void
	 */
	public function test_buttons_follow_the_advanced_section(): void {
		$this->reader->rows = array( array( $this->row() ) );

		$output = $this->render( $this->page() );

		$details_end = strpos( $output, '</details>', (int) strpos( $output, '<details class="rilm-advanced"' ) );
		$apply       = strpos( $output, 'name="rilm_apply"' );
		$reset       = strpos( $output, '>Reset</a>' );
		$table       = strpos( $output, 'class="wp-list-table' );
		$first       = strpos( $output, 'type="submit"' );

		$this->assertTrue( $details_end < $apply && $apply < $reset && $reset < $table );
		$this->assertStringContainsString( 'name="rilm_apply"', substr( $output, (int) $first, 60 ), 'The first submit button is Apply, so Enter applies the filters.' );
	}

	/**
	 * The period select names the two custom date fields it controls.
	 *
	 * @return void
	 */
	public function test_period_select_controls_the_custom_dates(): void {
		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'name="period" aria-controls="rilm-from rilm-to"', $output );
		$this->assertSame( 2, substr_count( $output, 'data-rilm-custom-date' ) );
	}

	/**
	 * The server never hides the custom dates: without the script they must stay usable.
	 *
	 * @return void
	 */
	public function test_custom_dates_are_visible_without_the_script(): void {
		$output = $this->render( $this->page() );

		$this->assertDoesNotMatchRegularExpression( '/data-rilm-custom-date[^>]*\bhidden\b/', $output );
		$this->assertDoesNotMatchRegularExpression( '/<p[^>]*\bhidden\b[^>]*>\s*<label for="rilm-(from|to)"/', $output );
	}

	/**
	 * Invalid filter values are ignored and the user is told.
	 *
	 * @return void
	 */
	public function test_invalid_filters_are_reported(): void {
		$_GET = array(
			'outcome' => 'deleted',
			'guard'   => 'maybe',
		);

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'These filters were ignored', $output );
		$this->assertStringContainsString( 'Outcome, Guardrails', $output );
		$this->assertStringNotContainsString( 'deleted', $this->reader->queries[0] );
	}

	/**
	 * Every control has a visible label tied to it.
	 *
	 * @return void
	 */
	public function test_controls_have_labels(): void {
		$output = $this->render( $this->page() );

		foreach ( array( 'period', 'from', 'to', 'outcome', 'instance', 'user_id', 'guard', 'input_verdict', 'output_verdict', 'other_reply', 'recall', 'search', 'orderby', 'order', 'per_page' ) as $name ) {
			$this->assertStringContainsString( 'for="rilm-' . $name . '"', $output, $name );
			$this->assertStringContainsString( 'id="rilm-' . $name . '"', $output, $name );
		}
	}

	/**
	 * The apply button is the first submit control, so Enter applies the filters.
	 *
	 * @return void
	 */
	public function test_apply_is_the_first_submit_button(): void {
		$this->reader->rows = array( array( $this->row() ) );

		$output = $this->render( $this->page() );

		$apply = strpos( $output, 'name="rilm_apply"' );
		$paged = strpos( $output, 'name="paged"' );

		$this->assertNotFalse( $apply );
		$this->assertTrue( false === $paged || $apply < $paged );
	}

	/**
	 * The form posts to the page, protected by a nonce.
	 *
	 * @return void
	 */
	public function test_form_is_a_nonce_protected_post(): void {
		$output = $this->render( $this->page() );

		$this->assertMatchesRegularExpression( '/<form method="post" action="[^"]*page=rilm-interactions"/', $output );
		$this->assertStringContainsString( 'name="' . Interactions_Page::NONCE_FIELD . '"', $output );
		$this->assertDoesNotMatchRegularExpression( '/<form method="get"/', $output );
	}

	/**
	 * The list offers standard sortable headers and pagination links while no search is active.
	 *
	 * @return void
	 */
	public function test_without_search_headers_and_pagination_are_links(): void {
		$this->reader->rows = array( array( $this->row() ) );

		$output = $this->render( $this->page() );

		$this->assertMatchesRegularExpression( '/<a href="[^"]*orderby=ts[^"]*"/', $output );
		$this->assertMatchesRegularExpression( '/<a class="next-page button" href="[^"]*paged=2/', $output );
		$this->assertStringContainsString( 'Page 1 of 3', $output );
	}

	/**
	 * The "jump to page" box of WordPress is not used, so no stray page number is sent with the apply button.
	 *
	 * @return void
	 */
	public function test_no_page_number_field_is_sent_with_the_form(): void {
		$this->reader->rows = array( array( $this->row() ) );

		$output = $this->render( $this->page() );

		$this->assertStringNotContainsString( "name='paged'", $output );
		$this->assertStringNotContainsString( 'name="paged"', $output );
		$this->assertStringNotContainsString( 'current-page-selector', $output );
	}

	/**
	 * Pagination links keep the non-sensitive filters and the page 1 link has no page argument.
	 *
	 * @return void
	 */
	public function test_pagination_links_keep_the_filters(): void {
		$_GET = array(
			'outcome' => 'generated',
			'paged'   => '2',
		);

		$this->reader->rows = array( array( $this->row() ) );

		$output = $this->render( $this->page() );

		$this->assertMatchesRegularExpression( '/<a class="next-page button" href="[^"]*outcome=generated[^"]*paged=3/', $output );
		$this->assertMatchesRegularExpression( '/<a class="first-page button" href="[^"?]*\?page=rilm-interactions(&#038;outcome=generated)?"/', $output );
		$this->assertStringContainsString( 'Page 2 of 3', $output );
	}

	/**
	 * A POST without a valid nonce stops before anything is queried.
	 *
	 * @dataProvider provide_bad_nonces
	 *
	 * @param array $fields Posted fields.
	 * @return void
	 */
	public function test_post_with_bad_nonce_is_refused_without_querying( array $fields ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = $fields;
		$_REQUEST                  = $fields;

		try {
			$this->render( $this->page() );
			$this->fail( 'The page must refuse the request.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( array(), $this->reader->queries );
		}
	}

	/**
	 * Provides posts with a missing or wrong nonce.
	 *
	 * @return array<string, array{array}>
	 */
	public static function provide_bad_nonces(): array {
		return array(
			'no nonce'    => array( array( 'search' => 'secret' ) ),
			'wrong nonce' => array(
				array(
					'search'                          => 'secret',
					Interactions_Page::NONCE_FIELD => 'invalid',
				),
			),
			'empty nonce' => array(
				array(
					'search'                          => 'secret',
					Interactions_Page::NONCE_FIELD => '',
				),
			),
		);
	}

	/**
	 * A POST with a valid nonce and a search runs the search in all three texts.
	 *
	 * @return void
	 */
	public function test_post_with_search_queries_the_three_texts(): void {
		$this->reader->rows = array( array( $this->row() ) );
		$this->post( array( 'search' => 'opening' ) );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( "( question LIKE '%opening%' OR llm_answer LIKE '%opening%' OR delivered LIKE '%opening%' )", $this->reader->queries[0] );
		$this->assertStringContainsString( 'What is the opening time?', $output );
	}

	/**
	 * While a search is active the search text appears in no link, so it cannot enter the URL.
	 *
	 * @return void
	 */
	public function test_search_text_never_appears_in_a_link(): void {
		$this->reader->rows = array( array( $this->row() ) );
		$this->post( array( 'search' => 'confidential phrase' ) );

		$output = $this->render( $this->page() );

		$this->assertDoesNotMatchRegularExpression( '/href="[^"]*(search=|confidential)/', $output );
		$this->assertDoesNotMatchRegularExpression( '/action="[^"]*(search=|confidential)/', $output );
		$this->assertDoesNotMatchRegularExpression( '/_wp_http_referer[^>]*(search=|confidential)/', $output );
	}

	/**
	 * While a search is active the pagination uses buttons that stay in the POST form.
	 *
	 * @return void
	 */
	public function test_search_pagination_uses_post_buttons(): void {
		$this->reader->rows = array( array( $this->row() ) );
		$this->post( array( 'search' => 'opening' ) );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<button type="submit" class="next-page button" name="paged" value="2">', $output );
		$this->assertStringNotContainsString( 'class="next-page button" href=', $output );
		$this->assertStringContainsString( 'Page 1 of 3', $output );
	}

	/**
	 * While a search is active the column headers are not sort links, because a link would drop the search.
	 *
	 * @return void
	 */
	public function test_search_disables_sort_links(): void {
		$this->reader->rows = array( array( $this->row() ) );
		$this->post( array( 'search' => 'opening' ) );

		$output = $this->render( $this->page() );

		$this->assertDoesNotMatchRegularExpression( '/<a href="[^"]*orderby=/', $output );
		$this->assertStringContainsString( 'name="orderby"', $output, 'Sorting stays available through the select.' );
	}

	/**
	 * The search text typed by the user is shown back, escaped.
	 *
	 * @return void
	 */
	public function test_search_text_is_shown_back_escaped(): void {
		$this->post( array( 'search' => '"><script>alert(1)</script>' ) );

		$output = $this->render( $this->page() );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $output );
		$this->assertStringContainsString( '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $output );
	}

	/**
	 * A POST without a search redirects to a clean URL that keeps the other filters.
	 *
	 * @return void
	 */
	public function test_post_without_search_redirects_to_a_clean_url(): void {
		$this->post(
			array(
				'outcome'  => 'incomplete',
				'instance' => 'site-a',
				'search'   => '   ',
				'orderby'  => 'duration_ms',
				'order'    => 'ASC',
				'per_page' => '50',
			)
		);

		$page = $this->page();

		ob_start();
		$page->handle_request();
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'Nothing is printed, so the redirect can still send its headers.' );
		$this->assertSame( array(), $this->reader->queries, 'Nothing is queried before the redirect.' );
		$this->assertNotNull( $page->redirected );
		$this->assertStringContainsString( 'page=rilm-interactions', $page->redirected );
		$this->assertStringContainsString( 'outcome=incomplete', $page->redirected );
		$this->assertStringContainsString( 'instance=site-a', $page->redirected );
		$this->assertStringContainsString( 'orderby=duration_ms', $page->redirected );
		$this->assertStringContainsString( 'order=ASC', $page->redirected );
		$this->assertStringContainsString( 'per_page=50', $page->redirected );
		$this->assertStringNotContainsString( 'search', $page->redirected );
		$this->assertStringNotContainsString( 'rilm_filters_nonce', $page->redirected );
	}

	/**
	 * The redirect of an empty search never carries a search text, even when other fields hold text.
	 *
	 * @return void
	 */
	public function test_redirect_never_contains_the_search_text(): void {
		$this->post(
			array(
				'search'  => '',
				'user_id' => 'someone',
			)
		);

		$page = $this->page();
		$page->handle_request();

		parse_str( (string) wp_parse_url( (string) $page->redirected, PHP_URL_QUERY ), $args );

		$this->assertSame(
			array(
				'page'    => 'rilm-interactions',
				'user_id' => 'someone',
			),
			$args,
			'Only the page and the non-sensitive filter are in the URL.'
		);
	}

	/**
	 * Drawing the page never redirects: output has already started when it runs.
	 *
	 * @dataProvider provide_request_kinds
	 *
	 * @param array $post Form fields; null means a plain GET.
	 * @return void
	 */
	public function test_render_never_redirects( ?array $post ): void {
		if ( null !== $post ) {
			$this->post( $post );
		}

		$page   = $this->page();
		$output = $this->render( $page );

		$this->assertNull( $page->redirected, 'render() must not redirect.' );
		$this->assertStringContainsString( '<h1>Interactions</h1>', $output );
	}

	/**
	 * Provides a GET and the POSTs with and without a search.
	 *
	 * @return array<string, array{array|null}>
	 */
	public static function provide_request_kinds(): array {
		return array(
			'get'                   => array( null ),
			'post with search'      => array( array( 'search' => 'opening' ) ),
			'post without search'   => array( array( 'search' => '' ) ),
			'post with blank search' => array( array( 'search' => '   ' ) ),
		);
	}

	/**
	 * A POST without a search that reaches render() anyway is drawn from the submitted values.
	 *
	 * @return void
	 */
	public function test_render_draws_a_post_without_search_from_the_submitted_values(): void {
		$this->post(
			array(
				'outcome' => 'incomplete',
				'search'  => '',
			)
		);

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( "outcome = 'incomplete'", $this->reader->queries[0] );
		$this->assertMatchesRegularExpression( '/<option value="incomplete" selected=\'selected\'>/', $output );
	}

	/**
	 * A GET is not handled: no redirect, nothing queried.
	 *
	 * @return void
	 */
	public function test_handle_request_ignores_a_get(): void {
		$_GET = array( 'outcome' => 'generated' );

		$page = $this->page();
		$page->handle_request();

		$this->assertNull( $page->redirected );
		$this->assertSame( array(), $this->reader->queries );
	}

	/**
	 * A POST with a search is left to render(): no redirect, so the text never reaches a URL.
	 *
	 * @return void
	 */
	public function test_handle_request_does_not_redirect_a_search(): void {
		$this->post( array( 'search' => 'confidential phrase' ) );

		$page = $this->page();
		$page->handle_request();

		$this->assertNull( $page->redirected );
		$this->assertSame( array(), $this->reader->queries );
	}

	/**
	 * An invalid nonce stops the request before anything is queried or redirected.
	 *
	 * @return void
	 */
	public function test_handle_request_refuses_a_bad_nonce(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'search'                       => '',
			Interactions_Page::NONCE_FIELD => 'invalid',
		);
		$_REQUEST                  = $_POST;

		$page = $this->page();

		try {
			$page->handle_request();
			$this->fail( 'The request must be refused.' );
		} catch ( WPDieException $exception ) {
			$this->assertNull( $page->redirected );
			$this->assertSame( array(), $this->reader->queries );
		}
	}

	/**
	 * A user without manage_options is refused before the form is handled.
	 *
	 * @return void
	 */
	public function test_handle_request_refuses_users_without_capability(): void {
		$this->post( array( 'search' => '' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$page = $this->page();

		try {
			$page->handle_request();
			$this->fail( 'The request must be refused.' );
		} catch ( WPDieException $exception ) {
			$this->assertNull( $page->redirected );
		}
	}

	/**
	 * Pressing a pagination button moves to that page inside the search.
	 *
	 * @return void
	 */
	public function test_post_pagination_moves_inside_the_search(): void {
		$this->reader->rows = array( array( $this->row() ) );
		$this->post(
			array(
				'search' => 'opening',
				'paged'  => '3',
			)
		);

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'LIMIT 20 OFFSET 40', $this->reader->queries[1] );
		$this->assertStringContainsString( 'LIKE', $this->reader->queries[1] );
		$this->assertStringContainsString( 'Page 3 of 3', $output );
	}

	/**
	 * A page past the last one shows the last page.
	 *
	 * @return void
	 */
	public function test_page_past_the_end_shows_the_last_page(): void {
		$_GET['paged'] = '99';

		$this->render( $this->page() );

		$this->assertStringContainsString( 'LIMIT 20 OFFSET 40', $this->reader->queries[1] );
	}

	/**
	 * Users without manage_options are refused, GET or POST.
	 *
	 * @return void
	 */
	public function test_users_without_capability_are_refused(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->expectException( WPDieException::class );

		$this->render( $this->page() );
	}

	/**
	 * Without a usable log database the page says so and shows no form or query.
	 *
	 * @return void
	 */
	public function test_unavailable_database_shows_a_message(): void {
		$output = $this->render( $this->page( false ) );

		$this->assertStringContainsString( 'The interaction log is not available', $output );
		$this->assertStringNotContainsString( '<form', $output );
		$this->assertSame( array(), $this->reader->queries );
	}

	/**
	 * Untrusted values are escaped in every cell.
	 *
	 * @return void
	 */
	public function test_cell_values_are_escaped(): void {
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'instance'       => '<img src=x onerror=alert(1)>',
						'user_id'        => '"><script>alert(2)</script>',
						'question'       => '<b>bold</b> & more',
						'delivered'      => '<script>alert(3)</script>',
						'input_verdict'  => '<i>verdict</i>',
						'output_verdict' => '"x"',
					)
				),
			),
		);

		$output = $this->render( $this->page() );

		foreach ( array( '<img src=x', '<script>alert', '<b>bold</b>', '<i>verdict</i>' ) as $raw ) {
			$this->assertStringNotContainsString( $raw, $output, $raw );
		}

		$this->assertStringContainsString( '&lt;b&gt;bold&lt;/b&gt; &amp; more', $output );
		$this->assertStringContainsString( '&lt;i&gt;verdict&lt;/i&gt;', $output );
	}

	/**
	 * Long texts are collapsible, short ones are inline, and the full text stays available inside the row.
	 *
	 * @return void
	 */
	public function test_long_text_is_collapsible(): void {
		$long               = str_repeat( 'word ', 60 ) . 'THE END';
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'question'  => $long,
						'delivered' => 'short answer',
					)
				),
			),
		);

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<details class="rilm-details"><summary>', $output );
		$this->assertStringContainsString( 'THE END', $output, 'The whole text is in the row, not cut by the markup.' );
		$this->assertSame( 1, substr_count( $output, '<details class="rilm-details">' ), 'Only the long text is collapsible.' );
		$this->assertStringContainsString( '<span class="rilm-text">short answer</span>', $output );
	}

	/**
	 * A text cut by the query points to the detail page.
	 *
	 * @return void
	 */
	public function test_text_cut_by_the_query_links_to_the_detail(): void {
		$this->reader->rows = array(
			array(
				$this->row( array( 'question' => str_repeat( 'a', Interaction_Repository::PREVIEW_LENGTH + 1 ) ) ),
			),
		);

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'Shortened to 1000 characters.', $output );
		$this->assertMatchesRegularExpression( '/<a href="[^"]*page=rilm-detail[^"]*id=7[^"]*">Open the details for the full text<\/a>/', $output );
	}

	/**
	 * Missing texts and verdicts are shown as "not recorded" or "None", not as empty cells.
	 *
	 * @return void
	 */
	public function test_missing_values_are_explained(): void {
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'question'    => null,
						'delivered'   => '',
						'duration_ms' => null,
					)
				),
			),
		);

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'Not recorded', $output );
		$this->assertStringContainsString( '(empty)', $output );
	}

	/**
	 * Each row links to its detail page with a descriptive hidden label.
	 *
	 * @return void
	 */
	public function test_each_row_links_to_the_detail(): void {
		$this->reader->rows = array( array( $this->row() ) );

		$output = $this->render( $this->page() );

		$this->assertMatchesRegularExpression( '/<a href="[^"]*page=rilm-detail[^"]*id=7[^"]*">2026-10-02 14:30:45<span class="screen-reader-text"> \(view the details of interaction 7\)<\/span><\/a>/', $output );
	}

	/**
	 * With no match the table says so.
	 *
	 * @return void
	 */
	public function test_empty_result_message(): void {
		$this->reader->var = '0';

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'No interactions found for the selected period and filters.', $output );
	}

	/**
	 * The detail link uses only the integer id, never any text of the interaction.
	 *
	 * @return void
	 */
	public function test_detail_link_carries_only_the_id(): void {
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'id'       => '15',
						'question' => 'personal question text',
					)
				),
			),
		);

		$output = $this->render( $this->page() );

		preg_match_all( '/href="([^"]*page=rilm-detail[^"]*)"/', $output, $links );

		$this->assertNotEmpty( $links[1] );

		foreach ( $links[1] as $link ) {
			$this->assertStringNotContainsString( 'personal', $link );
			$this->assertMatchesRegularExpression( '/id=15/', $link );
		}
	}
}
