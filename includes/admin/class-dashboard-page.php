<?php
/**
 * Dashboard page.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard screen. Placeholder until the dashboard phase.
 */
class Dashboard_Page {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		Access::require_admin();
		?>
		<div class="wrap rilm-wrap">
			<h1><?php esc_html_e( 'Dashboard', 'rag-interaction-logger-monitor' ); ?></h1>
			<p><?php esc_html_e( 'This page is not available yet.', 'rag-interaction-logger-monitor' ); ?></p>
		</div>
		<?php
	}
}
