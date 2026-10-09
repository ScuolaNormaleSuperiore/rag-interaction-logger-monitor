<?php
/**
 * Settings registration.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

use RILM\Config\Config;
use RILM\Config\Secret_Store;
use RILM\Repository\Period;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the option, the sections and the fields with the WordPress Settings API.
 */
class Settings {

	/**
	 * Settings group (the nonce and the allowed option are tied to it).
	 */
	public const GROUP = 'rilm_settings';

	/**
	 * Id of the connection section.
	 */
	public const SECTION = 'rilm_connection';

	/**
	 * Longest password accepted, in bytes.
	 */
	public const MAX_PASSWORD_LENGTH = 255;

	/**
	 * Provider of the current configuration.
	 *
	 * @var callable
	 */
	private $config_provider;

	/**
	 * Encrypted storage of the database password.
	 *
	 * @var Secret_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param callable|null     $config_provider Returns the current Config; defaults to the environment.
	 * @param Secret_Store|null $store           Storage of the encrypted password; defaults to the one of this site.
	 */
	public function __construct( ?callable $config_provider = null, ?Secret_Store $store = null ) {
		$this->store           = $store ?? Secret_Store::from_environment();
		$this->config_provider = $config_provider ?? function (): Config {
			return Config::from_environment( $this->store );
		};
	}

	/**
	 * Hooks the registration into WordPress.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'register' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( $this, 'capability' ) );
		add_action( 'add_option_' . Config::OPTION_NAME, array( $this, 'disable_autoload' ) );
		add_action( 'update_option_' . Config::OPTION_NAME, array( $this, 'disable_autoload' ) );
		add_action( 'add_option_' . Secret_Store::OPTION_NAME, array( $this, 'disable_password_autoload' ) );
		add_action( 'update_option_' . Secret_Store::OPTION_NAME, array( $this, 'disable_password_autoload' ) );
	}

	/**
	 * Returns the translated label of a field.
	 *
	 * @param string $key One of the Config::FIELDS values.
	 * @return string
	 */
	public static function label( string $key ): string {
		switch ( $key ) {
			case 'host':
				return __( 'Host', 'rag-interaction-logger-monitor' );
			case 'port':
				return __( 'Port', 'rag-interaction-logger-monitor' );
			case 'name':
				return __( 'Database', 'rag-interaction-logger-monitor' );
			case 'table':
				return __( 'Table', 'rag-interaction-logger-monitor' );
			case 'user':
				return __( 'Database user', 'rag-interaction-logger-monitor' );
			default:
				return '';
		}
	}

	/**
	 * Capability required to save the option through `options.php`.
	 *
	 * @return string
	 */
	public function capability(): string {
		return Menu::CAPABILITY;
	}

