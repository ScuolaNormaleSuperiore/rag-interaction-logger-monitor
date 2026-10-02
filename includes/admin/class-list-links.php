<?php
/**
 * Links to the interactions list.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

use DateTimeImmutable;
use RILM\Repository\Filters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the URL of the interactions list for a set of filters and the period in use.
 *
 * Only non-sensitive filters reach the URL: the search text is never part of it.
 */
class List_Links {

	/**
	 * Names of the period arguments carried into every link.
	 */
	private const PERIOD_KEYS = array( 'period', 'from', 'to' );

	/**
	 * Returns the URL of the list, filtered, inside the period of the current page.
	 *
	 * @param Filters           $current Filters of the current page (only the period is kept).
	 * @param array             $input   Filter input of the link, as read by `Filters::from_array()`.
	 * @param DateTimeImmutable $now     Current time in the site time zone.
	 * @return string
	 */
	public static function url( Filters $current, array $input, DateTimeImmutable $now ): string {
		$period_args = array_intersect_key( $current->to_query_args(), array_flip( self::PERIOD_KEYS ) );
		$args        = Filters::from_array( $period_args + $input, $now )->to_query_args();

		return add_query_arg( array( 'page' => Menu::SLUG_INTERACTIONS ) + $args, admin_url( 'admin.php' ) );
	}
}
