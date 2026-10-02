<?php
/**
 * Settings page.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

use RILM\Config\Config;
use RILM\Config\Secret_Store;
use RILM\Database\Connection;
use RILM\Database\Connection_Tester;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings screen: connection parameters and a manual connection test.
 */
class Settings_Page {

	/**
	 * Nonce action of the connection test form.
	 */
	public const TEST_NONCE_ACTION = 'rilm_test_connection';

	/**
	 * Nonce field name of the connection test form.
	 */
	public const TEST_NONCE_FIELD = 'rilm_test_connection_nonce';

	/**
	 * Name of the connection test submit button.
	 */
	public const TEST_BUTTON = 'rilm_test_connection';

	/**
	 * Nonce action of the form that removes the saved password.
	 */
	public const REMOVE_NONCE_ACTION = 'rilm_remove_password';

	/**
	 * Nonce field name of the form that removes the saved password.
	 */
	public const REMOVE_NONCE_FIELD = 'rilm_remove_password_nonce';

	/**
	 * Name of the button that removes the saved password.
	 */
	public const REMOVE_BUTTON = 'rilm_remove_password';

	/**
	 * Provider of the current configuration.
	 *
	 * @var callable
	 */
	private $config_provider;

	/**
	 * Connection tester.
	 *
	 * @var Connection_Tester
	 */
	private $tester;

	/**
	 * Encrypted storage of the database password.
	 *
	 * @var Secret_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param callable|null          $config_provider Returns the current Config; defaults to the environment.
	 * @param Connection_Tester|null $tester          Connection tester; defaults to the real one.
	 * @param Secret_Store|null      $store           Storage of the encrypted password; defaults to the one of this site.
	 */
	public function __construct( ?callable $config_provider = null, ?Connection_Tester $tester = null, ?Secret_Store $store = null ) {
		$this->store           = $store ?? Secret_Store::from_environment();
		$this->config_provider = $config_provider ?? function (): Config {
			return Config::from_environment( $this->store );
		};
		$this->tester          = $tester ?? new Connection_Tester();
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		Access::require_admin();

		// The password is removed before the configuration is read, so the page shows the new state.
		$removed     = $this->handle_remove_request();
		$config      = call_user_func( $this->config_provider );
		$test_result = $this->handle_test_request( $config );
		?>
		<div class="wrap rilm-wrap">
			<h1><?php esc_html_e( 'Settings', 'rag-interaction-logger-monitor' ); ?></h1>
			<?php
			settings_errors( Config::OPTION_NAME );
			settings_errors( Secret_Store::OPTION_NAME );

			if ( $removed ) {
				wp_admin_notice(
					esc_html__( 'The saved password was removed.', 'rag-interaction-logger-monitor' ),
					array( 'type' => 'success' )
				);
			}

			if ( null !== $test_result ) {
				$this->render_test_result( $test_result );
			}

			$this->render_credentials_status( $config );
			?>
			<form method="post" action="options.php">
				<?php
				settings_fields( Settings::GROUP );
				do_settings_sections( Menu::SLUG_SETTINGS );
				submit_button();
				?>
			</form>
			<?php $this->render_remove_form( $config ); ?>
			<h2><?php esc_html_e( 'Test the connection', 'rag-interaction-logger-monitor' ); ?></h2>
			<p><?php esc_html_e( 'Checks the saved settings: save your changes first.', 'rag-interaction-logger-monitor' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS ) ); ?>">
				<?php
				wp_nonce_field( self::TEST_NONCE_ACTION, self::TEST_NONCE_FIELD );
				submit_button( __( 'Test connection', 'rag-interaction-logger-monitor' ), 'secondary', self::TEST_BUTTON );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Removes the saved password when the removal form was submitted.
	 *
	 * The nonce is verified before anything is deleted; an invalid one stops the request.
	 *
	 * @return bool Whether a saved password was removed.
	 */
	private function handle_remove_request(): bool {
		if ( ! isset( $_POST[ self::REMOVE_BUTTON ] ) ) {
			return false;
		}

		check_admin_referer( self::REMOVE_NONCE_ACTION, self::REMOVE_NONCE_FIELD );

		return delete_option( Secret_Store::OPTION_NAME );
	}

	/**
	 * Prints the form that removes the saved password, when there is one that the form may remove.
	 *
	 * @param Config $config Configuration.
	 * @return void
	 */
	private function render_remove_form( Config $config ): void {
		if ( Config::SOURCE_CONSTANT === $config->password_source() || ! $this->store->has_saved() ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS ) ); ?>">
			<?php
			wp_nonce_field( self::REMOVE_NONCE_ACTION, self::REMOVE_NONCE_FIELD );
			submit_button( __( 'Remove the saved password', 'rag-interaction-logger-monitor' ), 'delete', self::REMOVE_BUTTON, false );
			?>
		</form>
		<?php
	}

	/**
	 * Runs the connection test when the test form was submitted.
	 *
	 * The nonce is verified before anything is done; an invalid one stops the request.
	 *
	 * @param Config $config Saved configuration.
	 * @return string|null Test result code, or null when no test was requested.
	 */
	private function handle_test_request( Config $config ): ?string {
		if ( ! isset( $_POST[ self::TEST_BUTTON ] ) ) {
			return null;
		}

		check_admin_referer( self::TEST_NONCE_ACTION, self::TEST_NONCE_FIELD );

		return $this->tester->run( $config );
	}

	/**
	 * Prints the outcome of the connection test.
	 *
	 * @param string $result Result code.
	 * @return void
	 */
	private function render_test_result( string $result ): void {
		if ( Connection::STATUS_OK === $result ) {
			wp_admin_notice(
				esc_html__( 'The connection works and the table can be read.', 'rag-interaction-logger-monitor' ),
				array( 'type' => 'success' )
			);
			return;
		}

		if ( Connection_Tester::RESULT_TABLE_UNREADABLE === $result ) {
			$message = esc_html__( 'The database server was reached, but the table cannot be read. Check the database and table names and the SELECT privilege.', 'rag-interaction-logger-monitor' );
		} else {
			$message = Notices::message_for( $result );
		}

		wp_admin_notice( $message, array( 'type' => 'error' ) );
	}

	/**
	 * Tells where the password comes from, never its value.
	 *
	 * @param Config $config Configuration.
	 * @return void
	 */
	private function render_credentials_status( Config $config ): void {
		$problems = $config->problem_fields();
		$source   = $config->password_source();

		if ( isset( $problems['password'] ) && Config::SOURCE_CONSTANT === $source ) {
			$state = __( 'defined in wp-config.php but not valid', 'rag-interaction-logger-monitor' );
		} elseif ( Config::SOURCE_CONSTANT === $source ) {
			$state = __( 'defined in wp-config.php', 'rag-interaction-logger-monitor' );
		} elseif ( Config::SOURCE_SAVED === $source ) {
			$state = __( 'saved here, encrypted', 'rag-interaction-logger-monitor' );
		} elseif ( $this->store->has_saved() ) {
			$state = __( 'saved here, but it cannot be read: type it again', 'rag-interaction-logger-monitor' );
		} else {
			$state = __( 'not set', 'rag-interaction-logger-monitor' );
		}

		printf(
			'<p><strong>%1$s:</strong> %2$s</p>',
			esc_html__( 'Database password', 'rag-interaction-logger-monitor' ),
			esc_html( $state )
		);
	}
}
