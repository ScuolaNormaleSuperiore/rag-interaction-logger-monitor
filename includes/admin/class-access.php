<?php
/**
 * Access control for the plugin screens.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single place where the capability required by every plugin screen is enforced.
 */
class Access {

	/**
	 * Tells whether the current user may use the plugin.
	 *
	 * @return bool
	 */
	public static function is_allowed(): bool {
		return current_user_can( Menu::CAPABILITY );
	}

	/**
	 * Stops the request with a generic error when the current user is not allowed.
	 *
	 * Every page callback must call this before producing any output.
	 *
	 * @return void
	 */
	public static function require_admin(): void {
		if ( self::is_allowed() ) {
			return;
		}

		wp_die(
			esc_html__( 'Sorry, you are not allowed to access this page.', 'rag-interaction-logger-monitor' ),
			'',
			array( 'response' => 403 )
		);
	}
}
