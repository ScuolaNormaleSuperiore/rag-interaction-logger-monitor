<?php
/**
 * Unit tests for the configuration resolver.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RILM\Config\Config;

/**
 * Verifies precedence, defaults, validation and secret handling.
 */
class ConfigTest extends TestCase {

	/**
	 * Constant that makes the configuration complete.
	 *
	 * @return array
	 */
	private static function credentials(): array {
		return array(
			'password' => 'secret-value',
		);
	}

	/**
	 * Options that make the configuration complete.
	 *
	 * @return array
	 */
	private static function options(): array {
		return array(
			'host' => 'db.example.test',
			'user' => 'reader',
		);
	}

	/**
	 * Defaults apply to port, database and table.
	 *
	 * @return void
	 */
	public function test_defaults_apply_to_optional_fields(): void {
		$config = new Config( self::options(), self::credentials() );

		$this->assertSame( 3306, $config->port() );
		$this->assertSame( 'rag-interaction-logger-db', $config->database() );
		$this->assertSame( 'ril_interactions', $config->table() );
		$this->assertSame( Config::SOURCE_DEFAULT, $config->source( 'port' ) );
		$this->assertSame( Config::STATUS_OK, $config->status() );
	}

	/**
	 * The host has no default.
	 *
	 * @return void
	 */
	public function test_host_has_no_default(): void {
		$config = new Config( array(), self::credentials() );

		$this->assertSame( '', $config->host() );
		$this->assertSame( Config::SOURCE_MISSING, $config->source( 'host' ) );
		$this->assertSame( Config::STATUS_NOT_CONFIGURED, $config->status() );
		$this->assertSame( Config::STATUS_NOT_CONFIGURED, $config->problem_fields()['host'] );
	}

	/**
	 * Option values replace the defaults.
	 *
	 * @return void
	 */
	public function test_options_replace_defaults(): void {
		$options = array(
			'host'  => 'db.example.test',
			'port'  => '3307',
			'name'  => 'other-db',
			'table' => 'other_table',
		);
		$config  = new Config( $options, self::credentials() );

		$this->assertSame( 3307, $config->port() );
		$this->assertSame( 'other-db', $config->database() );
		$this->assertSame( 'other_table', $config->table() );
		$this->assertSame( Config::SOURCE_OPTION, $config->source( 'table' ) );
	}

	/**
	 * A constant wins over the option holding the same setting.
	 *
	 * @return void
	 */
	public function test_constant_wins_over_option(): void {
		$options   = array(
			'host'  => 'option.example.test',
			'table' => 'option_table',
		);
		$constants = self::credentials() + array(
			'host'  => 'constant.example.test',
			'table' => 'constant_table',
		);
		$config    = new Config( $options, $constants );

		$this->assertSame( 'constant.example.test', $config->host() );
		$this->assertSame( 'constant_table', $config->table() );
		$this->assertSame( Config::SOURCE_CONSTANT, $config->source( 'host' ) );
	}

	/**
	 * Blank option values fall back to the default.
	 *
	 * @return void
	 */
	public function test_blank_option_falls_back_to_default(): void {
		$config = new Config( self::options() + array( 'table' => '   ' ), self::credentials() );

		$this->assertSame( 'ril_interactions', $config->table() );
		$this->assertSame( Config::SOURCE_DEFAULT, $config->source( 'table' ) );
	}

	/**
	 * Only the password is never taken from the option.
	 *
	 * @return void
	 */
	public function test_password_is_never_read_from_options(): void {
		$options = array_merge(
			self::options(),
			array(
				'user'     => 'from-option',
				'password' => 'from-option',
			)
		);
		$config  = new Config( $options, array() );

		$this->assertSame( 'from-option', $config->user() );
		$this->assertSame( '', $config->password() );
		$this->assertSame( Config::STATUS_NOT_CONFIGURED, $config->status() );
		$this->assertArrayHasKey( 'password', $config->problem_fields() );
	}

	/**
	 * The user may come from a constant and the password always does.
	 *
	 * @return void
	 */
	public function test_user_and_password_can_come_from_constants(): void {
		$config = new Config( self::options(), self::credentials() + array( 'user' => 'constant-reader' ) );

		$this->assertSame( 'constant-reader', $config->user() );
		$this->assertSame( 'secret-value', $config->password() );
	}

	/**
	 * Empty or non-string credentials are invalid, not missing.
	 *
	 * @dataProvider provide_invalid_credentials
	 *
	 * @param mixed $user     User constant.
	 * @param mixed $password Password constant.
	 * @return void
	 */
	public function test_invalid_credentials_are_reported( $user, $password ): void {
		$config = new Config(
			self::options(),
			array(
				'user'     => $user,
				'password' => $password,
			)
		);

		$this->assertSame( Config::STATUS_INVALID, $config->status() );
	}

	/**
	 * Provides invalid credential pairs.
	 *
	 * @return array<string, array{mixed, mixed}>
	 */
	public static function provide_invalid_credentials(): array {
		return array(
			'empty user'        => array( '', 'secret' ),
			'empty password'    => array( 'reader', '' ),
			'non-string user'   => array( 42, 'secret' ),
			'non-string secret' => array( 'reader', array( 'x' ) ),
		);
	}