	/**
	 * Registers the option, the section and the fields. Runs on `admin_init`.
	 *
	 * @return void
	 */
	public function register(): void {
		register_setting(
			self::GROUP,
			Config::OPTION_NAME,
			array(
				'type'              => 'array',
				'default'           => array(),
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);

		add_settings_section(
			self::SECTION,
			__( 'Log database connection', 'rag-interaction-logger-monitor' ),
			array( $this, 'render_section' ),
			Menu::SLUG_SETTINGS
		);

		foreach ( Config::FIELDS as $key ) {
			add_settings_field(
				'rilm_' . $key,
				self::label( $key ),
				array( $this, 'render_field' ),
				Menu::SLUG_SETTINGS,
				self::SECTION,
				array(
					'key'       => $key,
					'label_for' => 'rilm-' . $key,
				)
			);
		}

		add_settings_field(
			'rilm-default-period',
			__( 'Default period', 'rag-interaction-logger-monitor' ),
			array( $this, 'render_default_period_field' ),
			Menu::SLUG_SETTINGS,
			self::SECTION,
			array( 'label_for' => 'rilm-default-period' )
		);

		// The password has its own option: it is never part of the readable settings array.
		register_setting(
			self::GROUP,
			Secret_Store::OPTION_NAME,
			array(
				'type'              => 'string',
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize_password' ),
			)
		);

		add_settings_field(
			'rilm_password',
			__( 'Database password', 'rag-interaction-logger-monitor' ),
			array( $this, 'render_password_field' ),
			Menu::SLUG_SETTINGS,
			self::SECTION,
			array( 'label_for' => 'rilm-password' )
		);
	}

	/**
	 * Turns the submitted password into the value to save: encrypted, or the existing one when nothing is typed.
	 *
	 * Runs on every save, with `null` when the field was not sent (it is disabled when a
	 * constant defines the password), and can run twice on the first save: an already
	 * encrypted value is returned as it is.
	 *
	 * @param mixed $input Submitted value.
	 * @return string Value to save.
	 */
	public function sanitize_password( $input ): string {
		$existing = get_option( Secret_Store::OPTION_NAME, '' );
		$existing = is_string( $existing ) ? $existing : '';

		if ( ! is_string( $input ) || '' === $input ) {
			return $existing;
		}

		if ( $this->store->is_encrypted( $input ) ) {
			return $input;
		}

		// A constant owns the password: whatever is typed is ignored.
		if ( Config::SOURCE_CONSTANT === $this->config()->password_source() ) {
			return $existing;
		}

		if ( strlen( $input ) > self::MAX_PASSWORD_LENGTH ) {
			$this->password_error( 'rilm_password_long', __( 'The password is too long: the previous one was kept.', 'rag-interaction-logger-monitor' ) );
			return $existing;
		}

		$encrypted = $this->store->encrypt( $input );

		if ( null === $encrypted ) {
			$this->password_error( 'rilm_password_keys', __( 'The password was not saved: define unique security keys in wp-config.php first.', 'rag-interaction-logger-monitor' ) );
			return $existing;
		}

		return $encrypted;
	}

	/**
	 * Keeps the encrypted password out of the autoloaded options: it is only read on plugin screens.
	 *
	 * @return void
	 */
	public function disable_password_autoload(): void {
		wp_set_option_autoload( Secret_Store::OPTION_NAME, false );
	}

	/**
	 * Prints the password field. It never contains the password, not even the saved one.
	 *
	 * @return void
	 */
	public function render_password_field(): void {
		$source    = $this->config()->password_source();
		$locked    = Config::SOURCE_CONSTANT === $source;
		$available = $this->store->is_available();
		$saved     = $this->store->has_saved();
		$readable  = Config::SOURCE_SAVED === $source;

		printf(
			'<input type="password" id="rilm-password" name="%1$s" value="" class="regular-text" autocomplete="new-password" maxlength="%2$d" placeholder="%3$s" aria-describedby="rilm-password-description"%4$s />',
			esc_attr( Secret_Store::OPTION_NAME ),
			(int) self::MAX_PASSWORD_LENGTH,
			esc_attr( $readable ? '••••••••' : '' ),
			( $locked || ! $available ) ? ' disabled="disabled"' : ''
		);

		if ( $locked ) {
			$description = __( 'Defined in wp-config.php: it cannot be changed here.', 'rag-interaction-logger-monitor' );
		} elseif ( ! $available ) {
			$description = __( 'To save the password here, define unique security keys (SECURE_AUTH_KEY and SECURE_AUTH_SALT) in wp-config.php. Until then, define ICT_RAG_MONITOR_DB_PASSWORD in wp-config.php instead.', 'rag-interaction-logger-monitor' );
		} elseif ( $saved && ! $readable ) {
			$description = __( 'A saved password can no longer be read, usually because the security keys changed. Type it again.', 'rag-interaction-logger-monitor' );
		} elseif ( $readable ) {
			$description = __( 'A password is saved, encrypted. Leave this field empty to keep it, or type a new one to replace it.', 'rag-interaction-logger-monitor' );
		} else {
			$description = __( 'No password is saved. It is stored encrypted, with a key derived from the security keys of wp-config.php, and is never shown again.', 'rag-interaction-logger-monitor' );
		}

		printf(
			'<p class="description" id="rilm-password-description">%s</p>',
			esc_html( $description )
		);
	}

	/**
	 * Sanitizes the submitted option value.
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, string>
	 */
	public function sanitize( $input ): array {
		return ( new Settings_Sanitizer( $this->config() ) )->sanitize( $input );
	}

	/**
	 * Keeps the option out of the autoloaded options: it is only read on plugin screens.
	 *
	 * @return void
	 */
	public function disable_autoload(): void {
		wp_set_option_autoload( Config::OPTION_NAME, false );
	}

	/**
	 * Prints the introduction of the connection section.
	 *
	 * @return void
	 */
	public function render_section(): void {
		echo '<p>';
		esc_html_e( 'Set the database connection parameters here. The password is stored encrypted and is never shown again; it can also be defined in wp-config.php, which takes precedence.', 'rag-interaction-logger-monitor' );
		echo '</p>';
	}

	/**
	 * Prints one field. A field defined by a constant is shown read-only.
	 *
	 * @param array $args Field arguments, with the `key` of the field.
	 * @return void
	 */
	public function render_field( array $args ): void {
		$key = isset( $args['key'] ) ? (string) $args['key'] : '';

		if ( ! in_array( $key, Config::FIELDS, true ) ) {
			return;
		}

		$config = $this->config();
		$locked = Config::SOURCE_CONSTANT === $config->source( $key );
		$id     = 'rilm-' . $key;

		if ( $locked ) {
			$value = $this->effective_value( $config, $key );
		} else {
			$stored = get_option( Config::OPTION_NAME, array() );
			$value  = ( is_array( $stored ) && isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) ) ? (string) $stored[ $key ] : '';
		}

		printf(
			'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text" placeholder="%5$s" aria-describedby="%2$s-description"%6$s />',
			esc_attr( 'port' === $key ? 'number' : 'text' ),
			esc_attr( $id ),
			esc_attr( Config::OPTION_NAME . '[' . $key . ']' ),
			esc_attr( $value ),
			esc_attr( $this->placeholder( $key ) ),
			$locked ? ' disabled="disabled"' : ''
		);

		printf(
			'<p class="description" id="%1$s-description">%2$s</p>',
			esc_attr( $id ),
			esc_html( $this->description( $key, $locked ) )
		);
	}

