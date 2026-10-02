<?php
/**
 * Integration tests for the Settings page, its sanitizer and the uninstall routine.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Integration;

use RILM\Admin\Menu;
use RILM\Admin\Settings;
use RILM\Admin\Settings_Page;
use RILM\Admin\Settings_Sanitizer;
use RILM\Config\Config;
use RILM\Database\Connection;
use RILM\Database\Connection_Tester;
use WP_UnitTestCase;
use WPDieException;

/**
 * Verifies saving, locking, nonce handling, autoload and uninstall behaviour.
 */
class SettingsTest extends WP_UnitTestCase {

	/**
	 * Settings registration used by the tests.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Loads the admin API and registers the option.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		delete_option( Config::OPTION_NAME );
		$GLOBALS['wp_settings_errors'] = array();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->settings = new Settings( array( $this, 'empty_config' ) );
		$this->settings->init();
		$this->settings->register();
	}

	/**
	 * Cleans the globals touched by the tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_POST[ Settings_Page::TEST_BUTTON ], $_POST[ Settings_Page::TEST_NONCE_FIELD ], $_REQUEST[ Settings_Page::TEST_NONCE_FIELD ] );
		delete_option( Config::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Configuration with no constant.
	 *
	 * @return Config
	 */
	public function empty_config(): Config {
		return new Config();
	}

	/**
	 * Configuration where the host is defined by a constant.
	 *
	 * @return Config
	 */
	public function locked_host_config(): Config {
		return new Config( array(), array( 'host' => 'constant.example.test' ) );
	}

	/**
	 * Valid values are stored as the permitted fields only.
	 *
	 * @return void
	 */
	public function test_valid_values_are_saved(): void {
		update_option(
			Config::OPTION_NAME,
			array(
				'host'  => ' db.example.test ',
				'port'  => '03307',
				'name'  => 'other-db',
				'table' => 'other_table',
				'user'  => 'logger_reader_user',
			)
		);

		$this->assertSame(
			array(
				'host'  => 'db.example.test',
				'port'  => '3307',
				'name'  => 'other-db',
				'table' => 'other_table',
				'user'  => 'logger_reader_user',
			),
			get_option( Config::OPTION_NAME )
		);
	}

	/**
	 * The password and unknown keys are dropped, never stored.
	 *
	 * @return void
	 */
	public function test_password_and_unknown_keys_are_not_stored(): void {
		update_option(
			Config::OPTION_NAME,
			array(
				'host'     => 'db.example.test',
				'user'     => 'logger_reader_user',
				'password' => 'attacker_password',
				'extra'    => 'x',
			)
		);

		$stored = get_option( Config::OPTION_NAME );

		$this->assertSame( array( 'host', 'port', 'name', 'table', 'user' ), array_keys( $stored ) );
		$this->assertSame( 'logger_reader_user', $stored['user'] );
		$this->assertStringNotContainsString( 'attacker_password', wp_json_encode( $stored ) );
	}

	/**
	 * An invalid value keeps the previous one and reports an error that does not echo the value.
	 *
	 * @return void
	 */
	public function test_invalid_value_keeps_previous_and_reports_error(): void {
		update_option( Config::OPTION_NAME, array( 'port' => '3307' ) );
		global $wp_settings_errors;
		$wp_settings_errors = array();

		update_option( Config::OPTION_NAME, array( 'port' => 'not-a-port<script>' ) );

		$this->assertSame( '3307', get_option( Config::OPTION_NAME )['port'] );

		$errors = get_settings_errors( Config::OPTION_NAME );
		$this->assertCount( 1, $errors );
		$this->assertSame( 'rilm_invalid_port', $errors[0]['code'] );
		$this->assertStringNotContainsString( 'not-a-port', $errors[0]['message'] );
	}

	/**
	 * An empty value clears the field.
	 *
	 * @return void
	 */
	public function test_empty_value_clears_the_field(): void {
		update_option( Config::OPTION_NAME, array( 'table' => 'custom_table' ) );
		update_option( Config::OPTION_NAME, array( 'table' => '  ' ) );

		$this->assertSame( '', get_option( Config::OPTION_NAME )['table'] );
	}

