<?php
/**
 * Integration tests for the interaction detail page.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Integration;

use RILM\Admin\Assets;
use RILM\Admin\Detail_Page;
use RILM\Admin\Menu;
use RILM\Config\Config;
use RILM\Repository\Interaction_Repository;
use RILM\Tests\Unit\Support\Fake_Reader;
use WP_UnitTestCase;
use WPDieException;

/**
 * Verifies the id handling, the fields, the full texts, the comparison and the escaping.
 */
class DetailPageTest extends WP_UnitTestCase {

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
		wp_dequeue_style( 'revisions' );
		wp_dequeue_style( Assets::STYLE_HANDLE );

		parent::tear_down();
	}

	/**
	 * A complete row as the driver returns it.
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
			'turn_id'            => 'turn-abc',
			'outcome'            => 'generated',
			'question'           => 'What is the opening time?',
			'llm_answer'         => 'It opens at 9.',
			'delivered'          => 'It opens at 9.',
			'guard_present'      => '1',
			'input_verdict'      => null,
			'output_verdict'     => null,
			'other_plugin_reply' => null,
			'recall_count'       => '2',
			'recall_top_score'   => '0.5',
		);
	}

	/**
	 * Creates the page on the fake reader.
	 *
	 * @param bool $available Whether the log database is usable.
	 * @param array $optional Optional columns the table has.
	 * @return Detail_Page
	 */
	private function page( bool $available = true, array $optional = array() ): Detail_Page {
		$repository = $available ? new Interaction_Repository(
			$this->reader,
			new Config(
				array( 'host' => 'db.example.test' ),
				array(
					'user'     => 'reader',
					'password' => 'secret',
				)
			),
			$optional
		) : null;

		return new Detail_Page(
			null,
			static function () use ( $repository ) {
				return $repository;
			}
		);
	}

	/**
	 * Renders the page for an id and returns the output.
	 *
	 * @param Detail_Page $page Page.
	 * @param mixed       $id   Value of the `id` query argument; null leaves it out.
	 * @return string
	 */
	private function render( Detail_Page $page, $id = '7' ): string {
		$_GET = null === $id ? array() : array( 'id' => $id );

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
	 * The page shows every field of the row.
	 *
	 * @return void
	 */
	public function test_shows_every_field(): void {
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'input_verdict'      => 'blocked',
						'output_verdict'     => 'toxic',
						'other_plugin_reply' => '1',
					)
				),
			),
		);

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<h1>Interaction details</h1>', $output );
		$this->assertStringContainsString( '<th scope="row">ID</th><td>7</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Instance</th><td>site-a</td>', $output );
		$this->assertStringContainsString( '<th scope="row">User</th><td>42</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Turn ID</th><td>turn-abc</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Outcome</th><td>Generated</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Duration</th><td>1,500 ms</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Guardrails</th><td>Present</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Input verdict</th><td>blocked</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Output verdict</th><td>toxic</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Other plugin reply</th><td>Replied</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Recalled sources</th><td>2</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Best recall score</th><td>0.5000</td>', $output );
	}

	/**
	 * Both the UTC and the local time are shown, and the zone is named.
	 *
	 * @return void
	 */
	public function test_shows_utc_and_local_time(): void {
		$this->reader->rows = array( array( $this->row() ) );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<th scope="row">Date and time (UTC)</th><td>2026-10-02 12:30:45.123</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Date and time (Europe/Rome)</th><td>2026-10-02 14:30:45.123</td>', $output );
	}

	/**
	 * The three texts are shown in full, with no truncation.
	 *
	 * @return void
	 */
	public function test_texts_are_shown_in_full(): void {
		$long               = str_repeat( 'long text ', 3000 ) . 'THE VERY END';
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'question'   => $long,
						'llm_answer' => $long . ' generated',
						'delivered'  => $long . ' delivered',
					)
				),
			),
		);

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( $long . ' generated', $output );
		$this->assertStringContainsString( $long . ' delivered', $output );
		$this->assertStringContainsString( '<h2>Question</h2><div class="rilm-text rilm-full-text">' . $long . '</div>', $output );
		$this->assertStringNotContainsString( 'Shortened to', $output );
		$this->assertStringNotContainsString( '<details class="rilm-details"><summary>' . substr( $long, 0, 120 ), $output );
	}

	/**
	 * The detail query asks for the row by its integer id.
	 *
	 * @return void
	 */
	public function test_queries_by_integer_id(): void {
		$this->reader->rows = array( array( $this->row() ) );

		$this->render( $this->page(), '7' );

		$this->assertCount( 1, $this->reader->queries );
		$this->assertStringContainsString( ' WHERE id = 7 LIMIT 1', $this->reader->queries[0] );
	}

	/**
	 * Ids that are not positive integers never reach the database and give the generic message.
	 *
	 * @dataProvider provide_bad_ids
	 *
	 * @param mixed $id Value of the id argument.
	 * @return void
	 */
	public function test_bad_ids_show_not_found_without_querying( $id ): void {
		$output = $this->render( $this->page(), $id );

		$this->assertStringContainsString( 'Interaction not found.', $output );
		$this->assertSame( array(), $this->reader->queries );
		$this->assertStringNotContainsString( '<table', $output );
	}

	/**
	 * Provides ids that are not positive integers.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function provide_bad_ids(): array {
		return array(
			'missing'          => array( null ),
			'empty'            => array( '' ),
			'zero'             => array( '0' ),
			'negative'         => array( '-1' ),
			'text'             => array( 'abc' ),
			'decimal'          => array( '1.5' ),
			'exponent'         => array( '1e3' ),
			'leading zero'     => array( '007' ),
			'sql injection'    => array( '1 OR 1=1' ),
			'sql comment'      => array( '1; DROP TABLE x' ),
			'too long'         => array( '99999999999999999999' ),
			'array'            => array( array( '7' ) ),
			'spaces'           => array( ' 7 x' ),
		);
	}

	/**
	 * An id with no row gives exactly the same message as a malformed one.
	 *
	 * @return void
	 */
	public function test_unknown_id_looks_like_a_malformed_one(): void {
		$malformed = $this->render( $this->page(), 'abc' );

		$this->reader->rows = array( array() );
		$unknown            = $this->render( $this->page(), '999' );

		$this->assertSame( $malformed, $unknown );
		$this->assertCount( 1, $this->reader->queries, 'Only the well-formed id reached the database.' );
	}

	/**
	 * Users without manage_options get no data.
	 *
	 * @return void
	 */
	public function test_users_without_capability_are_refused(): void {
		$this->reader->rows = array( array( $this->row() ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		try {
			$this->render( $this->page() );
			$this->fail( 'The page must refuse the request.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( array(), $this->reader->queries, 'Nothing is queried for a user without the capability.' );
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
		$this->assertSame( array(), $this->reader->queries );
	}

	/**
	 * A link leads back to the list.
	 *
	 * @return void
	 */
	public function test_back_link(): void {
		$this->reader->rows = array( array( $this->row() ) );

		$output = $this->render( $this->page() );

		$this->assertMatchesRegularExpression( '/<a href="[^"]*page=rilm-interactions">Back to the interactions list<\/a>/', $output );
		$this->assertDoesNotMatchRegularExpression( '/page=rilm-interactions[^"]*&#038;/', $output, 'The link carries no filter, so no search text.' );
	}

	/**
	 * NULL values are explained, and are not confused with empty values.
	 *
	 * @return void
	 */
	public function test_null_values_are_explained(): void {
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'turn_id'            => null,
						'duration_ms'        => null,
						'question'           => null,
						'llm_answer'         => '',
						'delivered'          => null,
						'recall_count'       => null,
						'recall_top_score'   => null,
						'other_plugin_reply' => null,
					)
				),
			),
		);

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<th scope="row">Turn ID</th><td>Not recorded</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Duration</th><td>Not recorded</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Input verdict</th><td>None (no block recorded)</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Output verdict</th><td>None (no block recorded)</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Other plugin reply</th><td>Not recorded</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Recalled sources</th><td>Not recorded</td>', $output );
		$this->assertStringContainsString( '<h2>Question</h2><p>Not recorded</p>', $output );
		$this->assertStringContainsString( '<h2>Generated answer</h2><p><em>(empty)</em></p>', $output );
		$this->assertStringContainsString( '<h2>Delivered answer</h2><p>Not recorded</p>', $output );
	}

	/**
	 * "Did not reply" (0) and "not recorded" (NULL) are shown differently.
	 *
	 * @return void
	 */
	public function test_other_plugin_reply_states_are_distinct(): void {
		$this->reader->rows = array( array( $this->row( array( 'other_plugin_reply' => '0' ) ) ) );

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<th scope="row">Other plugin reply</th><td>Did not reply</td>', $output );
	}

	/**
	 * Zero recall and a zero score are values, not missing data.
	 *
	 * @return void
	 */
	public function test_zero_recall_is_a_value(): void {
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'recall_count'     => '0',
						'recall_top_score' => '0',
						'duration_ms'      => '0',
					)
				),
			),
		);

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( '<th scope="row">Recalled sources</th><td>0</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Best recall score</th><td>0.0000</td>', $output );
		$this->assertStringContainsString( '<th scope="row">Duration</th><td>0 ms</td>', $output );
	}

	/**
	 * Different answers are compared line by line.
	 *
	 * @return void
	 */
	public function test_differing_answers_are_compared(): void {
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'llm_answer' => "First line\nOriginal second line",
						'delivered'  => "First line\nChanged second line",
					)
				),
			),
		);

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'Comparison of the generated and delivered answers', $output );
		$this->assertStringContainsString( "<table class='diff", $output );
		$this->assertStringContainsString( '<del>Original</del> second line', $output, 'The changed word is marked as deleted.' );
		$this->assertStringContainsString( '<ins>Changed</ins> second line', $output, 'The new word is marked as added.' );
		$this->assertStringContainsString( 'Unchanged: </span>First line', $output, 'Unchanged lines are announced to screen readers.' );
		$this->assertStringContainsString( 'Generated answer</th>', $output );
		$this->assertStringContainsString( 'Delivered answer</th>', $output );
	}

	/**
	 * Equal answers are not compared.
	 *
	 * @return void
	 */
	public function test_equal_answers_are_not_compared(): void {
		$this->reader->rows = array( array( $this->row() ) );

		$output = $this->render( $this->page() );

		$this->assertStringNotContainsString( 'Comparison of the generated', $output );
		$this->assertStringNotContainsString( "<table class='diff", $output );
	}

	/**
	 * With a missing answer there is nothing to compare.
	 *
	 * @dataProvider provide_missing_answers
	 *
	 * @param string|null $generated Generated answer.
	 * @param string|null $delivered Delivered answer.
	 * @return void
	 */
	public function test_missing_answer_is_not_compared( ?string $generated, ?string $delivered ): void {
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'llm_answer' => $generated,
						'delivered'  => $delivered,
					)
				),
			),
		);

		$output = $this->render( $this->page() );

		$this->assertStringNotContainsString( 'Comparison of the generated', $output );
	}

	/**
	 * Provides rows where one answer is missing.
	 *
	 * @return array<string, array{string|null, string|null}>
	 */
	public static function provide_missing_answers(): array {
		return array(
			'no generated answer' => array( null, 'delivered' ),
			'no delivered answer' => array( 'generated', null ),
			'neither'             => array( null, null ),
		);
	}

	/**
	 * Very long answers are not compared and the page says why.
	 *
	 * @return void
	 */
	public function test_very_long_answers_are_not_compared(): void {
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'llm_answer' => str_repeat( 'a', Detail_Page::COMPARISON_LIMIT + 1 ),
						'delivered'  => 'short',
					)
				),
			),
		);

		$output = $this->render( $this->page() );

		$this->assertStringContainsString( 'Comparison of the generated and delivered answers', $output );
		$this->assertStringContainsString( 'The comparison is not available for texts longer than 20,000 characters', $output );
		$this->assertStringNotContainsString( "<table class='diff", $output );
	}

	/**
	 * The text of the comparison is escaped.
	 *
	 * @return void
	 */
	public function test_comparison_is_escaped(): void {
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'llm_answer' => '<script>alert("generated")</script>',
						'delivered'  => '<img src=x onerror=alert("delivered")>',
					)
				),
			),
		);

		$output = $this->render( $this->page() );

		$this->assertStringNotContainsString( '<script>alert', $output );
		$this->assertStringNotContainsString( '<img src=x', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	/**
	 * Every field is escaped.
	 *
	 * @return void
	 */
	public function test_every_value_is_escaped(): void {
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'instance'       => '<b>instance</b>',
						'user_id'        => '"><script>alert(1)</script>',
						'turn_id'        => '<i>turn</i>',
						'question'       => '<script>alert(2)</script>',
						'input_verdict'  => '<u>input</u>',
						'output_verdict' => '<s>output</s>',
					)
				),
			),
		);

		$output = $this->render( $this->page() );

		foreach ( array( '<b>instance', '<script>alert', '<i>turn', '<u>input', '<s>output' ) as $raw ) {
			$this->assertStringNotContainsString( $raw, $output, $raw );
		}

		$this->assertStringContainsString( '&lt;b&gt;instance&lt;/b&gt;', $output );
	}

	/**
	 * Columns added later appear only when they hold a value, as escaped text.
	 *
	 * @return void
	 */
	public function test_optional_columns_appear_only_with_a_value(): void {
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'tools_used'     => 'web_search',
						'tool_input'     => '{"q":"<b>x</b>"}',
						'tool_output'    => null,
						'recall_sources' => 'doc-1, doc-2',
					)
				),
			),
		);

		$output = $this->render( $this->page( true, array( 'tools_used', 'tool_input', 'tool_output', 'recall_sources' ) ) );

		$this->assertStringContainsString( '<summary>Tools used</summary>', $output );
		$this->assertStringContainsString( '<summary>Tool input</summary>', $output );
		$this->assertStringContainsString( '<summary>Recall sources</summary>', $output );
		$this->assertStringNotContainsString( '<summary>Tool output</summary>', $output );
		$this->assertStringContainsString( '{&quot;q&quot;:&quot;&lt;b&gt;x&lt;/b&gt;&quot;}', $output );
		$this->assertStringNotContainsString( '<b>x</b>', $output );
	}

	/**
	 * Recall-source JSON is shown as document metadata and web sources are linked.
	 *
	 * @return void
	 */
	public function test_recall_source_urls_are_linked_and_other_sources_stay_text(): void {
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'recall_sources' => '[{"id":"doc-1","source":"https://example.org/guide?a=1&b=2","score":0.875},{"id":"doc-2","source":"guide.pdf","score":0.5}]',
					)
				)
			),
		);

		$output = $this->render( $this->page( true, array( 'recall_sources' ) ) );

		$this->assertStringContainsString( '<ul class="rilm-recall-sources">', $output );
		$this->assertStringContainsString( '<strong>ID:</strong> doc-1', $output );
		$this->assertStringContainsString( 'href="https://example.org/guide?a=1&#038;b=2"', $output );
		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $output );
		$this->assertStringContainsString( '>https://example.org/guide?a=1&amp;b=2</a>', $output );
		$this->assertStringContainsString( '<strong>ID:</strong> doc-2 guide.pdf', $output );
		$this->assertStringContainsString( 'Score: 0.875000', $output );
		$this->assertStringContainsString( 'Score: 0.500000', $output );
		$this->assertStringNotContainsString( 'href="guide.pdf"', $output );
	}

	/**
	 * Recall sources that are not logger JSON remain safely visible as text.
	 *
	 * @return void
	 */
	public function test_invalid_recall_source_json_falls_back_to_escaped_text(): void {
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'recall_sources' => 'javascript:alert(1) <script>alert(2)</script>',
					)
				)
			),
		);

		$output = $this->render( $this->page( true, array( 'recall_sources' ) ) );

		$this->assertStringNotContainsString( 'href="javascript:alert(1)"', $output );
		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( 'javascript:alert(1) &lt;script&gt;alert(2)&lt;/script&gt;', $output );
	}

	/**
	 * Non-web schemes in otherwise valid logger JSON are never links.
	 *
	 * @return void
	 */
	public function test_recall_source_links_are_limited_to_http_and_https(): void {
		$this->reader->rows = array(
			array(
				$this->row(
					array(
						'recall_sources' => '[{"source":"javascript:alert(1)"},{"source":"file:///private/guide.pdf"}]',
					)
				)
			),
		);

		$output = $this->render( $this->page( true, array( 'recall_sources' ) ) );

		$this->assertStringNotContainsString( 'href="javascript:alert(1)"', $output );
		$this->assertStringNotContainsString( 'href="file:///private/guide.pdf"', $output );
		$this->assertStringContainsString( 'javascript:alert(1)', $output );
		$this->assertStringContainsString( 'file:///private/guide.pdf', $output );
	}

	/**
	 * The query selects only the optional columns that exist.
	 *
	 * @return void
	 */
	public function test_query_selects_only_existing_optional_columns(): void {
		$this->reader->rows = array( array( $this->row() ) );

		$this->render( $this->page( true, array( 'tool_input' ) ) );

		$this->assertStringContainsString( ', tool_input FROM ', $this->reader->queries[0] );
		$this->assertStringNotContainsString( 'tools_used', $this->reader->queries[0] );
	}

	/**
	 * Without the columns added later no extra section appears.
	 *
	 * @return void
	 */
	public function test_no_optional_sections_without_the_columns(): void {
		$this->reader->rows = array( array( $this->row() ) );

		$output = $this->render( $this->page() );

		$this->assertStringNotContainsString( '<summary>', $output );
	}

	/**
	 * The stylesheet of the core diff table is loaded on the detail screen only.
	 *
	 * @return void
	 */
	public function test_diff_styles_are_loaded_only_on_the_detail_screen(): void {
		$GLOBALS['menu']              = array();
		$GLOBALS['submenu']           = array();
		$GLOBALS['admin_page_hooks']  = array();
		$GLOBALS['_registered_pages'] = array();
		$GLOBALS['_parent_pages']     = array();

		$menu = new Menu();
		$menu->register();

		$assets = new Assets( $menu );

		$assets->enqueue( get_plugin_page_hookname( Menu::SLUG_INTERACTIONS, Menu::SLUG_DASHBOARD ) );
		$this->assertFalse( wp_style_is( 'revisions', 'enqueued' ) );

		$assets->enqueue( get_plugin_page_hookname( Menu::SLUG_DETAIL, '' ) );
		$this->assertTrue( wp_style_is( 'revisions', 'enqueued' ) );
		$this->assertTrue( $menu->is_detail_screen( get_plugin_page_hookname( Menu::SLUG_DETAIL, '' ) ) );
		$this->assertFalse( $menu->is_detail_screen( get_plugin_page_hookname( Menu::SLUG_DASHBOARD, '' ) ) );
	}
}
