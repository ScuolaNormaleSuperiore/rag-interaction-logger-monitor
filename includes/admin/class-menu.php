<?php
/**
 * Administration menu.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "Monitor RAG" menu, its sub pages and the hidden detail page.
 */
class Menu {

	/**
	 * Capability required to see the menu and use every page.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Menu slug of the dashboard (also the top-level menu slug).
	 */
	public const SLUG_DASHBOARD = 'rilm-dashboard';

	/**
	 * Menu slug of the interactions list.
	 */
	public const SLUG_INTERACTIONS = 'rilm-interactions';

	/**
	 * Menu slug of the anomalies views.
	 */
	public const SLUG_ANOMALIES = 'rilm-anomalies';

	/**
	 * Menu slug of the settings page.
	 */
	public const SLUG_SETTINGS = 'rilm-settings';

	/**
	 * Menu slug of the interaction detail page (not listed in the menu).
	 */
	public const SLUG_DETAIL = 'rilm-detail';

	/**
	 * Hook suffixes of the registered plugin screens.
	 *
	 * @var string[]
	 */
	private $hook_suffixes = array();

	/**
	 * Hooks the menu registration into WordPress.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register' ) );
	}

	/**
	 * Registers the menu and the pages. Runs on `admin_menu`.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->hook_suffixes = array();

		// add_menu_page() lists the item even without the capability: register nothing for other users.
		if ( ! Access::is_allowed() ) {
			return;
		}

		$dashboard = add_menu_page(
			__( 'Monitor RAG', 'rag-interaction-logger-monitor' ),
			__( 'Monitor RAG', 'rag-interaction-logger-monitor' ),
			self::CAPABILITY,
			self::SLUG_DASHBOARD,
			array( new Dashboard_Page(), 'render' ),
			'dashicons-chart-area',
			80
		);

		$this->hook_suffixes[] = $dashboard;

		// Same slug as the parent: relabels the first item and must not repeat the callback.
		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Dashboard', 'rag-interaction-logger-monitor' ),
			__( 'Dashboard', 'rag-interaction-logger-monitor' ),
			self::CAPABILITY,
			self::SLUG_DASHBOARD
		);

		$this->add_submenu(
			__( 'Interactions', 'rag-interaction-logger-monitor' ),
			self::SLUG_INTERACTIONS,
			array( new Interactions_Page(), 'render' )
		);

		$this->add_submenu(
			__( 'Anomalies', 'rag-interaction-logger-monitor' ),
			self::SLUG_ANOMALIES,
			array( new Anomalies_Page(), 'render' )
		);

		$this->add_submenu(
			__( 'Settings', 'rag-interaction-logger-monitor' ),
			self::SLUG_SETTINGS,
			array( new Settings_Page(), 'render' )
		);

		// An empty parent slug registers a page reachable by URL but absent from every menu.
		$detail = add_submenu_page(
			'',
			__( 'Interaction detail', 'rag-interaction-logger-monitor' ),
			__( 'Interaction detail', 'rag-interaction-logger-monitor' ),
			self::CAPABILITY,
			self::SLUG_DETAIL,
			array( new Detail_Page(), 'render' )
		);

		if ( false !== $detail ) {
			$this->hook_suffixes[] = $detail;
		}
	}

	/**
	 * Tells whether a hook suffix belongs to one of the plugin screens.
	 *
	 * @param string $hook_suffix Hook suffix passed to `admin_enqueue_scripts`.
	 * @return bool
	 */
	public function is_plugin_screen( string $hook_suffix ): bool {
		return in_array( $hook_suffix, $this->hook_suffixes, true );
	}

	/**
	 * Adds a visible sub page under the top-level menu.
	 *
	 * @param string   $title    Page and menu title.
	 * @param string   $slug     Menu slug.
	 * @param callable $callback Page renderer.
	 * @return void
	 */
	private function add_submenu( string $title, string $slug, callable $callback ): void {
		$hook_suffix = add_submenu_page(
			self::SLUG_DASHBOARD,
			$title,
			$title,
			self::CAPABILITY,
			$slug,
			$callback
		);

		if ( false !== $hook_suffix ) {
			$this->hook_suffixes[] = $hook_suffix;
		}
	}
}
