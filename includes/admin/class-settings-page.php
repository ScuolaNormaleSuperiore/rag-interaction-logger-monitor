<?php
/**
 * Settings page.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

use RILM\Config\Config;
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
	 * Constructor.
	 *
	 * @param callable|null          $config_provider Returns the current Config; defaults to the environment.
	 * @param Connection_Tester|null $tester          Connection tester; defaults to the real one.
	 */
	public function __construct( ?callable $config_provider = null, ?Connection_Tester $tester = null ) {
		$this->config_provider = $config_provider ?? array( Config::class, 'from_environment' );
		$this->tester          = $tester ?? new Connection_Tester();
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		Access::require_admin();

		$config      = call_user_func( $this->config_provider );
		$test_result = $this->handle_test_request( $config );
		?>
		<div class="wrap rilm-wrap">
			<h1><?php esc_html_e( 'Settings', 'rag-interaction-logger-monitor' ); ?></h1>
			<?php
			settings_errors( Config::OPTION_NAME );

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
	 * Tells whether the user and password constants are defined, never their values.
	 *
	 * @param Config $config Configuration.
	 * @return void
	 */
	private function render_credentials_status( Config $config ): void {
		$problems = $config->problem_fields();

		echo '<p>';

		foreach ( array(
			'user'     => __( 'Database user', 'rag-interaction-logger-monitor' ),
			'password' => __( 'Database password', 'rag-interaction-logger-monitor' ),
		) as $key => $label ) {
			if ( ! isset( $problems[ $key ] ) ) {
				$state = __( 'defined in wp-config.php', 'rag-interaction-logger-monitor' );
			} elseif ( Config::STATUS_NOT_CONFIGURED === $problems[ $key ] ) {
				$state = __( 'not defined in wp-config.php', 'rag-interaction-logger-monitor' );
			} else {
				$state = __( 'defined but not valid', 'rag-interaction-logger-monitor' );
			}

			printf(
				'<strong>%1$s:</strong> %2$s<br />',
				esc_html( $label ),
				esc_html( $state )
			);
		}

		echo '</p>';
	}
}
