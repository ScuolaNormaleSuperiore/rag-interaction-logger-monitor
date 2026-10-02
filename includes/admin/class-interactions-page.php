<?php
/**
 * Interactions list page.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

use DateTimeImmutable;
use RILM\Config\Config;
use RILM\Database\Connection;
use RILM\Database\Optional_Columns;
use RILM\Repository\Filters;
use RILM\Repository\Interaction_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interactions screen: filters, search and the paginated table.
 *
 * One form carries every control. Filters that are not sensitive live in the URL
 * (links, bookmarks), but the search text is personal data and is only ever sent
 * in a POST body protected by a nonce. While a search is active the whole state
 * stays in POST; when it is empty the request is redirected to a clean URL.
 */
class Interactions_Page {

	/**
	 * Nonce action of the filter form.
	 */
	public const NONCE_ACTION = 'rilm_filter_interactions';

	/**
	 * Nonce field name of the filter form.
	 */
	public const NONCE_FIELD = 'rilm_filters_nonce';

	/**
	 * Name of the search field (read from POST only).
	 */
	public const SEARCH_FIELD = 'search';

	/**
	 * Names of the filter fields, read from GET or POST.
	 */
	private const FILTER_KEYS = array( 'period', 'from', 'to', 'outcome', 'instance', 'user_id', 'guard', 'input_verdict', 'output_verdict', 'other_reply', 'recall', 'answers', 'orderby', 'order', 'per_page', 'paged' );

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

		$is_post = $this->is_post_request();

		if ( $is_post ) {
			// An invalid or missing nonce stops the request before anything is read or queried.
			check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
		}

		$now     = call_user_func( $this->clock );
		$filters = Filters::from_array( $this->read_input( $is_post ), $now );

		// No search: leave POST for a clean, shareable URL that holds only non-sensitive filters.
		if ( $is_post && null === $filters->search() ) {
			$this->redirect( $this->page_url( $filters->to_query_args() ) );
			return;
		}

