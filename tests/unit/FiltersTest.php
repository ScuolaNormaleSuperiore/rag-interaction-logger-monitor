<?php
/**
 * Unit tests for the query filters.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RILM\Repository\Filters;
use RILM\Repository\Period;

/**
 * Verifies that every input is validated and invalid values are dropped.
 */
class FiltersTest extends TestCase {

	/**
	 * Builds filters at a fixed local time.
	 *
	 * @param array $input Raw values.
	 * @return Filters
	 */
	private static function filters( array $input ): Filters {
		return Filters::from_array( $input, new DateTimeImmutable( '2026-10-02 14:30:00', new DateTimeZone( 'Europe/Rome' ) ) );
	}

	/**
	 * With no input the defaults apply: today, newest first, first page.
	 *
	 * @return void
	 */
	public function test_defaults(): void {
		$filters = self::filters( array() );

		$this->assertSame( Period::TODAY, $filters->period()->key() );
		$this->assertSame( '2026-10-01 22:00:00.000', $filters->period()->start_utc() );
		$this->assertSame( 'ts', $filters->orderby() );
		$this->assertSame( 'DESC', $filters->order() );
		$this->assertSame( 1, $filters->page() );
		$this->assertSame( 20, $filters->per_page() );
		$this->assertSame( 0, $filters->offset() );
		$this->assertNull( $filters->outcome() );
		$this->assertNull( $filters->instance() );
		$this->assertNull( $filters->user_id() );
		$this->assertNull( $filters->guard() );
		$this->assertNull( $filters->input_verdict() );
		$this->assertNull( $filters->output_verdict() );
		$this->assertNull( $filters->other_reply() );
		$this->assertFalse( $filters->recall_empty() );
		$this->assertNull( $filters->search() );
		$this->assertSame( array(), $filters->errors() );
	}

	/**
	 * Valid values are kept.
	 *
	 * @return void
	 */
	public function test_valid_values_are_kept(): void {
		$filters = self::filters(
			array(
				'period'         => 'week',
				'outcome'        => 'generated',
				'instance'       => 'site-a',
				'user_id'        => 'user 42',
				'guard'          => 'present',
				'input_verdict'  => 'blocked',
				'output_verdict' => Filters::VERDICT_ANY,
				'other_reply'    => 'unknown',
				'recall'         => 'empty',
				'search'         => 'hello world',
				'orderby'        => 'duration_ms',
				'order'          => 'asc',
				'paged'          => '3',
				'per_page'       => '50',
			)
		);

		$this->assertSame( Period::WEEK, $filters->period()->key() );
		$this->assertSame( 'generated', $filters->outcome() );
		$this->assertSame( 'site-a', $filters->instance() );
		$this->assertSame( 'user 42', $filters->user_id() );
		$this->assertSame( Filters::GUARD_PRESENT, $filters->guard() );
		$this->assertSame( 'blocked', $filters->input_verdict() );
		$this->assertSame( Filters::VERDICT_ANY, $filters->output_verdict() );
		$this->assertSame( Filters::REPLY_UNKNOWN, $filters->other_reply() );
		$this->assertTrue( $filters->recall_empty() );
		$this->assertSame( 'hello world', $filters->search() );
		$this->assertSame( 'duration_ms', $filters->orderby() );
		$this->assertSame( 'ASC', $filters->order() );
		$this->assertSame( 3, $filters->page() );
		$this->assertSame( 50, $filters->per_page() );
		$this->assertSame( 100, $filters->offset() );
		$this->assertSame( array(), $filters->errors() );
	}

	/**
	 * Values outside the whitelists are dropped and reported by key.
	 *
	 * @dataProvider provide_invalid_values
	 *
	 * @param string $key   Input key.
	 * @param string $value Invalid value.
	 * @param string $error Key expected in errors().
	 * @return void
	 */
	public function test_invalid_values_are_dropped_and_reported( string $key, string $value, string $error ): void {
		$filters = self::filters( array( $key => $value ) );

		$this->assertSame( array( $error ), $filters->errors() );
		$this->assertNull( $filters->outcome() );
		$this->assertNull( $filters->guard() );
		$this->assertNull( $filters->other_reply() );
		$this->assertSame( 'ts', $filters->orderby() );
		$this->assertSame( Period::TODAY, $filters->period()->key() );
	}

