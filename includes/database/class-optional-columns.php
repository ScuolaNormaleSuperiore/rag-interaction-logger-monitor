<?php
/**
 * Detection of the optional columns of the interaction table.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds which of the later-added columns exist in the configured table.
 *
 * Older installations of the RAG Interaction Logger may lack them, so the
 * repository must not select a column that is not there.
 */
class Optional_Columns {

	/**
	 * Columns added to the table after its first version.
	 */
	public const COLUMNS = array( 'tools_used', 'tool_input', 'tool_output', 'recall_sources' );

	/**
	 * Connection to the log database.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Columns found, cached for the request.
	 *
	 * @var string[]|null
	 */
	private $present = null;

	/**
	 * Constructor.
	 *
	 * @param Connection $connection Connection to the log database.
	 */
	public function __construct( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * Returns the optional columns that exist in the table.
	 *
	 * @return string[]
	 */
	public function present(): array {
		if ( null !== $this->present ) {
			return $this->present;
		}

		$this->present = array();
		$db            = $this->connection->db();

		if ( null === $db ) {
			return $this->present;
		}

		$config = $this->connection->config();
		$query  = $db->prepare(
			'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME IN ( %s, %s, %s, %s )',
			array_merge( array( $config->database(), $config->table() ), self::COLUMNS )
		);

		$found = is_string( $query ) ? $db->get_col( $query ) : array();

		if ( is_array( $found ) ) {
			$this->present = array_values( array_intersect( self::COLUMNS, $found ) );
		}

		return $this->present;
	}

	/**
	 * Tells whether one optional column exists in the table.
	 *
	 * @param string $column Column name.
	 * @return bool
	 */
	public function has( string $column ): bool {
		return in_array( $column, $this->present(), true );
	}
}
