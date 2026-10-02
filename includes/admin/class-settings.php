<?php
/**
 * Settings registration.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

use RILM\Config\Config;

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
	 * Provider of the current configuration.
	 *
	 * @var callable
	 */
	private $config_provider;

	/**
	 * Constructor.
	 *
	 * @param callable|null $config_provider Returns the current Config; defaults to the environment.
	 */
	public function __construct( ?callable $config_provider = null ) {
		$this->config_provider = $config_provider ?? array( Config::class, 'from_environment' );
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
		esc_html_e( 'The database user and password are defined in wp-config.php and are never shown or saved here.', 'rag-interaction-logger-monitor' );
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
			default:
				return '';
		}
	}
}