	/**
	 * Provides invalid values.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function provide_invalid_values(): array {
		return array(
			'outcome'      => array( 'outcome', 'deleted', 'outcome' ),
			'outcome sql'  => array( 'outcome', "generated' OR '1'='1", 'outcome' ),
			'guard'        => array( 'guard', 'maybe', 'guard' ),
			'other reply'  => array( 'other_reply', '1', 'other_reply' ),
			'orderby'      => array( 'orderby', 'question; DROP TABLE x', 'orderby' ),
			'orderby star' => array( 'orderby', '*', 'orderby' ),
			'period'       => array( 'period', 'fortnight', 'period' ),
		);
	}

	/**
	 * Sort directions other than ASC and DESC fall back to DESC.
	 *
	 * @return void
	 */
	public function test_invalid_order_falls_back_to_desc(): void {
		$this->assertSame( 'DESC', self::filters( array( 'order' => 'sideways' ) )->order() );
		$this->assertSame( 'DESC', self::filters( array( 'order' => 'asc; DROP' ) )->order() );
		$this->assertSame( 'ASC', self::filters( array( 'order' => 'ASC' ) )->order() );
	}

	/**
	 * Each sortable column is accepted.
	 *
	 * @return void
	 */
	public function test_every_sortable_column_is_accepted(): void {
		foreach ( Filters::SORTABLE as $column ) {
			$this->assertSame( $column, self::filters( array( 'orderby' => $column ) )->orderby() );
		}
	}

	/**
	 * Page and page size are clamped to sensible values.
	 *
	 * @dataProvider provide_paging
	 *
	 * @param array $input    Raw values.
	 * @param int   $page     Expected page.
	 * @param int   $per_page Expected page size.
	 * @return void
	 */
	public function test_paging_is_clamped( array $input, int $page, int $per_page ): void {
		$filters = self::filters( $input );

		$this->assertSame( $page, $filters->page() );
		$this->assertSame( $per_page, $filters->per_page() );
	}

	/**
	 * Provides paging input and the expected result.
	 *
	 * @return array<string, array{array, int, int}>
	 */
	public static function provide_paging(): array {
		return array(
			'zero page'          => array( array( 'paged' => '0' ), 1, 20 ),
			'negative page'      => array( array( 'paged' => '-4' ), 1, 20 ),
			'text page'          => array( array( 'paged' => 'abc' ), 1, 20 ),
			'huge page'          => array( array( 'paged' => '99999999999' ), 100000, 20 ),
			'allowed size'       => array( array( 'per_page' => '100' ), 1, 100 ),
			'size not allowed'   => array( array( 'per_page' => '37' ), 1, 20 ),
			'size too large'     => array( array( 'per_page' => '100000' ), 1, 20 ),
			'size not a number'  => array( array( 'per_page' => '20; DROP' ), 1, 20 ),
		);
	}

	/**
	 * A valid custom interval is read in the local time zone.
	 *
	 * @return void
	 */
	public function test_custom_period_is_read_in_local_time(): void {
		$filters = self::filters(
			array(
				'period' => 'custom',
				'from'   => '2026-09-01T00:00',
				'to'     => '2026-09-30T23:59',
			)
		);

		$this->assertSame( Period::CUSTOM, $filters->period()->key() );
		$this->assertSame( '2026-08-31 22:00:00.000', $filters->period()->start_utc() );
		$this->assertSame( '2026-09-30 21:59:59.999', $filters->period()->end_utc() );
		$this->assertSame( array(), $filters->errors() );
	}

	/**
	 * A custom interval with the end before the start is refused and reported.
	 *
	 * @return void
	 */
	public function test_custom_period_with_end_before_start_is_reported(): void {
		$filters = self::filters(
			array(
				'period' => 'custom',
				'from'   => '2026-09-30T00:00',
				'to'     => '2026-09-01T00:00',
			)
		);

		$this->assertSame( array( 'period' ), $filters->errors() );
		$this->assertSame( Period::TODAY, $filters->period()->key() );
	}

