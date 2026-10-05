<?php
/**
 * Validated query filters.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Repository;

use DateTimeImmutable;
use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable set of filters, sorting and pagination for the interactions list.
 *
 * Every value is validated on entry: whatever is not valid is dropped and its
 * key is reported by `errors()`, so the repository only ever sees safe values.
 */
class Filters {

	/**
	 * Allowed outcomes (the ENUM of the source table).
	 */
	public const OUTCOMES = array( 'generated', 'fast_reply', 'incomplete' );

	/**
	 * Columns the list can be sorted by.
	 */
	public const SORTABLE = array( 'ts', 'outcome', 'instance', 'user_id', 'duration_ms' );

	/**
	 * Allowed page sizes; the first is the default.
	 */
	public const PER_PAGE_OPTIONS = array( 20, 50, 100 );

	/**
	 * Verdict filter value: any verdict (a block was recorded).
	 */
	public const VERDICT_ANY = '__any__';

	/**
	 * Verdict filter value: no verdict (`NULL`, no block recorded).
	 */
	public const VERDICT_NONE = '__none__';

	/**
	 * Guardrails filter value: Guardrails present.
	 */
	public const GUARD_PRESENT = 'present';

	/**
	 * Guardrails filter value: Guardrails absent.
	 */
	public const GUARD_ABSENT = 'absent';

	/**
	 * Other-plugin-reply filter value: replied (`1`).
	 */
	public const REPLY_YES = 'yes';

	/**
	 * Other-plugin-reply filter value: did not reply (`0`).
	 */
	public const REPLY_NO = 'no';

	/**
	 * Other-plugin-reply filter value: not recorded (`NULL`).
	 */
	public const REPLY_UNKNOWN = 'unknown';

	/**
	 * Tools filter value: at least one tool or form ran.
	 */
	public const TOOLS_YES = 'yes';

	/**
	 * Tools filter value: no tool or form ran.
	 */
	public const TOOLS_NO = 'no';

	/**
	 * Longest text kept for instance and user filters.
	 */
	private const MAX_IDENTIFIER_LENGTH = 255;

	/**
	 * Longest verdict kept (matches the `VARCHAR(64)` column).
	 */
	private const MAX_VERDICT_LENGTH = 64;

	/**
	 * Longest search text kept.
	 */
	private const MAX_SEARCH_LENGTH = 200;

	/**
	 * Highest page number accepted.
	 */
	private const MAX_PAGE = 100000;

	/**
	 * Time period.
	 *
	 * @var Period
	 */
	private $period;

	/**
	 * Filter values keyed by name; absent keys mean "no filter".
	 *
	 * @var array<string, mixed>
	 */
	private $values;

	/**
	 * Column to sort by.
	 *
	 * @var string
	 */
	private $orderby;

	/**
	 * Sort direction, `ASC` or `DESC`.
	 *
	 * @var string
	 */
	private $order;

	/**
	 * Page number, from 1.
	 *
	 * @var int
	 */
	private $page;

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	private $per_page;

	/**
	 * Keys of the input values that were not valid.
	 *
	 * @var string[]
	 */
	private $errors;

	/**
	 * Constructor.
	 *
	 * @param Period               $period   Time period.
	 * @param array<string, mixed> $values   Validated filter values.
	 * @param string               $orderby  Sort column.
	 * @param string               $order    Sort direction.
	 * @param int                  $page     Page number.
	 * @param int                  $per_page Rows per page.
	 * @param string[]             $errors   Keys of the invalid input values.
	 */
	private function __construct( Period $period, array $values, string $orderby, string $order, int $page, int $per_page, array $errors ) {
		$this->period   = $period;
		$this->values   = $values;
		$this->orderby  = $orderby;
		$this->order    = $order;
		$this->page     = $page;
		$this->per_page = $per_page;
		$this->errors   = $errors;
	}

