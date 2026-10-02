<?php
/**
 * Interaction detail page.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

use DateTimeZone;
use RILM\Config\Config;
use RILM\Database\Connection;
use RILM\Database\Optional_Columns;
use RILM\Repository\Interaction;
use RILM\Repository\Interaction_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detail screen: every field and the full texts of one interaction.
 *
 * The page is read-only and reached by a link that carries only the integer id.
 * A missing, malformed or unknown id all give the same generic message, so the
 * page never reveals whether a row exists. Every value is untrusted and escaped.
 */
class Detail_Page {

	/**
	 * Longest text, in characters, that is still compared line by line.
	 */
	public const COMPARISON_LIMIT = 20000;

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
	 * Constructor.
	 *
	 * @param Connection|null $connection         Connection to the log database; defaults to the configured one.
	 * @param callable|null   $repository_factory Returns an Interaction_Repository or null; defaults to the connection.
	 */
	public function __construct( ?Connection $connection = null, ?callable $repository_factory = null ) {
		$this->connection         = $connection;
		$this->repository_factory = $repository_factory ?? array( $this, 'default_repository' );
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		Access::require_admin();

		?>
		<div class="wrap rilm-wrap">
			<h1><?php esc_html_e( 'Interaction details', 'rag-interaction-logger-monitor' ); ?></h1>
			<p>
				<a href="<?php echo esc_url( add_query_arg( 'page', Menu::SLUG_INTERACTIONS, admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Back to the interactions list', 'rag-interaction-logger-monitor' ); ?></a>
			</p>
			<?php
			$repository = call_user_func( $this->repository_factory );

			if ( null === $repository ) {
				?>
				<p><?php esc_html_e( 'The interaction log is not available. See the notice above.', 'rag-interaction-logger-monitor' ); ?></p>
				<?php
			} else {
				$id          = $this->read_id();
				$interaction = null === $id ? null : $repository->find_by_id( $id );

				if ( null === $interaction ) {
					?>
					<p><?php esc_html_e( 'Interaction not found.', 'rag-interaction-logger-monitor' ); ?></p>
					<?php
				} else {
					$this->render_interaction( $interaction );
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
	 * Reads the interaction id from the URL: a positive integer, or null.
	 *
	 * @return int|null
	 */
	private function read_id(): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page: access is limited by capability and the id is validated as a positive integer.
		$raw = isset( $_GET['id'] ) && is_string( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';

		if ( 1 !== preg_match( '/^[1-9][0-9]{0,17}$/', $raw ) ) {
			return null;
		}

		return (int) $raw;
	}

	/**
	 * Prints one interaction.
	 *
	 * @param Interaction $interaction Interaction to show.
	 * @return void
	 */
	private function render_interaction( Interaction $interaction ): void {
		$zone = wp_timezone();

		$this->render_metadata( $interaction, $zone );

		$this->render_text( __( 'Question', 'rag-interaction-logger-monitor' ), $interaction->question );
		$this->render_text( __( 'Generated answer', 'rag-interaction-logger-monitor' ), $interaction->llm_answer );
		$this->render_text( __( 'Delivered answer', 'rag-interaction-logger-monitor' ), $interaction->delivered );

		$this->render_comparison( $interaction );
		$this->render_optional_columns( $interaction );
	}

	/**
	 * Prints the table of the fields other than the long texts.
	 *
	 * @param Interaction  $interaction Interaction to show.
	 * @param DateTimeZone $zone        Site time zone.
	 * @return void
	 */
	private function render_metadata( Interaction $interaction, DateTimeZone $zone ): void {
		$rows = array(
			__( 'ID', 'rag-interaction-logger-monitor' )   => (string) $interaction->id,
			__( 'Date and time (UTC)', 'rag-interaction-logger-monitor' ) => $interaction->timestamp()->format( 'Y-m-d H:i:s.v' ),
			sprintf(
				/* translators: %s: time zone name, e.g. "Europe/Rome". */
				__( 'Date and time (%s)', 'rag-interaction-logger-monitor' ),
				wp_timezone_string()
			)                                              => $interaction->timestamp()->setTimezone( $zone )->format( 'Y-m-d H:i:s.v' ),
			__( 'Instance', 'rag-interaction-logger-monitor' ) => $interaction->instance,
			__( 'User', 'rag-interaction-logger-monitor' ) => $interaction->user_id,
			__( 'Turn ID', 'rag-interaction-logger-monitor' ) => $this->or_not_recorded( $interaction->turn_id ),
			__( 'Outcome', 'rag-interaction-logger-monitor' ) => $this->outcome_label( $interaction->outcome ),
			__( 'Duration', 'rag-interaction-logger-monitor' ) => null === $interaction->duration_ms
				? __( 'Not recorded', 'rag-interaction-logger-monitor' )
				: sprintf(
					/* translators: %s: duration in milliseconds. */
					__( '%s ms', 'rag-interaction-logger-monitor' ),
					number_format_i18n( $interaction->duration_ms )
				),
			__( 'Guardrails', 'rag-interaction-logger-monitor' ) => $interaction->guard_present
				? __( 'Present', 'rag-interaction-logger-monitor' )
				: __( 'Absent', 'rag-interaction-logger-monitor' ),
			__( 'Input verdict', 'rag-interaction-logger-monitor' ) => $this->verdict_label( $interaction->input_verdict ),
			__( 'Output verdict', 'rag-interaction-logger-monitor' ) => $this->verdict_label( $interaction->output_verdict ),
			__( 'Other plugin reply', 'rag-interaction-logger-monitor' ) => $this->reply_label( $interaction->other_plugin_reply ),
			__( 'Recalled sources', 'rag-interaction-logger-monitor' ) => null === $interaction->recall_count
				? __( 'Not recorded', 'rag-interaction-logger-monitor' )
				: number_format_i18n( $interaction->recall_count ),
			__( 'Best recall score', 'rag-interaction-logger-monitor' ) => null === $interaction->recall_top_score
				? __( 'Not recorded', 'rag-interaction-logger-monitor' )
				: number_format_i18n( $interaction->recall_top_score, 4 ),
		);

		echo '<table class="widefat striped rilm-detail-table"><tbody>';

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				esc_html( (string) $label ),
				esc_html( (string) $value )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Prints a long text in full, as plain escaped text.
	 *
	 * @param string      $title Section title.
	 * @param string|null $text  Text, or null when not recorded.
	 * @return void
	 */
	private function render_text( string $title, ?string $text ): void {
		printf( '<h2>%s</h2>', esc_html( $title ) );

		if ( null === $text ) {
			echo '<p>' . esc_html__( 'Not recorded', 'rag-interaction-logger-monitor' ) . '</p>';
			return;
		}

		if ( '' === $text ) {
			echo '<p><em>' . esc_html__( '(empty)', 'rag-interaction-logger-monitor' ) . '</em></p>';
			return;
		}

		echo '<div class="rilm-text rilm-full-text">' . esc_html( $text ) . '</div>';
	}

	/**
	 * Prints the comparison of the generated and delivered answers when they differ.
	 *
	 * @param Interaction $interaction Interaction to show.
	 * @return void
	 */
	private function render_comparison( Interaction $interaction ): void {
		$generated = $interaction->llm_answer;
		$delivered = $interaction->delivered;

		if ( null === $generated || null === $delivered || $generated === $delivered ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'Comparison of the generated and delivered answers', 'rag-interaction-logger-monitor' ) );

		if ( mb_strlen( $generated ) > self::COMPARISON_LIMIT || mb_strlen( $delivered ) > self::COMPARISON_LIMIT ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: number of characters. */
					__( 'The comparison is not available for texts longer than %s characters: the full texts are shown above.', 'rag-interaction-logger-monitor' ),
					number_format_i18n( self::COMPARISON_LIMIT )
				)
			) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'The answers differ. Changed lines are highlighted.', 'rag-interaction-logger-monitor' ) . '</p>';

		// wp_text_diff() escapes the lines it prints.
		echo wp_text_diff( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes the compared text; the titles are escaped here.
			$generated,
			$delivered,
			array(
				'title_left'  => esc_html__( 'Generated answer', 'rag-interaction-logger-monitor' ),
				'title_right' => esc_html__( 'Delivered answer', 'rag-interaction-logger-monitor' ),
			)
		);
	}

	/**
	 * Prints the columns added to the table later, only those that hold a value.
	 *
	 * @param Interaction $interaction Interaction to show.
	 * @return void
	 */
	private function render_optional_columns( Interaction $interaction ): void {
		$sections = array(
			__( 'Tools used', 'rag-interaction-logger-monitor' )    => $interaction->tools_used,
			__( 'Tool input', 'rag-interaction-logger-monitor' )    => $interaction->tool_input,
			__( 'Tool output', 'rag-interaction-logger-monitor' )   => $interaction->tool_output,
			__( 'Recall sources', 'rag-interaction-logger-monitor' ) => $interaction->recall_sources,
		);

		foreach ( $sections as $title => $text ) {
			if ( null === $text ) {
				continue;
			}

			printf(
				'<details class="rilm-details"><summary>%1$s</summary><div class="rilm-text rilm-full-text">%2$s</div></details>',
				esc_html( (string) $title ),
				esc_html( $text )
			);
		}
	}

	/**
	 * Returns a text, or "Not recorded" for NULL.
	 *
	 * @param string|null $value Value.
	 * @return string
	 */
	private function or_not_recorded( ?string $value ): string {
		return null === $value ? __( 'Not recorded', 'rag-interaction-logger-monitor' ) : $value;
	}

	/**
	 * Returns the label of an outcome.
	 *
	 * @param string $outcome Outcome value.
	 * @return string
	 */
	private function outcome_label( string $outcome ): string {
		switch ( $outcome ) {
			case 'generated':
				return __( 'Generated', 'rag-interaction-logger-monitor' );
			case 'fast_reply':
				return __( 'Fast reply', 'rag-interaction-logger-monitor' );
			case 'incomplete':
				return __( 'Incomplete', 'rag-interaction-logger-monitor' );
			default:
				return $outcome;
		}
	}

	/**
	 * Returns the label of a verdict: no verdict means that no block was recorded.
	 *
	 * @param string|null $verdict Verdict.
	 * @return string
	 */
	private function verdict_label( ?string $verdict ): string {
		return null === $verdict ? __( 'None (no block recorded)', 'rag-interaction-logger-monitor' ) : $verdict;
	}

	/**
	 * Returns the label of the other-plugin reply: NULL is "not recorded", which is not the same as "did not reply".
	 *
	 * @param bool|null $reply Whether another plugin replied.
	 * @return string
	 */
	private function reply_label( ?bool $reply ): string {
		if ( null === $reply ) {
			return __( 'Not recorded', 'rag-interaction-logger-monitor' );
		}

		return $reply ? __( 'Replied', 'rag-interaction-logger-monitor' ) : __( 'Did not reply', 'rag-interaction-logger-monitor' );
	}
}