	/**
	 * A custom interval with a missing or malformed date is refused and reported.
	 *
	 * @dataProvider provide_bad_custom_periods
	 *
	 * @param array $input Raw values.
	 * @return void
	 */
	public function test_custom_period_with_bad_dates_is_reported( array $input ): void {
		$filters = self::filters( array( 'period' => 'custom' ) + $input );

		$this->assertSame( array( 'period' ), $filters->errors() );
		$this->assertSame( Period::TODAY, $filters->period()->key() );
	}

	/**
	 * Provides bad custom periods.
	 *
	 * @return array<string, array{array}>
	 */
	public static function provide_bad_custom_periods(): array {
		return array(
			'no dates'       => array( array() ),
			'no end'         => array( array( 'from' => '2026-09-01T00:00' ) ),
			'no start'       => array( array( 'to' => '2026-09-01T00:00' ) ),
			'impossible day' => array(
				array(
					'from' => '2026-02-30T00:00',
					'to'   => '2026-03-01T00:00',
				),
			),
			'text'           => array(
				array(
					'from' => 'yesterday',
					'to'   => 'today',
				),
			),
		);
	}

	/**
	 * Control characters are removed and text is trimmed.
	 *
	 * @return void
	 */
	public function test_text_is_cleaned(): void {
		$filters = self::filters(
			array(
				'instance' => "  site\x00-a\n ",
				'search'   => "\t hello \r\n",
			)
		);

		$this->assertSame( 'site-a', $filters->instance() );
		$this->assertSame( 'hello', $filters->search() );
	}

	/**
	 * Blank values count as "no filter".
	 *
	 * @return void
	 */
	public function test_blank_values_mean_no_filter(): void {
		$filters = self::filters(
			array(
				'instance'      => '   ',
				'user_id'       => '',
				'search'        => " \n ",
				'input_verdict' => '',
			)
		);

		$this->assertNull( $filters->instance() );
		$this->assertNull( $filters->user_id() );
		$this->assertNull( $filters->search() );
		$this->assertNull( $filters->input_verdict() );
		$this->assertSame( array(), $filters->errors() );
	}

	/**
	 * Over-long text is cut to the column or search limit.
	 *
	 * @return void
	 */
	public function test_long_text_is_cut(): void {
		$filters = self::filters(
			array(
				'instance'      => str_repeat( 'a', 400 ),
				'input_verdict' => str_repeat( 'b', 100 ),
				'search'        => str_repeat( 'c', 500 ),
			)
		);

		$this->assertSame( 255, mb_strlen( (string) $filters->instance() ) );
		$this->assertSame( 64, mb_strlen( (string) $filters->input_verdict() ) );
		$this->assertSame( 200, mb_strlen( (string) $filters->search() ) );
	}

	/**
	 * Non-scalar and invalid UTF-8 input never breaks the filters.
	 *
	 * @return void
	 */
	public function test_non_scalar_and_invalid_input_is_ignored(): void {
		$filters = self::filters(
			array(
				'instance' => array( 'a' ),
				'user_id'  => "\xC3\x28",
				'search'   => new \stdClass(),
			)
		);

		$this->assertNull( $filters->instance() );
		$this->assertNull( $filters->user_id() );
		$this->assertNull( $filters->search() );
	}

	/**
	 * The default filters give no URL arguments.
	 *
	 * @return void
	 */
	public function test_defaults_give_no_query_args(): void {
		$this->assertSame( array(), self::filters( array() )->to_query_args() );
	}

