<?php
/**
 * Connection to the interaction log database.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Database;

use RILM\Config\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opens the second `wpdb` connection lazily, at most once per request.
 *
 * Encryption in transit is the one the site already uses: `wpdb` applies the
 * `MYSQL_CLIENT_FLAGS` defined in `wp-config.php`, so nothing is configured here.
 */
class Connection {

	/**
	 * The connection is open.
	 */
	public const STATUS_OK = 'ok';

	/**
	 * A required value is missing.
	 */
	public const STATUS_NOT_CONFIGURED = 'not_configured';

	/**
	 * A configuration value is not valid.
	 */
	public const STATUS_INVALID = 'invalid_config';

	/**
	 * The server could not be reached or refused the credentials.
	 */
	public const STATUS_UNREACHABLE = 'unreachable';

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Open connection, once attempted successfully.
	 *
	 * @var Guarded_Wpdb|null
	 */
	private $db = null;

	/**
	 * Outcome of the single connection attempt, null before it.
	 *
	 * @var string|null
	 */
	private $status = null;

	/**
	 * Constructor. Does not connect.
	 *
	 * @param Config $config Configuration.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Returns the configuration used by this connection.
	 *
	 * @return Config
	 */
	public function config(): Config {
		return $this->config;
	}

	/**
	 * Returns the outcome of the connection, attempting it on first use.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function status(): string {
		$this->connect();

		return (string) $this->status;
	}

	/**
	 * Returns the open connection, or null when it is not available.
	 *
	 * @return Guarded_Wpdb|null
	 */
	public function db(): ?Guarded_Wpdb {
		$this->connect();

		return self::STATUS_OK === $this->status ? $this->db : null;
	}

	/**
	 * Attempts the connection once and records the outcome.
	 *
	 * @return void
	 */
	private function connect(): void {
		if ( null !== $this->status ) {
			return;
		}

		$config_status = $this->config->status();

		if ( Config::STATUS_NOT_CONFIGURED === $config_status ) {
			$this->status = self::STATUS_NOT_CONFIGURED;
			return;
		}

		if ( Config::STATUS_INVALID === $config_status ) {
			$this->status = self::STATUS_INVALID;
			return;
		}

		$db = $this->open();

		if ( null !== $db && $db->is_connected() ) {
			$this->db     = $db;
			$this->status = self::STATUS_OK;
			return;
		}

		$this->status = self::STATUS_UNREACHABLE;
	}

	/**
	 * Creates the `wpdb` object, hiding every PHP warning and driver error.
	 *
	 * Connection failures can carry host names or driver details: they must reach
	 * neither the output nor the PHP error log.
	 *
	 * @return Guarded_Wpdb|null Null when the object could not be created.
	 */
	private function open(): ?Guarded_Wpdb {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Hides driver warnings that may disclose connection details.
		set_error_handler(
			static function (): bool {
				return true;
			}
		);

		try {
			return new Guarded_Wpdb(
				$this->config->user(),
				$this->config->password(),
				$this->config->database(),
				$this->config->connection_host()
			);
		} catch ( \Throwable $error ) {
			return null;
		} finally {
			restore_error_handler();
		}
	}
}
