<?php
/**
 * Dashboard page.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

use DateTimeImmutable;
use DateTimeZone;
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
 * Dashboard screen: the figures of a period, the tools that ran and the blocks by verdict.
 *
 * It only reads data. The period is not sensitive, so it travels in the URL, and every
 * count links to the interactions list filtered the same way. The daily charts are on
 * the Daily trend page.
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
				$report = ( new Dashboard_Report( $repository ) )->build( $filters->period(), true, false );

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
		$this->render_test_status( $report, $now->getTimezone() );
		$this->render_indicators( $report, $link );
		$this->render_tools( $report, $link );
		$this->render_verdicts( $report, $link );
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
	 * Prints a compact box of indicators useful while a test is running: whether interactions are
	 * still arriving, how many turns complete, how much of the traffic Guardrails actually covered,
	 * and the block rate on only the turns it covered (a different, usually higher, share than the
	 * one in the indicators table below, which is taken of all turns).
	 *
	 * @param array<string, mixed> $report Report of the period.
	 * @param DateTimeZone         $zone   Site time zone.
	 * @return void
	 */
	private function render_test_status( array $report, DateTimeZone $zone ): void {
		?>
		<h2><?php esc_html_e( 'Test status', 'rag-interaction-logger-monitor' ); ?></h2>
		<div class="rilm-tiles">
			<div class="rilm-tile">
				<p class="rilm-tile-label"><?php esc_html_e( 'Last interaction recorded', 'rag-interaction-logger-monitor' ); ?></p>
				<p class="rilm-tile-value"><?php echo esc_html( self::last_interaction_text( $report['last_ts'], $zone ) ); ?></p>
			</div>
			<div class="rilm-tile">
				<p class="rilm-tile-label"><?php esc_html_e( 'Completed turns', 'rag-interaction-logger-monitor' ); ?></p>
				<p class="rilm-tile-value"><?php echo esc_html( number_format_i18n( $report['duration']['completed'] ) ); ?></p>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: share of all turns that are incomplete. */
							__( '%s incomplete', 'rag-interaction-logger-monitor' ),
							self::percent_text( $report['outcomes']['incomplete']['percent'] )
						)
					);
					?>
				</p>
			</div>
			<div class="rilm-tile">
				<p class="rilm-tile-label"><?php esc_html_e( 'Guardrails coverage', 'rag-interaction-logger-monitor' ); ?></p>
				<p class="rilm-tile-value"><?php echo esc_html( self::percent_text( $report['guardrails_covered']['percent'] ) ); ?></p>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of turns Guardrails covered, 2: total number of turns. */
							__( '%1$s of %2$s turns', 'rag-interaction-logger-monitor' ),
							number_format_i18n( $report['guardrails_covered']['count'] ),
							number_format_i18n( $report['total'] )
						)
					);
					?>
				</p>
			</div>
			<div class="rilm-tile">
				<p class="rilm-tile-label"><?php esc_html_e( 'Blocks on turns Guardrails covered', 'rag-interaction-logger-monitor' ); ?></p>
				<p class="rilm-tile-value-small">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: share of the covered turns whose input was blocked. */
							__( 'Input: %s', 'rag-interaction-logger-monitor' ),
							self::percent_text( $report['input_blocks']['percent_of_covered'] )
						)
					);
					?>
				</p>
				<p class="rilm-tile-value-small">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: share of the covered turns whose output was blocked. */
							__( 'Output: %s', 'rag-interaction-logger-monitor' ),
							self::percent_text( $report['output_blocks']['percent_of_covered'] )
						)
					);
					?>
				</p>
			</div>
			<?php if ( isset( $report['tools'] ) ) : ?>
			<div class="rilm-tile">
				<p class="rilm-tile-label"><?php echo esc_html( Indicator_Texts::label( Indicator_Texts::TOOLS ) ); ?></p>
				<p class="rilm-tile-value"><?php echo esc_html( self::percent_text( $report['tools']['percent'] ) ); ?></p>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of turns that used a tool, 2: total number of turns. */
							__( '%1$s of %2$s turns', 'rag-interaction-logger-monitor' ),
							number_format_i18n( $report['tools']['count'] ),
							number_format_i18n( $report['total'] )
						)
					);
					?>
				</p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Formats the last interaction of the period in the site time zone, or "Not recorded".
	 *
	 * @param string|null  $last_ts Stored UTC timestamp (`Y-m-d H:i:s.v`), or null when the period is empty.
	 * @param DateTimeZone $zone    Site time zone.
	 * @return string
	 */
	private static function last_interaction_text( ?string $last_ts, DateTimeZone $zone ): string {
		if ( null === $last_ts ) {
			return __( 'Not recorded', 'rag-interaction-logger-monitor' );
		}

		return ( new DateTimeImmutable( $last_ts, new DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Prints the table of the indicators with their counts, shares and links.
	 *
	 * @param array<string, mixed> $report Report of the period.
	 * @param callable             $link   Builds the list URL for filter input.
	 * @return void
	 */
	private function render_indicators( array $report, callable $link ): void {
		$rows       = array(
			array( Indicator_Texts::GENERATED, $report['outcomes']['generated'], array( 'outcome' => 'generated' ) ),
			array( Indicator_Texts::FAST_REPLY, $report['outcomes']['fast_reply'], array( 'outcome' => 'fast_reply' ) ),
			array( Indicator_Texts::INCOMPLETE, $report['outcomes']['incomplete'], array( 'outcome' => 'incomplete' ) ),
			array( Indicator_Texts::INPUT_BLOCKS, $report['input_blocks'], array( 'input_verdict' => Filters::VERDICT_ANY ) ),
			array( Indicator_Texts::OUTPUT_BLOCKS, $report['output_blocks'], array( 'output_verdict' => Filters::VERDICT_ANY ) ),
			array( Indicator_Texts::NO_GUARDRAILS, $report['no_guardrails'], array( 'guard' => Filters::GUARD_ABSENT ) ),
			array(
				Indicator_Texts::ZERO_RECALL,
				$report['zero_recall'],
				array(
					'outcome' => 'generated',
					'recall'  => 'empty',
				),
			),
		);
		$with_tools = isset( $report['tools'] );

		if ( $with_tools ) {
			$rows[] = array( Indicator_Texts::TOOLS, $report['tools'], array( 'tools' => Filters::TOOLS_YES ) );
		}
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
					list( $key, $figure, $input ) = $row;

					$label = Indicator_Texts::label( $key );
					$whole = Indicator_Texts::basis_phrase( $key );

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
		$this->render_legend( $with_tools );
	}

	/**
	 * Prints the number of turns in which each tool or form ran, when the table has the tools column.
	 *
	 * @param array<string, mixed> $report Report of the period.
	 * @param callable             $link   Builds the list URL for filter input.
	 * @return void
	 */
	private function render_tools( array $report, callable $link ): void {
		if ( ! isset( $report['tools'] ) ) {
			return;
		}

		$tools = $report['tools'];
		?>
		<h2><?php esc_html_e( 'Tools and forms', 'rag-interaction-logger-monitor' ); ?></h2>
		<?php
		if ( $tools['by_name_failed'] ) {
			?>
			<p><?php esc_html_e( 'The tools could not be loaded.', 'rag-interaction-logger-monitor' ); ?></p>
			<?php
			return;
		}

		if ( array() === $tools['by_name'] ) {
			?>
			<p><?php esc_html_e( 'No tool or form ran in this period.', 'rag-interaction-logger-monitor' ); ?></p>
			<?php
			return;
		}
		?>
		<p class="description"><?php esc_html_e( 'A turn that used several tools is counted in each of them, so the counts can add up to more than the turns that used tools.', 'rag-interaction-logger-monitor' ); ?></p>
		<?php
		if ( $tools['by_name_cut'] ) {
			wp_admin_notice(
				esc_html__( 'There are too many different combinations of tools in this period: the counts below are partial. Choose a shorter period.', 'rag-interaction-logger-monitor' ),
				array( 'type' => 'warning' )
			);
		}
		?>
		<table class="widefat striped rilm-tools-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Tool or form', 'rag-interaction-logger-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Interactions', 'rag-interaction-logger-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $tools['by_name'] as $name => $turns ) {
					printf(
						'<tr><th scope="row">%1$s</th><td><a href="%2$s">%3$s<span class="screen-reader-text"> (%1$s)</span></a></td></tr>',
						esc_html( (string) $name ),
						esc_url( $link( array( 'tool' => (string) $name ) ) ),
						esc_html( number_format_i18n( $turns ) )
					);
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Prints the legend that says what each indicator counts and what its share is taken of.
	 *
	 * The wording comes from `Indicator_Texts`, which the Anomalies page also uses.
	 *
	 * @param bool $with_tools Whether the table has the tools column, so the tools row has an entry.
	 * @return void
	 */
	private function render_legend( bool $with_tools ): void {
		?>
		<details class="rilm-details">
			<summary id="rilm-indicators-legend"><?php esc_html_e( 'What the indicators mean', 'rag-interaction-logger-monitor' ); ?></summary>
			<dl class="rilm-legend-list" aria-labelledby="rilm-indicators-legend">
				<?php
				foreach ( Indicator_Texts::dashboard_keys( $with_tools ) as $key ) {
					printf(
						'<dt>%1$s</dt><dd>%2$s %3$s</dd>',
						esc_html( Indicator_Texts::label( $key ) ),
						esc_html( Indicator_Texts::description( $key ) ),
						esc_html( Indicator_Texts::basis_sentence( $key ) )
					);
				}
				?>
			</dl>
		</details>
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
}