	/**
	 * Non-default filters become URL arguments named like the input keys.
	 *
	 * @return void
	 */
	public function test_query_args_round_trip(): void {
		$input = array(
			'period'         => 'week',
			'outcome'        => 'generated',
			'instance'       => 'site-a',
			'user_id'        => '42',
			'guard'          => 'absent',
			'input_verdict'  => Filters::VERDICT_ANY,
			'output_verdict' => Filters::VERDICT_NONE,
			'other_reply'    => 'no',
			'recall'         => 'empty',
			'orderby'        => 'instance',
			'order'          => 'ASC',
			'per_page'       => '50',
			'paged'          => '2',
		);

		$args = self::filters( $input )->to_query_args();

		$this->assertSame(
			array(
				'period'         => 'week',
				'outcome'        => 'generated',
				'instance'       => 'site-a',
				'user_id'        => '42',
				'guard'          => 'absent',
				'input_verdict'  => Filters::VERDICT_ANY,
				'output_verdict' => Filters::VERDICT_NONE,
				'other_reply'    => 'no',
				'recall'         => 'empty',
				'orderby'        => 'instance',
				'order'          => 'ASC',
				'per_page'       => 50,
				'paged'          => 2,
			),
			$args
		);

		// Rebuilding the filters from the arguments gives the same arguments.
		$this->assertSame( $args, self::filters( array_map( 'strval', $args ) )->to_query_args() );
	}

	/**
	 * The search text never becomes a URL argument.
	 *
	 * @return void
	 */
	public function test_query_args_never_include_the_search(): void {
		$args = self::filters(
			array(
				'search'  => 'secret words',
				'outcome' => 'generated',
			)
		)->to_query_args();

		$this->assertArrayNotHasKey( 'search', $args );
		$this->assertStringNotContainsString( 'secret', (string) json_encode( $args ) );
	}

	/**
	 * A custom period is kept in local time and survives the round trip.
	 *
	 * @return void
	 */
	public function test_custom_period_round_trip(): void {
		$args = self::filters(
			array(
				'period' => 'custom',
				'from'   => '2026-09-01T00:00',
				'to'     => '2026-09-30T23:59',
			)
		)->to_query_args();

		$this->assertSame( 'custom', $args['period'] );
		$this->assertSame( '2026-09-01T00:00', $args['from'] );
		$this->assertSame( '2026-09-30T23:59', $args['to'] );

		$again = self::filters( $args );
		$this->assertSame( '2026-08-31 22:00:00.000', $again->period()->start_utc() );
		$this->assertSame( '2026-09-30 21:59:59.999', $again->period()->end_utc() );
	}

	/**
	 * Moving to another page keeps every other value.
	 *
	 * @return void
	 */
	public function test_with_page_keeps_the_other_values(): void {
		$filters = self::filters(
			array(
				'outcome'  => 'incomplete',
				'search'   => 'x',
				'per_page' => '50',
				'paged'    => '4',
			)
		);

		$moved = $filters->with_page( 2 );

		$this->assertSame( 2, $moved->page() );
		$this->assertSame( 50, $moved->per_page() );
		$this->assertSame( 'incomplete', $moved->outcome() );
		$this->assertSame( 'x', $moved->search() );
		$this->assertSame( 4, $filters->page(), 'The original is not changed.' );
		$this->assertSame( 1, $filters->with_page( 0 )->page() );
		$this->assertSame( 1, $filters->with_page( -3 )->page() );
	}

	/**
	 * The "answers differ" filter is read from `answers=differ` only.
	 *
	 * @return void
	 */
	public function test_answers_differ_filter(): void {
		$this->assertTrue( self::filters( array( 'answers' => 'differ' ) )->answers_differ() );
		$this->assertFalse( self::filters( array() )->answers_differ() );
		$this->assertFalse( self::filters( array( 'answers' => 'same' ) )->answers_differ() );
		$this->assertFalse( self::filters( array( 'answers' => '1' ) )->answers_differ() );
		$this->assertSame( array( 'answers' => 'differ' ), self::filters( array( 'answers' => 'differ' ) )->to_query_args() );
	}

	/**
	 * Without filters nothing is active in the advanced section.
	 *
	 * @return void
	 */
	public function test_no_advanced_filter_is_active_by_default(): void {
		$this->assertSame( 0, self::filters( array() )->advanced_count() );
		$this->assertFalse( self::filters( array() )->has_custom_view() );
	}

	/**
	 * Each advanced filter counts once.
	 *
	 * @dataProvider provide_advanced_filters
	 *
	 * @param array $input Raw values holding one advanced filter.
	 * @return void
	 */
	public function test_each_advanced_filter_counts_once( array $input ): void {
		$this->assertSame( 1, self::filters( $input )->advanced_count() );
	}

