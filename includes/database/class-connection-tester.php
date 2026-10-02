<?php
/**
 * Manual connection test.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Database;

use RILM\Config\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks that the saved configuration reaches the server and can read the table.
 *
 * The only SQL it runs is a `SELECT` on identifiers already validated by `Config`;
 * results are reported as short codes, never as driver messages.
 */
class Connection_Tester {

	/**
	 * The connection works but the table cannot be read.
	 */
	public const RESULT_TABLE_UNREADABLE = 'table_unreadable';

	/**
	 * Runs the test.
	 *
	 * @param Config $config Configuration to test.
	 * @return string One of the Connection::STATUS_* constants or RESULT_TABLE_UNREADABLE.
	 */
	public function run( Config $config ): string {
		$connection = $this->connect( $config );
		$status     = $connection->status();

		if ( Connection::STATUS_OK !== $status ) {
			return $status;
		}

		$db = $connection->db();

		if ( null === $db ) {
			return Connection::STATUS_UNREACHABLE;
		}

		// The table identifier is validated by Config (letters, digits, underscore); there are no values to prepare.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only probe on a validated identifier.
		$result = $db->query( 'SELECT 1 FROM ' . $config->qualified_table() . ' LIMIT 1' );

		return false === $result ? self::RESULT_TABLE_UNREADABLE : Connection::STATUS_OK;
	}

	/**
	 * Creates the connection to test. Overridable so tests can avoid the network.
	 *
	 * @param Config $config Configuration to test.
	 * @return Connection
	 */
	protected function connect( Config $config ): Connection {
		return new Connection( $config );
	}
}