	/**
	 * A field defined by a constant is never changed by the form.
	 *
	 * @return void
	 */
	public function test_constant_locked_field_is_not_changed(): void {
		$sanitizer = new Settings_Sanitizer( $this->locked_host_config() );

		update_option( Config::OPTION_NAME, array( 'host' => 'stored.example.test' ), false );
		$result = $sanitizer->sanitize(
			array(
				'host' => 'form.example.test',
				'port' => '3310',
			)
		);

		$this->assertSame( 'stored.example.test', $result['host'] );
		$this->assertSame( '3310', $result['port'] );
	}

	/**
	 * The option is not autoloaded.
	 *
	 * @return void
	 */
	public function test_option_is_not_autoloaded(): void {
		update_option( Config::OPTION_NAME, array( 'host' => 'db.example.test' ) );

		$this->assertArrayNotHasKey( Config::OPTION_NAME, wp_load_alloptions( true ) );
	}

	/**
	 * Saving through options.php needs manage_options.
	 *
	 * @return void
	 */
	public function test_saving_capability_is_manage_options(): void {
		$this->assertSame( 'manage_options', apply_filters( 'option_page_capability_' . Settings::GROUP, 'edit_posts' ) );
	}

	/**
	 * The option is registered without REST exposure.
	 *
	 * @return void
	 */
	public function test_option_is_not_exposed_in_rest(): void {
		$registered = get_registered_settings();

		$this->assertArrayHasKey( Config::OPTION_NAME, $registered );
		$this->assertFalse( $registered[ Config::OPTION_NAME ]['show_in_rest'] );
	}

	/**
	 * Renders the page with the given configuration.
	 *
	 * @param callable               $provider Config provider.
	 * @param Connection_Tester|null $tester   Optional tester.
	 * @return string
	 */
	private function render_page( callable $provider, ?Connection_Tester $tester = null ): string {
		$settings = new Settings( $provider );
		$settings->register();

		ob_start();

		try {
			( new Settings_Page( $provider, $tester ) )->render();
		} catch ( \Throwable $exception ) {
			ob_end_clean();
			throw $exception;
		}

		return ob_get_clean();
	}

	/**
	 * The form posts to options.php with the Settings API nonce, and the password has its own empty field.
	 *
	 * @return void
	 */
	public function test_page_form_is_protected_and_the_password_field_is_empty(): void {
		$output = $this->render_page( array( $this, 'empty_config' ) );

		$this->assertStringContainsString( 'action="options.php"', $output );
		$this->assertStringContainsString( "name='option_page' value='" . Settings::GROUP . "'", $output );
		$this->assertStringContainsString( '_wpnonce', $output );
		$this->assertStringContainsString( 'name="rilm_settings[host]"', $output );
		$this->assertStringContainsString( 'name="rilm_settings[table]"', $output );
		$this->assertStringContainsString( 'rilm_settings[user]', $output );
		$this->assertMatchesRegularExpression( '/<input type="password" id="rilm-password" name="rilm_db_password" value="" /', $output );
		$this->assertStringContainsString( 'autocomplete="new-password"', $output );
		$this->assertStringNotContainsString( 'rilm_settings[password]', $output, 'The password is not part of the settings array.' );
		$this->assertStringContainsString( 'Database password', $output );
	}

	/**
	 * Fields have explicit labels.
	 *
	 * @return void
	 */
	public function test_fields_have_explicit_labels(): void {
		$output = $this->render_page( array( $this, 'empty_config' ) );

		foreach ( Config::FIELDS as $key ) {
			$this->assertStringContainsString( 'for="rilm-' . $key . '"', $output );
			$this->assertStringContainsString( 'id="rilm-' . $key . '"', $output );
		}
	}

	/**
	 * A field defined by a constant is shown disabled with its effective value.
	 *
	 * @return void
	 */
	public function test_locked_field_is_disabled(): void {
		$output = $this->render_page( array( $this, 'locked_host_config' ) );

		$this->assertMatchesRegularExpression( '/id="rilm-host"[^>]*value="constant\.example\.test"[^>]*disabled="disabled"/', $output );
		$this->assertStringContainsString( 'Defined in wp-config.php', $output );
		$this->assertDoesNotMatchRegularExpression( '/id="rilm-port"[^>]*disabled/', $output );
	}

