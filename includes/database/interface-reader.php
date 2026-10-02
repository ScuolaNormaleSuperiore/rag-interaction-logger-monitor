<?php
/**
 * Read access to the interaction log database.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The part of `wpdb` the repository needs.
 *
 * `Guarded_Wpdb` implements it through the inherited `wpdb` methods, so the
 * repository can be tested without WordPress or a database.
 */
interface Reader {

	/**
	 * Prepares a query, replacing the placeholders with escaped values.
	 *
	 * @param string $query   Query template with `%s` and `%d` placeholders.
	 * @param mixed  ...$args Values, or one array of values.
	 * @return string|void Prepared query.
	 */
	public function prepare( $query, ...$args );

	/**
	 * Escapes the wildcard characters of a `LIKE` pattern.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	public function esc_like( $text );

	/**
	 * Returns the rows of a `SELECT`.
	 *
	 * @param string|null $query  Prepared query.
	 * @param string      $output Output type, e.g. `ARRAY_A`.
	 * @return array|object|null
	 */
	public function get_results( $query = null, $output = 'OBJECT' );

	/**
	 * Returns one value of a `SELECT`.
	 *
	 * @param string|null $query Prepared query.
	 * @param int         $x     Column offset.
	 * @param int         $y     Row offset.
	 * @return string|null
	 */
	public function get_var( $query = null, $x = 0, $y = 0 );

	/**
	 * Returns one column of a `SELECT`.
	 *
	 * @param string|null $query Prepared query.
	 * @param int         $x     Column offset.
	 * @return array
	 */
	public function get_col( $query = null, $x = 0 );
}