	/**
	 * Prints the default period selector used when a page URL has no period.
	 *
	 * @return void
	 */
	public function render_default_period_field(): void {
		printf( '<select id="rilm-default-period" name="%1$s">', esc_attr( Config::OPTION_NAME . '[' . Period::DEFAULT_SETTING . ']' ) );

		foreach ( Period_Fields::options() as $value => $label ) {
			if ( Period::CUSTOM === $value ) {
				continue;
			}

			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $value, Period::default_preset(), false ), esc_html( $label ) );
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Used by Dashboard, Trends, Interactions and Anomalies when no period is selected in the URL.', 'rag-interaction-logger-monitor' ) . '</p>';
	}

	/**
	 * Registers an error about the password, once.
	 *
	 * @param string $code    Error code.
	 * @param string $message Translated message; it never contains the password.
	 * @return void
	 */
	private function password_error( string $code, string $message ): void {
		// The sanitize callback can run twice when the option is created: report once.
		foreach ( get_settings_errors( Secret_Store::OPTION_NAME ) as $error ) {
			if ( $code === $error['code'] ) {
				return;
			}
		}

		add_settings_error( Secret_Store::OPTION_NAME, $code, esc_html( $message ), 'error' );
	}

	/**
	 * Returns the current configuration.
	 *
	 * @return Config
	 */
	private function config(): Config {
		return call_user_func( $this->config_provider );
	}

	/**
	 * Returns the effective value of a field as text.
	 *
	 * @param Config $config Configuration.
	 * @param string $key    Field key.
	 * @return string
	 */
	private function effective_value( Config $config, string $key ): string {
		switch ( $key ) {
			case 'host':
				return $config->host();
			case 'port':
				return 0 === $config->port() ? '' : (string) $config->port();
			case 'name':
				return $config->database();
			case 'table':
				return $config->table();
			case 'user':
				return $config->user();
			default:
				return '';
		}
	}

	/**
	 * Returns the placeholder of a field (its default value, when it has one).
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private function placeholder( string $key ): string {
		switch ( $key ) {
			case 'host':
				return 'db.example.org';
			case 'port':
				return '3306';
			case 'name':
				return 'rag-interaction-logger-db';
			case 'table':
				return 'ril_interactions';
			case 'user':
				return 'logger_reader_user';
			default:
				return '';
		}
	}

	/**
	 * Returns the help text of a field.
	 *
	 * @param string $key    Field key.
	 * @param bool   $locked Whether a constant defines the value.
	 * @return string
	 */
	private function description( string $key, bool $locked ): string {
		if ( $locked ) {
			return __( 'Defined in wp-config.php: it cannot be changed here.', 'rag-interaction-logger-monitor' );
		}

		switch ( $key ) {
			case 'host':
				return __( 'Host name or IP address of the database server, without the port. Required.', 'rag-interaction-logger-monitor' );
			case 'port':
				return __( 'TCP port of the database server. Leave empty for 3306.', 'rag-interaction-logger-monitor' );
			case 'name':
				return __( 'Name of the database holding the log table. Leave empty for rag-interaction-logger-db.', 'rag-interaction-logger-monitor' );
			case 'table':
				return __( 'Name of the log table: letters, digits and underscores only. Leave empty for ril_interactions.', 'rag-interaction-logger-monitor' );
			case 'user':
				return __( 'Database account with SELECT-only access to the log table. Required.', 'rag-interaction-logger-monitor' );
			default:
				return '';
		}
	}
}
