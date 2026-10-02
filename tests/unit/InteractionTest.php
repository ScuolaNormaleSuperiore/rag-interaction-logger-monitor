<?php
/**
 * Unit tests for the interaction row mapping.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RILM\Repository\Interaction;

/**
 * Verifies types, NULL preservation and preview cutting.
 */
class InteractionTest extends TestCase {

	/**
	 * A complete row as the driver returns it (everything as strings).
	 *
	 * @return array
	 */
	private static function row(): array {
		return array(
			'id'                 => '12',
			'ts'                 => '2026-10-02 12:30:45.123',
			'duration_ms'        => '1500',
			'instance'           => 'site-a',
			'user_id'            => '42',
			'turn_id'            => 'abc',
			'outcome'            => 'generated',
			'question'           => 'What?',
			'llm_answer'         => 'Because.',
			'delivered'          => 'Because.',
			'guard_present'      => '1',
			'input_verdict'      => null,
			'output_verdict'     => 'blocked',
			'other_plugin_reply' => '0',
			'recall_count'       => '3',
			'recall_top_score'   => '0.82',
		);
	}

	/**
	 * Columns are converted to PHP types.
	 *
	 * @return void
	 */
	public function test_types_are_converted(): void {
		$row = Interaction::from_row( self::row() );

		$this->assertSame( 12, $row->id );
		$this->assertSame( 1500, $row->duration_ms );
		$this->assertSame( '42', $row->user_id );
		$this->assertTrue( $row->guard_present );
		$this->assertFalse( $row->other_plugin_reply );
		$this->assertSame( 3, $row->recall_count );
		$this->assertSame( 0.82, $row->recall_top_score );
		$this->assertSame( 'blocked', $row->output_verdict );
	}

	/**
	 * NULL stays NULL and is not confused with zero, false or the empty string.
	 *
	 * @return void
	 */
	public function test_null_is_preserved(): void {
		$row = Interaction::from_row(
			array(
				'id'                 => '1',
				'ts'                 => '2026-10-02 12:30:45.123',
				'duration_ms'        => null,
				'turn_id'            => null,
				'question'           => null,
				'llm_answer'         => null,
				'delivered'          => null,
				'guard_present'      => '0',
				'input_verdict'      => null,
				'output_verdict'     => null,
				'other_plugin_reply' => null,
				'recall_count'       => null,
				'recall_top_score'   => null,
			)
		);

		$this->assertNull( $row->duration_ms );
		$this->assertNull( $row->turn_id );
		$this->assertNull( $row->question );
		$this->assertNull( $row->llm_answer );
		$this->assertNull( $row->delivered );
		$this->assertFalse( $row->guard_present );
		$this->assertNull( $row->input_verdict );
		$this->assertNull( $row->output_verdict );
		$this->assertNull( $row->other_plugin_reply );
		$this->assertNull( $row->recall_count );
		$this->assertNull( $row->recall_top_score );
	}

	/**
	 * Zero and the empty string are explicit values, different from NULL.
	 *
	 * @return void
	 */
	public function test_explicit_empty_values_are_kept(): void {
		$row = Interaction::from_row(
			array(
				'duration_ms'        => '0',
				'question'           => '',
				'input_verdict'      => '',
				'other_plugin_reply' => '0',
				'recall_count'       => '0',
				'recall_top_score'   => '0',
			)
		);

		$this->assertSame( 0, $row->duration_ms );
		$this->assertSame( '', $row->question );
		$this->assertSame( '', $row->input_verdict );
		$this->assertFalse( $row->other_plugin_reply );
		$this->assertSame( 0, $row->recall_count );
		$this->assertSame( 0.0, $row->recall_top_score );
	}

	/**
	 * Optional columns missing from the row read as NULL.
	 *
	 * @return void
	 */
	public function test_missing_optional_columns_are_null(): void {
		$row = Interaction::from_row( self::row() );

		$this->assertNull( $row->tools_used );
		$this->assertNull( $row->tool_input );
		$this->assertNull( $row->tool_output );
		$this->assertNull( $row->recall_sources );
	}

	/**
	 * Optional columns present in the row are mapped.
	 *
	 * @return void
	 */
	public function test_optional_columns_are_mapped(): void {
		$row = Interaction::from_row(
			self::row() + array(
				'tools_used'     => 'search',
				'tool_input'     => '{"q":"x"}',
				'tool_output'    => '[]',
				'recall_sources' => 'a,b',
			)
		);

		$this->assertSame( 'search', $row->tools_used );
		$this->assertSame( '{"q":"x"}', $row->tool_input );
		$this->assertSame( '[]', $row->tool_output );
		$this->assertSame( 'a,b', $row->recall_sources );
	}

	/**
	 * Long texts are cut to the preview length and flagged.
	 *
	 * @return void
	 */
	public function test_long_texts_are_cut_to_the_preview(): void {
		$row = Interaction::from_row(
			array(
				'question'  => str_repeat( 'a', 15 ),
				'delivered' => 'short',
			),
			10
		);

		$this->assertSame( str_repeat( 'a', 10 ), $row->question );
		$this->assertTrue( $row->question_truncated );
		$this->assertSame( 'short', $row->delivered );
		$this->assertFalse( $row->delivered_truncated );
	}

	/**
	 * A text exactly as long as the preview is not flagged as cut.
	 *
	 * @return void
	 */
	public function test_text_of_preview_length_is_not_cut(): void {
		$row = Interaction::from_row( array( 'question' => str_repeat( 'a', 10 ) ), 10 );

		$this->assertSame( str_repeat( 'a', 10 ), $row->question );
		$this->assertFalse( $row->question_truncated );
	}

	/**
	 * Cutting counts characters, not bytes, and never splits a character.
	 *
	 * @return void
	 */
	public function test_preview_counts_characters(): void {
		$row = Interaction::from_row( array( 'question' => 'àèìòùàèìòù' ), 5 );

		$this->assertSame( 'àèìòù', $row->question );
		$this->assertTrue( $row->question_truncated );
	}

	/**
	 * Without a preview length the full text is kept.
	 *
	 * @return void
	 */
	public function test_full_text_is_kept_without_preview(): void {
		$row = Interaction::from_row( array( 'question' => str_repeat( 'a', 5000 ) ) );

		$this->assertSame( 5000, strlen( (string) $row->question ) );
		$this->assertFalse( $row->question_truncated );
	}

	/**
	 * The timestamp is read as UTC.
	 *
	 * @return void
	 */
	public function test_timestamp_is_utc(): void {
		$date = Interaction::from_row( self::row() )->timestamp();

		$this->assertSame( 'UTC', $date->getTimezone()->getName() );
		$this->assertSame( '2026-10-02 12:30:45.123', $date->format( 'Y-m-d H:i:s.v' ) );
	}
}
