<?php
/**
 * Names and definitions of the indicators.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single source of the words used for the indicators and the anomalies.
 *
 * The Dashboard legend and the Anomalies page both read their definitions here, so the
 * two can never disagree. The definitions follow the README and the code of the
 * RAG Interaction Logger that writes the data.
 */
class Indicator_Texts {

	/**
	 * Turns answered by the language model.
	 */
	public const GENERATED = 'generated';

	/**
	 * Turns answered by another plugin before the language model.
	 */
	public const FAST_REPLY = 'fast_reply';

	/**
	 * Turns that started and never finished.
	 */
	public const INCOMPLETE = 'incomplete';

	/**
	 * Turns whose question was blocked.
	 */
	public const INPUT_BLOCKS = 'input_blocks';

	/**
	 * Turns whose answer was blocked.
	 */
	public const OUTPUT_BLOCKS = 'output_blocks';

	/**
	 * Turns that Guardrails did not handle.
	 */
	public const NO_GUARDRAILS = 'no_guardrails';

	/**
	 * Generated turns for which no source was recalled.
	 */
	public const ZERO_RECALL = 'zero_recall';

	/**
	 * Generated turns whose delivered answer differs from the generated one without an output verdict.
	 */
	public const ANSWERS_DIFFER = 'answers_differ';

	/**
	 * Turns in which at least one tool or form ran.
	 */
	public const TOOLS = 'tools';

	/**
	 * Turns in which tools or forms ran but the turn did not complete.
	 */
	public const TOOLS_INCOMPLETE = 'tools_incomplete';

	/**
	 * Turns in which tools or forms ran without Guardrails.
	 */
	public const TOOLS_NO_GUARDRAILS = 'tools_no_guardrails';

	/**
	 * Turns in which tools or forms ran and the output was blocked.
	 */
	public const TOOLS_OUTPUT_BLOCKED = 'tools_output_blocked';

	/**
	 * Share taken of all the turns of the period.
	 */
	private const BASIS_ALL = 'all';

	/**
	 * Share taken of the generated answers only.
	 */
	private const BASIS_GENERATED = 'generated';

	/**
	 * Returns the keys of the indicators of the Dashboard, in the order of its table and legend.
	 *
	 * @param bool $with_tools Whether the table has the `tools_used` column, so the tools row exists.
	 * @return string[]
	 */
	public static function dashboard_keys( bool $with_tools = false ): array {
		$keys = array(
			self::GENERATED,
			self::FAST_REPLY,
			self::INCOMPLETE,
			self::INPUT_BLOCKS,
			self::OUTPUT_BLOCKS,
			self::NO_GUARDRAILS,
			self::ZERO_RECALL,
		);

		if ( $with_tools ) {
			$keys[] = self::TOOLS;
		}

		return $keys;
	}

	/**
	 * Returns the name of an indicator, as shown in the Dashboard table and legend.
	 *
	 * @param string $key Indicator key.
	 * @return string Empty when the key is unknown.
	 */
	public static function label( string $key ): string {
		switch ( $key ) {
			case self::GENERATED:
				return __( 'Generated', 'rag-interaction-logger-monitor' );
			case self::FAST_REPLY:
				return __( 'Fast reply', 'rag-interaction-logger-monitor' );
			case self::INCOMPLETE:
				return __( 'Incomplete', 'rag-interaction-logger-monitor' );
			case self::INPUT_BLOCKS:
				return __( 'Input blocked', 'rag-interaction-logger-monitor' );
			case self::OUTPUT_BLOCKS:
				return __( 'Output blocked', 'rag-interaction-logger-monitor' );
			case self::NO_GUARDRAILS:
				return __( 'Guardrails did not handle the turn', 'rag-interaction-logger-monitor' );
			case self::ZERO_RECALL:
				return __( 'Generated without recalled sources', 'rag-interaction-logger-monitor' );
			case self::ANSWERS_DIFFER:
				return __( 'Answer changed without an output verdict', 'rag-interaction-logger-monitor' );
			case self::TOOLS:
				return __( 'Turns that used tools', 'rag-interaction-logger-monitor' );
			case self::TOOLS_INCOMPLETE:
				return __( 'Tools ran but the turn is incomplete', 'rag-interaction-logger-monitor' );
			case self::TOOLS_NO_GUARDRAILS:
				return __( 'Tools ran without Guardrails', 'rag-interaction-logger-monitor' );
			case self::TOOLS_OUTPUT_BLOCKED:
				return __( 'Tools ran and the output was blocked', 'rag-interaction-logger-monitor' );
			default:
				return '';
		}
	}

