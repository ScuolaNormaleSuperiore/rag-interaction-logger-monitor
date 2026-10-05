<?php
/**
 * Integration tests for the shared names and definitions of the indicators.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Integration;

use RILM\Admin\Anomalies_Page;
use RILM\Admin\Indicator_Texts;
use RILM\Repository\Anomalies;
use WP_UnitTestCase;

/**
 * Verifies that every indicator and anomaly has a name and a definition, and that the two pages agree.
 */
class IndicatorTextsTest extends WP_UnitTestCase {

	/**
	 * Every indicator of the Dashboard has a name and a definition.
	 *
	 * @return void
	 */
	public function test_every_dashboard_indicator_is_defined(): void {
		$keys = Indicator_Texts::dashboard_keys();

		$this->assertSame(
			array( 'generated', 'fast_reply', 'incomplete', 'input_blocks', 'output_blocks', 'no_guardrails', 'zero_recall' ),
			$keys
		);

		foreach ( $keys as $key ) {
			$this->assertNotSame( '', Indicator_Texts::label( $key ), $key );
			$this->assertNotSame( '', Indicator_Texts::description( $key ), $key );
		}
	}

	/**
	 * The tools row exists only with the tools column, and has a name and a definition.
	 *
	 * @return void
	 */
	public function test_tools_indicator_needs_the_column(): void {
		$this->assertNotContains( 'tools', Indicator_Texts::dashboard_keys() );
		$keys = Indicator_Texts::dashboard_keys( true );
		$this->assertSame( 'tools', end( $keys ) );
		$this->assertCount( 8, Indicator_Texts::dashboard_keys( true ) );
		$this->assertSame( 'Turns that used tools', Indicator_Texts::label( 'tools' ) );
		$this->assertSame( 'of all turns', Indicator_Texts::basis_phrase( 'tools' ) );
	}

	/**
	 * Every anomaly, the ones about tools included, has a name and a definition.
	 *
	 * @return void
	 */
	public function test_every_anomaly_is_defined(): void {
		foreach ( array_keys( Anomalies::definitions( array( 'tools_used' ) ) ) as $key ) {
			$this->assertNotSame( '', Indicator_Texts::anomaly_label( $key ), $key );
			$this->assertNotSame( '', Indicator_Texts::description( $key ), $key );
		}
	}

	/**
	 * An unknown key has no text, instead of a made-up one.
	 *
	 * @return void
	 */
	public function test_unknown_key_has_no_text(): void {
		$this->assertSame( '', Indicator_Texts::label( 'nonsense' ) );
		$this->assertSame( '', Indicator_Texts::anomaly_label( 'nonsense' ) );
		$this->assertSame( '', Indicator_Texts::description( 'nonsense' ) );
	}

	/**
	 * The Anomalies page explains an indicator with exactly the words of the Dashboard legend.
	 *
	 * @return void
	 */
	public function test_anomalies_use_the_same_definitions(): void {
		foreach ( Anomalies_Page::descriptions( array( 'tools_used' ) ) as $key => $text ) {
			$this->assertSame( Indicator_Texts::description( $key ), $text['description'], $key );
			$this->assertSame( Indicator_Texts::anomaly_label( $key ), $text['label'], $key );
		}
	}

	/**
	 * An anomaly is named like its indicator, except incomplete turns, which need a more explicit name.
	 *
	 * @return void
	 */
	public function test_anomaly_names_follow_the_indicator_names(): void {
		foreach ( array( 'no_guardrails', 'input_blocks', 'output_blocks', 'zero_recall', 'answers_differ' ) as $key ) {
			$this->assertSame( Indicator_Texts::label( $key ), Indicator_Texts::anomaly_label( $key ), $key );
		}

		$this->assertSame( 'Incomplete', Indicator_Texts::label( 'incomplete' ) );
		$this->assertSame( 'Incomplete interactions', Indicator_Texts::anomaly_label( 'incomplete' ) );
	}

	/**
	 * Two keys never share a definition, and no label is repeated among the Dashboard indicators.
	 *
	 * @return void
	 */
	public function test_texts_are_not_repeated(): void {
		$descriptions = array();
		$labels       = array();

		foreach ( array_merge( Indicator_Texts::dashboard_keys( true ), array( 'answers_differ', 'tools_incomplete', 'tools_no_guardrails', 'tools_output_blocked' ) ) as $key ) {
			$descriptions[] = Indicator_Texts::description( $key );
		}

		foreach ( Indicator_Texts::dashboard_keys() as $key ) {
			$labels[] = Indicator_Texts::label( $key );
		}

		$this->assertSame( $descriptions, array_unique( $descriptions ) );
		$this->assertSame( $labels, array_unique( $labels ) );
	}

	/**
	 * What the logger documents is said in its own words.
	 *
	 * @return void
	 */
	public function test_definitions_follow_the_logger_documentation(): void {
		$this->assertSame( 'The language model produced the answer.', Indicator_Texts::description( 'generated' ) );
		$this->assertSame( 'Another plugin answered the question before the language model was asked.', Indicator_Texts::description( 'fast_reply' ) );
		$this->assertSame( 'The turn started and never finished.', Indicator_Texts::description( 'incomplete' ) );
	}

	/**
	 * Guardrails absent is described as "did not handle the turn", with the cases it covers and its effect on the verdicts.
	 *
	 * @return void
	 */
	public function test_guardrails_definition_covers_every_cause(): void {
		$description = Indicator_Texts::description( 'no_guardrails' );

		$this->assertSame( 'Guardrails did not handle the turn', Indicator_Texts::label( 'no_guardrails' ) );
		$this->assertStringContainsString( 'left no mark', $description );
		$this->assertStringContainsString( 'not installed or not active', $description );
		$this->assertStringContainsString( 'did not handle that turn', $description );
		$this->assertStringContainsString( 'verdicts are always empty', $description );
		$this->assertStringNotContainsString( 'did not run', Indicator_Texts::label( 'no_guardrails' ) );
	}

	/**
	 * The share is taken of all turns, except for recall, which is taken of the generated answers.
	 *
	 * @return void
	 */
	public function test_share_denominators(): void {
		foreach ( array( 'generated', 'fast_reply', 'incomplete', 'input_blocks', 'output_blocks', 'no_guardrails' ) as $key ) {
			$this->assertSame( 'of all turns', Indicator_Texts::basis_phrase( $key ), $key );
			$this->assertSame( 'The share is taken of all the turns of the period.', Indicator_Texts::basis_sentence( $key ), $key );
		}

		$this->assertSame( 'of generated answers', Indicator_Texts::basis_phrase( 'zero_recall' ) );
		$this->assertSame( 'The share is taken of the generated answers.', Indicator_Texts::basis_sentence( 'zero_recall' ) );
	}
}
