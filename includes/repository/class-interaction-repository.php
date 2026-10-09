<?php
/**
 * Read-only repository of the interactions.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Repository;

use LogicException;
use RILM\Config\Config;
use RILM\Database\Connection;
use RILM\Database\Optional_Columns;
use RILM\Database\Reader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only place that builds SQL. It issues `SELECT` statements only.
 *
 * Values always go through `prepare()`. The table and column names that are
 * concatenated come from the validated configuration or from internal whitelists,
 * never from the request.
 */
class Interaction_Repository {

	/**
	 * Characters kept of the question and delivered answer in the list.
	 */
	public const PREVIEW_LENGTH = 1000;

	/**
	 * Columns of the list, without the long texts.
	 */
	private const LIST_COLUMNS = 'id, ts, duration_ms, instance, user_id, turn_id, outcome, guard_present, input_verdict, output_verdict, other_plugin_reply, recall_count, recall_top_score';

	/**
	 * Columns that can be listed as distinct values.
	 */
	private const DISTINCT_COLUMNS = array( 'instance', 'input_verdict', 'output_verdict' );

	/**
	 * Most distinct values returned.
	 */
	private const DISTINCT_LIMIT = 200;

	/**
	 * Most distinct combinations of tools read to build the per-tool counts.
	 */
	private const TOOL_COMBINATION_LIMIT = 500;

	/**
	 * Most verdicts returned by a count.
	 */
	private const VERDICT_LIMIT = 50;

	/**
	 * Condition that defines a completed turn: an outcome other than `incomplete` and a known duration.
	 */
	private const COMPLETED = "outcome <> 'incomplete' AND duration_ms IS NOT NULL";

	/**
	 * The figures of the dashboard that are counts, as filter input keyed by alias.
	 */
	private const SUMMARY_DEFINITIONS = array(
		'generated'     => array( 'outcome' => 'generated' ),
		'fast_reply'    => array( 'outcome' => 'fast_reply' ),
		'incomplete'    => array( 'outcome' => 'incomplete' ),
		'no_guardrails' => array( 'guard' => Filters::GUARD_ABSENT ),
		'input_blocks'  => array( 'input_verdict' => Filters::VERDICT_ANY ),
		'output_blocks' => array( 'output_verdict' => Filters::VERDICT_ANY ),
		'zero_recall'   => array(
			'outcome' => 'generated',
			'recall'  => 'empty',
		),
	);

	/**
	 * Database access.
	 *
	 * @var Reader
	 */
	private $db;

	/**
	 * Quoted `database`.`table` name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Optional columns that exist in the table.
	 *
	 * @var string[]
	 */
	private $optional_columns;

	/**
	 * Constructor.
	 *
	 * @param Reader   $db               Database access.
	 * @param Config   $config           Valid configuration.
	 * @param string[] $optional_columns Optional columns present in the table.
	 * @throws LogicException When the configuration is not valid.
	 */
	public function __construct( Reader $db, Config $config, array $optional_columns = array() ) {
		if ( Config::STATUS_OK !== $config->status() ) {
			throw new LogicException( 'The repository needs a valid configuration.' );
		}

		$this->db               = $db;
		$this->table            = $config->qualified_table();
		$this->optional_columns = array_values( array_intersect( Optional_Columns::COLUMNS, $optional_columns ) );
	}

	/**
	 * Builds a repository from the connection, when the log database is available.
	 *
	 * @param Connection       $connection Connection to the log database.
	 * @param Optional_Columns $columns    Optional columns detection.
	 * @return self|null Null when the database is not usable.
	 */
	public static function from_connection( Connection $connection, Optional_Columns $columns ): ?self {
		$db = $connection->db();

		if ( null === $db ) {
			return null;
		}

		return new self( $db, $connection->config(), $columns->present() );
	}

	/**
	 * Tells whether the table has the `tools_used` column, so the tools filters and figures exist.
	 *
	 * @return bool
	 */
	public function supports_tools(): bool {
		return in_array( 'tools_used', $this->optional_columns, true );
	}

