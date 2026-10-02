<?php
/**
 * Database reader double that records the queries instead of running them.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit\Support;

use RILM\Database\Reader;

/**
 * Prepares queries like `wpdb` (quoted `%s`, integer `%d`) and returns canned results.
 */
class Fake_Reader implements Reader {

	/**
	 * Prepared queries received, in order.
	 *
	 * @var string[]
	 */
	public $queries = array();

	/**
	 * Rows returned by the next get_results() calls.
	 *
	 * @var array[]
	 */
	public $rows = array();

	/**
	 * Value returned by get_var().
	 *
	 * @var string|null
	 */
	public $var = null;

	/**
	 * Values returned by get_col().
	 *
	 * @var array
	 */
	public $col = array();

	/**
	 * Replaces the placeholders with quoted or integer values.
	 *
	 * @param string $query   Query template.
	 * @param mixed  ...$args Values, or one array of values.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$index = 0;

		return preg_replace_callback(
			'/%[sd]/',
			static function ( array $match ) use ( &$index, $args ): string {
				$value = $args[ $index++ ];

				return 's' === $match[0][1]
					? "'" . addslashes( (string) $value ) . "'"
					: (string) (int) $value;
			},
			$query
		);
	}

	/**
	 * Escapes the wildcards of a LIKE pattern, like `wpdb::esc_like()`.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Records the query and returns the next canned rows.
	 *
	 * @param string|null $query  Prepared query.
	 * @param string      $output Output type.
	 * @return array
	 */
	public function get_results( $query = null, $output = 'OBJECT' ) {
		$this->queries[] = $query;

		return array_shift( $this->rows ) ?? array();
	}

	/**
	 * Records the query and returns the canned value.
	 *
	 * @param string|null $query Prepared query.
	 * @param int         $x     Column offset.
	 * @param int         $y     Row offset.
	 * @return string|null
	 */
	public function get_var( $query = null, $x = 0, $y = 0 ) {
		$this->queries[] = $query;

		return $this->var;
	}

	/**
	 * Records the query and returns the canned column.
	 *
	 * @param string|null $query Prepared query.
	 * @param int         $x     Column offset.
	 * @return array
	 */
	public function get_col( $query = null, $x = 0 ) {
		$this->queries[] = $query;

		return $this->col;
	}
}
