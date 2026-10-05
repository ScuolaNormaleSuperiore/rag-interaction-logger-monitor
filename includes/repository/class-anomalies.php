<?php
/**
 * Predefined anomaly views.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The anomalies of the SPEC, each one a set of filters for the interactions list.
 *
 * The keys are internal constants: they are used as SQL aliases and as array keys,
 * and never come from a request.
 */
class Anomalies {

	/**
	 * Interactions that did not complete.
	 */
	public const INCOMPLETE = 'incomplete';

	/**
	 * Interactions Guardrails did not check.
	 */
	public const NO_GUARDRAILS = 'no_guardrails';

	/**
	 * Interactions whose input was blocked.
	 */
	public const INPUT_BLOCKS = 'input_blocks';

	/**
	 * Interactions whose output was blocked.
	 */
	public const OUTPUT_BLOCKS = 'output_blocks';

	/**
	 * Generated interactions whose delivered answer differs from the generated one without an output verdict.
	 */
	public const ANSWERS_DIFFER = 'answers_differ';

	/**
	 * Generated interactions that recalled nothing.
	 */
	public const ZERO_RECALL = 'zero_recall';

	/**
	 * Interactions where tools or forms ran but the turn did not complete.
	 */
	public const TOOLS_INCOMPLETE = 'tools_incomplete';

	/**
	 * Interactions where tools or forms ran without Guardrails, so nothing checked them.
	 */
	public const TOOLS_NO_GUARDRAILS = 'tools_no_guardrails';

	/**
	 * Interactions where tools or forms ran and the output was blocked.
	 */
	public const TOOLS_OUTPUT_BLOCKED = 'tools_output_blocked';

	/**
	 * Returns the filters of every anomaly, as input for `Filters::from_array()`.
	 *
	 * The period is not part of the definition: the page adds the chosen one. The three
	 * anomalies about tools exist only when the table has the `tools_used` column.
	 *
	 * @param string[] $optional_columns Optional columns present in the table.
	 * @return array<string, array<string, string>> Filter input keyed by anomaly key, in display order.
	 */
	public static function definitions( array $optional_columns = array() ): array {
		$definitions = array(
			self::INCOMPLETE     => array( 'outcome' => 'incomplete' ),
			self::NO_GUARDRAILS  => array( 'guard' => Filters::GUARD_ABSENT ),
			self::INPUT_BLOCKS   => array( 'input_verdict' => Filters::VERDICT_ANY ),
			self::OUTPUT_BLOCKS  => array( 'output_verdict' => Filters::VERDICT_ANY ),
			self::ANSWERS_DIFFER => array(
				'outcome'        => 'generated',
				'output_verdict' => Filters::VERDICT_NONE,
				'answers'        => 'differ',
			),
			self::ZERO_RECALL    => array(
				'outcome' => 'generated',
				'recall'  => 'empty',
			),
		);

		if ( in_array( 'tools_used', $optional_columns, true ) ) {
			$definitions[ self::TOOLS_INCOMPLETE ]     = array(
				'tools'   => Filters::TOOLS_YES,
				'outcome' => 'incomplete',
			);
			$definitions[ self::TOOLS_NO_GUARDRAILS ]  = array(
				'tools' => Filters::TOOLS_YES,
				'guard' => Filters::GUARD_ABSENT,
			);
			$definitions[ self::TOOLS_OUTPUT_BLOCKED ] = array(
				'tools'          => Filters::TOOLS_YES,
				'output_verdict' => Filters::VERDICT_ANY,
			);
		}

		return $definitions;
	}
}
