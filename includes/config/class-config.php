<?php
/**
 * Plugin configuration.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the connection configuration of the interaction log database.
 *
 * Credentials (user and password) come only from `wp-config.php` constants and are
 * never read from options. Host, port, database and table resolve in this order:
 * constant, then the `rilm_settings` option, then the default value.
 */
class Config {

	/**
	 * Name of the option holding the non-secret connection parameters.
	 */
	public const OPTION_NAME = 'rilm_settings';

	/**
	 * Configuration is complete and valid.
	 */
	public const STATUS_OK = 'ok';

	/**
	 * A required value (host, user or password) is missing.
	 */
	public const STATUS_NOT_CONFIGURED = 'not_configured';

	/**
	 * A value is present but not valid.
	 */
	public const STATUS_INVALID = 'invalid';

	/**
	 * The value comes from a `wp-config.php` constant.
	 */
	public const SOURCE_CONSTANT = 'constant';

	/**
	 * The value comes from the `rilm_settings` option.
	 */
	public const SOURCE_OPTION = 'option';

	/**
	 * The value is the built-in default.
	 */
	public const SOURCE_DEFAULT = 'default';

	/**
	 * No value is available.
	 */
	public const SOURCE_MISSING = 'missing';

	/**
	 * Non-secret fields that can be set from the Settings page.
	 */
	public const FIELDS = array( 'host', 'port', 'name', 'table' );

	/**
	 * Constants read for every setting.
	 */
	private const CONSTANTS = array(
		'host'     => 'ICT_RAG_MONITOR_DB_HOST',
		'port'     => 'ICT_RAG_MONITOR_DB_PORT',
		'name'     => 'ICT_RAG_MONITOR_DB_NAME',
		'table'    => 'ICT_RAG_MONITOR_DB_TABLE',
		'user'     => 'ICT_RAG_MONITOR_DB_USER',
		'password' => 'ICT_RAG_MONITOR_DB_PASSWORD',
	);

	/**
	 * Default values of the optional fields.
	 */
	private const DEFAULTS = array(
		'port'  => 3306,
		'name'  => 'rag-interaction-logger-db',
		'table' => 'ril_interactions',
	);

	/**
	 * Values stored in the `rilm_settings` option.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Values of the defined constants, keyed by setting.
	 *
	 * @var array
	 */
	private $constants;

	/**
	 * Constructor.
	 *
	 * @param array $options   Values of the `rilm_settings` option.
	 * @param array $constants Values of the defined constants, keyed by setting.
	 */
	public function __construct( array $options = array(), array $constants = array() ) {
		$this->options   = $options;
		$this->constants = $constants;
	}

	/**
	 * Builds the configuration from the option and the `wp-config.php` constants.
	 *
	 * @return self
	 */
	public static function from_environment(): self {
		$options = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$constants = array();

		foreach ( self::CONSTANTS as $key => $constant ) {
			if ( defined( $constant ) ) {
				$constants[ $key ] = constant( $constant );
			}
		}

		return new self( $options, $constants );
	}

	/**
	 * Tells whether a host name or IP address is acceptable.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function is_valid_host( $value ): bool {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 253 ) {
			return false;
		}

		if ( false !== filter_var( $value, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		return 1 === preg_match( '/^[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?$/', $value );
	}

	/**
	 * Tells whether a TCP port is acceptable.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function is_valid_port( $value ): bool {
		if ( is_int( $value ) ) {
			return $value >= 1 && $value <= 65535;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{1,5}$/', $value ) ) {
			return false;
		}

		return (int) $value >= 1 && (int) $value <= 65535;
	}

	/**
	 * Tells whether a database name is acceptable.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function is_valid_database( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_$-]{1,64}$/', $value );
	}

	/**
	 * Tells whether a table name is acceptable.
	 *
	 * The name is placed in SQL text, where it cannot be prepared: only letters,
	 * digits and underscores are allowed.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function is_valid_table( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_]{1,64}$/', $value );
	}

	/**
	 * Returns the host.
	 *
	 * @return string Empty when not configured.
	 */
	public function host(): string {
		return $this->text_value( 'host' );
	}

	/**
	 * Returns the port.
	 *
	 * @return int Zero when the value is not valid.
	 */
	public function port(): int {
		list( $value ) = $this->resolve( 'port' );

		return self::is_valid_port( $value ) ? (int) $value : 0;
	}

	/**
	 * Returns the database name.
	 *
	 * @return string
	 */
	public function database(): string {
		return $this->text_value( 'name' );
	}

	/**
	 * Returns the table name.
	 *
	 * @return string
	 */
	public function table(): string {
		return $this->text_value( 'table' );
	}