	/**
	 * Builds the filters from request values, dropping the invalid ones.
	 *
	 * @param array             $input Raw values (already unslashed), keyed by the names listed in the class.
	 * @param DateTimeImmutable $now   Current time in the local time zone.
	 * @return self
	 */
	public static function from_array( array $input, DateTimeImmutable $now ): self {
		$errors = array();
		$values = array();

		$period = self::read_period( $input, $now, $errors );

		$outcome = self::text( $input, 'outcome', 20 );
		if ( null !== $outcome ) {
			if ( in_array( $outcome, self::OUTCOMES, true ) ) {
				$values['outcome'] = $outcome;
			} else {
				$errors[] = 'outcome';
			}
		}

		foreach ( array(
			'instance' => self::MAX_IDENTIFIER_LENGTH,
			'user_id'  => self::MAX_IDENTIFIER_LENGTH,
		) as $key => $max ) {
			$text = self::text( $input, $key, $max );
			if ( null !== $text ) {
				$values[ $key ] = $text;
			}
		}

		$guard = self::text( $input, 'guard', 10 );
		if ( null !== $guard ) {
			if ( in_array( $guard, array( self::GUARD_PRESENT, self::GUARD_ABSENT ), true ) ) {
				$values['guard'] = $guard;
			} else {
				$errors[] = 'guard';
			}
		}

		foreach ( array( 'input_verdict', 'output_verdict' ) as $key ) {
			$verdict = self::text( $input, $key, self::MAX_VERDICT_LENGTH );
			if ( null !== $verdict ) {
				$values[ $key ] = $verdict;
			}
		}

		$reply = self::text( $input, 'other_reply', 10 );
		if ( null !== $reply ) {
			if ( in_array( $reply, array( self::REPLY_YES, self::REPLY_NO, self::REPLY_UNKNOWN ), true ) ) {
				$values['other_reply'] = $reply;
			} else {
				$errors[] = 'other_reply';
			}
		}

		$tools = self::text( $input, 'tools', 10 );
		if ( null !== $tools ) {
			if ( in_array( $tools, array( self::TOOLS_YES, self::TOOLS_NO ), true ) ) {
				$values['tools'] = $tools;
			} else {
				$errors[] = 'tools';
			}
		}

		$tool = self::text( $input, 'tool', self::MAX_IDENTIFIER_LENGTH );
		if ( null !== $tool ) {
			$values['tool'] = $tool;
		}

		if ( 'empty' === self::text( $input, 'recall', 10 ) ) {
			$values['recall_empty'] = true;
		}

		if ( 'differ' === self::text( $input, 'answers', 10 ) ) {
			$values['answers_differ'] = true;
		}

		$search = self::text( $input, 'search', self::MAX_SEARCH_LENGTH );
		if ( null !== $search ) {
			$values['search'] = $search;
		}

		$orderby = self::text( $input, 'orderby', 20 );
		if ( null === $orderby || ! in_array( $orderby, self::SORTABLE, true ) ) {
			if ( null !== $orderby ) {
				$errors[] = 'orderby';
			}
			$orderby = 'ts';
		}

		$order = strtoupper( (string) self::text( $input, 'order', 4 ) );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		// The page size is not clamped to the largest option: a value that is not allowed falls back to the default.
		$page     = self::integer( $input, 'paged', 1, self::MAX_PAGE );
		$per_page = self::integer( $input, 'per_page', self::PER_PAGE_OPTIONS[0], self::MAX_PAGE );

		if ( ! in_array( $per_page, self::PER_PAGE_OPTIONS, true ) ) {
			$per_page = self::PER_PAGE_OPTIONS[0];
		}

		return new self( $period, $values, $orderby, $order, $page, $per_page, array_values( array_unique( $errors ) ) );
	}

	/**
	 * Returns the time period.
	 *
	 * @return Period
	 */
	public function period(): Period {
		return $this->period;
	}

	/**
	 * Returns the outcome filter.
	 *
	 * @return string|null
	 */
	public function outcome(): ?string {
		return $this->values['outcome'] ?? null;
	}

	/**
	 * Returns the instance filter.
	 *
	 * @return string|null
	 */
	public function instance(): ?string {
		return $this->values['instance'] ?? null;
	}

	/**
	 * Returns the user id filter (exact match).
	 *
	 * @return string|null
	 */
	public function user_id(): ?string {
		return $this->values['user_id'] ?? null;
	}

	/**
	 * Returns the Guardrails filter.
	 *
	 * @return string|null GUARD_PRESENT, GUARD_ABSENT or null.
	 */
	public function guard(): ?string {
		return $this->values['guard'] ?? null;
	}

