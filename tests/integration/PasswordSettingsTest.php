<?php
/**
 * Integration tests for saving the database password from the Settings page.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Integration;

use RILM\Admin\Settings;
use RILM\Admin\Settings_Page;
use RILM\Config\Config;
use RILM\Config\Secret_Store;
use WP_UnitTestCase;
use WPDieException;

/**
 * Verifies that the password is stored encrypted, never shown, and handled safely in every state.
 */
class PasswordSettingsTest extends WP_UnitTestCase {

	/**
	 * Key material of a site with unique security keys.
	 */
	private const KEY = 'a-unique-and-long-enough-secret-for-the-tests|another-unique-and-long-salt-value';

	/**
	 * Store with usable keys.
	 *
	 * @var Secret_Store
	 */
	private $store;

	/**
	 * Settings registration under test.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Registers the options for an administrator.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		delete_option( Secret_Store::OPTION_NAME );
		delete_option( Config::OPTION_NAME );
		$GLOBALS['wp_settings_errors'] = array();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->store    = new Secret_Store( self::KEY );
		$this->settings = new Settings( null, $this->store );
		$this->settings->init();
		$this->settings->register();
	}

	/**
	 * Cleans up options and request globals.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Secret_Store::OPTION_NAME );
		delete_option( Config::OPTION_NAME );
		unset( $_POST[ Settings_Page::REMOVE_BUTTON ], $_POST[ Settings_Page::REMOVE_NONCE_FIELD ], $_REQUEST[ Settings_Page::REMOVE_NONCE_FIELD ] );

		parent::tear_down();
	}

	/**
	 * Returns what the database holds for the password option.
	 *
	 * @return string
	 */
	private function stored(): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Secret_Store::OPTION_NAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * A typed password is saved encrypted, once.
	 *
	 * @return void
	 */
	public function test_typed_password_is_saved_encrypted(): void {
		update_option( Secret_Store::OPTION_NAME, 'typed-secret-value' );

		$stored = $this->stored();

		$this->assertStringStartsWith( 'rilm-enc:v1:', $stored );
		$this->assertStringNotContainsString( 'typed-secret-value', $stored );
		$this->assertSame( 'typed-secret-value', $this->store->decrypt( $stored ), 'Encrypted once, not twice.' );
		$this->assertSame( 'typed-secret-value', $this->store->read() );
	}

	/**
	 * The plain password is nowhere in the database after saving.
	 *
	 * @return void
	 */
	public function test_plain_password_is_not_in_the_database(): void {
		global $wpdb;

		update_option( Secret_Store::OPTION_NAME, 'needle-password-12345' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( 'needle-password-12345' ) . '%' ) );

