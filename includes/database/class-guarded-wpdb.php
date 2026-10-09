<?php
/**
 * Guarded read-only wpdb connection.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wpdb` subclass for the interaction log database.
 *
 * It never ends the request when the server is unreachable, never prints or logs
 * errors (messages and queries may contain sensitive data) and refuses every
 * statement that is not a `SELECT`, as a second barrier behind the database grant.
 */
class Guarded_Wpdb extends \wpdb implements Reader {

	/**
	 * Opens the connection without ever terminating the request.
	 *
	 * @param string $dbuser     Database user.
	 * @param string $dbpassword Database password.
	 * @param string $dbname     Database name.
	 * @param string $dbhost     Host, optionally with `:port`.
	 */
	public function __construct( $dbuser, $dbpassword, $dbname, $dbhost ) {
		parent::__construct( $dbuser, $dbpassword, $dbname, $dbhost );

		$this->hide_errors();
		$this->suppress_errors( true );
	}

	/**
	 * Tells whether the connection is open and the database is selected.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return (bool) $this->ready;
	}

	/**
	 * Tells whether a statement is a single plain `SELECT` that writes nothing.
	 *
	 * Quoted values and identifiers are blanked first, so a search text that
	 * contains `;` or `INTO OUTFILE` (quoted by `prepare()`) is still accepted,
	 * while the same text outside quotes is refused.
	 *
	 * @param mixed $query SQL statement.
	 * @return bool
	 */
	public static function is_read_only_query( $query ): bool {
		if ( ! is_string( $query ) || 1 !== preg_match( '/^\s*SELECT\b/i', $query ) ) {
			return false;
		}

		$unquoted = preg_replace( '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|`(?:[^`]|``)*`/s', ' 0 ', $query );

		// An unbalanced quote leaves the statement ambiguous: refuse it.
		if ( null === $unquoted || 1 === preg_match( '/[\'"`]/', $unquoted ) ) {
			return false;
		}

		return false === strpos( $unquoted, ';' )
			&& 0 === preg_match( '/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i', $unquoted );
	}

	/**
	 * Runs a statement, but only if it is a `SELECT`.
	 *
	 * @param string $query SQL statement.
	 * @return int|bool Result of the parent method, or false when refused.
	 */
	public function query( $query ) {
		if ( ! self::is_read_only_query( $query ) ) {
			return false;
		}

		return parent::query( $query );
	}

	/**
	 * Does not stop the request when the database is unreachable.
	 *
	 * @param string $message    Error message (ignored on purpose).
	 * @param string $error_code Error code (ignored on purpose).
	 * @return bool Always false.
	 */
	public function bail( $message, $error_code = '500' ) {
		return false;
	}

	/**
	 * Does not print or log database errors.
	 *
	 * @param string $str Error message (ignored on purpose).
	 * @return bool Always false.
	 */
	public function print_error( $str = '' ) {
		return false;
	}

	/**
	 * Prevents credentials from appearing in `var_dump()` and similar output.
	 *
	 * @return array
	 */
	public function __debugInfo() {
		return array( 'connected' => $this->is_connected() );
	}
}
