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

		// Recall happens as the question is processed, before the answer is generated.
		if ( null !== $interaction->recall_sources ) {
			$this->render_recall_sources( $interaction->recall_sources );
		}

		$this->render_text( __( 'Generated answer', 'rag-interaction-logger-monitor' ), $interaction->llm_answer );
		$this->render_text( __( 'Delivered answer', 'rag-interaction-logger-monitor' ), $interaction->delivered );

		$this->render_comparison( $interaction );
		$this->render_tool_invocation( $interaction );
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
				? __( 'Guardrails were executed', 'rag-interaction-logger-monitor' )
				: __( 'Guardrails were not executed', 'rag-interaction-logger-monitor' ),
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
	 * Prints all recorded information about tool invocations in one section.
	 *
	 * The logger stores these fields at interaction level. When more than one tool
	 * ran, its data may therefore describe the whole interaction rather than a
	 * separate input and output for each individual invocation.
	 *
	 * @param Interaction $interaction Interaction to show.
	 * @return void
	 */
	private function render_tool_invocation( Interaction $interaction ): void {
		if ( null === $interaction->tools_used && null === $interaction->tool_input && null === $interaction->tool_output ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'Tool invocation', 'rag-interaction-logger-monitor' ) );
		echo '<table class="widefat striped rilm-detail-table"><tbody>';

		$this->render_tool_row(
			__( 'Status', 'rag-interaction-logger-monitor' ),
			null !== $interaction->tools_used && '' !== trim( $interaction->tools_used )
				? __( 'Tools were invoked', 'rag-interaction-logger-monitor' )
				: __( 'No tools were invoked', 'rag-interaction-logger-monitor' )
		);
		$this->render_tool_row( __( 'Tools used', 'rag-interaction-logger-monitor' ), $interaction->tools_used );
		$this->render_tool_row( __( 'Tool input', 'rag-interaction-logger-monitor' ), $interaction->tool_input );
		$this->render_tool_row( __( 'Tool output', 'rag-interaction-logger-monitor' ), $interaction->tool_output );

		echo '</tbody></table>';
	}

	/**
	 * Prints one value from the tool-invocation record.
	 *
	 * @param string      $label Field label.
	 * @param string|null $value Field value.
	 * @return void
	 */
	private function render_tool_row( string $label, ?string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';

		if ( null === $value ) {
			esc_html_e( 'Not recorded', 'rag-interaction-logger-monitor' );
		} elseif ( '' === $value ) {
			echo '<em>' . esc_html__( '(empty)', 'rag-interaction-logger-monitor' ) . '</em>';
		} else {
			echo '<div class="rilm-text rilm-full-text">' . esc_html( $value ) . '</div>';
		}

		echo '</td></tr>';
	}

	/**
	 * Prints recalled-document metadata, linking only safe web sources.
	 *
	 * Current versions of RAG Interaction Logger store this field as a JSON array;
	 * per document, an id, a source (file name or URL), a score, and from the
	 * document's metadata an optional type, origin, WordPress id, title and URL
	 * (for example from the WordPress importer). Older logger versions may have
	 * stored another representation, which remains visible as escaped plain text
	 * instead of being discarded.
	 *
	 * @param string $sources Stored recall sources.
	 * @return void
	 */
	private function render_recall_sources( string $sources ): void {
		$items = json_decode( $sources, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $items ) || ! array_is_list( $items ) ) {
			$this->render_optional_text( __( 'Recall sources', 'rag-interaction-logger-monitor' ), $sources );
			return;
		}

		printf( '<h2>%s</h2><ul class="rilm-recall-sources">', esc_html__( 'Recall sources', 'rag-interaction-logger-monitor' ) );

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$this->render_recall_source( $item );
		}

		echo '</ul>';
	}

	/**
	 * Prints one recalled document.
	 *
	 * The document's name is its title when the logger recorded one, else its source,
	 * else nothing more than the `ID:` line already shows. That name links to the
	 * document's `url` when the logger recorded one and it is an absolute `http`/`https`
	 * URL that was not cut to its length limit; without a usable `url`, a `source` that
	 * is itself a web URL is linked instead, exactly as it always has been, so a row
	 * written before the logger recorded `url` still renders the same way.
	 *
	 * @param array<string, mixed> $item Recalled-document metadata.
	 * @return void
	 */
	private function render_recall_source( array $item ): void {
		$id     = isset( $item['id'] ) && is_scalar( $item['id'] ) ? (string) $item['id'] : '';
		$source = isset( $item['source'] ) && is_string( $item['source'] ) ? $item['source'] : '';
		$title  = isset( $item['title'] ) && is_string( $item['title'] ) ? $item['title'] : '';
		$url    = isset( $item['url'] ) && is_string( $item['url'] ) ? $item['url'] : '';
		$score  = isset( $item['score'] ) && is_numeric( $item['score'] ) ? (float) $item['score'] : null;

		$name = '' !== $title ? $title : $source;
		$link = '';

		if ( '' !== $url && empty( $item['url_cut'] ) && $this->is_web_url( $url ) ) {
			$link = $url;
		} elseif ( '' === $title && '' !== $source && $this->is_web_url( $source ) ) {
			$link = $source;
		}

		echo '<li>';

		if ( '' === $title && '' !== $id ) {
			printf(
				'<strong>%1$s:</strong> %2$s ',
				esc_html__( 'ID', 'rag-interaction-logger-monitor' ),
				esc_html( $id )
			);
		}

		if ( '' !== $name ) {
			if ( '' !== $link ) {
				printf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a> ',
					esc_url( $link ),
					esc_html( $name )
				);
			} else {
				echo esc_html( $name ) . ' ';
			}
		}

		$secondary = array();

		foreach (
			array(
				'origin' => __( 'Origin', 'rag-interaction-logger-monitor' ),
				'wp_id'  => __( 'WordPress ID', 'rag-interaction-logger-monitor' ),
				'type'   => __( 'Type', 'rag-interaction-logger-monitor' ),
			) as $key => $label
		) {
			$value = $this->recall_label( $item, $key );

			if ( '' !== $value ) {
				$secondary[] = esc_html( $label ) . ': ' . esc_html( $value );
			}
		}

		if ( null !== $score ) {
			$secondary[] = esc_html__( 'Score', 'rag-interaction-logger-monitor' ) . ': <strong>' . esc_html( number_format_i18n( $score, 6 ) ) . '</strong>';
		}

		if ( array() !== $secondary ) {
			// Each piece of $secondary is already escaped where it was built, above.
			printf( '<span class="description">%s</span>', implode( ' &middot; ', $secondary ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</li>';
	}

	/**
	 * Reads a short identifying field (origin, WordPress id, type) from recalled-document metadata.
	 *
	 * @param array<string, mixed> $item Recalled-document metadata.
	 * @param string               $key  Field name.
	 * @return string Empty when absent, empty, or not text or a number.
	 */
	private function recall_label( array $item, string $key ): string {
		if ( ! isset( $item[ $key ] ) || is_bool( $item[ $key ] ) || ! is_scalar( $item[ $key ] ) ) {
			return '';
		}

		return (string) $item[ $key ];
	}

	/**
	 * Checks whether a source is an absolute HTTP or HTTPS URL.
	 *
	 * @param string $source Source value from the external database.
	 * @return bool
	 */
	private function is_web_url( string $source ): bool {
		$parts = wp_parse_url( $source );

		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& is_string( $parts['scheme'] )
			&& in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	/**
	 * Prints an optional column as escaped plain text.
	 *
	 * @param string $title Section title.
	 * @param string $text  Column value.
	 * @return void
	 */
	private function render_optional_text( string $title, string $text ): void {
		printf(
			'<h2>%1$s</h2><div class="rilm-text rilm-full-text">%2$s</div>',
			esc_html( $title ),
			esc_html( $text )
		);
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