		$this->assertSame( '0', (string) $found );
	}

	/**
	 * Leaving the field empty keeps the saved password.
	 *
	 * @return void
	 */
	public function test_empty_field_keeps_the_saved_password(): void {
		update_option( Secret_Store::OPTION_NAME, 'first-secret' );
		$before = $this->stored();

		update_option( Secret_Store::OPTION_NAME, '' );
		$this->assertSame( $before, $this->stored() );

		update_option( Secret_Store::OPTION_NAME, null );
		$this->assertSame( $before, $this->stored() );
		$this->assertSame( 'first-secret', $this->store->read() );
	}

	/**
	 * Typing a new password replaces the old one.
	 *
	 * @return void
	 */
	public function test_new_password_replaces_the_old_one(): void {
		update_option( Secret_Store::OPTION_NAME, 'first-secret' );
		update_option( Secret_Store::OPTION_NAME, 'second-secret' );

		$this->assertSame( 'second-secret', $this->store->read() );
		$this->assertStringNotContainsString( 'first-secret', $this->stored() );
	}

	/**
	 * Spaces are part of a password and are kept.
	 *
	 * @return void
	 */
	public function test_password_is_not_trimmed_or_altered(): void {
		update_option( Secret_Store::OPTION_NAME, '  <b>p&ss</b> "x"  ' );

		$this->assertSame( '  <b>p&ss</b> "x"  ', $this->store->read() );
	}

	/**
	 * An already encrypted value (second pass of the first save) is not encrypted again.
	 *
	 * @return void
	 */
	public function test_encrypted_value_is_not_encrypted_twice(): void {
		$encrypted = (string) $this->store->encrypt( 'secret' );

		$this->assertSame( $encrypted, $this->settings->sanitize_password( $encrypted ) );
	}

	/**
	 * Without unique security keys nothing is saved and the user is told.
	 *
	 * @return void
	 */
	public function test_password_is_not_saved_without_unique_keys(): void {
		$weak     = new Secret_Store( 'put your unique phrase here|put your unique phrase here' );
		$settings = new Settings( null, $weak );
		$settings->register();

		$result = $settings->sanitize_password( 'would-be-plain-text' );

		$this->assertSame( '', $result );

		$errors = get_settings_errors( Secret_Store::OPTION_NAME );

		$this->assertCount( 1, $errors );
		$this->assertSame( 'rilm_password_keys', $errors[0]['code'] );
		$this->assertStringNotContainsString( 'would-be-plain-text', $errors[0]['message'] );
	}

	/**
	 * Without keys the existing saved value is kept as it is.
	 *
	 * @return void
	 */
	public function test_existing_value_survives_a_failed_save(): void {
		update_option( Secret_Store::OPTION_NAME, 'kept-secret' );
		$before = $this->stored();

		$settings = new Settings( null, new Secret_Store( null ) );

		$this->assertSame( $before, $settings->sanitize_password( 'another' ) );
	}

	/**
	 * A password defined by a constant owns the field: what is typed is ignored.
	 *
	 * @return void
	 */
	public function test_constant_password_ignores_the_typed_one(): void {
		$settings = new Settings(
			static function (): Config {
				return new Config( array(), array( 'password' => 'constant-secret' ) );
			},
			$this->store
		);

		$this->assertSame( '', $settings->sanitize_password( 'typed-secret' ), 'Nothing is produced to be saved.' );
		$this->assertSame( '', $this->stored(), 'Nothing reaches the database.' );

		// With a password already saved, the typed one does not replace it either.
		update_option( Secret_Store::OPTION_NAME, 'saved-before' );
		$before = $this->stored();

		$this->assertSame( $before, $settings->sanitize_password( 'typed-secret' ) );
	}

	/**
	 * A password longer than the limit is refused and the previous one is kept.
	 *
	 * @return void
	 */
	public function test_too_long_password_is_refused(): void {
		update_option( Secret_Store::OPTION_NAME, 'kept-secret' );

		update_option( Secret_Store::OPTION_NAME, str_repeat( 'a', Settings::MAX_PASSWORD_LENGTH + 1 ) );

		$this->assertSame( 'kept-secret', $this->store->read() );

		$errors = get_settings_errors( Secret_Store::OPTION_NAME );

		$this->assertSame( 'rilm_password_long', $errors[0]['code'] );
	}

	/**
	 * A password of exactly the limit is accepted.
	 *
	 * @return void
	 */
	public function test_password_at_the_limit_is_accepted(): void {
		$password = str_repeat( 'a', Settings::MAX_PASSWORD_LENGTH );

		update_option( Secret_Store::OPTION_NAME, $password );

		$this->assertSame( $password, $this->store->read() );
	}

	/**
	 * The password option is neither autoloaded nor exposed in the REST API.
	 *
	 * @return void
	 */
	public function test_option_is_not_autoloaded_or_exposed(): void {
		update_option( Secret_Store::OPTION_NAME, 'secret' );

		$this->assertArrayNotHasKey( Secret_Store::OPTION_NAME, wp_load_alloptions( true ) );

		$registered = get_registered_settings();

		$this->assertArrayHasKey( Secret_Store::OPTION_NAME, $registered );
		$this->assertFalse( $registered[ Secret_Store::OPTION_NAME ]['show_in_rest'] );
	}

	/**
	 * The password option belongs to the same settings group, so it needs the same capability and nonce.
	 *
	 * @return void
	 */
	public function test_option_is_in_the_settings_group(): void {
		global $new_allowed_options;

		$this->assertContains( Secret_Store::OPTION_NAME, $new_allowed_options[ Settings::GROUP ] ?? array() );
		$this->assertSame( 'manage_options', apply_filters( 'option_page_capability_' . Settings::GROUP, 'edit_posts' ) );
	}

	/**
	 * The saved password is used by the configuration.
	 *
	 * @return void
	 */
	public function test_configuration_uses_the_saved_password(): void {
		update_option( Secret_Store::OPTION_NAME, 'saved-secret' );
		update_option(
			Config::OPTION_NAME,
			array(
				'host' => 'db.example.test',
				'user' => 'reader',
			)
		);

		$config = Config::from_environment( $this->store );

		$this->assertSame( 'saved-secret', $config->password() );
		$this->assertSame( Config::SOURCE_SAVED, $config->password_source() );
		$this->assertSame( Config::STATUS_OK, $config->status() );
	}

	/**
	 * Renders the settings page for a store.
	 *
	 * @param Secret_Store $store    Store.
	 * @param Config|null  $config   Configuration; null reads it from the store.
	 * @return string
	 */
	private function render_page( Secret_Store $store, ?Config $config = null ): string {
		$settings = new Settings(
			null === $config ? null : static function () use ( $config ): Config {
				return $config;
			},
			$store
		);
		$settings->register();

		$provider = null === $config ? null : static function () use ( $config ): Config {
			return $config;
		};

		ob_start();

		try {
			( new Settings_Page( $provider, null, $store ) )->render();
		} catch ( \Throwable $exception ) {
			ob_end_clean();
			throw $exception;
		}

		return ob_get_clean();
	}

	/**
	 * The page never contains the saved password, nor its encrypted form.
	 *
	 * @return void
	 */
	public function test_page_never_shows_the_password_or_its_ciphertext(): void {
		update_option( Secret_Store::OPTION_NAME, 'page-secret-password' );
		$stored = $this->stored();

		$output = $this->render_page( $this->store );

		$this->assertStringNotContainsString( 'page-secret-password', $output );
		$this->assertStringNotContainsString( $stored, $output );
		$this->assertStringNotContainsString( 'rilm-enc:v1:', $output );
		$this->assertMatchesRegularExpression( '/name="rilm_db_password" value="" /', $output );
	}

	/**
	 * With a saved password the field is enabled, shows dots as a hint and explains how to keep or replace it.
	 *
	 * @return void
	 */
	public function test_saved_state(): void {
		update_option( Secret_Store::OPTION_NAME, 'secret' );

		$output = $this->render_page( $this->store );

		$this->assertStringContainsString( '<strong>Database password:</strong> saved here, encrypted', $output );
		$this->assertStringContainsString( 'placeholder="••••••••"', $output );
		$this->assertStringContainsString( 'Leave this field empty to keep it', $output );
		$this->assertDoesNotMatchRegularExpression( '/id="rilm-password"[^>]*disabled/', $output );
		$this->assertStringContainsString( 'Remove the saved password', $output );
	}

	/**
	 * With nothing saved the page says so and offers no removal.
	 *
	 * @return void
	 */
	public function test_nothing_saved_state(): void {
		$output = $this->render_page( $this->store );

		$this->assertStringContainsString( '<strong>Database password:</strong> not set', $output );
		$this->assertStringContainsString( 'No password is saved', $output );
		$this->assertStringNotContainsString( 'Remove the saved password', $output );
	}

	/**
	 * A password saved under other keys is reported as unreadable, and the user is asked to type it again.
	 *
	 * @return void
	 */
	public function test_unreadable_state(): void {
		update_option( Secret_Store::OPTION_NAME, 'secret' );

		$other = new Secret_Store( 'a-completely-different-and-long-enough-secret-value-123' );

		$output = $this->render_page( $other );

		$this->assertStringContainsString( 'saved here, but it cannot be read: type it again', $output );
		$this->assertStringContainsString( 'can no longer be read', $output );
		$this->assertStringContainsString( 'Remove the saved password', $output );
	}

	/**
	 * With a constant the field is disabled and nothing can be removed.
	 *
	 * @return void
	 */
	public function test_constant_state(): void {
		update_option( Secret_Store::OPTION_NAME, 'secret' );

		$output = $this->render_page( $this->store, new Config( array(), array( 'password' => 'constant-secret' ) ) );

		$this->assertStringContainsString( '<strong>Database password:</strong> defined in wp-config.php', $output );
		$this->assertMatchesRegularExpression( '/id="rilm-password"[^>]*disabled="disabled"/', $output );
		$this->assertStringContainsString( 'Defined in wp-config.php: it cannot be changed here.', $output );
		$this->assertStringNotContainsString( 'Remove the saved password', $output );
		$this->assertStringNotContainsString( 'constant-secret', $output );
	}

	/**
	 * Without unique keys the field is disabled and the page explains what to do.
	 *
	 * @return void
	 */
	public function test_unavailable_state(): void {
		$output = $this->render_page( new Secret_Store( 'put your unique phrase here|put your unique phrase here' ) );

		$this->assertMatchesRegularExpression( '/id="rilm-password"[^>]*disabled="disabled"/', $output );
		$this->assertStringContainsString( 'SECURE_AUTH_KEY and SECURE_AUTH_SALT', $output );
		$this->assertStringContainsString( 'ICT_RAG_MONITOR_DB_PASSWORD', $output );
	}

	/**
	 * The password field has a label tied to it.
	 *
	 * @return void
	 */
	public function test_field_has_a_label(): void {
		$output = $this->render_page( $this->store );

		$this->assertStringContainsString( '<label for="rilm-password">Database password</label>', $output );
		$this->assertStringContainsString( 'aria-describedby="rilm-password-description"', $output );
	}

	/**
	 * Removing the saved password needs a valid nonce.
	 *
	 * @return void
	 */
	public function test_removal_requires_a_nonce(): void {
		update_option( Secret_Store::OPTION_NAME, 'secret' );
		$before = $this->stored();

		$_POST[ Settings_Page::REMOVE_BUTTON ]        = 'Remove';
		$_POST[ Settings_Page::REMOVE_NONCE_FIELD ]    = 'invalid';
		$_REQUEST[ Settings_Page::REMOVE_NONCE_FIELD ] = 'invalid';

		try {
			$this->render_page( $this->store );
			$this->fail( 'The page must refuse the request.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( $before, $this->stored() );
		}
	}

	/**
	 * A valid request removes the saved password and the page shows the new state.
	 *
	 * @return void
	 */
	public function test_removal_deletes_the_saved_password(): void {
		update_option( Secret_Store::OPTION_NAME, 'secret' );

		$nonce                                         = wp_create_nonce( Settings_Page::REMOVE_NONCE_ACTION );
		$_POST[ Settings_Page::REMOVE_BUTTON ]        = 'Remove';
		$_POST[ Settings_Page::REMOVE_NONCE_FIELD ]    = $nonce;
		$_REQUEST[ Settings_Page::REMOVE_NONCE_FIELD ] = $nonce;

		$output = $this->render_page( $this->store );

		$this->assertSame( '', $this->stored() );
		$this->assertNull( $this->store->read() );
		$this->assertStringContainsString( 'The saved password was removed.', $output );
		$this->assertStringContainsString( '<strong>Database password:</strong> not set', $output );
		$this->assertStringNotContainsString( 'Remove the saved password', $output );
	}

	/**
	 * Users without manage_options cannot remove it, even with a valid nonce.
	 *
	 * @return void
	 */
	public function test_removal_is_refused_without_capability(): void {
		update_option( Secret_Store::OPTION_NAME, 'secret' );
		$before = $this->stored();

		$nonce = wp_create_nonce( Settings_Page::REMOVE_NONCE_ACTION );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$_POST[ Settings_Page::REMOVE_BUTTON ]        = 'Remove';
		$_POST[ Settings_Page::REMOVE_NONCE_FIELD ]    = $nonce;
		$_REQUEST[ Settings_Page::REMOVE_NONCE_FIELD ] = $nonce;

		try {
			$this->render_page( $this->store );
			$this->fail( 'The page must refuse the request.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( $before, $this->stored() );
		}
	}

	/**
	 * Uninstalling removes the encrypted password with the settings.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_the_password(): void {
		update_option( Secret_Store::OPTION_NAME, 'secret' );
		update_option( Config::OPTION_NAME, array( 'host' => 'db.example.test' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'rag-interaction-logger-monitor/rag-interaction-logger-monitor.php' );
		}

		include dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertSame( 'absent', get_option( Secret_Store::OPTION_NAME, 'absent' ) );
		$this->assertSame( 'absent', get_option( Config::OPTION_NAME, 'absent' ) );
	}

	/**
	 * Errors about the password never contain the password.
	 *
	 * @return void
	 */
	public function test_errors_never_contain_the_password(): void {
		$settings = new Settings( null, new Secret_Store( null ) );

		$settings->sanitize_password( 'super-secret-typed-value' );

		foreach ( get_settings_errors( Secret_Store::OPTION_NAME ) as $error ) {
			$this->assertStringNotContainsString( 'super-secret-typed-value', $error['message'] );
		}
	}
}