	/**
	 * Returns the name of an anomaly view, as shown on the Anomalies page.
	 *
	 * It is the name of the indicator, except where the view needs a more explicit one.
	 *
	 * @param string $key Indicator key.
	 * @return string Empty when the key is unknown.
	 */
	public static function anomaly_label( string $key ): string {
		return self::INCOMPLETE === $key
			? __( 'Incomplete interactions', 'rag-interaction-logger-monitor' )
			: self::label( $key );
	}

	/**
	 * Returns what an indicator counts.
	 *
	 * @param string $key Indicator key.
	 * @return string Empty when the key is unknown.
	 */
	public static function description( string $key ): string {
		switch ( $key ) {
			case self::GENERATED:
				return __( 'The language model produced the answer.', 'rag-interaction-logger-monitor' );
			case self::FAST_REPLY:
				return __( 'Another plugin answered the question before the language model was asked.', 'rag-interaction-logger-monitor' );
			case self::INCOMPLETE:
				return __( 'The turn started and never finished.', 'rag-interaction-logger-monitor' );
			case self::INPUT_BLOCKS:
				return __( 'Guardrails recorded a verdict on the question: a block was recorded.', 'rag-interaction-logger-monitor' );
			case self::OUTPUT_BLOCKS:
				return __( 'Guardrails recorded a verdict on the answer: a block was recorded.', 'rag-interaction-logger-monitor' );
			case self::NO_GUARDRAILS:
				return __( 'The rag-guardrails plugin left no mark on the turn: it was not installed or not active, or it did not handle that turn. In these turns the verdicts are always empty, so they cannot show blocks.', 'rag-interaction-logger-monitor' );
			case self::ZERO_RECALL:
				return __( 'A generated answer for which no source was recalled.', 'rag-interaction-logger-monitor' );
			case self::ANSWERS_DIFFER:
				return __( 'The delivered answer differs from the generated one, but no output verdict explains it.', 'rag-interaction-logger-monitor' );
			case self::TOOLS:
				return __( 'At least one tool ran during the turn; here a tool also means a form. A turn that used several is counted once in this row and once for each of them in the table of tools and forms, so the counts of that table can add up to more than this row.', 'rag-interaction-logger-monitor' );
			case self::TOOLS_INCOMPLETE:
				return __( 'At least one tool or form ran, but the turn started and never finished.', 'rag-interaction-logger-monitor' );
			case self::TOOLS_NO_GUARDRAILS:
				return __( 'At least one tool or form ran in a turn Guardrails did not handle. The input and the results of tools are not checked by any guard, so these turns had no check at all.', 'rag-interaction-logger-monitor' );
			case self::TOOLS_OUTPUT_BLOCKED:
				return __( 'At least one tool or form ran and Guardrails recorded a verdict on the answer: a block was recorded.', 'rag-interaction-logger-monitor' );
			default:
				return '';
		}
	}

	/**
	 * Returns the short phrase that completes a percentage, for example "of all turns".
	 *
	 * @param string $key Indicator key.
	 * @return string
	 */
	public static function basis_phrase( string $key ): string {
		return self::BASIS_GENERATED === self::basis( $key )
			? __( 'of generated answers', 'rag-interaction-logger-monitor' )
			: __( 'of all turns', 'rag-interaction-logger-monitor' );
	}

	/**
	 * Returns a full sentence that tells what the share of an indicator is taken of.
	 *
	 * @param string $key Indicator key.
	 * @return string
	 */
	public static function basis_sentence( string $key ): string {
		return self::BASIS_GENERATED === self::basis( $key )
			? __( 'The share is taken of the generated answers.', 'rag-interaction-logger-monitor' )
			: __( 'The share is taken of all the turns of the period.', 'rag-interaction-logger-monitor' );
	}

	/**
	 * Returns the whole a share is taken of.
	 *
	 * @param string $key Indicator key.
	 * @return string BASIS_ALL or BASIS_GENERATED.
	 */
	private static function basis( string $key ): string {
		return self::ZERO_RECALL === $key ? self::BASIS_GENERATED : self::BASIS_ALL;
	}
}
