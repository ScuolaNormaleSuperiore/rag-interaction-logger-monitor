<?php
/**
 * Unit tests for the plugin autoloader.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RILM\Autoloader;

/**
 * Verifies how class names are mapped to WordPress style file names.
 */
class AutoloaderTest extends TestCase {

	/**
	 * Autoloader under test, rooted at a fake directory.
	 *
	 * @var Autoloader
	 */
	private $autoloader;

	/**
	 * Creates the autoloader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->autoloader = new Autoloader( '/plugin/includes' );
	}

	/**
	 * Class names map to class-*.php files in lowercase subdirectories.
	 *
	 * @dataProvider provide_mapped_classes
	 *
	 * @param string $class_name Class name.
	 * @param string $expected   Expected path.
	 * @return void
	 */
	public function test_class_name_maps_to_wordpress_file_name( string $class_name, string $expected ): void {
		$this->assertSame( $expected, $this->autoloader->path_for( $class_name ) );
	}

	/**
	 * Provides class names and their expected paths.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function provide_mapped_classes(): array {
		return array(
			'root class'                  => array( 'RILM\\Plugin', '/plugin/includes/class-plugin.php' ),
			'sub namespace'               => array( 'RILM\\Admin\\Menu', '/plugin/includes/admin/class-menu.php' ),
			'underscores become hyphens'  => array( 'RILM\\Admin\\Dashboard_Page', '/plugin/includes/admin/class-dashboard-page.php' ),
			'leading backslash is ignored' => array( '\\RILM\\Database\\Guarded_Wpdb', '/plugin/includes/database/class-guarded-wpdb.php' ),
		);
	}

	/**
	 * Classes of other namespaces are never handled.
	 *
	 * @return void
	 */
	public function test_foreign_namespaces_are_ignored(): void {
		$this->assertNull( $this->autoloader->path_for( 'Other\\Plugin' ) );
		$this->assertNull( $this->autoloader->path_for( 'RILMX\\Plugin' ) );
		$this->assertNull( $this->autoloader->path_for( 'wpdb' ) );
	}

	/**
	 * Malformed names cannot escape the base directory.
	 *
	 * @dataProvider provide_unsafe_names
	 *
	 * @param string $class_name Unsafe class name.
	 * @return void
	 */
	public function test_unsafe_names_are_rejected( string $class_name ): void {
		$this->assertNull( $this->autoloader->path_for( $class_name ) );
	}

	/**
	 * Provides malformed class names.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_unsafe_names(): array {
		return array(
			'dot segments'     => array( 'RILM\\..\\..\\secret' ),
			'slash'            => array( 'RILM\\Admin/Menu' ),
			'empty segment'    => array( 'RILM\\\\Menu' ),
			'no class name'    => array( 'RILM\\' ),
			'null byte'        => array( "RILM\\Plugin\0" ),
			'trailing slash'   => array( 'RILM\\Admin\\' ),
		);
	}

	/**
	 * A registered autoloader loads the real plugin classes.
	 *
	 * @return void
	 */
	public function test_registered_autoloader_loads_plugin_classes(): void {
		$this->assertTrue( class_exists( 'RILM\\Plugin' ) );
		$this->assertTrue( class_exists( 'RILM\\Admin\\Menu' ) );
		$this->assertTrue( class_exists( 'RILM\\Admin\\Dashboard_Page' ) );
	}
}
