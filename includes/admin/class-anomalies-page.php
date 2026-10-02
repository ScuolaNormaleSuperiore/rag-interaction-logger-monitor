<?php
/**
 * Anomalies page.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

use DateTimeImmutable;
use RILM\Config\Config;
use RILM\Database\Connection;
use RILM\Database\Optional_Columns;
use RILM\Repository\Anomalies;
use RILM\Repository\Filters;
use RILM\Repository\Interaction_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anomalies screen: one row per predefined view, with its count in the chosen period
 * and a link to the interactions list already filtered.
 *
 * The page only reads data: it sends no notification and changes nothing. The period
 * is not sensitive, so it travels in the URL.
 */
class Anomalies_Page {

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
			<h1><?php esc_html_e( 'Anomalies', 'rag-interaction-logger-monitor' ); ?></h1>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: time zone name, e.g. "Europe/Rome". */
						__( 'Counts are for the chosen period, read in the site time zone: %s.', 'rag-interaction-logger-monitor' ),
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
				$this->render_counts( $repository, $filters, $now );
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
	 * Returns the label and the explanation of each anomaly.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function descriptions(): array {
		return array(
			Anomalies::INCOMPLETE     => array(
				'label'       => __( 'Incomplete interactions', 'rag-interaction-logger-monitor' ),
				'description' => __( 'The turn did not complete.', 'rag-interaction-logger-monitor' ),
			),
			Anomalies::NO_GUARDRAILS  => array(
				'label'       => __( 'Guardrails did not run', 'rag-interaction-logger-monitor' ),
				'description' => __( 'No Guardrails check is recorded for the turn.', 'rag-interaction-logger-monitor' ),
			),
			Anomalies::INPUT_BLOCKS   => array(
				'label'       => __( 'Input blocked', 'rag-interaction-logger-monitor' ),
				'description' => __( 'Guardrails recorded a verdict on the question.', 'rag-interaction-logger-monitor' ),
			),
			Anomalies::OUTPUT_BLOCKS  => array(
				'label'       => __( 'Output blocked', 'rag-interaction-logger-monitor' ),
				'description' => __( 'Guardrails recorded a verdict on the answer.', 'rag-interaction-logger-monitor' ),
			),
			Anomalies::ANSWERS_DIFFER => array(
				'label'       => __( 'Answer changed without an output verdict', 'rag-interaction-logger-monitor' ),
				'description' => __( 'The delivered answer differs from the generated one, but no output verdict explains it.', 'rag-interaction-logger-monitor' ),
			),
			Anomalies::ZERO_RECALL    => array(
				'label'       => __( 'Generated without recalled sources', 'rag-interaction-logger-monitor' ),
				'description' => __( 'A generated answer for which no source was recalled.', 'rag-interaction-logger-monitor' ),
			),
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
			<input type="hidden" name="page" value="<?php echo esc_attr( Menu::SLUG_ANOMALIES ); ?>" />
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
	 * Prints the table of the anomalies with their counts and links.
	 *
	 * @param Interaction_Repository $repository Interactions source.
	 * @param Filters                $filters    Filters of the request (only the period is used).
	 * @param DateTimeImmutable      $now        Current time in the site time zone.
	 * @return void
	 */
	private function render_counts( Interaction_Repository $repository, Filters $filters, DateTimeImmutable $now ): void {
		$counts = $repository->anomaly_counts( $filters->period() );

		if ( null === $counts ) {
			?>
			<p><?php esc_html_e( 'The counts could not be loaded.', 'rag-interaction-logger-monitor' ); ?></p>
			<?php
			return;
		}

		?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of interactions. */
					_n( '%s interaction in the period.', '%s interactions in the period.', $counts['total'], 'rag-interaction-logger-monitor' ),
					number_format_i18n( $counts['total'] )
				)
			);
			?>
		</p>
		<table class="widefat striped rilm-anomalies-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Anomaly', 'rag-interaction-logger-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Interactions', 'rag-interaction-logger-monitor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'List', 'rag-interaction-logger-monitor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( self::descriptions() as $key => $text ) {
					$url = List_Links::url( $filters, Anomalies::definitions()[ $key ], $now );

					printf(
						'<tr><th scope="row">%1$s<br /><span class="description">%2$s</span></th><td>%3$s</td><td><a href="%4$s">%5$s<span class="screen-reader-text"> %6$s</span></a></td></tr>',
						esc_html( $text['label'] ),
						esc_html( $text['description'] ),
						esc_html( number_format_i18n( $counts[ $key ] ) ),
						esc_url( $url ),
						esc_html__( 'View interactions', 'rag-interaction-logger-monitor' ),
						esc_html(
							sprintf(
								/* translators: %s: name of the anomaly. */
								__( '(%s)', 'rag-interaction-logger-monitor' ),
								$text['label']
							)
						)
					);
				}
				?>
			</tbody>
		</table>
		<?php
	}
}
