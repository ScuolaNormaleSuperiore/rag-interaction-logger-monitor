<?php
/**
 * Unit smoke test for the test environment.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that the unit test bootstrap works.
 */
class EnvironmentTest extends TestCase {

	/**
	 * Ensure the unit bootstrap flags the test run and the PHP version is supported.
	 *
	 * @return void
	 */
	public function test_unit_bootstrap_is_loaded() {
		$this->assertTrue( defined( 'RILM_RUNNING_TESTS' ) );
		$this->assertTrue( version_compare( PHP_VERSION, '8.3', '>=' ) );
	}
}
