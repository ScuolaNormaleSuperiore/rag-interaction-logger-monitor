<?php
/**
 * Unit tests bootstrap.
 *
 * Boots only the Composer autoloader: no WordPress, no database.
 *
 * @package RagInteractionLoggerMonitor
 */

if ( ! defined( 'RILM_RUNNING_TESTS' ) ) {
	define( 'RILM_RUNNING_TESTS', true );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$rilm_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $rilm_autoload ) ) {
	die( "Composer autoloader not found. Run 'composer install' in the plugin root.\n" );
}

require_once $rilm_autoload;
