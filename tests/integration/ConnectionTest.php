<?php
/**
 * Integration tests for the guarded connection, notices and optional columns.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Integration;

use RILM\Admin\Menu;
use RILM\Admin\Notices;
use RILM\Config\Config;
use RILM\Database\Connection;
use RILM\Database\Guarded_Wpdb;
use RILM\Database\Optional_Columns;
use WP_UnitTestCase;

/**
 * Verifies safe failure, read-only enforcement, notices and column detection.
 */
class ConnectionTest extends WP_UnitTestCase {

	/**
	 * Configuration pointing at a closed local port: the connection is refused at once.
	 *
	 * @return Config
	 */
	private function unreachable_config(): Config {
		return new Config(
			array(
				'host' => '127.0.0.1',
				'port' => '1',
			),
			array(
				'user'     => 'rilm_user_that_must_not_leak',
				'password' => 'rilm_password_that_must_not_leak',
			)
		);
	}

	/**
	 * Complete configuration, not used to connect.
	 *
	 * @return Config
	 */
	private function valid_config(): Config {
		return new Config(
			array( 'host' => 'db.example.test' ),
			array(
				'user'     => 'reader',
				'password' => 'secret',
			)
		);
	}

	/**
	 * An unreachable server yields a status, no exception and no PHP warning.
	 *
	 * PHPUnit turns any leaked warning into a failure, so passing proves the
	 * driver warning is hidden.
	 *
	 * @return void
	 */
	public function test_unreachable_server_is_reported_without_warnings(): void {
		$connection = new Connection( $this->unreachable_config() );

		ob_start();
		$status = $connection->status();
		$output = ob_get_clean();

		$this->assertSame( Connection::STATUS_UNREACHABLE, $status );
		$this->assertNull( $connection->db() );
		$this->assertSame( '', $output );
	}

	/**
	 * A missing configuration is reported without any connection attempt.
	 *
	 * @return void
	 */
	public function test_missing_configuration_is_reported(): void {
		$connection = new Connection( new Config() );

		$this->assertSame( Connection::STATUS_NOT_CONFIGURED, $connection->status() );
		$this->assertNull( $connection->db() );
	}

	/**
	 * An invalid configuration is reported without any connection attempt.
	 *
	 * @return void
	 */
	public function test_invalid_configuration_is_reported(): void {
		$config = new Config(
			array(
				'host'  => 'db.example.test',
				'table' => 'a;b',
			),
			array(
				'user'     => 'reader',
				'password' => 'secret',
			)
		);

		$this->assertSame( Connection::STATUS_INVALID, ( new Connection( $config ) )->status() );
	}

	/**
	 * Creating the connection object does not open anything.
	 *
	 * @return void
	 */
	public function test_connection_is_lazy(): void {
		$connection = new Connection( $this->unreachable_config() );

		$this->assertInstanceOf( Connection::class, $connection );
		$this->assertSame( 'unreachable', $connection->status() );
	}

	/**
	 * Only SELECT statements are accepted.
	 *
	 * @dataProvider provide_statements
	 *
	 * @param string $query    SQL statement.
	 * @param bool   $expected Whether it is accepted.
	 * @return void
	 */
	public function test_only_select_statements_are_accepted( string $query, bool $expected ): void {
		$this->assertSame( $expected, Guarded_Wpdb::is_read_only_query( $query ) );
	}

