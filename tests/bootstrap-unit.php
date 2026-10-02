<?php
/**
 * Unit tests bootstrap.
 *
 * Boots the Composer autoloader (test tooling) and the plugin autoloader: no WordPress, no database.
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

// Plugin classes are loaded by the plugin's own autoloader, as in production.
require_once dirname( __DIR__ ) . '/includes/class-autoloader.php';

( new \RILM\Autoloader( dirname( __DIR__ ) . '/includes' ) )->register();
