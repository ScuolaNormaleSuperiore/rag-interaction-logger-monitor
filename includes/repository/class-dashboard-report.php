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
	 * @param Period $period Period to report on.
	 * @return array<string, mixed>|null Null when the main query failed.
	 */
	public function build( Period $period ): ?array {
		$summary = $this->repository->summary( $period );

		if ( null === $summary ) {
			return null;
		}

		$total = $summary['total'];

		$report = array(
			'total'         => $total,
			'outcomes'      => array(
				'generated'  => $this->share( $summary['generated'], $total ),
				'fast_reply' => $this->share( $summary['fast_reply'], $total ),
				'incomplete' => $this->share( $summary['incomplete'], $total ),
			),
			'no_guardrails' => $this->share( $summary['no_guardrails'], $total ),
			'input_blocks'  => $this->share( $summary['input_blocks'], $total ) + array(
				'by_verdict' => $summary['input_blocks'] > 0 ? $this->repository->verdict_counts( 'input_verdict', $period ) : array(),
			),
			'output_blocks' => $this->share( $summary['output_blocks'], $total ) + array(
				'by_verdict' => $summary['output_blocks'] > 0 ? $this->repository->verdict_counts( 'output_verdict', $period ) : array(),
			),
			// Zero recall is a share of the generated answers, the only turns that use recall.
			'zero_recall'   => $this->share( $summary['zero_recall'], $summary['generated'] ) + array( 'generated' => $summary['generated'] ),
			'duration'      => array(
				'completed'  => $summary['completed'],
				'average_ms' => $summary['average_ms'],
				'median_ms'  => $this->repository->median_duration( $period, $summary['completed'] ),
			),
			'daily'         => array(),
		);

		$report['daily_failed'] = false;

		// No turns, no series: an empty period must not become a chart of zeros.
		if ( $total > 0 ) {
			$hourly = $this->repository->hourly_series( $period );

			$report['daily']        = Daily_Series::from_hourly( is_array( $hourly ) ? $hourly : array(), $period );
			$report['daily_failed'] = null === $hourly;
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
