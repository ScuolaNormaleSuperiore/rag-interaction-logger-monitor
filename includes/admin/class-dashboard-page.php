<?php
/**
 * Dashboard page.
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
 * Dashboard screen: the figures of a period and their daily trend.
 *
 * It only reads data. The period is not sensitive, so it travels in the URL, and every
 * count links to the interactions list filtered the same way.
 */
class Dashboard_Page {

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
			<h1><?php esc_html_e( 'Dashboard', 'rag-interaction-logger-monitor' ); ?></h1>
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
					esc_html__( 'The period was ignored because its value is not valid: today is shown.', 'rag-interaction-logger-monitor' ),
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
				$report = ( new Dashboard_Report( $repository ) )->build( $filters->period() );

				if ( null === $report ) {
					?>
					<p><?php esc_html_e( 'The figures could not be loaded.', 'rag-interaction-logger-monitor' ); ?></p>
					<?php
				} else {
					$this->render_report( $report, $filters, $now );
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
	 * Formats a share: one decimal and a percent sign, or a dash when there is nothing to compare.
	 *
	 * @param float|null $percent Share, or null when the whole is zero.
	 * @return string
	 */
	public static function percent_text( ?float $percent ): string {
		if ( null === $percent ) {
			return '–';
		}

		return number_format_i18n( $percent, 1 ) . '%';
	}

	/**
	 * Formats a duration in milliseconds, or "Not available".
	 *
	 * @param float|null $milliseconds Duration, or null when there is no completed turn.
	 * @return string
	 */
	public static function duration_text( ?float $milliseconds ): string {
		if ( null === $milliseconds ) {
			return __( 'Not available', 'rag-interaction-logger-monitor' );
		}

		return sprintf(
			/* translators: %s: duration in milliseconds. */
			__( '%s ms', 'rag-interaction-logger-monitor' ),
			number_format_i18n( (int) round( $milliseconds ) )
		);
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
			<input type="hidden" name="page" value="<?php echo esc_attr( Menu::SLUG_DASHBOARD ); ?>" />
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
	 * Prints the figures, the verdict tables and the charts.
	 *
	 * @param array<string, mixed> $report  Report of the period.
	 * @param Filters              $filters Filters of the request (only the period is used).
	 * @param DateTimeImmutable    $now     Current time in the site time zone.
	 * @return void
	 */
	private function render_report( array $report, Filters $filters, DateTimeImmutable $now ): void {
		$link = static function ( array $input ) use ( $filters, $now ): string {
			return List_Links::url( $filters, $input, $now );
		};

		$this->render_tiles( $report, $link );
		$this->render_indicators( $report, $link );
		$this->render_verdicts( $report, $link );
		$this->render_trend( $report );
	}

	/**
	 * Prints the three headline figures.
	 *
	 * @param array<string, mixed> $report Report of the period.
	 * @param callable             $link   Builds the list URL for filter input.
	 * @return void
	 */
	private function render_tiles( array $report, callable $link ): void {
		?>
		<div class="rilm-tiles">
			<div class="rilm-tile">
				<p class="rilm-tile-label"><?php esc_html_e( 'Turns', 'rag-interaction-logger-monitor' ); ?></p>
				<p class="rilm-tile-value"><a href="<?php echo esc_url( $link( array() ) ); ?>"><?php echo esc_html( number_format_i18n( $report['total'] ) ); ?><span class="screen-reader-text"> <?php esc_html_e( '(view the interactions)', 'rag-interaction-logger-monitor' ); ?></span></a></p>
			</div>
			<div class="rilm-tile">
				<p class="rilm-tile-label"><?php esc_html_e( 'Average duration of completed turns', 'rag-interaction-logger-monitor' ); ?></p>
				<p class="rilm-tile-value"><?php echo esc_html( self::duration_text( $report['duration']['average_ms'] ) ); ?></p>
			</div>
			<div class="rilm-tile">
				<p class="rilm-tile-label"><?php esc_html_e( 'Median duration of completed turns', 'rag-interaction-logger-monitor' ); ?></p>
				<p class="rilm-tile-value"><?php echo esc_html( self::duration_text( $report['duration']['median_ms'] ) ); ?></p>
			</div>
		</div>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of completed turns. */
					_n( 'Completed turns: %s. A turn is completed when it is not incomplete and its duration is recorded.', 'Completed turns: %s. A turn is completed when it is not incomplete and its duration is recorded.', (int) $report['duration']['completed'], 'rag-interaction-logger-monitor' ),
					number_format_i18n( $report['duration']['completed'] )
				)
			);
			?>
		</p>
		<?php
	}

