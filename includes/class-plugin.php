<?php
/**
 * Plugin orchestrator.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM;

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

		$menu->init();
		$assets->init();
		$notices->init();
		$settings->init();
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
