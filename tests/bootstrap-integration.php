<?php
/**
 * Integration tests bootstrap.
 *
 * Boots the WordPress test framework (wordpress-develop) and loads the plugin.
 *
 * @package RagInteractionLoggerMonitor
 */

if ( ! defined( 'RILM_RUNNING_TESTS' ) ) {
	define( 'RILM_RUNNING_TESTS', true );
}

$rilm_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $rilm_autoload ) ) {
	die( "Composer autoloader not found. Run 'composer install' in the plugin root.\n" );
}

require_once $rilm_autoload;

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

// Get the WordPress tests framework directory.
$rilm_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $rilm_tests_dir ) {
	$rilm_tests_dir = 'C:/WordpressDEV/wordpress-develop/tests/phpunit';
}

// Verify the path exists.
if ( ! file_exists( $rilm_tests_dir . '/includes/functions.php' ) ) {
	die( "WordPress test library not found at: {$rilm_tests_dir}\n" );
}

// Give access to tests_add_filter() function.
require_once $rilm_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 *
 * @return void
 */
function rilm_manually_load_plugin() {
	$plugin_main_file = dirname( __DIR__ ) . '/rag-interaction-logger-monitor.php';

	if ( ! file_exists( $plugin_main_file ) ) {
		die( "Plugin bootstrap not found at: {$plugin_main_file}\n" );
	}

	require $plugin_main_file;

	if ( ! defined( 'RILM_TEST_PLUGIN_LOADED' ) ) {
		define( 'RILM_TEST_PLUGIN_LOADED', true );
	}
}

// Load the plugin before WordPress boots fully.
tests_add_filter( 'muplugins_loaded', 'rilm_manually_load_plugin' );

// Start up the WordPress testing environment.
require $rilm_tests_dir . '/includes/bootstrap.php';
