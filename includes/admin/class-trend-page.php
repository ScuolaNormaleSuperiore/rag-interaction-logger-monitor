<?php
/**
 * Daily trend page.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

use DateTimeImmutable;
use RILM\Config\Config;
use RILM\Database\Connection;
use RILM\Database\Optional_Columns;
use RILM\Repository\Dashboard_Report;
use RILM\Repository\Filters;
use RILM\Repository\Interaction_Repository;
use RILM\Repository\Period_Series;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily trend screen: the charts of a period and the table with the same numbers.
 *
 * The bucket size (day, week or month) grows with the length of the period, chosen
 * by `Period_Series::granularity_for()`, so a chart never has to draw too many bars.
 * It only reads data. The period is not sensitive, so it travels in the URL.
 */
class Trend_Page {

	/**
	 * Names of the period fields read from the URL.
	 */
	private const PERIOD_KEYS = array( 'period', 'from', 'to' );

	/**
	 * Connection to the log database.
	 *
	 * @var Connection|null
	 */
	private $connection;

	/**
	 * Returns the repository, or null when the log database is not usable.
	 *
	 * @var callable
	 */
	private $repository_factory;

	/**
	 * Returns the current time in the site time zone.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Connection|null $connection         Connection to the log database; defaults to the configured one.
	 * @param callable|null   $repository_factory Returns an Interaction_Repository or null; defaults to the connection.
	 * @param callable|null   $clock              Returns a DateTimeImmutable in the site time zone; defaults to now.
	 */
	public function __construct( ?Connection $connection = null, ?callable $repository_factory = null, ?callable $clock = null ) {
		$this->connection         = $connection;
		$this->repository_factory = $repository_factory ?? array( $this, 'default_repository' );
		$this->clock              = $clock ?? static function (): DateTimeImmutable {
			return new DateTimeImmutable( 'now', wp_timezone() );
		};
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		Access::require_admin();

		$now     = call_user_func( $this->clock );
		$filters = Filters::from_array( $this->read_period_input(), $now );
		?>
		<div class="wrap rilm-wrap">
			<h1><?php esc_html_e( 'Daily trend', 'rag-interaction-logger-monitor' ); ?></h1>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: time zone name, e.g. "Europe/Rome". */
						__( 'Figures are for the chosen period, read in the site time zone: %s.', 'rag-interaction-logger-monitor' ),
						wp_timezone_string()
					)
				);
				?>
			</p>
			<?php
			if ( in_array( 'period', $filters->errors(), true ) ) {
				wp_admin_notice(
					esc_html( Period_Fields::invalid_period_message() ),
					array( 'type' => 'warning' )
				);
			}

			$this->render_period_form( $filters );

			$repository = call_user_func( $this->repository_factory );

			if ( null === $repository ) {
				?>
				<p><?php esc_html_e( 'The interaction log is not available. See the notice above.', 'rag-interaction-logger-monitor' ); ?></p>
				<?php
			} else {
				$report = ( new Dashboard_Report( $repository ) )->build( $filters->period(), false, true );

				if ( null === $report ) {
					?>
					<p><?php esc_html_e( 'The figures could not be loaded.', 'rag-interaction-logger-monitor' ); ?></p>
					<?php
				} else {
					$this->render_trend( $report );
				}
			}
			?>
		</div>
		<?php
	}

	/**
	 * Builds the repository from the connection.
	 *
	 * @return Interaction_Repository|null
	 */
	public function default_repository(): ?Interaction_Repository {
		if ( null === $this->connection ) {
			$this->connection = new Connection( Config::from_environment() );
		}

		return Interaction_Repository::from_connection( $this->connection, new Optional_Columns( $this->connection ) );
	}

	/**
	 * Reads the period fields from the URL.
	 *
	 * @return array<string, string>
	 */
	private function read_period_input(): array {
		$input = array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view: the values are validated by Filters and never change data.
		foreach ( self::PERIOD_KEYS as $key ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
				$input[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $input;
	}

	/**
	 * Prints the period form. It uses GET: the period holds nothing sensitive and can be bookmarked.
	 *
	 * @param Filters $filters Filters of the request.
	 * @return void
	 */
	private function render_period_form( Filters $filters ): void {
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( Menu::SLUG_TREND ); ?>" />
			<div class="rilm-filters">
				<p class="rilm-filter-actions">
					<?php submit_button( __( 'Apply period', 'rag-interaction-logger-monitor' ), 'primary', '', false ); ?>
				</p>
				<?php Period_Fields::render( $filters->period() ); ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Prints the daily charts and the table with the same numbers.
	 *
	 * @param array<string, mixed> $report Report of the period.
	 * @return void
	 */
	private function render_trend( array $report ): void {
		if ( ! empty( $report['daily_failed'] ) ) {
			?>
			<p><?php esc_html_e( 'The daily figures could not be loaded.', 'rag-interaction-logger-monitor' ); ?></p>
			<?php
			return;
		}

		if ( array() === $report['daily'] ) {
			?>
			<p><?php esc_html_e( 'No turns in the period.', 'rag-interaction-logger-monitor' ); ?></p>
			<?php
			return;
		}

		$days        = $report['daily'];
		$granularity = $report['daily_granularity'] ?? Period_Series::GRANULARITY_DAY;
		$texts       = self::texts_for( $granularity );
		$labels      = array_column( $days, 'date' );
		$unit        = self::unit_phrase( $granularity, count( $labels ) );
		?>
		<div class="rilm-charts">
			<?php
			Bar_Chart::render(
				'rilm-chart-turns',
				$texts['turns'],
				$unit,
				$labels,
				array(
					array(
						'name'   => __( 'Turns', 'rag-interaction-logger-monitor' ),
						'values' => array_column( $days, 'turns' ),
					),
				)
			);
			Bar_Chart::render(
				'rilm-chart-incomplete',
				$texts['incomplete'],
				$unit,
				$labels,
				array(
					array(
						'name'   => __( 'Incomplete', 'rag-interaction-logger-monitor' ),
						'values' => array_column( $days, 'incomplete' ),
					),
				)
			);
			Bar_Chart::render(
				'rilm-chart-blocks',
				$texts['blocks'],
				$unit,
				$labels,
				array(
					array(
						'name'   => __( 'Input blocked', 'rag-interaction-logger-monitor' ),
						'values' => array_column( $days, 'input_blocks' ),
					),
					array(
						'name'   => __( 'Output blocked', 'rag-interaction-logger-monitor' ),
						'values' => array_column( $days, 'output_blocks' ),
					),
				)
			);
			if ( isset( $report['tools'] ) ) {
				Bar_Chart::render(
					'rilm-chart-tools',
					$texts['tools'],
					$unit,
					$labels,
					array(
						array(
							'name'   => __( 'Without tools', 'rag-interaction-logger-monitor' ),
							'values' => array_map(
								static function ( array $day ): int {
									return max( 0, $day['turns'] - $day['tools'] );
								},
								$days
							),
						),
						array(
							'name'   => __( 'Turns that used tools', 'rag-interaction-logger-monitor' ),
							'values' => array_column( $days, 'tools' ),
						),
					)
				);
			}
			?>
		</div>
		<p class="description"><?php esc_html_e( 'A turn blocked on both input and output counts in both series of the third chart.', 'rag-interaction-logger-monitor' ); ?></p>
		<details class="rilm-details" open>
			<summary><?php echo esc_html( $texts['show_table'] ); ?></summary>
			<table class="widefat striped rilm-daily-table">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html( $texts['column'] ); ?></th>
						<th scope="col"><?php esc_html_e( 'Turns', 'rag-interaction-logger-monitor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Incomplete', 'rag-interaction-logger-monitor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Input blocked', 'rag-interaction-logger-monitor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Output blocked', 'rag-interaction-logger-monitor' ); ?></th>
						<?php if ( isset( $report['tools'] ) ) : ?>
							<th scope="col"><?php esc_html_e( 'Turns that used tools', 'rag-interaction-logger-monitor' ); ?></th>
						<?php endif; ?>
						<th scope="col"><?php esc_html_e( '% incomplete', 'rag-interaction-logger-monitor' ); ?></th>
						<th scope="col"><?php esc_html_e( '% input blocked', 'rag-interaction-logger-monitor' ); ?></th>
						<th scope="col"><?php esc_html_e( '% output blocked', 'rag-interaction-logger-monitor' ); ?></th>
						<?php if ( isset( $report['tools'] ) ) : ?>
							<th scope="col"><?php esc_html_e( '% used a tool', 'rag-interaction-logger-monitor' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php
					// Each percentage is of the turns of its own row, not of the whole period; a row with
					// no turns shows a dash instead of dividing by zero.
					foreach ( $days as $day ) {
						$percent_incomplete = esc_html( Dashboard_Page::percent_text( Dashboard_Report::percent( $day['incomplete'], $day['turns'] ) ) );
						$percent_input      = esc_html( Dashboard_Page::percent_text( Dashboard_Report::percent( $day['input_blocks'], $day['turns'] ) ) );
						$percent_output     = esc_html( Dashboard_Page::percent_text( Dashboard_Report::percent( $day['output_blocks'], $day['turns'] ) ) );

						if ( isset( $report['tools'] ) ) {
							printf(
								'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td><td>%8$s</td><td>%9$s</td><td>%10$s</td></tr>',
								esc_html( $day['date'] ),
								esc_html( number_format_i18n( $day['turns'] ) ),
								esc_html( number_format_i18n( $day['incomplete'] ) ),
								esc_html( number_format_i18n( $day['input_blocks'] ) ),
								esc_html( number_format_i18n( $day['output_blocks'] ) ),
								esc_html( number_format_i18n( $day['tools'] ) ),
								$percent_incomplete, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above.
								$percent_input, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above.
								$percent_output, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above.
								esc_html( Dashboard_Page::percent_text( Dashboard_Report::percent( $day['tools'], $day['turns'] ) ) )
							);
						} else {
							printf(
								'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td><td>%8$s</td></tr>',
								esc_html( $day['date'] ),
								esc_html( number_format_i18n( $day['turns'] ) ),
								esc_html( number_format_i18n( $day['incomplete'] ) ),
								esc_html( number_format_i18n( $day['input_blocks'] ) ),
								esc_html( number_format_i18n( $day['output_blocks'] ) ),
								$percent_incomplete, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above.
								$percent_input, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above.
								$percent_output // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above.
							);
						}
					}
					?>
				</tbody>
			</table>
		</details>
		<?php
	}

	/**
	 * Returns the chart titles, the table column name and the table summary for a bucket size.
	 *
	 * No `default` arm: `Period_Series` is the single place that knows which granularities
	 * exist, so a value it stops producing (or a new one it starts producing) must be added
	 * here too, or this throws instead of silently mislabeling the bucket size.
	 *
	 * @param string $granularity One of the `Period_Series::GRANULARITY_*` constants.
	 * @return array<string, string>
	 */
	private static function texts_for( string $granularity ): array {
		return match ( $granularity ) {
			Period_Series::GRANULARITY_WEEK => array(
				'turns'      => __( 'Turns per week', 'rag-interaction-logger-monitor' ),
				'incomplete' => __( 'Incomplete turns per week', 'rag-interaction-logger-monitor' ),
				'blocks'     => __( 'Blocks per week', 'rag-interaction-logger-monitor' ),
				'tools'      => __( 'Turns with and without tools per week', 'rag-interaction-logger-monitor' ),
				'column'     => __( 'Week', 'rag-interaction-logger-monitor' ),
				'show_table' => __( 'Show the weekly figures as a table', 'rag-interaction-logger-monitor' ),
			),
			Period_Series::GRANULARITY_MONTH => array(
				'turns'      => __( 'Turns per month', 'rag-interaction-logger-monitor' ),
				'incomplete' => __( 'Incomplete turns per month', 'rag-interaction-logger-monitor' ),
				'blocks'     => __( 'Blocks per month', 'rag-interaction-logger-monitor' ),
				'tools'      => __( 'Turns with and without tools per month', 'rag-interaction-logger-monitor' ),
				'column'     => __( 'Month', 'rag-interaction-logger-monitor' ),
				'show_table' => __( 'Show the monthly figures as a table', 'rag-interaction-logger-monitor' ),
			),
			Period_Series::GRANULARITY_DAY => array(
				'turns'      => __( 'Turns per day', 'rag-interaction-logger-monitor' ),
				'incomplete' => __( 'Incomplete turns per day', 'rag-interaction-logger-monitor' ),
				'blocks'     => __( 'Blocks per day', 'rag-interaction-logger-monitor' ),
				'tools'      => __( 'Turns with and without tools per day', 'rag-interaction-logger-monitor' ),
				'column'     => __( 'Day', 'rag-interaction-logger-monitor' ),
				'show_table' => __( 'Show the daily figures as a table', 'rag-interaction-logger-monitor' ),
			),
		};
	}

	/**
	 * Returns the worded, pluralized count of bars for a bucket size, for the charts' accessible description.
	 *
	 * Bar_Chart itself does not know whether its bars are days, weeks or months, so the words
	 * and the plural form (which depend on the count) are resolved here, once per render. No
	 * `default` arm, for the same reason as `texts_for()`.
	 *
	 * @param string $granularity One of the `Period_Series::GRANULARITY_*` constants.
	 * @param int    $count       Number of bars.
	 * @return string
	 */
	private static function unit_phrase( string $granularity, int $count ): string {
		return match ( $granularity ) {
			Period_Series::GRANULARITY_WEEK => sprintf(
				/* translators: %s: number of weeks. */
				_n( '%s week', '%s weeks', $count, 'rag-interaction-logger-monitor' ),
				number_format_i18n( $count )
			),
			Period_Series::GRANULARITY_MONTH => sprintf(
				/* translators: %s: number of months. */
				_n( '%s month', '%s months', $count, 'rag-interaction-logger-monitor' ),
				number_format_i18n( $count )
			),
			Period_Series::GRANULARITY_DAY => sprintf(
				/* translators: %s: number of days. */
				_n( '%s day', '%s days', $count, 'rag-interaction-logger-monitor' ),
				number_format_i18n( $count )
			),
		};
	}
}
