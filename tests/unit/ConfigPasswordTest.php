<?php
/**
 * Unit tests for how the configuration resolves the database password.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RILM\Config\Config;

/**
 * Verifies the precedence between the constant and the saved password, and the lazy read.
 */
class ConfigPasswordTest extends TestCase {

	/**
	 * Options that make everything but the password complete.
	 *
	 * @return array
	 */
	private static function options(): array {
		return array(
			'host' => 'db.example.test',
			'user' => 'reader',
		);
	}

	/**
	 * A saved password is used when no constant is defined.
	 *
	 * @return void
	 */
	public function test_saved_password_is_used(): void {
		$config = new Config( self::options(), array(), 'saved-secret' );

		$this->assertSame( 'saved-secret', $config->password() );
		$this->assertSame( Config::SOURCE_SAVED, $config->password_source() );
		$this->assertSame( Config::STATUS_OK, $config->status() );
	}

	/**
	 * The constant wins over a saved password.
	 *
	 * @return void
	 */
	public function test_constant_wins_over_the_saved_password(): void {
		$config = new Config( self::options(), array( 'password' => 'constant-secret' ), 'saved-secret' );

		$this->assertSame( 'constant-secret', $config->password() );
		$this->assertSame( Config::SOURCE_CONSTANT, $config->password_source() );
	}

	/**
	 * With neither, the password is missing and the configuration is not complete.
	 *
	 * @dataProvider provide_no_saved_password
	 *
	 * @param mixed $saved Saved password value.
	 * @return void
	 */
	public function test_missing_password( $saved ): void {
		$config = new Config( self::options(), array(), $saved );

		$this->assertSame( '', $config->password() );
		$this->assertSame( Config::SOURCE_MISSING, $config->password_source() );
		$this->assertSame( Config::STATUS_NOT_CONFIGURED, $config->status() );
		$this->assertSame( Config::STATUS_NOT_CONFIGURED, $config->problem_fields()['password'] );
	}

	/**
	 * Provides saved values that count as "no password".
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function provide_no_saved_password(): array {
		return array(
			'nothing'        => array( null ),
			'empty text'     => array( '' ),
			'unreadable'     => array(
				static function () {
					return null;
				},
			),
			'reader of none' => array(
				static function () {
					return '';
				},
			),
		);
	}

	/**
	 * A constant that is not usable is reported as invalid, even when a password is saved.
	 *
	 * @return void
	 */
	public function test_invalid_constant_is_not_replaced_by_the_saved_password(): void {
		$config = new Config( self::options(), array( 'password' => '' ), 'saved-secret' );

		$this->assertSame( '', $config->password() );
		$this->assertSame( Config::STATUS_INVALID, $config->status() );
		$this->assertSame( Config::STATUS_INVALID, $config->problem_fields()['password'] );
	}

	/**
	 * The saved password can be given as a reader that is called only when the password is needed.
	 *
	 * @return void
	 */
	public function test_saved_password_is_read_lazily_and_once(): void {
		$calls  = 0;
		$config = new Config(
			self::options(),
			array(),
			static function () use ( &$calls ) {
				++$calls;
				return 'lazy-secret';
			}
		);

		$config->host();
		$config->port();
		$config->user();
		$this->assertSame( 0, $calls, 'Other values do not read the password.' );

		$this->assertSame( 'lazy-secret', $config->password() );
		$config->password();
		$config->password_source();
		$config->status();
		$this->assertSame( 1, $calls, 'The password is read once.' );
	}

	/**
	 * A defined constant means the saved password is never read.
	 *
	 * @return void
	 */
	public function test_saved_password_is_not_read_when_a_constant_exists(): void {
		$calls  = 0;
		$config = new Config(
			self::options(),
			array( 'password' => 'constant-secret' ),
			static function () use ( &$calls ) {
				++$calls;
				return 'saved-secret';
			}
		);

		$config->password();
		$config->password_source();
		$config->status();

		$this->assertSame( 0, $calls );
	}

	/**
	 * A password never comes from the options array, whatever it contains.
	 *
	 * @return void
	 */
	public function test_password_is_never_read_from_the_options(): void {
		$config = new Config( self::options() + array( 'password' => 'from-option' ), array() );

		$this->assertSame( '', $config->password() );
		$this->assertSame( Config::SOURCE_MISSING, $config->password_source() );
	}

	/**
	 * Dumping the configuration reveals neither the constant nor the saved password.
	 *
	 * @return void
	 */
	public function test_dump_hides_the_saved_password(): void {
		$config = new Config( self::options(), array(), 'saved-secret-value' );
		$config->password();

		ob_start();
		var_dump( $config );
		$dump = ob_get_clean();

		$this->assertStringNotContainsString( 'saved-secret-value', $dump );
	}
}
