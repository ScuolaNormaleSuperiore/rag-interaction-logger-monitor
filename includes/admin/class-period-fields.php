<?php
/**
 * Period fields shared by the pages that filter by time.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

use RILM\Repository\Period;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prints the period select and the custom start and end fields.
 */
class Period_Fields {

	/**
	 * Returns the options of the period select.
	 *
	 * @return array<string, string>
	 */
	public static function options(): array {
		return array(
			Period::TODAY        => __( 'Today', 'rag-interaction-logger-monitor' ),
			Period::WEEK         => __( 'Last week', 'rag-interaction-logger-monitor' ),
			Period::MONTH        => __( 'Last month', 'rag-interaction-logger-monitor' ),
			Period::THREE_MONTHS => __( 'Last 3 months', 'rag-interaction-logger-monitor' ),
			Period::SIX_MONTHS   => __( 'Last 6 months', 'rag-interaction-logger-monitor' ),
			Period::YEAR         => __( 'Last year', 'rag-interaction-logger-monitor' ),
			Period::CUSTOM       => __( 'Custom (use the dates below)', 'rag-interaction-logger-monitor' ),
		);
	}

	/**
	 * Prints the three fields: preset, start and end of a custom period.
	 *
	 * @param Period $period Period currently in use.
	 * @return void
	 */
	public static function render( Period $period ): void {
		$is_custom = Period::CUSTOM === $period->key();

		// `aria-controls` names the two date fields that the script shows only for a custom period.
		printf(
			'<p class="rilm-field"><label for="rilm-period">%1$s</label> <select id="rilm-period" name="period" aria-controls="rilm-from rilm-to">',
			esc_html__( 'Period', 'rag-interaction-logger-monitor' )
		);

		foreach ( self::options() as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $value, $period->key(), false ),
				esc_html( $label )
			);
		}

		echo '</select></p>';

		self::datetime( 'from', __( 'From (custom period)', 'rag-interaction-logger-monitor' ), $is_custom ? $period->start()->format( 'Y-m-d\TH:i' ) : '' );
		self::datetime( 'to', __( 'To (custom period)', 'rag-interaction-logger-monitor' ), $is_custom ? $period->end()->format( 'Y-m-d\TH:i' ) : '' );
	}

	/**
	 * Prints a labelled date and time field.
	 *
	 * Without JavaScript the field is always visible, so a custom period can still be
	 * chosen; `assets/js/admin.js` hides it while the period is not "custom".
	 *
	 * @param string $name  Field name and id suffix.
	 * @param string $label Visible label.
	 * @param string $value Current value, `Y-m-d\TH:i`.
	 * @return void
	 */
	private static function datetime( string $name, string $label, string $value ): void {
		printf(
			'<p class="rilm-field" data-rilm-custom-date><label for="rilm-%1$s">%2$s</label> <input type="datetime-local" id="rilm-%1$s" name="%1$s" value="%3$s" /></p>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value )
		);
	}
}
