<?php
/**
 * Settings sanitizer.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

use RILM\Config\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates the values submitted from the Settings page.
 *
 * Only the four non-secret fields are accepted: anything else, including a
 * user or password, is dropped. A field that is invalid keeps its previous value.
 */
class Settings_Sanitizer {

	/**
	 * Current configuration, used to find the fields locked by a constant.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param Config $config Current configuration.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Sanitizes the submitted option value.
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, string> The four fields, as strings.
	 */
	public function sanitize( $input ): array {
		$previous = get_option( Config::OPTION_NAME, array() );
		$previous = is_array( $previous ) ? $previous : array();
		$input    = is_array( $input ) ? $input : array();
		$result   = array();

		foreach ( Config::FIELDS as $key ) {
			$old = ( isset( $previous[ $key ] ) && is_scalar( $previous[ $key ] ) ) ? (string) $previous[ $key ] : '';

			// A constant owns the value: the stored one is left untouched.
			if ( Config::SOURCE_CONSTANT === $this->config->source( $key ) ) {
				$result[ $key ] = $old;
				continue;
			}

			if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
				$result[ $key ] = $old;
				continue;
			}

			$value = trim( sanitize_text_field( (string) $input[ $key ] ) );

			// An empty value clears the field, so the default (or "not configured") applies.
			if ( '' === $value ) {
				$result[ $key ] = '';
				continue;
			}

			if ( ! Config::is_valid_value( $key, $value ) ) {
				$result[ $key ] = $old;
				$this->report_invalid( $key );
				continue;
			}

			$result[ $key ] = 'port' === $key ? (string) (int) $value : $value;
		}

		return $result;
	}

	/**
	 * Registers a settings error that names the field but never its value.
	 *
	 * @param string $key Field key.
	 * @return void
	 */
	private function report_invalid( string $key ): void {
		$code = 'rilm_invalid_' . $key;

		// The sanitize callback can run twice when the option is created: report once.
		foreach ( get_settings_errors( Config::OPTION_NAME ) as $error ) {
			if ( $code === $error['code'] ) {
				return;
			}
		}

		add_settings_error(
			Config::OPTION_NAME,
			$code,
			sprintf(
				/* translators: %s: name of the setting, e.g. "Port". */
				esc_html__( '%s is not valid: the previous value was kept.', 'rag-interaction-logger-monitor' ),
				Settings::label( $key )
			),
			'error'
		);
	}
}
