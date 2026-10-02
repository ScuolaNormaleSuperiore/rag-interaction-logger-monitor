<?php
/**
 * Uninstall routine.
 *
 * Removes the only data the plugin stores: the non-secret connection settings.
 * Nothing in the external log database is ever touched.
 *
 * @package RagInteractionLoggerMonitor
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The plugin classes are not loaded during uninstall: the option name is repeated on purpose.
delete_option( 'rilm_settings' );