	/**
	 * Returns the input verdict filter.
	 *
	 * @return string|null VERDICT_ANY, VERDICT_NONE, a specific verdict or null.
	 */
	public function input_verdict(): ?string {
		return $this->values['input_verdict'] ?? null;
	}

	/**
	 * Returns the output verdict filter.
	 *
	 * @return string|null VERDICT_ANY, VERDICT_NONE, a specific verdict or null.
	 */
	public function output_verdict(): ?string {
		return $this->values['output_verdict'] ?? null;
	}

	/**
	 * Returns the other-plugin-reply filter.
	 *
	 * @return string|null REPLY_YES, REPLY_NO, REPLY_UNKNOWN or null.
	 */
	public function other_reply(): ?string {
		return $this->values['other_reply'] ?? null;
	}

	/**
	 * Returns the tools filter.
	 *
	 * @return string|null TOOLS_YES, TOOLS_NO or null.
	 */
	public function tools(): ?string {
		return $this->values['tools'] ?? null;
	}

	/**
	 * Returns the name of the tool (or form) that must appear among the ones used.
	 *
	 * @return string|null
	 */
	public function tool(): ?string {
		return $this->values['tool'] ?? null;
	}

	/**
	 * Tells whether only interactions with an empty recall are wanted.
	 *
	 * @return bool
	 */
	public function recall_empty(): bool {
		return true === ( $this->values['recall_empty'] ?? false );
	}

	/**
	 * Tells whether only interactions whose generated and delivered answers differ are wanted.
	 *
	 * @return bool
	 */
	public function answers_differ(): bool {
		return true === ( $this->values['answers_differ'] ?? false );
	}

	/**
	 * Returns the search text.
	 *
	 * @return string|null
	 */
	public function search(): ?string {
		return $this->values['search'] ?? null;
	}

	/**
	 * Returns the column to sort by.
	 *
	 * @return string One of SORTABLE.
	 */
	public function orderby(): string {
		return $this->orderby;
	}

	/**
	 * Returns the sort direction.
	 *
	 * @return string `ASC` or `DESC`.
	 */
	public function order(): string {
		return $this->order;
	}

	/**
	 * Returns the page number.
	 *
	 * @return int
	 */
	public function page(): int {
		return $this->page;
	}

	/**
	 * Returns the number of rows per page.
	 *
	 * @return int
	 */
	public function per_page(): int {
		return $this->per_page;
	}

	/**
	 * Returns the offset of the first row of the page.
	 *
	 * @return int
	 */
	public function offset(): int {
		return ( $this->page - 1 ) * $this->per_page;
	}

	/**
	 * Returns the keys of the input values that were dropped as invalid.
	 *
	 * @return string[]
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Counts the active filters shown in the "advanced filters" section.
	 *
	 * Period, search, Guardrails, the input verdict and the tools yes/no choice are always
	 * visible and are not counted here; neither are sorting and page size, which change the view and not
	 * the rows that match.
	 *
	 * @return int
	 */
	public function advanced_count(): int {
		$count = 0;

		foreach ( array( 'outcome', 'instance', 'user_id', 'output_verdict', 'other_reply', 'tool' ) as $key ) {
			if ( isset( $this->values[ $key ] ) ) {
				++$count;
			}
		}

		if ( $this->recall_empty() ) {
			++$count;
		}

		if ( $this->answers_differ() ) {
			++$count;
		}

		return $count;
	}

	/**
	 * Tells whether sorting or page size differ from their defaults.
	 *
	 * @return bool
	 */
	public function has_custom_view(): bool {
		return 'ts' !== $this->orderby || 'DESC' !== $this->order || self::PER_PAGE_OPTIONS[0] !== $this->per_page;
	}

	/**
	 * Returns the same filters on another page.
	 *
	 * @param int $page Page number, from 1.
	 * @return self
	 */
	public function with_page( int $page ): self {
		return new self( $this->period, $this->values, $this->orderby, $this->order, max( 1, min( $page, self::MAX_PAGE ) ), $this->per_page, $this->errors );
	}