	/**
	 * Returns the database user, defined only by a constant.
	 *
	 * @return string
	 */
	public function user(): string {
		$value = $this->constants['user'] ?? '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Returns the database password, defined only by a constant.
	 *
	 * @return string
	 */
	public function password(): string {
		$value = $this->constants['password'] ?? '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Returns the host in the format expected by `wpdb` (`host:port`).
	 *
	 * @return string
	 */
	public function connection_host(): string {
		$host = $this->host();

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$host = '[' . $host . ']';
		}

		return $host . ':' . $this->port();
	}

	/**
	 * Returns the database and table names quoted for SQL.
	 *
	 * Only meaningful when the configuration is valid.
	 *
	 * @return string
	 */
	public function qualified_table(): string {
		return '`' . $this->database() . '`.`' . $this->table() . '`';
	}

	/**
	 * Tells where the value of a non-secret field comes from.
	 *
	 * @param string $key One of the FIELDS values.
	 * @return string One of the SOURCE_* constants.
	 */
	public function source( string $key ): string {
		list( , $source ) = $this->resolve( $key );

		return $source;
	}

	/**
	 * Returns the overall state of the configuration.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function status(): string {
		$problems = $this->problems();

		if ( array() === $problems ) {
			return self::STATUS_OK;
		}

		return in_array( self::STATUS_NOT_CONFIGURED, $problems, true )
			? self::STATUS_NOT_CONFIGURED
			: self::STATUS_INVALID;
	}

	/**
	 * Lists the problematic settings, as setting key => problem state.
	 *
	 * Only keys are exposed, never values.
	 *
	 * @return array<string, string> Values are STATUS_NOT_CONFIGURED or STATUS_INVALID.
	 */
	public function problem_fields(): array {
		return $this->problems();
	}

	/**
	 * Prevents secrets from appearing in `var_dump()` and similar output.
	 *
	 * @return array
	 */
	public function __debugInfo() {
		return array( 'status' => $this->status() );
	}

	/**
	 * Computes the problem map used by status() and problem_fields().
	 *
	 * @return array<string, string>
	 */
	private function problems(): array {
		$problems = array();

		foreach ( self::FIELDS as $key ) {
			list( $value, $source ) = $this->resolve( $key );

			if ( self::SOURCE_MISSING === $source ) {
				$problems[ $key ] = self::STATUS_NOT_CONFIGURED;
			} elseif ( ! self::is_valid_value( $key, $value ) ) {
				$problems[ $key ] = self::STATUS_INVALID;
			}
		}

		foreach ( array( 'user', 'password' ) as $key ) {
			if ( ! array_key_exists( $key, $this->constants ) ) {
				$problems[ $key ] = self::STATUS_NOT_CONFIGURED;
			} elseif ( ! is_string( $this->constants[ $key ] ) || '' === $this->constants[ $key ] ) {
				$problems[ $key ] = self::STATUS_INVALID;
			}
		}

		return $problems;
	}

	/**
	 * Resolves a non-secret field: constant, then option, then default.
	 *
	 * @param string $key One of the FIELDS values.
	 * @return array{0: mixed, 1: string} Value and one of the SOURCE_* constants.
	 */
	private function resolve( string $key ): array {
		if ( array_key_exists( $key, $this->constants ) ) {
			return array( $this->constants[ $key ], self::SOURCE_CONSTANT );
		}

		$option = $this->options[ $key ] ?? null;

		if ( is_string( $option ) ) {
			$option = trim( $option );
		}

		if ( ( is_string( $option ) && '' !== $option ) || is_int( $option ) ) {
			return array( $option, self::SOURCE_OPTION );
		}

		if ( array_key_exists( $key, self::DEFAULTS ) ) {
			return array( self::DEFAULTS[ $key ], self::SOURCE_DEFAULT );
		}

		return array( null, self::SOURCE_MISSING );
	}

	/**
	 * Returns a resolved value as a string, or an empty string when invalid.
	 *
	 * @param string $key One of the FIELDS values.
	 * @return string
	 */
	private function text_value( string $key ): string {
		list( $value ) = $this->resolve( $key );

		return ( is_string( $value ) && self::is_valid_value( $key, $value ) ) ? $value : '';
	}

	/**
	 * Validates the value of a non-secret field.
	 *
	 * @param string $key   One of the FIELDS values.
	 * @param mixed  $value Candidate value.
	 * @return bool
	 */
	public static function is_valid_value( string $key, $value ): bool {
		switch ( $key ) {
			case 'host':
				return self::is_valid_host( $value );
			case 'port':
				return self::is_valid_port( $value );
			case 'name':
				return self::is_valid_database( $value );
			case 'table':
				return self::is_valid_table( $value );
			default:
				return false;
		}
	}
}
