<?php
/**
 * Integration tests for the administration menu, access control and assets.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Integration;

use RILM\Admin\Anomalies_Page;
use RILM\Admin\Assets;
use RILM\Admin\Dashboard_Page;
use RILM\Admin\Detail_Page;
use RILM\Admin\Interactions_Page;
use RILM\Admin\Menu;
use RILM\Admin\Settings_Page;
use RILM\Plugin;
use WP_UnitTestCase;
use WPDieException;

/**
 * Verifies menu registration, the manage_options requirement and asset scoping.
 */
class MenuTest extends WP_UnitTestCase {

	/**
	 * Menu used by the test.
	 *
	 * @var Menu
	 */
	private $menu;

	/**
	 * Loads the admin API and resets the global menu structures.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$GLOBALS['menu']             = array();
		$GLOBALS['submenu']          = array();
		$GLOBALS['admin_page_hooks'] = array();
		$GLOBALS['_registered_pages'] = array();
		$GLOBALS['_parent_pages']    = array();
		$GLOBALS['_wp_submenu_nopriv'] = array();

		$this->menu = new Menu();
		$this->menu->init();
	}

	/**
	 * Removes the stylesheet registered by the test.
	 *
	 * @return void
	 */
	public function tear_down() {
		wp_dequeue_style( Assets::STYLE_HANDLE );
		wp_deregister_style( Assets::STYLE_HANDLE );
		wp_dequeue_script( Assets::SCRIPT_HANDLE );
		wp_deregister_script( Assets::SCRIPT_HANDLE );

		parent::tear_down();
	}