	/**
	 * Prints the table of the indicators with their counts, shares and links.
	 *
	 * @param array<string, mixed> $report Report of the period.
	 * @param callable             $link   Builds the list URL for filter input.
	 * @return void
	 */
	private function render_indicators( array $report, callable $link ): void {
		$rows = array(
			array(
				__( 'Generated', 'rag-interaction-logger-monitor' ),
				$report['outcomes']['generated'],
				array( 'outcome' => 'generated' ),
				__( 'of all turns', 'rag-interaction-logger-monitor' ),
			),
			array(
				__( 'Fast reply', 'rag-interaction-logger-monitor' ),
				$report['outcomes']['fast_reply'],
				array( 'outcome' => 'fast_reply' ),
				__( 'of all turns', 'rag-interaction-logger-monitor' ),
			),
			array(
				__( 'Incomplete', 'rag-interaction-logger-monitor' ),
				$report['outcomes']['incomplete'],
				array( 'outcome' => 'incomplete' ),
				__( 'of all turns', 'rag-interaction-logger-monitor' ),
			),
			array(
				__( 'Input blocked', 'rag-interaction-logger-monitor' ),
				$report['input_blocks'],
				array( 'input_verdict' => Filters::VERDICT_ANY ),
				__( 'of all turns', 'rag-interaction-logger-monitor' ),
			),
			array(
				__( 'Output blocked', 'rag-interaction-logger-monitor' ),
				$report['output_blocks'],
				array( 'output_verdict' => Filters::VERDICT_ANY ),
				__( 'of all turns', 'rag-interaction-logger-monitor' ),
			),
			array(
				__( 'Guardrails did not run', 'rag-interaction-logger-monitor' ),
				$report['no_guardrails'],
				array( 'guard' => Filters::GUARD_ABSENT ),
				__( 'of all turns', 'rag-interaction-logger-monitor' ),
			),
			array(
				__( 'Generated without recalled sources', 'rag-interaction-logger-monitor' ),
				$report['zero_recall'],
				array(
					'outcome' => 'generated',
					'recall'  => 'empty',
				),
				__( 'of generated answers', 'rag-interaction-logger-monitor' ),
			),
		);
		?>
		<h2><?php esc_html_e( 'Indicators', 'rag-interaction-logger-monitor' ); ?></h2>
		<table class="widefat striped rilm-indicators-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Indicator', 'rag-interaction-logger-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Interactions', 'rag-interaction-logger-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Share', 'rag-interaction-logger-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $rows as $row ) {
					list( $label, $figure, $input, $whole ) = $row;

					printf(
						'<tr><th scope="row">%1$s</th><td><a href="%2$s">%3$s<span class="screen-reader-text"> (%4$s)</span></a></td><td>%5$s %6$s</td></tr>',
						esc_html( $label ),
						esc_url( $link( $input ) ),
						esc_html( number_format_i18n( $figure['count'] ) ),
						esc_html( $label ),
						esc_html( self::percent_text( $figure['percent'] ) ),
						null === $figure['percent'] ? '<span class="screen-reader-text">' . esc_html__( 'Not available: no turns to compare with.', 'rag-interaction-logger-monitor' ) . '</span>' : esc_html( $whole )
					);
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Prints the blocks of each verdict.
	 *
	 * @param array<string, mixed> $report Report of the period.
	 * @param callable             $link   Builds the list URL for filter input.
	 * @return void
	 */
	private function render_verdicts( array $report, callable $link ): void {
		?>
		<h2><?php esc_html_e( 'Blocks by verdict', 'rag-interaction-logger-monitor' ); ?></h2>
		<div class="rilm-verdicts">
			<?php
			$this->render_verdict_table( __( 'Input verdicts', 'rag-interaction-logger-monitor' ), 'input_verdict', $report['input_blocks']['by_verdict'], $link );
			$this->render_verdict_table( __( 'Output verdicts', 'rag-interaction-logger-monitor' ), 'output_verdict', $report['output_blocks']['by_verdict'], $link );
			?>
		</div>
		<?php
	}

	/**
	 * Prints one verdict table.
	 *
	 * @param string             $title    Table title.
	 * @param string             $filter   Filter name: `input_verdict` or `output_verdict`.
	 * @param array<string, int> $verdicts Count keyed by verdict.
	 * @param callable           $link     Builds the list URL for filter input.
	 * @return void
	 */
	private function render_verdict_table( string $title, string $filter, array $verdicts, callable $link ): void {
		?>
		<table class="widefat striped rilm-verdict-table">
			<caption><?php echo esc_html( $title ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Verdict', 'rag-interaction-logger-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Blocks', 'rag-interaction-logger-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				if ( array() === $verdicts ) {
					echo '<tr><td colspan="2">' . esc_html__( 'No blocks recorded in the period.', 'rag-interaction-logger-monitor' ) . '</td></tr>';
				}

				foreach ( $verdicts as $verdict => $count ) {
					printf(
						'<tr><th scope="row">%1$s</th><td><a href="%2$s">%3$s<span class="screen-reader-text"> (%1$s)</span></a></td></tr>',
						esc_html( (string) $verdict ),
						esc_url( $link( array( $filter => (string) $verdict ) ) ),
						esc_html( number_format_i18n( $count ) )
					);
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Prints the daily charts and the table with the same numbers.
	 *
	 * @param array<string, mixed> $report Report of the period.
	 * @return void
	 */
	private function render_trend( array $report ): void {
		?>
		<h2><?php esc_html_e( 'Daily trend', 'rag-interaction-logger-monitor' ); ?></h2>
		<?php
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
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $days as $day ) {
						printf(
							'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
							esc_html( $day['date'] ),
							esc_html( number_format_i18n( $day['turns'] ) ),
							esc_html( number_format_i18n( $day['incomplete'] ) ),
							esc_html( number_format_i18n( $day['input_blocks'] ) ),
							esc_html( number_format_i18n( $day['output_blocks'] ) )
						);
					}
					?>
				</tbody>
			</table>
		</details>
		<?php
	}
}
