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
		$this->render( $page );

		$this->assertStringContainsString( 'answers=differ', (string) $page->redirected );
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

		$page   = $this->page();
		$output = $this->render( $page );

		$this->assertSame( '', $output );
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
		$this->render( $page );

		$this->assertSame( 'someone', rawurldecode( (string) wp_parse_url( $page->redirected, PHP_URL_QUERY ) ) === '' ? '' : 'someone' );
		$this->assertStringContainsString( 'user_id=someone', $page->redirected );
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
