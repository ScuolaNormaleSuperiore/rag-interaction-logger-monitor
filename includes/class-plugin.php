<?php
/**
 * Plugin orchestrator.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM;

use RILM\Admin\Access;
use RILM\Admin\Assets;
use RILM\Admin\Menu;
use RILM\Admin\Notices;
use RILM\Admin\Settings;
use RILM\Config\Config;
use RILM\Database\Connection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the administration hooks of the plugin.
 */
class Plugin {

	/**
	 * Hooks the plugin components into WordPress.
	 *
	 * @return void
	 */
	public function init(): void {
		$connection = new Connection( Config::from_environment() );
		$menu       = new Menu( $connection );
		$assets     = new Assets( $menu );
		$notices    = new Notices( $menu, $connection );
		$settings   = new Settings();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RILM_PLUGIN_FILE ), array( $this, 'action_links' ) );

		$menu->init();
		$assets->init();
		$notices->init();
		$settings->init();
	}

	/**
	 * Adds a Settings link to the plugin's row in the Plugins screen, before Deactivate.
	 *
	 * @param string[] $links Action links of the row, keyed by action.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		if ( ! Access::is_allowed() ) {
			return $links;
		}

		$settings = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( add_query_arg( 'page', Menu::SLUG_SETTINGS, admin_url( 'admin.php' ) ) ),
			esc_html__( 'Settings', 'rag-interaction-logger-monitor' )
		);

		return array( 'settings' => $settings ) + $links;
	}

	/**
	 * Loads the translations shipped in the plugin's `languages` folder.
	 *
	 * WordPress finds a catalog by itself only in its own language folder (the language packs of
	 * WordPress.org); the ones in the plugin folder need this call. The user's own language is
	 * used, because the screens are in the administration area.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'rag-interaction-logger-monitor', false, dirname( plugin_basename( RILM_PLUGIN_FILE ) ) . '/languages' );
	}
}
