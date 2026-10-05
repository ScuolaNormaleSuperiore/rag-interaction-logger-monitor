<?php
/**
 * Unit tests for the translation catalogs.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit;

use Gettext\Translations;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the catalog follows the sources and that the Italian one is complete and consistent.
 */
class I18nCatalogTest extends TestCase {

	/**
	 * Text domain of the plugin.
	 */
	private const DOMAIN = 'rag-interaction-logger-monitor';

	/**
	 * Plugin root.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Loads a catalog.
	 *
	 * @param string $file File name inside `languages/`.
	 * @return Translations
	 */
	private static function catalog( string $file ): Translations {
		return Translations::fromPoFile( self::root() . '/languages/' . $file );
	}

	/**
	 * The catalog domain is the one of the plugin header.
	 *
	 * @return void
	 */
	public function test_pot_domain_matches_the_plugin_header(): void {
		$header = (string) file_get_contents( self::root() . '/rag-interaction-logger-monitor.php' );

		$this->assertSame( 1, preg_match( '/^ \* Text Domain:\s*(\S+)/m', $header, $text ) );
		$this->assertSame( 1, preg_match( '/^ \* Domain Path:\s*(\S+)/m', $header, $path ) );
		$this->assertSame( self::DOMAIN, $text[1] );
		$this->assertSame( '/languages', $path[1] );
		$this->assertStringContainsString( 'X-Domain: ' . self::DOMAIN, (string) file_get_contents( self::root() . '/languages/rag-interaction-logger-monitor.pot' ) );
	}

	/**
	 * Every literal text passed to a translation function of the plugin is in the catalog.
	 *
	 * @return void
	 */
	public function test_every_source_string_is_in_the_pot(): void {
		$known = array();

		foreach ( self::catalog( 'rag-interaction-logger-monitor.pot' ) as $translation ) {
			$known[ $translation->getOriginal() ] = true;
		}

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root() . '/includes', \FilesystemIterator::SKIP_DOTS ) );
		$missing  = array();
		$scanned  = 0;

		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() );

			preg_match_all( "/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'" . self::DOMAIN . "'/", $source, $matches );

			$scanned += count( $matches[1] );

			foreach ( $matches[1] as $text ) {
				$text = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $text );

				if ( ! isset( $known[ $text ] ) ) {
					$missing[] = basename( $file->getPathname() ) . ': ' . $text;
				}
			}
		}

		$this->assertGreaterThan( 150, $scanned, 'The scan found too few strings: the pattern no longer matches the sources.' );
		$this->assertSame( array(), $missing, 'Regenerate the catalog: these strings are not in the .pot.' );
	}

	/**
	 * Every text of the catalog has an Italian translation, and plural texts have both forms.
	 *
	 * @return void
	 */
	public function test_italian_catalog_is_complete(): void {
		$pot = self::catalog( 'rag-interaction-logger-monitor.pot' );
		$it  = self::catalog( 'rag-interaction-logger-monitor-it_IT.po' );

		$this->assertSame( 'it_IT', $it->getLanguage() );

		$empty = array();

		foreach ( $pot as $original ) {
			if ( '' === $original->getOriginal() ) {
				continue;
			}

			$translated = $it->find( $original->getContext(), $original->getOriginal() );

			if ( false === $translated || '' === $translated->getTranslation() ) {
				$empty[] = $original->getOriginal();
				continue;
			}

			if ( '' !== $original->getPlural() && array( '' ) === array_unique( array_merge( array( '' ), $translated->getPluralTranslations() ) ) ) {
				$empty[] = $original->getOriginal() . ' (plural)';
			}
		}

		$this->assertSame( array(), $empty );
		$this->assertCount( count( $pot ), $it );
	}

	/**
	 * A translation keeps the placeholders of its source, in number and kind.
	 *
	 * @return void
	 */
	public function test_italian_placeholders_match_the_source(): void {
		$problems = array();

		foreach ( self::catalog( 'rag-interaction-logger-monitor-it_IT.po' ) as $translation ) {
			$source = $translation->getOriginal();

			if ( '' === $source ) {
				continue;
			}

			$texts = array_merge( array( $translation->getTranslation() ), $translation->getPluralTranslations() );

			foreach ( $texts as $text ) {
				if ( self::placeholders( $source ) !== self::placeholders( $text ) ) {
					$problems[] = $source;
				}
			}
		}

		$this->assertSame( array(), $problems );
	}

	/**
	 * The `.mo` file has the same texts as the `.po` file.
	 *
	 * @return void
	 */
	public function test_mo_file_matches_the_po_file(): void {
		$po = self::catalog( 'rag-interaction-logger-monitor-it_IT.po' );
		$mo = Translations::fromMoFile( self::root() . '/languages/rag-interaction-logger-monitor-it_IT.mo' );

		$this->assertCount( count( $po ), $mo );

		foreach ( $po as $translation ) {
			if ( '' === $translation->getOriginal() ) {
				continue;
			}

			$found = $mo->find( $translation->getContext(), $translation->getOriginal() );

			$this->assertNotFalse( $found, $translation->getOriginal() );
			$this->assertSame( $translation->getTranslation(), $found->getTranslation(), $translation->getOriginal() );
		}
	}

	/**
	 * Lists the placeholders of a text, sorted, so their order in the sentence may change.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function placeholders( string $text ): array {
		preg_match_all( '/%(?:\d+\$)?[sd]/', $text, $matches );

		$found = $matches[0];
		sort( $found );

		return $found;
	}
}
