<?php
/**
 * Integration smoke test for the WordPress test environment.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Integration;

use WP_UnitTestCase;

/**
 * Verifies that WordPress boots and the plugin loads in the integration environment.
 */
class EnvironmentTest extends WP_UnitTestCase {

	/**
	 * Ensure WordPress is loaded and the plugin main file can be included.
	 *
	 * @return void
	 */
	public function test_wordpress_boots_and_plugin_loads() {
		$this->assertTrue( defined( 'RILM_RUNNING_TESTS' ) );
		$this->assertTrue( function_exists( 'add_action' ) );
		$this->assertTrue( defined( 'RILM_TEST_PLUGIN_LOADED' ) );
	}
}
