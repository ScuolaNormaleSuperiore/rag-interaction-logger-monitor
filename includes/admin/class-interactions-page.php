<?php
/**
 * Interactions list page.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interactions list screen. Placeholder until the interactions phase.
 */
class Interactions_Page {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		Access::require_admin();
		?>
		<div class="wrap rilm-wrap">
			<h1><?php esc_html_e( 'Interactions', 'rag-interaction-logger-monitor' ); ?></h1>
			<p><?php esc_html_e( 'This page is not available yet.', 'rag-interaction-logger-monitor' ); ?></p>
		</div>
		<?php
	}
}
