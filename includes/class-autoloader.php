<?php
/**
 * Class autoloader.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads RILM classes following the WordPress file naming convention.
 *
 * `RILM\Admin\Dashboard_Page` is loaded from `admin/class-dashboard-page.php`
 * inside the base directory. Classes outside the `RILM\` namespace are ignored.
 */
class Autoloader {

	/**
	 * Namespace prefix handled by this autoloader.
	 *
	 * @var string
	 */
	private const PREFIX = 'RILM\\';

	/**
	 * Base directory holding the class files, with a trailing slash.
	 *
	 * @var string
	 */
	private $base_dir;

	/**
	 * Constructor.
	 *
	 * @param string $base_dir Absolute path of the directory holding the class files.
	 */
	public function __construct( string $base_dir ) {
		$this->base_dir = rtrim( $base_dir, '/\\' ) . '/';
	}

	/**
	 * Registers the autoloader with SPL.
	 *
	 * @return void
	 */
	public function register(): void {
		spl_autoload_register( array( $this, 'load' ) );
	}

	/**
	 * Loads the file defining a class, when it belongs to this plugin.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public function load( string $class_name ): void {
		// WordPress naming: class-*.php for classes, interface-*.php for interfaces, trait-*.php for traits.
		foreach ( array( 'class', 'interface', 'trait' ) as $kind ) {
			$path = $this->path_for( $class_name, $kind );

			if ( null !== $path && is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}

	/**
	 * Maps a fully qualified name to its file path.
	 *
	 * @param string $class_name Fully qualified class, interface or trait name.
	 * @param string $kind       File prefix: `class`, `interface` or `trait`.
	 * @return string|null Absolute file path, or null when the name is not handled.
	 */
	public function path_for( string $class_name, string $kind = 'class' ): ?string {
		$class_name = ltrim( $class_name, '\\' );

		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return null;
		}

		// Only identifier characters and namespace separators: blocks path traversal.
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\\\\]+$/', $class_name ) ) {
			return null;
		}

		$parts = explode( '\\', substr( $class_name, strlen( self::PREFIX ) ) );
		$class = array_pop( $parts );

		if ( '' === $class || in_array( '', $parts, true ) ) {
			return null;
		}

		$directories = '';

		foreach ( $parts as $part ) {
			$directories .= self::slugify( $part ) . '/';
		}

		return $this->base_dir . $directories . $kind . '-' . self::slugify( $class ) . '.php';
	}

	/**
	 * Converts a class name segment to a lowercase, hyphenated file name part.
	 *
	 * @param string $segment Class or namespace segment.
	 * @return string
	 */
	private static function slugify( string $segment ): string {
		return strtolower( str_replace( '_', '-', $segment ) );
	}
}
