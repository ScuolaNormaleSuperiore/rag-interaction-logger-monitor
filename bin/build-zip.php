<?php
/**
 * Builds the distribution package: a zip with the plugin files that `.distignore` does not exclude.
 *
 * Usage: php bin/build-zip.php   (or: composer build)
 * Output: dist/rag-interaction-logger-monitor-<version>.zip, with the plugin folder at its top.
 *
 * Not part of the package: this script is excluded by `.distignore`.
 *
 * @package RagInteractionLoggerMonitor
 */

// phpcs:disable WordPress -- Command-line build script, never loaded by WordPress.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

const SLUG = 'rag-interaction-logger-monitor';

$root = dirname( __DIR__ );

if ( ! class_exists( ZipArchive::class ) ) {
	fwrite( STDERR, "The PHP zip extension is required.\n" );
	exit( 1 );
}

$header = (string) file_get_contents( $root . '/' . SLUG . '.php' );

if ( 1 !== preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $header, $version ) ) {
	fwrite( STDERR, "Version header not found.\n" );
	exit( 1 );
}

$patterns = array();

foreach ( file( $root . '/.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
	$line = trim( $line );

	if ( '' !== $line && '#' !== $line[0] ) {
		$patterns[] = $line;
	}
}

/**
 * Tells whether a path is excluded by one of the patterns.
 *
 * @param string   $path     Path relative to the plugin root, with forward slashes.
 * @param bool     $is_dir   Whether the path is a folder.
 * @param string[] $patterns Patterns of `.distignore`.
 * @return bool
 */
function is_ignored( string $path, bool $is_dir, array $patterns ): bool {
	foreach ( $patterns as $pattern ) {
		$dirs_only = '/' === substr( $pattern, -1 );
		$anchored  = '/' === $pattern[0];
		$glob      = trim( $pattern, '/' );

		if ( $dirs_only && ! $is_dir ) {
			continue;
		}

		$subject = $anchored ? $path : basename( $path );

		if ( fnmatch( $glob, $subject ) ) {
			return true;
		}
	}

	return false;
}

$files    = array();
$iterator = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		static function ( SplFileInfo $item ) use ( $root, $patterns ): bool {
			$path = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $root ) + 1 ) );

			return ! is_ignored( $path, $item->isDir(), $patterns );
		}
	),
	RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ( $iterator as $item ) {
	if ( $item->isFile() ) {
		$files[] = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $root ) + 1 ) );
	}
}

sort( $files );

if ( ! is_dir( $root . '/dist' ) ) {
	mkdir( $root . '/dist' );
}

$target = $root . '/dist/' . SLUG . '-' . $version[1] . '.zip';

if ( file_exists( $target ) ) {
	unlink( $target );
}

$zip = new ZipArchive();

if ( true !== $zip->open( $target, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Cannot create {$target}.\n" );
	exit( 1 );
}

foreach ( $files as $file ) {
	$zip->addFile( $root . '/' . $file, SLUG . '/' . $file );
}

$zip->close();

echo count( $files ) . ' files written to ' . $target . "\n";
foreach ( $files as $file ) {
	echo '  ' . $file . "\n";
}