	/**
	 * Returns the non-default filters as URL arguments, never including the search text.
	 *
	 * The search text may be personal data and must not reach a URL: it travels in a POST body.
	 *
	 * @return array<string, string|int> Arguments named like the keys read by from_array().
	 */
	public function to_query_args(): array {
		$args = array();

		// The default preset is left out of the URL; any other one, "today" included, is written out.
		if ( Period::DEFAULT_PRESET !== $this->period->key() ) {
			$args['period'] = $this->period->key();

			if ( Period::CUSTOM === $this->period->key() ) {
				$args['from'] = $this->period->start()->format( 'Y-m-d\TH:i' );
				$args['to']   = $this->period->end()->format( 'Y-m-d\TH:i' );
			}
		}

		foreach ( array(
			'outcome'        => 'outcome',
			'instance'       => 'instance',
			'user_id'        => 'user_id',
			'guard'          => 'guard',
			'input_verdict'  => 'input_verdict',
			'output_verdict' => 'output_verdict',
			'other_reply'    => 'other_reply',
			'tools'          => 'tools',
			'tool'           => 'tool',
		) as $name => $key ) {
			if ( isset( $this->values[ $key ] ) ) {
				$args[ $name ] = $this->values[ $key ];
			}
		}

		if ( $this->recall_empty() ) {
			$args['recall'] = 'empty';
		}

		if ( $this->answers_differ() ) {
			$args['answers'] = 'differ';
		}

		if ( 'ts' !== $this->orderby ) {
			$args['orderby'] = $this->orderby;
		}

		if ( 'DESC' !== $this->order ) {
			$args['order'] = $this->order;
		}

		if ( self::PER_PAGE_OPTIONS[0] !== $this->per_page ) {
			$args['per_page'] = $this->per_page;
		}

		if ( $this->page > 1 ) {
			$args['paged'] = $this->page;
		}

		return $args;
	}

	/**
	 * Reads the period from the input: a preset, or a custom interval.
	 *
	 * @param array             $input  Raw values.
	 * @param DateTimeImmutable $now    Current local time.
	 * @param string[]          $errors Collects the invalid keys.
	 * @return Period
	 */
	private static function read_period( array $input, DateTimeImmutable $now, array &$errors ): Period {
		$default = Period::preset( Period::DEFAULT_PRESET, $now );
		$key     = self::text( $input, 'period', 10 );

		if ( null === $key ) {
			return $default;
		}

		if ( Period::CUSTOM !== $key ) {
			$preset = Period::preset( $key, $now );

			if ( null === $preset ) {
				$errors[] = 'period';
				return $default;
			}

			return $preset;
		}

		$zone  = $now->getTimezone();
		$start = Period::parse_local( (string) self::text( $input, 'from', 25 ), $zone );
		$end   = Period::parse_local( (string) self::text( $input, 'to', 25 ), $zone, true );

		if ( null === $start || null === $end ) {
			$errors[] = 'period';
			return $default;
		}

		try {
			return Period::custom( $start, $end );
		} catch ( InvalidArgumentException $exception ) {
			$errors[] = 'period';
			return $default;
		}
	}

	/**
	 * Reads a text value: trimmed, without control characters, limited in length.
	 *
	 * @param array  $input Raw values.
	 * @param string $key   Value name.
	 * @param int    $max   Maximum length in characters.
	 * @return string|null Null when absent or empty.
	 */
	private static function text( array $input, string $key, int $max ): ?string {
		if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
			return null;
		}

		$text = preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $input[ $key ] );

		if ( null === $text ) {
			return null;
		}

		$text = trim( $text );

		if ( '' === $text ) {
			return null;
		}

		return mb_substr( $text, 0, $max );
	}

	/**
	 * Reads a positive integer, clamped to a range.
	 *
	 * @param array  $input   Raw values.
	 * @param string $key     Value name.
	 * @param int    $fallback Value used when absent or not numeric.
	 * @param int    $max     Highest value accepted.
	 * @return int
	 */
	private static function integer( array $input, string $key, int $fallback, int $max ): int {
		$text = self::text( $input, $key, 10 );

		if ( null === $text || 1 !== preg_match( '/^[0-9]+$/', $text ) ) {
			return $fallback;
		}

		return max( 1, min( (int) $text, $max ) );
	}
}