	/**
	 * Provides one advanced filter at a time.
	 *
	 * @return array<string, array{array}>
	 */
	public static function provide_advanced_filters(): array {
		return array(
			'outcome'        => array( array( 'outcome' => 'generated' ) ),
			'instance'       => array( array( 'instance' => 'site-a' ) ),
			'user'           => array( array( 'user_id' => '42' ) ),
			'output verdict' => array( array( 'output_verdict' => Filters::VERDICT_ANY ) ),
			'other reply'    => array( array( 'other_reply' => 'unknown' ) ),
			'empty recall'   => array( array( 'recall' => 'empty' ) ),
			'answers differ' => array( array( 'answers' => 'differ' ) ),
		);
	}

	/**
	 * Several advanced filters add up.
	 *
	 * @return void
	 */
	public function test_advanced_filters_add_up(): void {
		$filters = self::filters(
			array(
				'outcome'        => 'incomplete',
				'user_id'        => '42',
				'output_verdict' => Filters::VERDICT_NONE,
				'recall'         => 'empty',
				'answers'        => 'differ',
			)
		);

		$this->assertSame( 5, $filters->advanced_count() );
	}

	/**
	 * The filters that are always visible, the search and the view settings are not advanced filters.
	 *
	 * @return void
	 */
	public function test_visible_filters_and_view_settings_are_not_counted(): void {
		$filters = self::filters(
			array(
				'period'        => 'week',
				'guard'         => 'absent',
				'input_verdict' => Filters::VERDICT_ANY,
				'search'        => 'hello',
				'orderby'       => 'duration_ms',
				'order'         => 'ASC',
				'per_page'      => '50',
				'paged'         => '3',
			)
		);

		$this->assertSame( 0, $filters->advanced_count() );
	}

	/**
	 * A value that is not valid is dropped, so it does not count.
	 *
	 * @return void
	 */
	public function test_invalid_values_are_not_counted(): void {
		$filters = self::filters(
			array(
				'outcome'     => 'deleted',
				'other_reply' => 'maybe',
				'recall'      => 'yes',
				'answers'     => 'same',
				'instance'    => '   ',
			)
		);

		$this->assertSame( 0, $filters->advanced_count() );
	}

	/**
	 * Sorting or page size that differ from the defaults are a custom view.
	 *
	 * @dataProvider provide_custom_views
	 *
	 * @param array $input Raw values.
	 * @return void
	 */
	public function test_custom_view( array $input ): void {
		$filters = self::filters( $input );

		$this->assertTrue( $filters->has_custom_view() );
		$this->assertSame( 0, $filters->advanced_count(), 'A custom view is not an advanced filter.' );
	}

	/**
	 * Provides view settings that differ from the defaults.
	 *
	 * @return array<string, array{array}>
	 */
	public static function provide_custom_views(): array {
		return array(
			'sort column'     => array( array( 'orderby' => 'duration_ms' ) ),
			'ascending order' => array( array( 'order' => 'ASC' ) ),
			'bigger page'     => array( array( 'per_page' => '50' ) ),
			'biggest page'    => array( array( 'per_page' => '100' ) ),
		);
	}

	/**
	 * The defaults, written out or implied, are not a custom view.
	 *
	 * @return void
	 */
	public function test_default_view_values_are_not_custom(): void {
		$this->assertFalse( self::filters( array( 'orderby' => 'ts' ) )->has_custom_view() );
		$this->assertFalse( self::filters( array( 'order' => 'DESC' ) )->has_custom_view() );
		$this->assertFalse( self::filters( array( 'per_page' => '20' ) )->has_custom_view() );
		$this->assertFalse( self::filters( array( 'orderby' => 'nonsense', 'order' => 'sideways', 'per_page' => '37' ) )->has_custom_view(), 'Invalid values fall back to the defaults.' );
		$this->assertFalse( self::filters( array( 'paged' => '4' ) )->has_custom_view(), 'The page number is not a view setting.' );
	}

	/**
	 * Unicode text is preserved.
	 *
	 * @return void
	 */
	public function test_unicode_search_is_preserved(): void {
		$this->assertSame( 'perché città', self::filters( array( 'search' => 'perché città' ) )->search() );
	}
}
