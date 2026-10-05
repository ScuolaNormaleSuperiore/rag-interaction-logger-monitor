<?php
/**
 * Integration tests for the localization of the administration screens.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use RILM\Admin\Dashboard_Page;
use RILM\Admin\Interactions_Page;
use RILM\Config\Config;
use RILM\Plugin;
use RILM\Repository\Interaction_Repository;
use RILM\Tests\Unit\Support\Fake_Reader;
use WP_Locale_Switcher;
use WP_UnitTestCase;

/**
 * Verifies that the screens follow the locale of the user and never translate the logged data.
 */
class LocalizationTest extends WP_UnitTestCase {

	/**
	 * Double that records the queries.
	 *
	 * @var Fake_Reader
	 */
	private $reader;

	/**
	 * Locale switcher of the test suite, restored afterwards.
	 *
	 * @var WP_Locale_Switcher
	 */
	private $switcher;

	/**
	 * Catalog files the plugin asked for.
	 *
	 * @var string[]
	 */
	private $requested = array();

	/**
	 * Sets up the reader and the time zone.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		update_option( 'timezone_string', 'Europe/Rome' );

		// WordPress only switches to a language that is installed: pretend Italian is, the catalog being the plugin's own.
		add_filter( 'get_available_languages', array( $this, 'with_italian' ) );
		add_filter( 'load_textdomain_mofile', array( $this, 'redirect_mofile' ), 10, 2 );

		// The switcher reads the installed languages once, when it is created: create it again now.
		$this->switcher                = $GLOBALS['wp_locale_switcher'];
		$GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher();
		$GLOBALS['wp_locale_switcher']->init();

		$this->reader = new Fake_Reader();
	}

	/**
	 * Records the catalog the plugin asks for and serves the real file.
	 *
	 * The plugin is not inside the plugin folder of the test installation, so the file WordPress
	 * would look for is checked by its name and the real catalog of the plugin is loaded instead.
	 *
	 * @param string $mofile File WordPress would load.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public function redirect_mofile( string $mofile, string $domain ): string {
		if ( 'rag-interaction-logger-monitor' !== $domain ) {
			return $mofile;
		}

		$this->requested[] = $mofile;

		return dirname( __DIR__, 2 ) . '/languages/' . basename( $mofile );
	}

	/**
	 * Adds Italian to the installed languages.
	 *
	 * @param string[] $languages Installed languages.
	 * @return string[]
	 */
	public function with_italian( array $languages ): array {
		return array_merge( $languages, array( 'it_IT' ) );
	}

	/**
	 * Restores the request globals and the locale.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_GET = array();
		remove_all_actions( 'switch_locale' );
		$GLOBALS['wp_locale_switcher'] = $this->switcher;
		unload_textdomain( 'rag-interaction-logger-monitor', true );
		delete_option( 'timezone_string' );

		parent::tear_down();
	}

	/**
	 * Creates an administrator with a personal language.
	 *
	 * @param string $locale Locale of the user.
	 * @return int User id.
	 */
	private function admin( string $locale ): int {
		return self::factory()->user->create(
			array(
				'role'   => 'administrator',
				'locale' => $locale,
			)
		);
	}

	/**
	 * Builds a repository on the fake reader.
	 *
	 * @return Interaction_Repository
	 */
	private function repository(): Interaction_Repository {
		return new Interaction_Repository(
			$this->reader,
			new Config(
				array( 'host' => 'db.example.test' ),
				array(
					'user'     => 'reader',
					'password' => 'secret',
				)
			)
		);
	}

	/**
	 * Fixed clock.
	 *
	 * @return callable
	 */
	private static function clock(): callable {
		return static function (): DateTimeImmutable {
			return new DateTimeImmutable( '2026-10-02 14:30:00', new DateTimeZone( 'Europe/Rome' ) );
		};
	}

	/**
	 * Renders the Interactions page for the current user.
	 *
	 * @return string
	 */
	private function render_interactions(): string {
		$this->reader->rows = array(
			array(
				array(
					'id'                 => '7',
					'ts'                 => '2026-10-02 12:30:45.123',
					'duration_ms'        => '1500',
					'instance'           => 'sito-ict',
					'user_id'            => '42',
					'turn_id'            => 'abc',
					'outcome'            => 'generated',
					'question'           => 'What is the opening time?',
					'delivered'          => 'Dalle 9 alle 17.',
					'guard_present'      => '1',
					'input_verdict'      => 'prompt_injection',
					'output_verdict'     => null,
					'other_plugin_reply' => null,
					'recall_count'       => '2',
					'recall_top_score'   => '0.5',
				),
			),
		);

		$repository = $this->repository();
		$page       = new Interactions_Page(
			null,
			static function () use ( $repository ) {
				return $repository;
			},
			self::clock()
		);

		ob_start();
		$page->render();

		return (string) ob_get_clean();
	}

