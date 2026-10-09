<?php
/**
 * Administration menu.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

use RILM\Database\Connection;

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
	 * Menu slug of the daily trend charts.
	 */
	public const SLUG_TREND = 'rilm-trend';

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
	 * Hook suffix of the detail screen, empty until it is registered.
	 *
	 * @var string
	 */
	private $detail_hook_suffix = '';

	/**
	 * Connection to the log database, shared with the pages that read it.
	 *
	 * @var Connection|null
	 */
	private $connection;

	/**
	 * Constructor.
	 *
	 * @param Connection|null $connection Connection to the log database; pages create their own when null.
	 */
	public function __construct( ?Connection $connection = null ) {
		$this->connection = $connection;
	}

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
		$this->hook_suffixes      = array();
		$this->detail_hook_suffix = '';

		// add_menu_page() lists the item even without the capability: register nothing for other users.
		if ( ! Access::is_allowed() ) {
			return;
		}

		$dashboard = add_menu_page(
			__( 'Monitor RAG', 'rag-interaction-logger-monitor' ),
			__( 'Monitor RAG', 'rag-interaction-logger-monitor' ),
			self::CAPABILITY,
			self::SLUG_DASHBOARD,
			array( new Dashboard_Page( $this->connection ), 'render' ),
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
			__( 'Trends', 'rag-interaction-logger-monitor' ),
			self::SLUG_TREND,
			array( new Trend_Page( $this->connection ), 'render' ),
			__( 'Daily trend', 'rag-interaction-logger-monitor' )
		);

		$interactions      = new Interactions_Page( $this->connection );
		$interactions_hook = $this->add_submenu(
			__( 'Interactions', 'rag-interaction-logger-monitor' ),
			self::SLUG_INTERACTIONS,
			array( $interactions, 'render' )
		);

		// The form is handled before the page prints anything, so it can redirect.
		if ( null !== $interactions_hook ) {
			add_action( 'load-' . $interactions_hook, array( $interactions, 'handle_request' ) );
		}

		$this->add_submenu(
			__( 'Anomalies', 'rag-interaction-logger-monitor' ),
			self::SLUG_ANOMALIES,
			array( new Anomalies_Page( $this->connection ), 'render' )
		);

		$this->add_submenu(
			__( 'Settings', 'rag-interaction-logger-monitor' ),
			self::SLUG_SETTINGS,
			array( new Settings_Page(), 'render' )
		);

		// The `options.php` parent registers a page reachable by URL but absent from every menu; an empty
		// parent would leave the global $title null and trigger a strip_tags() deprecation on PHP 8.1+.
		$detail = add_submenu_page(
			'options.php',
			__( 'Interaction detail', 'rag-interaction-logger-monitor' ),
			__( 'Interaction detail', 'rag-interaction-logger-monitor' ),
			self::CAPABILITY,
			self::SLUG_DETAIL,
			array( new Detail_Page( $this->connection ), 'render' )
		);

		if ( false !== $detail ) {
			$this->hook_suffixes[]    = $detail;
			$this->detail_hook_suffix = $detail;
		}
	}

	/**
	 * Tells whether a hook suffix is the one of the interaction detail screen.
	 *
	 * @param string $hook_suffix Hook suffix passed to `admin_enqueue_scripts`.
	 * @return bool
	 */
	public function is_detail_screen( string $hook_suffix ): bool {
		return '' !== $this->detail_hook_suffix && $this->detail_hook_suffix === $hook_suffix;
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
	 * @param string      $menu_title Label shown in the menu; also the page title, unless given below.
	 * @param string      $slug       Menu slug.
	 * @param callable    $callback   Page renderer.
	 * @param string|null $page_title Page title (the browser tab), when it should read differently
	 *                                from the shorter menu label; defaults to `$menu_title`.
	 * @return string|null Hook suffix of the page, or null when it was not registered.
	 */
	private function add_submenu( string $menu_title, string $slug, callable $callback, ?string $page_title = null ): ?string {
		$hook_suffix = add_submenu_page(
			self::SLUG_DASHBOARD,
			$page_title ?? $menu_title,
			$menu_title,
			self::CAPABILITY,
			$slug,
			$callback
		);

		if ( false === $hook_suffix ) {
			return null;
		}

		$this->hook_suffixes[] = $hook_suffix;

		return $hook_suffix;
	}
}
