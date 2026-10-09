<?php
/**
 * Plugin Name: RAG Interaction Logger Monitor
 * Plugin URI:  https://ict.sns.it
 * Description: Administrator-only monitor for RAG Interaction Logger data.
 * Version:     1.0.2
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Author:      ICT SNS
 * Author URI:  https://ict.sns.it
 * Text Domain: rag-interaction-logger-monitor
 * Domain Path: /languages
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package RagInteractionLoggerMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RILM_VERSION', '1.0.2' );
define( 'RILM_PLUGIN_FILE', __FILE__ );
define( 'RILM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once RILM_PLUGIN_DIR . 'includes/class-autoloader.php';

( new \RILM\Autoloader( RILM_PLUGIN_DIR . 'includes' ) )->register();

// The plugin has no frontend output: its hooks are only needed in the administration area.
if ( is_admin() ) {
	( new \RILM\Plugin() )->init();
}