	/**
	 * Switches to the language of a user, as the administration screens do.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private function become( int $user_id ): void {
		wp_set_current_user( $user_id );
		switch_to_user_locale( $user_id );

		// What the plugin does on init, in the language the user now has.
		( new Plugin() )->load_textdomain();
	}

	/**
	 * The plugin loads its catalog on init, from its own languages folder and in the language of the user.
	 *
	 * @return void
	 */
	public function test_catalog_is_requested_from_the_languages_folder(): void {
		$this->become( $this->admin( 'it_IT' ) );

		// WordPress 6.7 and later load a catalog at the first translation, not when the path is registered.
		__( 'Reset', 'rag-interaction-logger-monitor' );

		$this->assertNotEmpty( $this->requested );
		$this->assertStringEndsWith( 'rag-interaction-logger-monitor/languages/rag-interaction-logger-monitor-it_IT.mo', end( $this->requested ) );
	}

	/**
	 * The catalog is loaded on init, once WordPress knows the user's language.
	 *
	 * @return void
	 */
	public function test_catalog_is_loaded_on_init(): void {
		( new Plugin() )->init();

		$found = false;

		foreach ( $GLOBALS['wp_filter']['init']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Plugin && 'load_textdomain' === $callback['function'][1] ) {
					$found = true;
				}
			}
		}

		$this->assertTrue( $found );
	}

	/**
	 * The English source strings are shown to a user whose language is English.
	 *
	 * @return void
	 */
	public function test_english_user_sees_english(): void {
		$this->become( $this->admin( 'en_US' ) );

		$output = $this->render_interactions();

		$this->assertStringContainsString( 'Apply filters', $output );
		$this->assertStringNotContainsString( 'Applica i filtri', $output );
	}

	/**
	 * An administrator whose language is Italian sees the labels in Italian without any plugin setting.
	 *
	 * @return void
	 */
	public function test_italian_user_sees_italian(): void {
		$this->become( $this->admin( 'it_IT' ) );

		$output = $this->render_interactions();

		$this->assertSame( 'it_IT', determine_locale() );
		$this->assertStringContainsString( 'Applica i filtri', $output );
		$this->assertStringContainsString( 'Filtri avanzati', $output );
		$this->assertStringContainsString( 'Cerca in domande e risposte', $output );
		$this->assertStringNotContainsString( 'Apply filters', $output );
	}

	/**
	 * Two administrators with different languages get different labels from the same code.
	 *
	 * @return void
	 */
	public function test_two_users_two_languages(): void {
		$english = $this->admin( 'en_US' );
		$italian = $this->admin( 'it_IT' );

		$this->become( $english );
		$first = $this->render_interactions();
		restore_previous_locale();

		$this->become( $italian );
		$second = $this->render_interactions();

		$this->assertStringContainsString( 'Reset', $first );
		$this->assertStringContainsString( 'Azzera', $second );
		$this->assertStringNotContainsString( 'Azzera', $first );
	}

	/**
	 * The logged data stays as it was written: neither the texts nor the values of the logger are translated.
	 *
	 * @return void
	 */
	public function test_logged_data_is_not_translated(): void {
		$this->become( $this->admin( 'it_IT' ) );

		$output = $this->render_interactions();

		$this->assertStringContainsString( 'What is the opening time?', $output );
		$this->assertStringContainsString( 'Dalle 9 alle 17.', $output );
		$this->assertStringContainsString( 'prompt_injection', $output );
		$this->assertStringContainsString( 'sito-ict', $output );
	}

	/**
	 * The Dashboard follows the locale too, plurals included.
	 *
	 * @return void
	 */
	public function test_dashboard_and_plurals_follow_the_locale(): void {
		$this->become( $this->admin( 'it_IT' ) );

		$this->reader->col  = array( '900' );
		$this->reader->rows = array(
			array(
				array(
					'total'         => '1',
					'generated'     => '1',
					'fast_reply'    => '0',
					'incomplete'    => '0',
					'no_guardrails' => '0',
					'input_blocks'  => '0',
					'output_blocks' => '0',
					'zero_recall'   => '0',
					'completed'     => '1',
					'average_ms'    => '900',
				),
			),
			array(
				array(
					'hour'          => '2026-10-02 09',
					'turns'         => '1',
					'incomplete'    => '0',
					'input_blocks'  => '0',
					'output_blocks' => '0',
				),
			),
		);

		$repository = $this->repository();
		$page       = new Dashboard_Page(
			null,
			static function () use ( $repository ) {
				return $repository;
			},
			self::clock()
		);

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Cosa significano gli indicatori', $output );
		$this->assertStringContainsString( 'Turni completati: 1.', $output );
		$this->assertStringNotContainsString( 'Completed turns', $output );
	}
}
