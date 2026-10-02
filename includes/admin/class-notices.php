<?php
/**
 * Administration notices.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

use RILM\Database\Connection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows a generic, safe notice on plugin screens when the log database is not usable.
 *
 * Messages never include host names, credentials, SQL or driver details.
 */
class Notices {

	/**
	 * Menu that knows which screens belong to the plugin.
	 *
	 * @var Menu
	 */
	private $menu;

	/**
	 * Connection to the log database.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Constructor.
	 *
	 * @param Menu       $menu       Registered menu.
	 * @param Connection $connection Connection to the log database.
	 */
	public function __construct( Menu $menu, Connection $connection ) {
		$this->menu       = $menu;
		$this->connection = $connection;
	}

	/**
	 * Hooks the notice into WordPress.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Prints the notice on plugin screens. Runs on `admin_notices`.
	 *
	 * The connection is attempted only here, so only plugin screens open it.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Access::is_allowed() ) {
			return;
		}

		$screen = get_current_screen();

		if ( null === $screen || ! $this->menu->is_plugin_screen( $screen->id ) ) {
			return;
		}

		$message = self::message_for( $this->connection->status() );

		if ( '' === $message ) {
			return;
		}

		wp_admin_notice(
			$message,
			array(
				'type'               => 'error',
				'additional_classes' => array( 'rilm-notice' ),
			)
		);
	}

	/**
	 * Returns the escaped message for a connection status.
	 *
	 * @param string $status One of the Connection::STATUS_* constants.
	 * @return string Empty when the connection is fine.
	 */
	public static function message_for( string $status ): string {
		switch ( $status ) {
			case Connection::STATUS_NOT_CONFIGURED:
				return esc_html__( 'The connection to the interaction log database is not configured yet. Complete the host, the database user and the password in Settings, or define the password in wp-config.php.', 'rag-interaction-logger-monitor' );
			case Connection::STATUS_INVALID:
				return esc_html__( 'The connection settings for the interaction log database are not valid. Check the host, port, database, table and user.', 'rag-interaction-logger-monitor' );
			case Connection::STATUS_UNREACHABLE:
				return esc_html__( 'The interaction log database cannot be reached. Check the connection settings and that the database server is available.', 'rag-interaction-logger-monitor' );
			default:
				return '';
		}
	}
}
