<?php
/**
 * Interactions list table.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

use DateTimeZone;
use RILM\Repository\Filters;
use RILM\Repository\Interaction;
use RILM\Repository\Interaction_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table of interactions, paginated by the database.
 *
 * Every database value is untrusted text: it is escaped here, at output. While a
 * search is active the table is inside a POST form, so the search text never
 * reaches a URL: pagination then uses submit buttons instead of links, and the
 * column headers are not sortable (the form has sort selects instead).
 */
class Interactions_List_Table extends \WP_List_Table {

	/**
	 * Characters of a text shown in the collapsed preview.
	 */
	public const SUMMARY_LENGTH = 120;

	/**
	 * Interactions source, null when the log database is not usable.
	 *
	 * @var Interaction_Repository|null
	 */
	private $repository;

	/**
	 * Filters of the current request.
	 *
	 * @var Filters
	 */
	private $filters;

	/**
	 * Time zone used to show the dates.
	 *
	 * @var DateTimeZone
	 */
	private $zone;

	/**
	 * Whether a search is active, so the state travels in a POST form.
	 *
	 * @var bool
	 */
	private $post_mode;

	/**
	 * Constructor.
	 *
	 * @param Interaction_Repository|null $repository Interactions source.
	 * @param Filters                     $filters    Filters of the current request.
	 * @param DateTimeZone                $zone       Time zone used to show the dates.
	 */
	public function __construct( ?Interaction_Repository $repository, Filters $filters, DateTimeZone $zone ) {
		parent::__construct(
			array(
				'singular' => 'interaction',
				'plural'   => 'interactions',
				'ajax'     => false,
			)
		);

		$this->repository = $repository;
		$this->filters    = $filters;
		$this->zone       = $zone;
		$this->post_mode  = null !== $filters->search();
	}

	/**
	 * Returns the filters, with the page corrected to an existing one.
	 *
	 * @return Filters
	 */
	public function filters(): Filters {
		return $this->filters;
	}

	/**
	 * Loads the rows of the current page. Called before display().
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'ts' );

		if ( null === $this->repository ) {
			$this->items = array();
			$this->set_pagination_args(
				array(
					'total_items' => 0,
					'per_page'    => $this->filters->per_page(),
					'total_pages' => 1,
				)
			);
			return;
		}

		$total       = $this->repository->count( $this->filters );
		$total_pages = max( 1, (int) ceil( $total / $this->filters->per_page() ) );

		// A page past the end (for example after the filters narrowed) shows the last page.
		if ( $this->filters->page() > $total_pages ) {
			$this->filters = $this->filters->with_page( $total_pages );
		}

		$this->items = $this->repository->find_page( $this->filters );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $this->filters->per_page(),
				'total_pages' => $total_pages,
			)
		);
	}

	/**
	 * Returns the columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'ts'             => __( 'Date and time', 'rag-interaction-logger-monitor' ),
			'outcome'        => __( 'Outcome', 'rag-interaction-logger-monitor' ),
			'instance'       => __( 'Instance', 'rag-interaction-logger-monitor' ),
			'user_id'        => __( 'User', 'rag-interaction-logger-monitor' ),
			'duration_ms'    => __( 'Duration', 'rag-interaction-logger-monitor' ),
			'guard'          => __( 'Guardrails', 'rag-interaction-logger-monitor' ),
			'input_verdict'  => __( 'Input verdict', 'rag-interaction-logger-monitor' ),
			'output_verdict' => __( 'Output verdict', 'rag-interaction-logger-monitor' ),
			'question'       => __( 'Question', 'rag-interaction-logger-monitor' ),
			'delivered'      => __( 'Delivered answer', 'rag-interaction-logger-monitor' ),
		);
	}

	/**
	 * Returns the sortable columns: none while a search is active, because a sort link would drop the search.
	 *
	 * @return array<string, array>
	 */
	protected function get_sortable_columns() {
		if ( $this->post_mode ) {
			return array();
		}

		return array(
			'ts'          => array( 'ts', true ),
			'outcome'     => array( 'outcome', false ),
			'instance'    => array( 'instance', false ),
			'user_id'     => array( 'user_id', false ),
			'duration_ms' => array( 'duration_ms', false ),
		);
	}

	/**
	 * Returns the table CSS classes (no fixed layout: there are many columns).
	 *
	 * @return string[]
	 */
	protected function get_table_classes() {
		return array( 'widefat', 'striped', 'rilm-table' );
	}

