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

		$menu->init();
		$assets->init();
		$notices->init();
		$settings->init();
	}
}