	/**
	 * Logs in a user with the given role.
	 *
	 * @param string $role User role.
	 * @return void
	 */
	private function login_as( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	/**
	 * Returns the slugs of the visible sub pages.
	 *
	 * @return string[]
	 */
	private function submenu_slugs(): array {
		$items = $GLOBALS['submenu'][ Menu::SLUG_DASHBOARD ] ?? array();

		return array_column( $items, 2 );
	}

	/**
	 * Administrators get the top-level menu and the three visible pages.
	 *
	 * @return void
	 */
	public function test_administrator_sees_menu_and_pages(): void {
		$this->login_as( 'administrator' );

		do_action( 'admin_menu' );

		$top_level_slugs = array_column( $GLOBALS['menu'], 2 );

		$this->assertContains( Menu::SLUG_DASHBOARD, $top_level_slugs );
		$this->assertSame(
			array( Menu::SLUG_DASHBOARD, Menu::SLUG_INTERACTIONS, Menu::SLUG_ANOMALIES, Menu::SLUG_SETTINGS ),
			$this->submenu_slugs()
		);
	}

	/**
	 * The detail page is registered but never listed in a menu.
	 *
	 * @return void
	 */
	public function test_detail_page_is_registered_but_hidden(): void {
		$this->login_as( 'administrator' );

		do_action( 'admin_menu' );

		$this->assertNotContains( Menu::SLUG_DETAIL, $this->submenu_slugs() );
		$this->assertContains( Menu::SLUG_DETAIL, array_column( $GLOBALS['submenu'][''] ?? array(), 2 ) );
		$this->assertArrayHasKey( get_plugin_page_hookname( Menu::SLUG_DETAIL, '' ), $GLOBALS['_registered_pages'] );
	}

	/**
	 * The dashboard callback is attached once, even though the first submenu repeats its slug.
	 *
	 * @return void
	 */
	public function test_dashboard_callback_is_attached_once(): void {
		$this->login_as( 'administrator' );

		do_action( 'admin_menu' );

		ob_start();
		do_action( get_plugin_page_hookname( Menu::SLUG_DASHBOARD, '' ) );
		$output = ob_get_clean();

		$this->assertSame( 1, substr_count( $output, '<h1>' ) );
	}

	/**
	 * Users without manage_options get no menu entries at all.
	 *
	 * @dataProvider provide_non_admin_roles
	 *
	 * @param string $role Role without manage_options.
	 * @return void
	 */
	public function test_non_administrators_get_no_menu( string $role ): void {
		$this->login_as( $role );

		do_action( 'admin_menu' );

		$this->assertNotContains( Menu::SLUG_DASHBOARD, array_column( $GLOBALS['menu'], 2 ) );
		$this->assertSame( array(), $this->submenu_slugs() );
		$this->assertSame( array(), $GLOBALS['submenu'][''] ?? array() );
	}

	/**
	 * Provides roles that must not reach the plugin.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_non_admin_roles(): array {
		return array(
			'editor'      => array( 'editor' ),
			'author'      => array( 'author' ),
			'subscriber'  => array( 'subscriber' ),
		);
	}

	/**
	 * Every page renderer refuses users without manage_options, even if called directly.
	 *
	 * @dataProvider provide_page_classes
	 *
	 * @param string $page_class Page class name.
	 * @return void
	 */
	public function test_pages_refuse_users_without_capability( string $page_class ): void {
		$this->login_as( 'editor' );

		$page = new $page_class();

		$this->expectException( WPDieException::class );

		ob_start();
		try {
			$page->render();
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * Every page renderer prints its heading for administrators.
	 *
	 * @dataProvider provide_page_classes
	 *
	 * @param string $page_class Page class name.
	 * @return void
	 */
	public function test_pages_render_for_administrators( string $page_class ): void {
		$this->login_as( 'administrator' );

		$page = new $page_class();

		ob_start();
		$page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<h1>', $output );
	}

	/**
	 * Provides the four page classes.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_page_classes(): array {
		return array(
			'dashboard'    => array( Dashboard_Page::class ),
			'interactions' => array( Interactions_Page::class ),
			'anomalies'    => array( Anomalies_Page::class ),
			'settings'     => array( Settings_Page::class ),
			'detail'       => array( Detail_Page::class ),
		);
	}

	/**
	 * The stylesheet is enqueued on plugin screens only.
	 *
	 * @return void
	 */
	public function test_stylesheet_is_enqueued_only_on_plugin_screens(): void {
		$this->login_as( 'administrator' );

		do_action( 'admin_menu' );

		$assets = new Assets( $this->menu );

		$assets->enqueue( 'index.php' );
		$this->assertFalse( wp_style_is( Assets::STYLE_HANDLE, 'enqueued' ) );

		$assets->enqueue( get_plugin_page_hookname( Menu::SLUG_DASHBOARD, '' ) );
		$this->assertTrue( wp_style_is( Assets::STYLE_HANDLE, 'enqueued' ) );
	}

	/**
	 * The interactions form is handled on `load-{page}`, which WordPress runs before any output,
	 * so the redirect for an empty search can still send its headers.
	 *
	 * @return void
	 */
	public function test_interactions_form_is_handled_before_any_output(): void {
		$this->login_as( 'administrator' );

		do_action( 'admin_menu' );

		$hook = get_plugin_page_hookname( Menu::SLUG_INTERACTIONS, Menu::SLUG_DASHBOARD );

		$this->assertNotFalse( has_action( 'load-' . $hook ), 'The page handles its form on load-{page}.' );

		$nonce                     = wp_create_nonce( Interactions_Page::NONCE_ACTION );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'outcome'                      => 'incomplete',
			'search'                       => '',
			Interactions_Page::NONCE_FIELD => $nonce,
		);
		$_REQUEST                  = $_POST;

		$location = null;

		// Returning false makes wp_redirect() send nothing, which also keeps the page from exiting.
		add_filter(
			'wp_redirect',
			static function ( $url ) use ( &$location ) {
				$location = $url;
				return false;
			}
		);

		ob_start();
		do_action( 'load-' . $hook );
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'Nothing is printed before the redirect.' );
		$this->assertNotNull( $location, 'An empty search is redirected to a clean URL.' );
		$this->assertStringContainsString( 'page=rilm-interactions', $location );
		$this->assertStringContainsString( 'outcome=incomplete', $location );
		$this->assertStringNotContainsString( 'search', $location );

		$_POST                     = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	/**
	 * Only the interactions page handles a form before output: the other pages have no redirect.
	 *
	 * @return void
	 */
	public function test_only_the_interactions_page_has_a_load_handler(): void {
		$this->login_as( 'administrator' );

		do_action( 'admin_menu' );

		foreach ( array( Menu::SLUG_ANOMALIES, Menu::SLUG_SETTINGS ) as $slug ) {
			$this->assertFalse( has_action( 'load-' . get_plugin_page_hookname( $slug, Menu::SLUG_DASHBOARD ) ), $slug );
		}

		$this->assertFalse( has_action( 'load-' . get_plugin_page_hookname( Menu::SLUG_DASHBOARD, '' ) ) );
	}

	/**
	 * The administration script is enqueued on plugin screens only, in the footer and deferred.
	 *
	 * @return void
	 */
	public function test_script_is_enqueued_only_on_plugin_screens(): void {
		$this->login_as( 'administrator' );

		do_action( 'admin_menu' );

		$assets = new Assets( $this->menu );

		$assets->enqueue( 'index.php' );
		$this->assertFalse( wp_script_is( Assets::SCRIPT_HANDLE, 'enqueued' ) );

		$assets->enqueue( get_plugin_page_hookname( Menu::SLUG_INTERACTIONS, Menu::SLUG_DASHBOARD ) );
		$this->assertTrue( wp_script_is( Assets::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertSame( 1, wp_scripts()->get_data( Assets::SCRIPT_HANDLE, 'group' ), 'The script is loaded in the footer.' );
		$this->assertSame( 'defer', wp_scripts()->get_data( Assets::SCRIPT_HANDLE, 'strategy' ) );
		$this->assertStringContainsString( 'assets/js/admin.js', wp_scripts()->registered[ Assets::SCRIPT_HANDLE ]->src );
		$this->assertSame( RILM_VERSION, wp_scripts()->registered[ Assets::SCRIPT_HANDLE ]->ver );
	}

	/**
	 * The script shows the custom dates only for the custom period, and refers to the markup the plugin prints.
	 *
	 * @return void
	 */
	public function test_script_matches_the_markup_it_controls(): void {
		$script = (string) file_get_contents( RILM_PLUGIN_DIR . 'assets/js/admin.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString( "getElementById( 'rilm-period' )", $script );
		$this->assertStringContainsString( '[data-rilm-custom-date]', $script );
		$this->assertStringContainsString( "'" . \RILM\Repository\Period::CUSTOM . "' === select.value", $script );
		$this->assertStringContainsString( 'field.hidden = ! custom', $script );
		$this->assertStringNotContainsString( 'innerHTML', $script );
		$this->assertStringNotContainsString( 'eval(', $script );
	}

	/**
	 * The orchestrator wires the menu and the assets to the WordPress hooks.
	 *
	 * @return void
	 */
	public function test_plugin_registers_admin_hooks(): void {
		( new Plugin() )->init();

		$this->assertNotFalse( has_action( 'admin_menu' ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts' ) );
		$this->assertNotFalse( has_action( 'admin_notices' ) );
		$this->assertNotFalse( has_action( 'admin_init' ) );
	}
}