	/**
	 * Prints the message shown when no row matches.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No interactions found for the selected period and filters.', 'rag-interaction-logger-monitor' );
	}

	/**
	 * Prints the pagination: links as usual, submit buttons while a search is active.
	 *
	 * It does not use the "jump to page" box of WordPress: inside this form that text
	 * field would be sent with the apply button and keep an old page number on new filters.
	 *
	 * @param string $which `top` or `bottom`.
	 * @return void
	 */
	protected function pagination( $which ) {
		$total_items = (int) $this->_pagination_args['total_items'];
		$total_pages = (int) $this->_pagination_args['total_pages'];
		$current     = $this->filters->page();

		echo '<div class="tablenav-pages' . ( $total_pages <= 1 ? ' one-page' : '' ) . '">';

		printf(
			'<span class="displaying-num">%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: number of interactions. */
					_n( '%s item', '%s items', $total_items, 'rag-interaction-logger-monitor' ),
					number_format_i18n( $total_items )
				)
			)
		);

		if ( $total_pages > 1 ) {
			echo '<span class="pagination-links">';
			$this->page_control( 'first-page', 1, '«', __( 'First page', 'rag-interaction-logger-monitor' ), $current <= 1 );
			$this->page_control( 'prev-page', max( 1, $current - 1 ), '‹', __( 'Previous page', 'rag-interaction-logger-monitor' ), $current <= 1 );
			printf(
				'<span class="tablenav-paging-text">%s</span>',
				esc_html(
					sprintf(
						/* translators: 1: current page, 2: total pages. */
						__( 'Page %1$s of %2$s', 'rag-interaction-logger-monitor' ),
						number_format_i18n( $current ),
						number_format_i18n( $total_pages )
					)
				)
			);
			$this->page_control( 'next-page', min( $total_pages, $current + 1 ), '›', __( 'Next page', 'rag-interaction-logger-monitor' ), $current >= $total_pages );
			$this->page_control( 'last-page', $total_pages, '»', __( 'Last page', 'rag-interaction-logger-monitor' ), $current >= $total_pages );
			echo '</span>';
		}

		echo '</div>';
	}

	/**
	 * Column: date and time in the site time zone, linked to the detail page.
	 *
	 * @param Interaction $item Row.
	 * @return string
	 */
	public function column_ts( $item ) {
		$local = $item->timestamp()->setTimezone( $this->zone )->format( 'Y-m-d H:i:s' );
		$url   = add_query_arg(
			array(
				'page' => Menu::SLUG_DETAIL,
				'id'   => $item->id,
			),
			admin_url( 'admin.php' )
		);

		return sprintf(
			'<a href="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></a>',
			esc_url( $url ),
			esc_html( $local ),
			esc_html(
				sprintf(
					/* translators: %d: interaction id. */
					__( '(view the details of interaction %d)', 'rag-interaction-logger-monitor' ),
					$item->id
				)
			)
		);
	}

	/**
	 * Column: outcome, as text.
	 *
	 * @param Interaction $item Row.
	 * @return string
	 */
	public function column_outcome( $item ) {
		switch ( $item->outcome ) {
			case 'generated':
				return esc_html__( 'Generated', 'rag-interaction-logger-monitor' );
			case 'fast_reply':
				return esc_html__( 'Fast reply', 'rag-interaction-logger-monitor' );
			case 'incomplete':
				return esc_html__( 'Incomplete', 'rag-interaction-logger-monitor' );
			default:
				return esc_html( $item->outcome );
		}
	}

	/**
	 * Column: duration in milliseconds.
	 *
	 * @param Interaction $item Row.
	 * @return string
	 */
	public function column_duration_ms( $item ) {
		if ( null === $item->duration_ms ) {
			return $this->not_recorded();
		}

		return esc_html(
			sprintf(
				/* translators: %s: duration in milliseconds. */
				__( '%s ms', 'rag-interaction-logger-monitor' ),
				number_format_i18n( $item->duration_ms )
			)
		);
	}

	/**
	 * Column: whether Guardrails ran, as text.
	 *
	 * @param Interaction $item Row.
	 * @return string
	 */
	public function column_guard( $item ) {
		return $item->guard_present
			? esc_html__( 'Present', 'rag-interaction-logger-monitor' )
			: esc_html__( 'Absent', 'rag-interaction-logger-monitor' );
	}

	/**
	 * Column: input verdict. No verdict means that no block was recorded.
	 *
	 * @param Interaction $item Row.
	 * @return string
	 */
	public function column_input_verdict( $item ) {
		return $this->verdict( $item->input_verdict );
	}

	/**
	 * Column: output verdict. No verdict means that no block was recorded.
	 *
	 * @param Interaction $item Row.
	 * @return string
	 */
	public function column_output_verdict( $item ) {
		return $this->verdict( $item->output_verdict );
	}

	/**
	 * Column: preview of the question.
	 *
	 * @param Interaction $item Row.
	 * @return string
	 */
	public function column_question( $item ) {
		return $this->preview( $item->question, $item->question_truncated, $item->id );
	}

	/**
	 * Column: preview of the delivered answer.
	 *
	 * @param Interaction $item Row.
	 * @return string
	 */
	public function column_delivered( $item ) {
		return $this->preview( $item->delivered, $item->delivered_truncated, $item->id );
	}

	/**
	 * Column: plain text values (instance, user).
	 *
	 * @param Interaction $item        Row.
	 * @param string      $column_name Column name.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'instance':
				return esc_html( $item->instance );
			case 'user_id':
				return esc_html( $item->user_id );
			default:
				return '';
		}
	}

	/**
	 * Prints one pagination control: a link, or a submit button while a search is active.
	 *
	 * @param string $class_name Control class.
	 * @param int    $page       Page the control leads to.
	 * @param string $symbol     Visible symbol.
	 * @param string $label      Accessible name.
	 * @param bool   $disabled   Whether the control is inactive.
	 * @return void
	 */
	private function page_control( string $class_name, int $page, string $symbol, string $label, bool $disabled ): void {
		if ( $this->post_mode ) {
			printf(
				'<button type="submit" class="%1$s button" name="paged" value="%2$d"%3$s><span class="screen-reader-text">%4$s</span><span aria-hidden="true">%5$s</span></button> ',
				esc_attr( $class_name ),
				(int) $page,
				$disabled ? ' disabled="disabled"' : '',
				esc_html( $label ),
				esc_html( $symbol )
			);
			return;
		}

		if ( $disabled ) {
			printf( '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">%s</span> ', esc_html( $symbol ) );
			return;
		}

		printf(
			'<a class="%1$s button" href="%2$s"><span class="screen-reader-text">%3$s</span><span aria-hidden="true">%4$s</span></a> ',
			esc_attr( $class_name ),
			esc_url( $this->page_url( $page ) ),
			esc_html( $label ),
			esc_html( $symbol )
		);
	}

	/**
	 * Returns the URL of a page of the current view (non-sensitive filters only).
	 *
	 * @param int $page Page number.
	 * @return string
	 */
	private function page_url( int $page ): string {
		$args = $this->filters->to_query_args();

		unset( $args['paged'] );

		if ( $page > 1 ) {
			$args['paged'] = $page;
		}

		return add_query_arg( array( 'page' => Menu::SLUG_INTERACTIONS ) + $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Returns the text of a verdict.
	 *
	 * @param string|null $verdict Verdict, or null when no block was recorded.
	 * @return string
	 */
	private function verdict( ?string $verdict ): string {
		return null === $verdict ? esc_html__( 'None', 'rag-interaction-logger-monitor' ) : esc_html( $verdict );
	}

	/**
	 * Returns the markup of a value that was not recorded.
	 *
	 * @return string
	 */
	private function not_recorded(): string {
		return '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'Not recorded', 'rag-interaction-logger-monitor' ) . '</span>';
	}

	/**
	 * Returns the markup of a text preview: short texts inline, long ones in a collapsible block.
	 *
	 * @param string|null $text      Text, or null when not recorded.
	 * @param bool        $truncated Whether the text was cut to the preview length by the query.
	 * @param int         $id        Interaction id, for the link to the full text.
	 * @return string
	 */
	private function preview( ?string $text, bool $truncated, int $id ): string {
		if ( null === $text ) {
			return $this->not_recorded();
		}

		if ( '' === $text ) {
			return '<em>' . esc_html__( '(empty)', 'rag-interaction-logger-monitor' ) . '</em>';
		}

		if ( ! $truncated && mb_strlen( $text ) <= self::SUMMARY_LENGTH ) {
			return '<span class="rilm-text">' . esc_html( $text ) . '</span>';
		}

		$summary = trim( (string) preg_replace( '/\s+/u', ' ', mb_substr( $text, 0, self::SUMMARY_LENGTH ) ) );
		$html    = '<details class="rilm-details"><summary>' . esc_html( $summary ) . '&hellip;<span class="screen-reader-text"> ' . esc_html__( '(expand to read more)', 'rag-interaction-logger-monitor' ) . '</span></summary>';
		$html   .= '<div class="rilm-text">' . esc_html( $text ) . '</div>';

		if ( $truncated ) {
			$url   = add_query_arg(
				array(
					'page' => Menu::SLUG_DETAIL,
					'id'   => $id,
				),
				admin_url( 'admin.php' )
			);
			$html .= '<p class="description">' . esc_html(
				sprintf(
					/* translators: %d: number of characters shown. */
					__( 'Shortened to %d characters.', 'rag-interaction-logger-monitor' ),
					Interaction_Repository::PREVIEW_LENGTH
				)
			) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Open the details for the full text', 'rag-interaction-logger-monitor' ) . '</a></p>';
		}

		return $html . '</details>';
	}
}