	/**
	 * Invalid values make the status invalid and name only the field.
	 *
	 * @dataProvider provide_invalid_field_values
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Invalid value.
	 * @return void
	 */
	public function test_invalid_field_values_make_configuration_invalid( string $key, $value ): void {
		$config = new Config( array( $key => $value ) + self::options(), self::credentials() );

		$this->assertSame( Config::STATUS_INVALID, $config->status() );
		$this->assertSame( array( $key => Config::STATUS_INVALID ), $config->problem_fields() );
	}

	/**
	 * Provides invalid values for each field.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public static function provide_invalid_field_values(): array {
		return array(
			'host with port'       => array( 'host', 'db.example.test:3306' ),
			'host with space'      => array( 'host', 'db example' ),
			'host with slash'      => array( 'host', 'db/example' ),
			'host starting dash'   => array( 'host', '-db.example.test' ),
			'port zero'            => array( 'port', '0' ),
			'port too high'        => array( 'port', '65536' ),
			'port not numeric'     => array( 'port', 'abc' ),
			'port negative'        => array( 'port', '-1' ),
			'database backtick'    => array( 'name', 'db`name' ),
			'database dot'         => array( 'name', 'db.name' ),
			'database slash'       => array( 'name', 'db/name' ),
			'database too long'    => array( 'name', str_repeat( 'a', 65 ) ),
			'table hyphen'         => array( 'table', 'a-b' ),
			'table backtick'       => array( 'table', 'a`b' ),
			'table semicolon'      => array( 'table', 'a;b' ),
			'table with dot'       => array( 'table', 'db.table' ),
			'table with space'     => array( 'table', 'my table' ),
			'table too long'       => array( 'table', str_repeat( 'a', 65 ) ),
			'user with space'      => array( 'user', 'reader account' ),
			'user with slash'      => array( 'user', 'reader/account' ),
		);
	}

	/**
	 * Valid values are accepted by the shared validators.
	 *
	 * @return void
	 */
	public function test_validators_accept_valid_values(): void {
		$this->assertTrue( Config::is_valid_host( 'localhost' ) );
		$this->assertTrue( Config::is_valid_host( 'db-1.internal.example.test' ) );
		$this->assertTrue( Config::is_valid_host( '10.0.0.5' ) );
		$this->assertTrue( Config::is_valid_host( '::1' ) );
		$this->assertTrue( Config::is_valid_port( 1 ) );
		$this->assertTrue( Config::is_valid_port( '65535' ) );
		$this->assertTrue( Config::is_valid_database( 'rag-interaction-logger-db' ) );
		$this->assertTrue( Config::is_valid_table( 'ril_interactions' ) );
		$this->assertTrue( Config::is_valid_user( 'logger_reader_user@localhost' ) );
	}

	/**
	 * An invalid value never leaks into the typed getters.
	 *
	 * @return void
	 */
	public function test_invalid_values_are_not_returned(): void {
		$config = new Config(
			array(
				'host'  => 'bad host',
				'port'  => 'abc',
				'table' => 'a;b',
			),
			self::credentials()
		);

		$this->assertSame( '', $config->host() );
		$this->assertSame( 0, $config->port() );
		$this->assertSame( '', $config->table() );
	}

	/**
	 * The wpdb host combines host and port, with brackets around IPv6.
	 *
	 * @return void
	 */
	public function test_connection_host_includes_port(): void {
		$config = new Config( array( 'host' => 'db.example.test' ) + array( 'port' => '3307' ), self::credentials() );
		$this->assertSame( 'db.example.test:3307', $config->connection_host() );

		$config = new Config( array( 'host' => '::1' ), self::credentials() );
		$this->assertSame( '[::1]:3306', $config->connection_host() );
	}

	/**
	 * The qualified table name is backtick quoted.
	 *
	 * @return void
	 */
	public function test_qualified_table_is_quoted(): void {
		$config = new Config( self::options(), self::credentials() );

		$this->assertSame( '`rag-interaction-logger-db`.`ril_interactions`', $config->qualified_table() );
	}

	/**
	 * Dumping the object does not reveal credentials.
	 *
	 * @return void
	 */
	public function test_dump_does_not_reveal_credentials(): void {
		$config = new Config( self::options(), self::credentials() );

		ob_start();
		var_dump( $config );
		$dump = ob_get_clean();

		$this->assertStringNotContainsString( 'secret-value', $dump );
		$this->assertStringNotContainsString( 'reader', $dump );
		$this->assertStringNotContainsString( 'db.example.test', $dump );
	}

	/**
	 * A non-array option is ignored by the constructor contract (only arrays are accepted).
	 *
	 * @return void
	 */
	public function test_status_lists_missing_and_invalid_separately(): void {
		$config = new Config( array( 'port' => 'abc' ), array( 'user' => 'reader' ) );

		$problems = $config->problem_fields();

		$this->assertSame( Config::STATUS_NOT_CONFIGURED, $problems['host'] );
		$this->assertSame( Config::STATUS_INVALID, $problems['port'] );
		$this->assertSame( Config::STATUS_NOT_CONFIGURED, $problems['password'] );
		$this->assertSame( Config::STATUS_NOT_CONFIGURED, $config->status() );
	}
}