	/**
	 * The page never prints stored values unescaped.
	 *
	 * @return void
	 */
	public function test_stored_values_are_escaped(): void {
		update_option( Config::OPTION_NAME, array( 'host' => 'db.example.test' ) );
		// Bypass the sanitizer to simulate a tampered row.
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( array( 'host' => '"><script>alert(1)</script>' ) ) ), array( 'option_name' => Config::OPTION_NAME ) );
		wp_cache_delete( Config::OPTION_NAME, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$output = $this->render_page( array( $this, 'empty_config' ) );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $output );
	}

	/**
	 * The connection test needs a valid nonce.
	 *
	 * @return void
	 */
	public function test_connection_test_requires_a_valid_nonce(): void {
		$_POST[ Settings_Page::TEST_BUTTON ]        = 'Test connection';
		$_POST[ Settings_Page::TEST_NONCE_FIELD ]    = 'invalid';
		$_REQUEST[ Settings_Page::TEST_NONCE_FIELD ] = 'invalid';

		$this->expectException( WPDieException::class );

		$this->render_page( array( $this, 'empty_config' ), $this->fake_tester( Connection::STATUS_OK ) );
	}

	/**
	 * A valid request runs the tester and prints a generic result.
	 *
	 * @dataProvider provide_test_results
	 *
	 * @param string $result   Result code returned by the tester.
	 * @param string $expected Fragment expected in the page.
	 * @return void
	 */
	public function test_connection_test_reports_the_result( string $result, string $expected ): void {
		$nonce                                       = wp_create_nonce( Settings_Page::TEST_NONCE_ACTION );
		$_POST[ Settings_Page::TEST_BUTTON ]        = 'Test connection';
		$_POST[ Settings_Page::TEST_NONCE_FIELD ]    = $nonce;
		$_REQUEST[ Settings_Page::TEST_NONCE_FIELD ] = $nonce;

		$output = $this->render_page( array( $this, 'empty_config' ), $this->fake_tester( $result ) );

		$this->assertStringContainsString( $expected, $output );
	}

	/**
	 * Provides tester results and the expected message fragment.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function provide_test_results(): array {
		return array(
			'ok'               => array( Connection::STATUS_OK, 'The connection works' ),
			'not configured'   => array( Connection::STATUS_NOT_CONFIGURED, 'not configured yet' ),
			'invalid'          => array( Connection::STATUS_INVALID, 'are not valid' ),
			'unreachable'      => array( Connection::STATUS_UNREACHABLE, 'cannot be reached' ),
			'table unreadable' => array( Connection_Tester::RESULT_TABLE_UNREADABLE, 'the table cannot be read' ),
		);
	}

	/**
	 * Without the test button the tester is not run.
	 *
	 * @return void
	 */
	public function test_connection_test_does_not_run_without_the_button(): void {
		$tester = $this->fake_tester( Connection::STATUS_OK );

		$output = $this->render_page( array( $this, 'empty_config' ), $tester );

		$this->assertSame( 0, $tester->calls );
		$this->assertStringNotContainsString( 'The connection works', $output );
		$this->assertStringContainsString( Settings_Page::TEST_NONCE_FIELD, $output );
	}

	/**
	 * The page refuses users without manage_options.
	 *
	 * @return void
	 */
	public function test_page_refuses_users_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->expectException( WPDieException::class );

		$this->render_page( array( $this, 'empty_config' ) );
	}

	/**
	 * A user without manage_options cannot trigger the connection test.
	 *
	 * @return void
	 */
	public function test_connection_test_is_refused_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$nonce                                       = wp_create_nonce( Settings_Page::TEST_NONCE_ACTION );
		$_POST[ Settings_Page::TEST_BUTTON ]        = 'Test connection';
		$_POST[ Settings_Page::TEST_NONCE_FIELD ]    = $nonce;
		$_REQUEST[ Settings_Page::TEST_NONCE_FIELD ] = $nonce;

		$tester = $this->fake_tester( Connection::STATUS_OK );

		try {
			$this->render_page( array( $this, 'empty_config' ), $tester );
			$this->fail( 'The page must refuse the request.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( 0, $tester->calls );
		}
	}

	/**
	 * Tester that returns a fixed result without any network access.
	 *
	 * @param string $result Result code to return.
	 * @return Connection_Tester
	 */
	private function fake_tester( string $result ): Connection_Tester {
		return new class( $result ) extends Connection_Tester {
			/**
			 * Number of runs.
			 *
			 * @var int
			 */
			public $calls = 0;

			/**
			 * Fixed result.
			 *
			 * @var string
			 */
			private $result;

			/**
			 * Constructor.
			 *
			 * @param string $result Result to return.
			 */
			public function __construct( string $result ) {
				$this->result = $result;
			}

			/**
			 * Returns the fixed result.
			 *
			 * @param Config $config Ignored.
			 * @return string
			 */
			public function run( Config $config ): string {
				++$this->calls;
				return $this->result;
			}
		};
	}

	/**
	 * The tester reports the connection status and never touches the network when not configured.
	 *
	 * @return void
	 */
	public function test_tester_returns_the_status_without_a_valid_configuration(): void {
		$tester = new Connection_Tester();

		$this->assertSame( Connection::STATUS_NOT_CONFIGURED, $tester->run( new Config() ) );
	}

	/**
	 * The tester reports an unreadable table when the probe query fails.
	 *
	 * @return void
	 */
	public function test_tester_reports_unreadable_table(): void {
		$config = new Config(
			array( 'host' => 'db.example.test' ),
			array(
				'user'     => 'reader',
				'password' => 'secret',
			)
		);

		$db = new class() extends \RILM\Database\Guarded_Wpdb {
			/**
			 * Statement received.
			 *
			 * @var string
			 */
			public $last_probe = '';

			/**
			 * Skips the real connection.
			 */
			public function __construct() {}

			/**
			 * Pretends the probe fails.
			 *
			 * @param string $query SQL statement.
			 * @return bool
			 */
			public function query( $query ) {
				$this->last_probe = $query;
				return false;
			}
		};

		$tester = new class( $db ) extends Connection_Tester {
			/**
			 * Fake database.
			 *
			 * @var \RILM\Database\Guarded_Wpdb
			 */
			private $fake;

			/**
			 * Constructor.
			 *
			 * @param \RILM\Database\Guarded_Wpdb $fake Fake database.
			 */
			public function __construct( \RILM\Database\Guarded_Wpdb $fake ) {
				$this->fake = $fake;
			}

			/**
			 * Returns a connection that is always open and serves the fake database.
			 *
			 * @param Config $config Configuration.
			 * @return Connection
			 */
			protected function connect( Config $config ): Connection {
				return new class( $config, $this->fake ) extends Connection {
					/**
					 * Fake database.
					 *
					 * @var \RILM\Database\Guarded_Wpdb
					 */
					private $fake;

					/**
					 * Constructor.
					 *
					 * @param Config                      $config Configuration.
					 * @param \RILM\Database\Guarded_Wpdb $fake   Fake database.
					 */
					public function __construct( Config $config, \RILM\Database\Guarded_Wpdb $fake ) {
						parent::__construct( $config );
						$this->fake = $fake;
					}

					/**
					 * Always open.
					 *
					 * @return string
					 */
					public function status(): string {
						return Connection::STATUS_OK;
					}

					/**
					 * Returns the fake database.
					 *
					 * @return \RILM\Database\Guarded_Wpdb|null
					 */
					public function db(): ?\RILM\Database\Guarded_Wpdb {
						return $this->fake;
					}
				};
			}
		};

		$this->assertSame( Connection_Tester::RESULT_TABLE_UNREADABLE, $tester->run( $config ) );
		$this->assertSame( 'SELECT 1 FROM `rag-interaction-logger-db`.`ril_interactions` LIMIT 1', $db->last_probe );
	}

	/**
	 * Uninstalling removes the option and nothing else.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_the_option(): void {
		update_option( Config::OPTION_NAME, array( 'host' => 'db.example.test' ) );
		update_option( 'rilm_unrelated_option', 'keep' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'rag-interaction-logger-monitor/rag-interaction-logger-monitor.php' );
		}

		include dirname( __DIR__, 2 ) . '/uninstall.php';

		// The registered default would hide a missing option: pass an explicit default.
		$this->assertSame( 'absent', get_option( Config::OPTION_NAME, 'absent' ) );
		$this->assertSame( 'keep', get_option( 'rilm_unrelated_option' ) );

		delete_option( 'rilm_unrelated_option' );
	}

	/**
	 * The settings submenu slug matches the page used for the Settings API sections.
	 *
	 * @return void
	 */
	public function test_sections_are_registered_for_the_settings_page(): void {
		global $wp_settings_sections, $wp_settings_fields;

		$this->assertArrayHasKey( Settings::SECTION, $wp_settings_sections[ Menu::SLUG_SETTINGS ] );
		$this->assertCount( count( Config::FIELDS ) + 1, $wp_settings_fields[ Menu::SLUG_SETTINGS ][ Settings::SECTION ], 'The fields of the settings array plus the password.' );
	}
}
