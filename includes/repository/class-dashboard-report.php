<?php
/**
 * Dashboard figures.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects every figure of the dashboard for a period.
 *
 * Shares are `null` when their denominator is zero: an empty period has no share,
 * which is different from a share of zero.
 */
class Dashboard_Report {

	/**
	 * Interactions source.
	 *
	 * @var Interaction_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param Interaction_Repository $repository Interactions source.
	 */
	public function __construct( Interaction_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Returns a share as a percentage, or null when the whole is zero.
	 *
	 * @param int $part  Part.
	 * @param int $whole Whole.
	 * @return float|null
	 */
	public static function percent( int $part, int $whole ): ?float {
		return $whole > 0 ? $part * 100 / $whole : null;
	}

	/**
	 * Builds the report of a period.
	 *
	 * @param Period $period          Period to report on.
	 * @param bool   $with_breakdowns Whether to query the median duration and the verdict and tool
	 *                                breakdowns; the Daily trend page shows none of them.
	 * @param bool   $with_daily      Whether to query the daily series; the Dashboard page does not show it.
	 * @return array<string, mixed>|null Null when the main query failed.
	 */
	public function build( Period $period, bool $with_breakdowns = true, bool $with_daily = true ): ?array {
		$summary = $this->repository->summary( $period );

		if ( null === $summary ) {
			return null;
		}

		$total   = $summary['total'];
		$covered = $total - $summary['no_guardrails'];

		$report = array(
			'total'              => $total,
			'last_ts'            => $summary['last_ts'],
			'outcomes'           => array(
				'generated'  => $this->share( $summary['generated'], $total ),
				'fast_reply' => $this->share( $summary['fast_reply'], $total ),
				'incomplete' => $this->share( $summary['incomplete'], $total ),
			),
			'no_guardrails'      => $this->share( $summary['no_guardrails'], $total ),
			// The turns Guardrails did handle: the complement of 'no_guardrails', since guard_present is 0 or 1.
			'guardrails_covered' => $this->share( $covered, $total ),
			'input_blocks'       => $this->share( $summary['input_blocks'], $total ) + array(
				'by_verdict'         => $with_breakdowns && $summary['input_blocks'] > 0 ? $this->repository->verdict_counts( 'input_verdict', $period ) : array(),
				'percent_of_covered' => self::percent( $summary['input_blocks'], $covered ),
			),
			'output_blocks'      => $this->share( $summary['output_blocks'], $total ) + array(
				'by_verdict'         => $with_breakdowns && $summary['output_blocks'] > 0 ? $this->repository->verdict_counts( 'output_verdict', $period ) : array(),
				'percent_of_covered' => self::percent( $summary['output_blocks'], $covered ),
			),
			// Zero recall is a share of the generated answers, the only turns that use recall.
			'zero_recall'        => $this->share( $summary['zero_recall'], $summary['generated'] ) + array( 'generated' => $summary['generated'] ),
			'duration'           => array(
				'completed'  => $summary['completed'],
				'average_ms' => $summary['average_ms'],
				'median_ms'  => $with_breakdowns ? $this->repository->median_duration( $period, $summary['completed'] ) : null,
			),
			'daily'              => array(),
		);

		// The tools figures exist only when the table has the column.
		if ( isset( $summary['tools'] ) ) {
			$by_name = $with_breakdowns && $summary['tools'] > 0 ? $this->repository->tool_counts( $period ) : null;

			$report['tools'] = $this->share( $summary['tools'], $total ) + array(
				'by_name'        => null === $by_name ? array() : $by_name['counts'],
				'by_name_cut'    => null !== $by_name && $by_name['truncated'],
				'by_name_failed' => $with_breakdowns && $summary['tools'] > 0 && null === $by_name,
			);
		}

		$report['daily_failed'] = false;

		// The Dashboard page never reads the granularity or the series: do not compute either for it.
		if ( $with_daily ) {
			$granularity                 = Period_Series::granularity_for( $period );
			$report['daily_granularity'] = $granularity;

			// No turns, no series: an empty period must not become a chart of zeros.
			if ( $total > 0 ) {
				$hourly = $this->repository->hourly_series( $period );

				$report['daily']        = Period_Series::from_hourly( is_array( $hourly ) ? $hourly : array(), $period, isset( $summary['tools'] ), $granularity );
				$report['daily_failed'] = null === $hourly;
			}
		}

		return $report;
	}

	/**
	 * Builds a count with its share.
	 *
	 * @param int $count Count.
	 * @param int $whole Whole the share is taken of.
	 * @return array{count: int, percent: float|null}
	 */
	private function share( int $count, int $whole ): array {
		return array(
			'count'   => $count,
			'percent' => self::percent( $count, $whole ),
		);
	}
}
