<?php
/**
 * Integration tests of the repository SQL with the real `wpdb::prepare()`.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use RILM\Config\Config;
use RILM\Database\Guarded_Wpdb;
use RILM\Database\Reader;
use RILM\Repository\Filters;
use RILM\Repository\Interaction_Repository;
use WP_UnitTestCase;

/**
 * Checks the escaping WordPress really applies, which a hand-written double cannot prove.
 */
class RepositoryEscapingTest extends WP_UnitTestCase {

	/**
	 * Reader that prepares with the site's `wpdb` and records the statements without running them.
	 *
	 * @return Reader
	 */
	private function recording_reader(): Reader {
		return new class() implements Reader {
			/**
			 * Prepared statements received.
			 *
			 * @var string[]
			 */
			public $queries = array();

			/**
			 * Prepares with the real wpdb.
			 *
			 * @param string $query   Query template.
			 * @param mixed  ...$args Values.
			 * @return string|void
			 */
			public function prepare( $query, ...$args ) {
				global $wpdb;

				return $wpdb->prepare( $query, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Test double forwarding the template.
			}

			/**
			 * Escapes with the real wpdb.
			 *
			 * @param string $text Text.
			 * @return string
			 */
			public function esc_like( $text ) {
				global $wpdb;

				return $wpdb->esc_like( $text );
			}

			/**
			 * Records the statement.
			 *
			 * @param string|null $query  Statement.
			 * @param string      $output Output type.
			 * @return array
			 */
			public function get_results( $query = null, $output = 'OBJECT' ) {
				$this->queries[] = $query;
				return array();
			}

			/**
			 * Records the statement.
			 *
			 * @param string|null $query Statement.
			 * @param int         $x     Column offset.
			 * @param int         $y     Row offset.
			 * @return string
			 */
			public function get_var( $query = null, $x = 0, $y = 0 ) {
				$this->queries[] = $query;
				return '0';
			}

			/**
			 * Records the statement.
			 *
			 * @param string|null $query Statement.
			 * @param int         $x     Column offset.
			 * @return array
			 */
			public function get_col( $query = null, $x = 0 ) {
				$this->queries[] = $query;
				return array();
			}
		};
	}

	/**
	 * Builds filters at a fixed local time.
	 *
	 * @param array $input Raw values.
	 * @return Filters
	 */
	private function filters( array $input ): Filters {
		return Filters::from_array( $input, new DateTimeImmutable( '2026-10-02 14:30:00', new DateTimeZone( 'Europe/Rome' ) ) );
	}

	/**
	 * Repository on the recording reader.
	 *
	 * @param Reader $reader Reader.
	 * @return Interaction_Repository
	 */
	private function repository( Reader $reader ): Interaction_Repository {
		return new Interaction_Repository(
			$reader,
			new Config(
				array( 'host' => 'db.example.test' ),
				array(
					'user'     => 'reader',
					'password' => 'secret',
				)
			)
		);
	}

	/**
	 * Guarded_Wpdb is usable wherever a Reader is expected.
	 *
	 * @return void
	 */
	public function test_guarded_wpdb_is_a_reader(): void {
		$this->assertTrue( is_subclass_of( Guarded_Wpdb::class, Reader::class ) );
	}

	/**
	 * The real prepare() quotes values and keeps an injection attempt inside the string.
	 *
	 * @return void
	 */
	public function test_real_prepare_keeps_injection_inside_the_value(): void {
		$reader = $this->recording_reader();

		$this->repository( $reader )->count( $this->filters( array( 'instance' => "x' OR '1'='1" ) ) );

		$query = $reader->queries[0];

		$this->assertStringStartsWith( 'SELECT COUNT(*) FROM `rag-interaction-logger-db`.`ril_interactions` WHERE', $query );
		$this->assertStringContainsString( "instance = 'x\\' OR \\'1\\'=\\'1'", $query );
	}

	/**
	 * The real esc_like() and prepare() make search wildcards literal.
	 *
	 * @return void
	 */
	public function test_real_search_escapes_wildcards(): void {
		$reader = $this->recording_reader();

		$this->repository( $reader )->count( $this->filters( array( 'search' => '50%_off' ) ) );

		$query = $reader->queries[0];

		// WordPress swaps every % of a value for a placeholder escape until the query runs, and prepare() doubles the
		// backslash that esc_like() put before the user's own % and _: the pattern is "<any>50\%\_off<any>".
		$placeholder = '\{[0-9a-f]{64}\}';
		$pattern     = "/LIKE '" . $placeholder . '50\\\\\\\\' . $placeholder . '\\\\\\\\_off' . $placeholder . "'/";

		$this->assertSame( 3, preg_match_all( $pattern, $query ) );
		$this->assertStringContainsString( 'question LIKE', $query );
		$this->assertStringContainsString( 'llm_answer LIKE', $query );
		$this->assertStringContainsString( 'delivered LIKE', $query );
	}

	/**
	 * Page size and offset are integers in the real statement.
	 *
	 * @return void
	 */
	public function test_real_prepare_formats_limit_and_offset(): void {
		$reader = $this->recording_reader();

		$this->repository( $reader )->find_page( $this->filters( array( 'paged' => '3', 'per_page' => '50' ) ) );

		$this->assertStringEndsWith( ' ORDER BY ts DESC, id DESC LIMIT 50 OFFSET 100', $reader->queries[0] );
	}

	/**
	 * Every statement built with the real prepare() is a single SELECT.
	 *
	 * @return void
	 */
	public function test_real_statements_are_read_only(): void {
		$reader  = $this->recording_reader();
		$filters = $this->filters(
			array(
				'outcome' => 'generated',
				'search'  => 'hello',
				'user_id' => '42',
			)
		);

		$repository = $this->repository( $reader );
		$repository->count( $filters );
		$repository->find_page( $filters );
		$repository->find_by_id( 9 );
		$repository->distinct_values( 'instance', $filters->period() );

		$this->assertCount( 4, $reader->queries );

		foreach ( $reader->queries as $query ) {
			$this->assertTrue( Guarded_Wpdb::is_read_only_query( $query ) );
			$this->assertStringNotContainsString( ';', $query );
		}
	}
}