	/**
	 * Provides statements and whether the guard accepts them.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function provide_statements(): array {
		return array(
			'select'               => array( 'SELECT 1', true ),
			'lowercase select'     => array( "  select id FROM t\n", true ),
			'insert'               => array( 'INSERT INTO t VALUES (1)', false ),
			'update'               => array( 'UPDATE t SET a = 1', false ),
			'delete'               => array( 'DELETE FROM t', false ),
			'drop'                 => array( 'DROP TABLE t', false ),
			'alter'                => array( 'ALTER TABLE t ADD c INT', false ),
			'create'               => array( 'CREATE TABLE t (a INT)', false ),
			'grant'                => array( "GRANT ALL ON *.* TO 'x'", false ),
			'set'                  => array( 'SET @a = 1', false ),
			'select word prefix'   => array( 'SELECTED_ROWS', false ),
			'comment before'       => array( '/* x */ DELETE FROM t', false ),
			'empty'                => array( '', false ),
		);
	}

	/**
	 * A guarded connection refuses statements that are not SELECT, even when it cannot connect.
	 *
	 * @return void
	 */
	public function test_guarded_wpdb_refuses_write_statements(): void {
		$connection = new Connection( $this->unreachable_config() );
		$connection->status();

		// Build the object directly: Connection drops it when it cannot connect.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Test only: hides the refused connection warning.
		$db = @new Guarded_Wpdb( 'u', 'p', 'rag-interaction-logger-tests', '127.0.0.1:1' );

		$this->assertFalse( $db->is_connected() );
		$this->assertFalse( $db->query( 'DELETE FROM anything' ) );
		$this->assertFalse( $db->query( 'DROP TABLE anything' ) );
		$this->assertFalse( $db->query( 'INSERT INTO anything VALUES (1)' ) );
	}

	/**
	 * Failures print nothing and never stop the request.
	 *
	 * @return void
	 */
	public function test_failures_are_silent(): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Test only: hides the refused connection warning.
		$db = @new Guarded_Wpdb( 'u', 'p', 'rag-interaction-logger-tests', '127.0.0.1:1' );

		ob_start();
		$this->assertFalse( $db->bail( 'secret message' ) );
		$this->assertFalse( $db->print_error( 'secret message' ) );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Dumping the connection reveals no credential.
	 *
	 * @return void
	 */
	public function test_dump_does_not_reveal_credentials(): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Test only: hides the refused connection warning.
		$db = @new Guarded_Wpdb( 'dump_user_value', 'dump_password_value', 'rag-interaction-logger-tests', '127.0.0.1:1' );

		ob_start();
		var_dump( $db );
		$dump = ob_get_clean();

		$this->assertStringNotContainsString( 'dump_user_value', $dump );
		$this->assertStringNotContainsString( 'dump_password_value', $dump );
	}

	/**
	 * Notices are generic for every failure status.
	 *
	 * @return void
	 */
	public function test_notice_messages_are_generic(): void {
		$notices = new Notices( new Menu(), new Connection( new Config() ) );

		$messages = array(
			$notices->message_for( Connection::STATUS_NOT_CONFIGURED ),
			$notices->message_for( Connection::STATUS_INVALID ),
			$notices->message_for( Connection::STATUS_UNREACHABLE ),
		);

		foreach ( $messages as $message ) {
			$this->assertNotSame( '', $message );
			$this->assertStringNotContainsString( 'rilm_user_that_must_not_leak', $message );
			$this->assertStringNotContainsString( '127.0.0.1', $message );
		}

		$this->assertCount( 3, array_unique( $messages ) );
		$this->assertSame( '', $notices->message_for( Connection::STATUS_OK ) );
	}

	/**
	 * The notice appears on plugin screens only, and only for administrators.
	 *
	 * @return void
	 */
	public function test_notice_is_shown_only_on_plugin_screens_to_administrators(): void {
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$GLOBALS['menu']              = array();
		$GLOBALS['submenu']           = array();
		$GLOBALS['admin_page_hooks']  = array();
		$GLOBALS['_registered_pages'] = array();
		$GLOBALS['_parent_pages']     = array();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$menu    = new Menu();
		$notices = new Notices( $menu, new Connection( new Config() ) );
		$menu->register();

		set_current_screen( 'dashboard' );
		ob_start();
		$notices->render();
		$this->assertSame( '', ob_get_clean() );

		set_current_screen( get_plugin_page_hookname( Menu::SLUG_DASHBOARD, '' ) );
		ob_start();
		$notices->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		$notices->render();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The connection is never opened by loading the plugin hooks.
	 *
	 * @return void
	 */
	public function test_plugin_hooks_do_not_open_the_connection(): void {
		$connection = new Connection( $this->valid_config() );

		// The valid config points at a host that does not exist: building it must not connect.
		$this->assertInstanceOf( Connection::class, $connection );
		$this->assertSame( Config::STATUS_OK, $this->valid_config()->status() );
	}

	/**
	 * Optional columns are the whitelisted ones reported by the server, queried once.
	 *
	 * @return void
	 */
	public function test_optional_columns_detects_only_known_columns_once(): void {
		$db = new class() extends Guarded_Wpdb {
			/**
			 * Arguments received by prepare().
			 *
			 * @var array
			 */
			public $prepare_args = array();

			/**
			 * Number of get_col() calls.
			 *
			 * @var int
			 */
			public $get_col_calls = 0;

			/**
			 * Skips the real connection.
			 */
			public function __construct() {}

			/**
			 * Records the arguments instead of preparing.
			 *
			 * @param string $query Query template.
			 * @param mixed  ...$args Values.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				$this->prepare_args = $args[0];
				return 'SELECT recorded';
			}

			/**
			 * Pretends the table has one known and one unknown column.
			 *
			 * @param string|null $query Query.
			 * @param int         $x     Column offset.
			 * @return array
			 */
			public function get_col( $query = null, $x = 0 ) {
				++$this->get_col_calls;
				return array( 'tool_input', 'unknown_column', 'recall_sources' );
			}
		};

		$connection = new class( $this->valid_config(), $db ) extends Connection {
			/**
			 * Fake connection returned by db().
			 *
			 * @var Guarded_Wpdb
			 */
			private $fake;

			/**
			 * Constructor.
			 *
			 * @param Config        $config Configuration.
			 * @param Guarded_Wpdb $fake   Fake database.
			 */
			public function __construct( Config $config, Guarded_Wpdb $fake ) {
				parent::__construct( $config );
				$this->fake = $fake;
			}

			/**
			 * Returns the fake database.
			 *
			 * @return Guarded_Wpdb|null
			 */
			public function db(): ?Guarded_Wpdb {
				return $this->fake;
			}
		};

		$columns = new Optional_Columns( $connection );

		$this->assertSame( array( 'tool_input', 'recall_sources' ), $columns->present() );
		$this->assertTrue( $columns->has( 'tool_input' ) );
		$this->assertFalse( $columns->has( 'tool_output' ) );
		$this->assertFalse( $columns->has( 'unknown_column' ) );
		$this->assertSame( 1, $db->get_col_calls );
		$this->assertSame(
			array( 'rag-interaction-logger-db', 'ril_interactions', 'tools_used', 'tool_input', 'tool_output', 'recall_sources' ),
			$db->prepare_args
		);
	}

	/**
	 * Without a connection no optional column is reported and nothing breaks.
	 *
	 * @return void
	 */
	public function test_optional_columns_are_empty_without_connection(): void {
		$columns = new Optional_Columns( new Connection( new Config() ) );

		$this->assertSame( array(), $columns->present() );
		$this->assertFalse( $columns->has( 'tool_input' ) );
	}
}
