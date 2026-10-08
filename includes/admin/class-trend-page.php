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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily trend screen: the daily charts of a period and the table with the same numbers.
 *
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

		$days = $report['daily'];

		$labels = array_column( $days, 'date' );
		?>
		<div class="rilm-charts">
			<?php
			Bar_Chart::render(
				'rilm-chart-turns',
				__( 'Turns per day', 'rag-interaction-logger-monitor' ),
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
				__( 'Incomplete turns per day', 'rag-interaction-logger-monitor' ),
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
				__( 'Blocks per day', 'rag-interaction-logger-monitor' ),
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
					__( 'Turns with and without tools per day', 'rag-interaction-logger-monitor' ),
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
		<details class="rilm-details">
			<summary><?php esc_html_e( 'Show the daily figures as a table', 'rag-interaction-logger-monitor' ); ?></summary>
			<table class="widefat striped rilm-daily-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Day', 'rag-interaction-logger-monitor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Turns', 'rag-interaction-logger-monitor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Incomplete', 'rag-interaction-logger-monitor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Input blocked', 'rag-interaction-logger-monitor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Output blocked', 'rag-interaction-logger-monitor' ); ?></th>
						<?php if ( isset( $report['tools'] ) ) : ?>
							<th scope="col"><?php esc_html_e( 'Turns that used tools', 'rag-interaction-logger-monitor' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $days as $day ) {
						if ( isset( $report['tools'] ) ) {
							printf(
								'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
								esc_html( $day['date'] ),
								esc_html( number_format_i18n( $day['turns'] ) ),
								esc_html( number_format_i18n( $day['incomplete'] ) ),
								esc_html( number_format_i18n( $day['input_blocks'] ) ),
								esc_html( number_format_i18n( $day['output_blocks'] ) ),
								esc_html( number_format_i18n( $day['tools'] ) )
							);
						} else {
							printf(
								'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
								esc_html( $day['date'] ),
								esc_html( number_format_i18n( $day['turns'] ) ),
								esc_html( number_format_i18n( $day['incomplete'] ) ),
								esc_html( number_format_i18n( $day['input_blocks'] ) ),
								esc_html( number_format_i18n( $day['output_blocks'] ) )
							);
						}
					}
					?>
				</tbody>
			</table>
		</details>
		<?php
	}
}