	/**
	 * Counts the interactions that match the filters.
	 *
	 * @param Filters $filters Filters.
	 * @return int
	 */
	public function count( Filters $filters ): int {
		list( $where, $params ) = $this->where( $filters );

		$query = $this->db->prepare(
			'SELECT COUNT(*) FROM ' . $this->table . ' WHERE ' . $where,
			$params
		);

		return (int) $this->db->get_var( $query );
	}

	/**
	 * Returns one page of interactions, with the texts cut to the preview length.
	 *
	 * @param Filters $filters Filters, sorting and pagination.
	 * @return Interaction[]
	 */
	public function find_page( Filters $filters ): array {
		list( $where, $params ) = $this->where( $filters );

		$preview = (int) ( self::PREVIEW_LENGTH + 1 );
		$columns = self::LIST_COLUMNS . ', LEFT( question, ' . $preview . ' ) AS question, LEFT( delivered, ' . $preview . ' ) AS delivered';

		// This short field lets the list show whether tools ran, without loading their input or output.
		if ( $this->supports_tools() ) {
			$columns .= ', tools_used';
		}

		$params[] = $filters->per_page();
		$params[] = $filters->offset();

		$query = $this->db->prepare(
			'SELECT ' . $columns . ' FROM ' . $this->table . ' WHERE ' . $where . ' ORDER BY ' . $this->order_by( $filters ) . ' LIMIT %d OFFSET %d',
			$params
		);

		$rows = $this->db->get_results( $query, 'ARRAY_A' );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): Interaction {
				return Interaction::from_row( $row, self::PREVIEW_LENGTH );
			},
			$rows
		);
	}

	/**
	 * Returns one interaction with all its texts.
	 *
	 * @param int $id Row id.
	 * @return Interaction|null Null when the id is not a positive integer or no row matches.
	 */
	public function find_by_id( int $id ): ?Interaction {
		if ( $id <= 0 ) {
			return null;
		}

		$columns = self::LIST_COLUMNS . ', question, llm_answer, delivered';

		if ( array() !== $this->optional_columns ) {
			$columns .= ', ' . implode( ', ', $this->optional_columns );
		}

		$query = $this->db->prepare(
			'SELECT ' . $columns . ' FROM ' . $this->table . ' WHERE id = %d LIMIT 1',
			array( $id )
		);

		$rows = $this->db->get_results( $query, 'ARRAY_A' );

		if ( ! is_array( $rows ) || array() === $rows || ! is_array( $rows[0] ) ) {
			return null;
		}

		return Interaction::from_row( $rows[0] );
	}

	/**
	 * Lists the distinct values of a column in a period, to fill a filter.
	 *
	 * @param string $column One of `instance`, `input_verdict`, `output_verdict`.
	 * @param Period $period Period to look into.
	 * @return string[]
	 */
	public function distinct_values( string $column, Period $period ): array {
		if ( ! in_array( $column, self::DISTINCT_COLUMNS, true ) ) {
			return array();
		}

		$query = $this->db->prepare(
			'SELECT DISTINCT ' . $column . ' FROM ' . $this->table . ' WHERE ts >= %s AND ts <= %s AND ' . $column . ' IS NOT NULL ORDER BY ' . $column . ' ASC LIMIT %d',
			array( $period->start_utc(), $period->end_utc(), self::DISTINCT_LIMIT )
		);

		$values = $this->db->get_col( $query );

		return is_array( $values ) ? array_map( 'strval', $values ) : array();
	}

	/**
	 * Counts, in one query, the interactions of each predefined anomaly in a period.
	 *
	 * The conditions are the ones the list applies for the same filters, so a count
	 * always matches what the linked list shows.
	 *
	 * @param Period $period Period to count in.
	 * @return array<string, int>|null Counts keyed by `total` and by anomaly key; null when the query failed.
	 */
	public function anomaly_counts( Period $period ): ?array {
		$row = $this->aggregate( Anomalies::definitions( $this->optional_columns ), array(), $period );

		if ( null === $row ) {
			return null;
		}

		$counts = array( 'total' => (int) ( $row['total'] ?? 0 ) );

		foreach ( array_keys( Anomalies::definitions( $this->optional_columns ) ) as $key ) {
			// SUM() is NULL when no row matches the period.
			$counts[ $key ] = (int) ( $row[ $key ] ?? 0 );
		}

		return $counts;
	}

	/**
	 * Returns, in one query, the figures of the dashboard for a period.
	 *
	 * @param Period $period Period to summarise.
	 * @return array{total: int, generated: int, fast_reply: int, incomplete: int, no_guardrails: int, input_blocks: int, output_blocks: int, zero_recall: int, completed: int, average_ms: float|null, tools?: int}|null Null when the query failed; `tools` is present only when the table has `tools_used`.
	 */
	public function summary( Period $period ): ?array {
		$completed   = 'CASE WHEN ' . self::COMPLETED . ' THEN 1 ELSE 0 END';
		$definitions = self::SUMMARY_DEFINITIONS;

		if ( $this->supports_tools() ) {
			$definitions['tools'] = array( 'tools' => Filters::TOOLS_YES );
		}

		$row = $this->aggregate(
			$definitions,
			array(
				'completed'  => 'SUM( ' . $completed . ' )',
				'average_ms' => 'AVG( CASE WHEN ' . self::COMPLETED . ' THEN duration_ms END )',
			),
			$period
		);

		if ( null === $row ) {
			return null;
		}

		$summary = array( 'total' => (int) ( $row['total'] ?? 0 ) );

		foreach ( array_keys( $definitions ) as $key ) {
			$summary[ $key ] = (int) ( $row[ $key ] ?? 0 );
		}

		$summary['completed']  = (int) ( $row['completed'] ?? 0 );
		$summary['average_ms'] = isset( $row['average_ms'] ) ? (float) $row['average_ms'] : null;

		return $summary;
	}

	/**
	 * Returns the exact median duration of the completed turns in a period.
	 *
	 * A turn is completed when its outcome is not `incomplete` and its duration is known.
	 * The median is the middle value, or the mean of the two middle values: it is read with
	 * `ORDER BY` and `LIMIT`, which every MySQL and MariaDB version supports.
	 *
	 * @param Period $period    Period to look into.
	 * @param int    $completed Number of completed turns in the period, from summary().
	 * @return float|null Null when there are no completed turns or the query failed.
	 */
	public function median_duration( Period $period, int $completed ): ?float {
		if ( $completed <= 0 ) {
			return null;
		}

		$limit  = 0 === $completed % 2 ? 2 : 1;
		$offset = intdiv( $completed - 1, 2 );

		$query = $this->db->prepare(
			'SELECT duration_ms FROM ' . $this->table . ' WHERE ts >= %s AND ts <= %s AND ' . self::COMPLETED . ' ORDER BY duration_ms ASC LIMIT %d OFFSET %d',
			array( $period->start_utc(), $period->end_utc(), $limit, $offset )
		);

		$values = $this->db->get_col( $query );

		if ( ! is_array( $values ) || array() === $values ) {
			return null;
		}

		return array_sum( array_map( 'floatval', $values ) ) / count( $values );
	}

	/**
	 * Counts, for each tool or form, the turns of a period in which it ran.
	 *
	 * Splitting a comma-separated list is not portable in SQL, so the distinct
	 * combinations are grouped by the database and split here. A turn that lists a
	 * name twice counts once; a turn with several tools counts in each of them.
	 *
	 * @param Period $period Period to look into.
	 * @return array{counts: array<string, int>, truncated: bool}|null Counts keyed by name, most frequent first; `truncated` is true when there were more combinations than were read; null when the table has no `tools_used` or the query failed.
	 */
	public function tool_counts( Period $period ): ?array {
		if ( ! $this->supports_tools() ) {
			return null;
		}

		$query = $this->db->prepare(
			'SELECT tools_used AS names, COUNT(*) AS turns FROM ' . $this->table . " WHERE ts >= %s AND ts <= %s AND tools_used IS NOT NULL AND TRIM( tools_used ) <> '' GROUP BY tools_used ORDER BY turns DESC LIMIT %d",
			array( $period->start_utc(), $period->end_utc(), self::TOOL_COMBINATION_LIMIT + 1 )
		);

		$rows = $this->db->get_results( $query, 'ARRAY_A' );

		if ( ! is_array( $rows ) ) {
			return null;
		}

		$truncated = count( $rows ) > self::TOOL_COMBINATION_LIMIT;
		$counts    = array();

		foreach ( array_slice( $rows, 0, self::TOOL_COMBINATION_LIMIT ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['names'] ) ) {
				continue;
			}

			$names = array_unique( array_filter( array_map( 'trim', explode( ',', (string) $row['names'] ) ), 'strlen' ) );

			foreach ( $names as $name ) {
				$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + (int) ( $row['turns'] ?? 0 );
			}
		}

		uksort(
			$counts,
			static function ( string $a, string $b ) use ( $counts ): int {
				return array( $counts[ $b ], $a ) <=> array( $counts[ $a ], $b );
			}
		);

		return array(
			'counts'    => $counts,
			'truncated' => $truncated,
		);
	}

	/**
	 * Counts the blocks of each verdict in a period.
	 *
	 * @param string $column `input_verdict` or `output_verdict`.
	 * @param Period $period Period to look into.
	 * @return array<string, int> Count keyed by verdict, most frequent first; empty when none or on failure.
	 */
	public function verdict_counts( string $column, Period $period ): array {
		if ( ! in_array( $column, array( 'input_verdict', 'output_verdict' ), true ) ) {
			return array();
		}

		$query = $this->db->prepare(
			'SELECT ' . $column . ' AS verdict, COUNT(*) AS blocks FROM ' . $this->table . ' WHERE ts >= %s AND ts <= %s AND ' . $column . ' IS NOT NULL GROUP BY ' . $column . ' ORDER BY blocks DESC, verdict ASC LIMIT %d',
			array( $period->start_utc(), $period->end_utc(), self::VERDICT_LIMIT )
		);

		$rows = $this->db->get_results( $query, 'ARRAY_A' );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$counts = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['verdict'] ) ) {
				$counts[ (string) $row['verdict'] ] = (int) ( $row['blocks'] ?? 0 );
			}
		}

		return $counts;
	}

	/**
	 * Returns the number of turns, incomplete turns, blocks and, when available, tool turns for each UTC hour of a period.
	 *
	 * The hour is cut from the stored UTC timestamp with `LEFT()`, not formatted with
	 * `DATE_FORMAT()`: its `%d` would be read as a placeholder by `prepare()`. Grouping
	 * the hours into buckets of the site time zone is done in PHP (see `Period_Series`),
	 * which keeps daylight saving changes correct without time zone tables in the database.
	 *
	 * @param Period $period Period to look into.
	 * @return array<int, array{hour: string, turns: int, incomplete: int, input_blocks: int, output_blocks: int, tools?: int}>|null Null when the query failed.
	 */
	public function hourly_series( Period $period ): ?array {
		$tools_select = $this->supports_tools()
			? ", SUM( CASE WHEN ( tools_used IS NOT NULL AND TRIM( tools_used ) <> '' ) THEN 1 ELSE 0 END ) AS tools"
			: '';

		$query = $this->db->prepare(
			"SELECT LEFT( ts, 13 ) AS hour, COUNT(*) AS turns, SUM( CASE WHEN outcome = 'incomplete' THEN 1 ELSE 0 END ) AS incomplete, SUM( CASE WHEN input_verdict IS NOT NULL THEN 1 ELSE 0 END ) AS input_blocks, SUM( CASE WHEN output_verdict IS NOT NULL THEN 1 ELSE 0 END ) AS output_blocks" . $tools_select . ' FROM ' . $this->table . ' WHERE ts >= %s AND ts <= %s GROUP BY LEFT( ts, 13 ) ORDER BY hour ASC',
			array( $period->start_utc(), $period->end_utc() )
		);

		$rows = $this->db->get_results( $query, 'ARRAY_A' );

		if ( ! is_array( $rows ) ) {
			return null;
		}

		$series = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['hour'] ) ) {
				continue;
			}

			$hour = array(
				'hour'          => (string) $row['hour'],
				'turns'         => (int) ( $row['turns'] ?? 0 ),
				'incomplete'    => (int) ( $row['incomplete'] ?? 0 ),
				'input_blocks'  => (int) ( $row['input_blocks'] ?? 0 ),
				'output_blocks' => (int) ( $row['output_blocks'] ?? 0 ),
			);

			if ( $this->supports_tools() ) {
				$hour['tools'] = (int) ( $row['tools'] ?? 0 );
			}

			$series[] = $hour;
		}

		return $series;
	}

	/**
	 * Runs one aggregate query: the total, one conditional sum per definition and some raw expressions.
	 *
	 * The conditions are the ones the list applies for the same filters, so every figure
	 * matches what the linked list shows. Aliases and raw expressions are internal
	 * constants, never request input.
	 *
	 * @param array<string, array<string, string>> $definitions Filter input keyed by the alias of its sum.
	 * @param array<string, string>                $raw         SQL expression keyed by alias.
	 * @param Period                               $period      Period to look into.
	 * @return array<string, mixed>|null The row, or null when the query failed.
	 */
	private function aggregate( array $definitions, array $raw, Period $period ): ?array {
		// The aliases are internal keys, but some (for example `generated`) are
		// reserved words on supported MySQL/MariaDB versions.
		$selects = array( 'COUNT(*) AS `total`' );
		$params  = array();

		foreach ( $definitions as $key => $input ) {
			list( $clauses, $values ) = $this->conditions( Filters::from_array( $input, $period->end() ) );

			$selects[] = 'SUM( CASE WHEN ' . implode( ' AND ', $clauses ) . ' THEN 1 ELSE 0 END ) AS `' . $key . '`';
			$params    = array_merge( $params, $values );
		}

		foreach ( $raw as $key => $expression ) {
			$selects[] = $expression . ' AS `' . $key . '`';
		}

		$params[] = $period->start_utc();
		$params[] = $period->end_utc();

		$query = $this->db->prepare(
			'SELECT ' . implode( ', ', $selects ) . ' FROM ' . $this->table . ' WHERE ts >= %s AND ts <= %s',
			$params
		);

		$rows = $this->db->get_results( $query, 'ARRAY_A' );

		if ( ! is_array( $rows ) || ! isset( $rows[0] ) || ! is_array( $rows[0] ) ) {
			return null;
		}

		return $rows[0];
	}

	/**
	 * Builds the `WHERE` clause and its values from the filters.
	 *
	 * The period is always present, so no query reads the whole table.
	 *
	 * @param Filters $filters Filters.
	 * @return array{0: string, 1: array} Clause and the values for its placeholders.
	 */
	private function where( Filters $filters ): array {
		list( $clauses, $params ) = $this->conditions( $filters );

		array_unshift( $clauses, 'ts >= %s', 'ts <= %s' );
		array_unshift( $params, $filters->period()->start_utc(), $filters->period()->end_utc() );

		return array( implode( ' AND ', $clauses ), $params );
	}

	/**
	 * Builds the conditions of the filters other than the period.
	 *
	 * @param Filters $filters Filters.
	 * @return array{0: string[], 1: array} Conditions and the values for their placeholders.
	 */
	private function conditions( Filters $filters ): array {
		$clauses = array();
		$params  = array();

		if ( null !== $filters->outcome() ) {
			$clauses[] = 'outcome = %s';
			$params[]  = $filters->outcome();
		}

		if ( null !== $filters->instance() ) {
			$clauses[] = 'instance = %s';
			$params[]  = $filters->instance();
		}

		if ( null !== $filters->user_id() ) {
			$clauses[] = 'user_id = %s';
			$params[]  = $filters->user_id();
		}

		if ( Filters::GUARD_PRESENT === $filters->guard() ) {
			$clauses[] = 'guard_present = 1';
		} elseif ( Filters::GUARD_ABSENT === $filters->guard() ) {
			$clauses[] = 'guard_present = 0';
		}

		$this->add_verdict( 'input_verdict', $filters->input_verdict(), $clauses, $params );
		$this->add_verdict( 'output_verdict', $filters->output_verdict(), $clauses, $params );

		if ( Filters::REPLY_YES === $filters->other_reply() ) {
			$clauses[] = 'other_plugin_reply = 1';
		} elseif ( Filters::REPLY_NO === $filters->other_reply() ) {
			$clauses[] = 'other_plugin_reply = 0';
		} elseif ( Filters::REPLY_UNKNOWN === $filters->other_reply() ) {
			$clauses[] = 'other_plugin_reply IS NULL';
		}

		if ( $this->supports_tools() ) {
			$this->add_tools( $filters, $clauses, $params );
		}

		if ( $filters->recall_empty() ) {
			$clauses[] = 'recall_count = 0';
		}

		if ( $filters->answers_differ() ) {
			// The null-safe operator treats two NULLs as equal and NULL against a text as different.
			$clauses[] = 'NOT ( llm_answer <=> delivered )';
		}

		if ( null !== $filters->search() ) {
			// Case-insensitivity comes from the table collation (utf8mb4_unicode_ci).
			$pattern   = '%' . $this->db->esc_like( $filters->search() ) . '%';
			$clauses[] = '( question LIKE %s OR llm_answer LIKE %s OR delivered LIKE %s )';
			$params[]  = $pattern;
			$params[]  = $pattern;
			$params[]  = $pattern;
		}

		return array( $clauses, $params );
	}

	/**
	 * Adds the clauses of the tools filters.
	 *
	 * A name matches only as a whole item of the comma-separated list (a space after the
	 * comma is tolerated), never as part of another name, and `%` and `_` in it are literal.
	 *
	 * @param Filters  $filters Filters.
	 * @param string[] $clauses Clauses being built.
	 * @param array    $params  Values being built.
	 * @return void
	 */
	private function add_tools( Filters $filters, array &$clauses, array &$params ): void {
		if ( Filters::TOOLS_YES === $filters->tools() ) {
			$clauses[] = "( tools_used IS NOT NULL AND TRIM( tools_used ) <> '' )";
		} elseif ( Filters::TOOLS_NO === $filters->tools() ) {
			$clauses[] = "( tools_used IS NULL OR TRIM( tools_used ) = '' )";
		}

		if ( null !== $filters->tool() ) {
			$clauses[] = "CONCAT( ',', REPLACE( tools_used, ', ', ',' ), ',' ) LIKE %s";
			$params[]  = '%,' . $this->db->esc_like( $filters->tool() ) . ',%';
		}
	}

	/**
	 * Adds the clause of a verdict filter: any block, no block, or one specific verdict.
	 *
	 * @param string      $column  `input_verdict` or `output_verdict`.
	 * @param string|null $verdict Filter value.
	 * @param string[]    $clauses Clauses being built.
	 * @param array       $params  Values being built.
	 * @return void
	 */
	private function add_verdict( string $column, ?string $verdict, array &$clauses, array &$params ): void {
		if ( null === $verdict ) {
			return;
		}

		if ( Filters::VERDICT_ANY === $verdict ) {
			$clauses[] = $column . ' IS NOT NULL';
		} elseif ( Filters::VERDICT_NONE === $verdict ) {
			$clauses[] = $column . ' IS NULL';
		} else {
			$clauses[] = $column . ' = %s';
			$params[]  = $verdict;
		}
	}

	/**
	 * Builds the `ORDER BY` clause from the whitelisted column and direction.
	 *
	 * The id is added so pages stay stable when many rows share the sorted value.
	 *
	 * @param Filters $filters Filters.
	 * @return string
	 */
	private function order_by( Filters $filters ): string {
		$column    = in_array( $filters->orderby(), Filters::SORTABLE, true ) ? $filters->orderby() : 'ts';
		$direction = 'ASC' === $filters->order() ? 'ASC' : 'DESC';

		return $column . ' ' . $direction . ', id ' . $direction;
	}
}
