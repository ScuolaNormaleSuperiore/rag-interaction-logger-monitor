<?php
/**
 * Bar chart.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prints a stacked bar chart as inline SVG, built on the server with no script.
 *
 * The chart is a picture of numbers that are also available in a table: it has a
 * text title and description for screen readers, each bar carries its value in a
 * `<title>`, and a legend names every series in words, so colour is never the only
 * way to tell the series apart.
 */
class Bar_Chart {

	/**
	 * Width of the drawing, in SVG units (the picture scales with its container).
	 */
	private const WIDTH = 600;

	/**
	 * Height of the drawing.
	 */
	private const HEIGHT = 130;

	/**
	 * Height reserved for the axis labels above and below the bars.
	 */
	private const MARGIN_TOP = 14;

	/**
	 * Height reserved for the labels below the bars.
	 */
	private const MARGIN_BOTTOM = 16;

	/**
	 * Prints the chart with its legend.
	 *
	 * @param string                                                  $id     Unique id of the chart in the page.
	 * @param string                                                  $title  Title of the chart.
	 * @param string                                                  $unit   Number of bars, already worded and pluralized by the caller (for example "7 days" or "12 months"): the bars are not always days.
	 * @param string[]                                                $labels One label per bar, oldest first (for example the date).
	 * @param array<int, array{name: string, values: array<int,int>}> $series Series stacked in each bar; `values` is aligned with `$labels`.
	 * @return void
	 */
	public static function render( string $id, string $title, string $unit, array $labels, array $series ): void {
		$count  = count( $labels );
		$totals = array_fill( 0, $count, 0 );

		foreach ( $series as $one ) {
			for ( $i = 0; $i < $count; $i++ ) {
				$totals[ $i ] += max( 0, (int) ( $one['values'][ $i ] ?? 0 ) );
			}
		}

		$max = array() === $totals ? 0 : max( $totals );

		echo '<figure class="rilm-chart">';
		printf( '<figcaption>%s</figcaption>', esc_html( $title ) );

		printf(
			'<svg viewBox="0 0 %1$d %2$d" role="img" aria-labelledby="%3$s-title %3$s-desc" focusable="false">',
			(int) self::WIDTH,
			(int) self::HEIGHT,
			esc_attr( $id )
		);
		printf( '<title id="%1$s-title">%2$s</title>', esc_attr( $id ), esc_html( $title ) );
		printf(
			'<desc id="%1$s-desc">%2$s</desc>',
			esc_attr( $id ),
			esc_html(
				sprintf(
					/* translators: 1: number of bars, already worded, e.g. "7 days" or "12 months", 2: highest value of a bar. */
					__( 'Bar chart of %1$s. The highest value is %2$s. The same numbers are in the table below.', 'rag-interaction-logger-monitor' ),
					$unit,
					number_format_i18n( $max )
				)
			)
		);

		self::bars( $labels, $series, $max );
		self::axis( $labels, $max );

		echo '</svg>';
		self::legend( $series );
		echo '</figure>';
	}

	/**
	 * Prints the bars.
	 *
	 * @param string[]                                                $labels One label per bar.
	 * @param array<int, array{name: string, values: array<int,int>}> $series Stacked series.
	 * @param int                                                     $max    Highest stack.
	 * @return void
	 */
	private static function bars( array $labels, array $series, int $max ): void {
		$count = count( $labels );

		if ( 0 === $count ) {
			return;
		}

		$area  = self::HEIGHT - self::MARGIN_TOP - self::MARGIN_BOTTOM;
		$slot  = self::WIDTH / $count;
		$width = max( 0.5, $slot * 0.8 );
		$base  = self::HEIGHT - self::MARGIN_BOTTOM;

		for ( $i = 0; $i < $count; $i++ ) {
			$stack_top = $base;
			$x         = $i * $slot + ( $slot - $width ) / 2;

			foreach ( $series as $index => $one ) {
				$value = max( 0, (int) ( $one['values'][ $i ] ?? 0 ) );

				if ( 0 === $value || 0 === $max ) {
					continue;
				}

				$height     = $value / $max * $area;
				$stack_top -= $height;

				printf(
					'<rect class="rilm-bar rilm-bar-%1$d" x="%2$s" y="%3$s" width="%4$s" height="%5$s"><title>%6$s</title></rect>',
					(int) $index,
					esc_attr( self::number( $x ) ),
					esc_attr( self::number( $stack_top ) ),
					esc_attr( self::number( $width ) ),
					esc_attr( self::number( $height ) ),
					esc_html( sprintf( '%1$s, %2$s: %3$s', $labels[ $i ], $one['name'], number_format_i18n( $value ) ) )
				);
			}
		}
	}

	/**
	 * Prints the baseline, the highest value and the first and last labels.
	 *
	 * @param string[] $labels One label per bar.
	 * @param int      $max    Highest stack.
	 * @return void
	 */
	private static function axis( array $labels, int $max ): void {
		$base = self::HEIGHT - self::MARGIN_BOTTOM;

		printf( '<line class="rilm-axis-line" x1="0" y1="%1$d" x2="%2$d" y2="%1$d"></line>', (int) $base, (int) self::WIDTH );
		printf( '<text class="rilm-axis" x="0" y="10">%s</text>', esc_html( number_format_i18n( $max ) ) );

		if ( array() === $labels ) {
			return;
		}

		printf( '<text class="rilm-axis" x="0" y="%d">%s</text>', (int) ( self::HEIGHT - 3 ), esc_html( (string) reset( $labels ) ) );

		if ( count( $labels ) > 1 ) {
			printf( '<text class="rilm-axis" x="%d" y="%d" text-anchor="end">%s</text>', (int) self::WIDTH, (int) ( self::HEIGHT - 3 ), esc_html( (string) end( $labels ) ) );
		}
	}

	/**
	 * Prints the legend, which names each series in words.
	 *
	 * @param array<int, array{name: string, values: array<int,int>}> $series Series.
	 * @return void
	 */
	private static function legend( array $series ): void {
		echo '<ul class="rilm-legend">';

		foreach ( $series as $index => $one ) {
			printf(
				'<li><span class="rilm-swatch rilm-bar-%1$d" aria-hidden="true"></span> %2$s</li>',
				(int) $index,
				esc_html( $one['name'] )
			);
		}

		echo '</ul>';
	}

	/**
	 * Formats a coordinate with a dot and at most two decimals, whatever the locale.
	 *
	 * @param float $value Coordinate.
	 * @return string
	 */
	private static function number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}
}
