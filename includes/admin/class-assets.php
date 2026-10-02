<?php
/**
 * Administration assets.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the plugin stylesheet only on the plugin screens.
 */
class Assets {

	/**
	 * Handle of the administration stylesheet.
	 */
	public const STYLE_HANDLE = 'rilm-admin';

	/**
	 * Menu that knows which screens belong to the plugin.
	 *
	 * @var Menu
	 */
	private $menu;

	/**
	 * Constructor.
	 *
	 * @param Menu $menu Registered menu.
	 */
	public function __construct( Menu $menu ) {
		$this->menu = $menu;
	}

	/**
	 * Hooks the asset loading into WordPress.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the stylesheet on plugin screens. Runs on `admin_enqueue_scripts`.
	 *
	 * @param string $hook_suffix Hook suffix of the current admin screen.
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		if ( ! is_string( $hook_suffix ) || ! $this->menu->is_plugin_screen( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			plugins_url( 'assets/css/admin.css', RILM_PLUGIN_FILE ),
			array(),
			RILM_VERSION
		);

		// The comparison of the two answers uses the core diff table, styled by the core "revisions" stylesheet.
		if ( $this->menu->is_detail_screen( $hook_suffix ) ) {
			wp_enqueue_style( 'revisions' );
		}
	}
}