		$repository = call_user_func( $this->repository_factory );

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$table = new Interactions_List_Table( $repository, $filters, wp_timezone() );
		$table->prepare_items();
		?>
		<div class="wrap rilm-wrap">
			<h1><?php esc_html_e( 'Interactions', 'rag-interaction-logger-monitor' ); ?></h1>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: time zone name, e.g. "Europe/Rome". */
						__( 'Dates and times are shown, and period limits are read, in the site time zone: %s.', 'rag-interaction-logger-monitor' ),
						wp_timezone_string()
					)
				);
				?>
			</p>
			<?php
			$this->render_ignored_filters( $filters );

			if ( null === $repository ) {
				?>
				<p><?php esc_html_e( 'The interaction log is not available. See the notice above.', 'rag-interaction-logger-monitor' ); ?></p>
				<?php
			} else {
				?>
				<form method="post" action="<?php echo esc_url( $this->page_url( array() ) ); ?>" id="rilm-interactions-form">
					<?php
					wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false );
					$this->render_filters( $table->filters(), $repository );
					$table->display();
					?>
				</form>
				<?php
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
	 * Redirects the browser and stops. Overridable so tests can observe the target.
	 *
	 * @param string $url Target URL, inside the administration area.
	 * @return void
	 */
	protected function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Tells whether the request is a POST.
	 *
	 * @return bool
	 */
	private function is_post_request(): bool {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}

	/**
	 * Reads the filter values from GET, or from POST (with the search text) when the form was submitted.
	 *
	 * The search text is never read from GET, so it cannot come from, or end up in, a URL.
	 *
	 * @param bool $from_post Whether the verified POST body is the source.
	 * @return array<string, string>
	 */
	private function read_input( bool $from_post ): array {
		$input = array();

		// phpcs:disable WordPress.Security.NonceVerification -- POST is verified in render() first; GET holds only non-sensitive, validated view filters.
		foreach ( self::FILTER_KEYS as $key ) {
			if ( $from_post ) {
				if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
					$input[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
				}
			} elseif ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
				$input[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}

		if ( $from_post && isset( $_POST[ self::SEARCH_FIELD ] ) && is_string( $_POST[ self::SEARCH_FIELD ] ) ) {
			// Kept as typed (tags included): Filters trims and limits it, the repository only passes it to a prepared LIKE, and it is escaped on output.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$input[ self::SEARCH_FIELD ] = wp_unslash( $_POST[ self::SEARCH_FIELD ] );
		}
		// phpcs:enable WordPress.Security.NonceVerification

		return $input;
	}

	/**
	 * Builds the URL of this page with the given arguments.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	private function page_url( array $args ): string {
		return add_query_arg(
			array( 'page' => Menu::SLUG_INTERACTIONS ) + $args,
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Tells the user which filter values were ignored as invalid.
	 *
	 * @param Filters $filters Filters of the request.
	 * @return void
	 */
	private function render_ignored_filters( Filters $filters ): void {
		if ( array() === $filters->errors() ) {
			return;
		}

		$labels = array(
			'period'      => __( 'Period', 'rag-interaction-logger-monitor' ),
			'outcome'     => __( 'Outcome', 'rag-interaction-logger-monitor' ),
			'guard'       => __( 'Guardrails', 'rag-interaction-logger-monitor' ),
			'other_reply' => __( 'Other plugin reply', 'rag-interaction-logger-monitor' ),
			'orderby'     => __( 'Sort by', 'rag-interaction-logger-monitor' ),
		);

		$names = array();

		foreach ( $filters->errors() as $key ) {
			$names[] = $labels[ $key ] ?? $key;
		}

		wp_admin_notice(
			esc_html(
				sprintf(
					/* translators: %s: comma separated names of the filters. */
					__( 'These filters were ignored because their value is not valid: %s.', 'rag-interaction-logger-monitor' ),
					implode( ', ', $names )
				)
			),
			array( 'type' => 'warning' )
		);
	}

	/**
	 * Prints the filter controls. The apply button comes first so Enter applies the filters.
	 *
	 * @param Filters                $filters    Filters of the request.
	 * @param Interaction_Repository $repository Interactions source, for the value lists.
	 * @return void
	 */
	private function render_filters( Filters $filters, Interaction_Repository $repository ): void {
		$period    = $filters->period();
		$instances = $repository->distinct_values( 'instance', $period );
		$inputs    = $repository->distinct_values( 'input_verdict', $period );
		$outputs   = $repository->distinct_values( 'output_verdict', $period );
		?>
		<div class="rilm-filters">
			<p class="rilm-filter-actions">
				<?php submit_button( __( 'Apply filters', 'rag-interaction-logger-monitor' ), 'primary', 'rilm_apply', false ); ?>
				<a class="button" href="<?php echo esc_url( $this->page_url( array() ) ); ?>"><?php esc_html_e( 'Reset', 'rag-interaction-logger-monitor' ); ?></a>
			</p>

			<?php
			Period_Fields::render( $period );
			$this->field_select(
				'outcome',
				__( 'Outcome', 'rag-interaction-logger-monitor' ),
				array(
					''           => __( 'All', 'rag-interaction-logger-monitor' ),
					'generated'  => __( 'Generated', 'rag-interaction-logger-monitor' ),
					'fast_reply' => __( 'Fast reply', 'rag-interaction-logger-monitor' ),
					'incomplete' => __( 'Incomplete', 'rag-interaction-logger-monitor' ),
				),
				(string) $filters->outcome()
			);
			$this->field_select( 'instance', __( 'Instance', 'rag-interaction-logger-monitor' ), $this->value_options( $instances, (string) $filters->instance() ), (string) $filters->instance() );
			$this->field_text( 'user_id', __( 'User (exact)', 'rag-interaction-logger-monitor' ), (string) $filters->user_id(), 255, 'text' );
			$this->field_select(
				'guard',
				__( 'Guardrails', 'rag-interaction-logger-monitor' ),
				array(
					''                     => __( 'All', 'rag-interaction-logger-monitor' ),
					Filters::GUARD_PRESENT => __( 'Present', 'rag-interaction-logger-monitor' ),
					Filters::GUARD_ABSENT  => __( 'Absent', 'rag-interaction-logger-monitor' ),
				),
				(string) $filters->guard()
			);
			$this->field_select( 'input_verdict', __( 'Input verdict', 'rag-interaction-logger-monitor' ), $this->verdict_options( $inputs, (string) $filters->input_verdict() ), (string) $filters->input_verdict() );
			$this->field_select( 'output_verdict', __( 'Output verdict', 'rag-interaction-logger-monitor' ), $this->verdict_options( $outputs, (string) $filters->output_verdict() ), (string) $filters->output_verdict() );
			$this->field_select(
				'other_reply',
				__( 'Other plugin reply', 'rag-interaction-logger-monitor' ),
				array(
					''                     => __( 'All', 'rag-interaction-logger-monitor' ),
					Filters::REPLY_YES     => __( 'Replied', 'rag-interaction-logger-monitor' ),
					Filters::REPLY_NO      => __( 'Did not reply', 'rag-interaction-logger-monitor' ),
					Filters::REPLY_UNKNOWN => __( 'Not recorded', 'rag-interaction-logger-monitor' ),
				),
				(string) $filters->other_reply()
			);
			?>
			<p class="rilm-field">
				<input type="checkbox" id="rilm-recall" name="recall" value="empty"<?php checked( $filters->recall_empty() ); ?> />
				<label for="rilm-recall"><?php esc_html_e( 'Only interactions with an empty recall', 'rag-interaction-logger-monitor' ); ?></label>
			</p>
			<p class="rilm-field">
				<input type="checkbox" id="rilm-answers" name="answers" value="differ"<?php checked( $filters->answers_differ() ); ?> />
				<label for="rilm-answers"><?php esc_html_e( 'Only interactions where the generated and delivered answers differ', 'rag-interaction-logger-monitor' ); ?></label>
			</p>
			<?php
			$this->field_text( self::SEARCH_FIELD, __( 'Search in question and answers', 'rag-interaction-logger-monitor' ), (string) $filters->search(), 200, 'search' );
			$this->field_select(
				'orderby',
				__( 'Sort by', 'rag-interaction-logger-monitor' ),
				array(
					'ts'          => __( 'Date and time', 'rag-interaction-logger-monitor' ),
					'outcome'     => __( 'Outcome', 'rag-interaction-logger-monitor' ),
					'instance'    => __( 'Instance', 'rag-interaction-logger-monitor' ),
					'user_id'     => __( 'User', 'rag-interaction-logger-monitor' ),
					'duration_ms' => __( 'Duration', 'rag-interaction-logger-monitor' ),
				),
				$filters->orderby()
			);
			$this->field_select(
				'order',
				__( 'Order', 'rag-interaction-logger-monitor' ),
				array(
					'DESC' => __( 'Newest or highest first', 'rag-interaction-logger-monitor' ),
					'ASC'  => __( 'Oldest or lowest first', 'rag-interaction-logger-monitor' ),
				),
				$filters->order()
			);
			$this->field_select(
				'per_page',
				__( 'Rows per page', 'rag-interaction-logger-monitor' ),
				array_combine( array_map( 'strval', Filters::PER_PAGE_OPTIONS ), array_map( 'strval', Filters::PER_PAGE_OPTIONS ) ),
				(string) $filters->per_page()
			);
			?>
			<p class="description">
				<?php
				esc_html_e( 'The search text is sent in the request body and never appears in the address bar.', 'rag-interaction-logger-monitor' );
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Prints a labelled select.
	 *
	 * @param string                $name     Field name and id suffix.
	 * @param string                $label    Visible label.
	 * @param array<string, string> $options  Value => label.
	 * @param string                $selected Selected value.
	 * @return void
	 */
	private function field_select( string $name, string $label, array $options, string $selected ): void {
		printf(
			'<p class="rilm-field"><label for="rilm-%1$s">%2$s</label> <select id="rilm-%1$s" name="%1$s">',
			esc_attr( $name ),
			esc_html( $label )
		);

		foreach ( $options as $value => $text ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( (string) $value, $selected, false ),
				esc_html( (string) $text )
			);
		}

		echo '</select></p>';
	}

	/**
	 * Prints a labelled text field.
	 *
	 * @param string $name      Field name and id suffix.
	 * @param string $label     Visible label.
	 * @param string $value     Current value.
	 * @param int    $maxlength Maximum length.
	 * @param string $type      Input type.
	 * @return void
	 */
	private function field_text( string $name, string $label, string $value, int $maxlength, string $type ): void {
		printf(
			'<p class="rilm-field"><label for="rilm-%1$s">%2$s</label> <input type="%3$s" id="rilm-%1$s" name="%1$s" value="%4$s" maxlength="%5$d" class="regular-text" /></p>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			(int) $maxlength
		);
	}

	/**
	 * Builds the options of a select from a list of values, with an "All" entry.
	 *
	 * The selected value is kept even if the period holds no such row, so the filter stays visible.
	 *
	 * @param string[] $values   Values found in the period.
	 * @param string   $selected Selected value.
	 * @return array<string, string>
	 */
	private function value_options( array $values, string $selected ): array {
		$options = array( '' => __( 'All', 'rag-interaction-logger-monitor' ) );

		if ( '' !== $selected && ! in_array( $selected, $values, true ) ) {
			$values[] = $selected;
		}

		foreach ( $values as $value ) {
			$options[ $value ] = $value;
		}

		return $options;
	}

	/**
	 * Builds the options of a verdict select: all, any block, no block, then each verdict.
	 *
	 * @param string[] $values   Verdicts found in the period.
	 * @param string   $selected Selected value.
	 * @return array<string, string>
	 */
	private function verdict_options( array $values, string $selected ): array {
		$options = array(
			''                    => __( 'All', 'rag-interaction-logger-monitor' ),
			Filters::VERDICT_ANY  => __( 'Any block recorded', 'rag-interaction-logger-monitor' ),
			Filters::VERDICT_NONE => __( 'No block recorded', 'rag-interaction-logger-monitor' ),
		);

		if ( '' !== $selected && ! isset( $options[ $selected ] ) && ! in_array( $selected, $values, true ) ) {
			$values[] = $selected;
		}

		foreach ( $values as $value ) {
			$options[ $value ] = $value;
		}

		return $options;
	}
}
