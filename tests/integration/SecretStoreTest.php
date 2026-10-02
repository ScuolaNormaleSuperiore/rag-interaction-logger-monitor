<?php
/**
 * Integration tests for the encrypted password storage (WordPress provides libsodium through sodium_compat when the extension is missing).
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Tests\Integration;

use RILM\Config\Secret_Store;
use WP_UnitTestCase;

/**
 * Verifies encryption, tampering, key handling and the availability rules.
 */
class SecretStoreTest extends WP_UnitTestCase {

	/**
	 * Key material long and random enough to be accepted.
	 */
	private const KEY = 'a-unique-and-long-enough-secret-for-the-tests|another-unique-and-long-salt-value';

	/**
	 * Builds a store with usable keys.
	 *
	 * @param string $material Key material.
	 * @return Secret_Store
	 */
	private static function store( string $material = self::KEY ): Secret_Store {
		return new Secret_Store( $material );
	}

	/**
	 * A password survives a round trip.
	 *
	 * @return void
	 */
	public function test_round_trip(): void {
		$store     = self::store();
		$encrypted = $store->encrypt( 's3cr3t pass: with spaces & "quotes" é' );

		$this->assertNotNull( $encrypted );
		$this->assertSame( 's3cr3t pass: with spaces & "quotes" é', $store->decrypt( $encrypted ) );
	}

	/**
	 * Any password can be stored, whatever its characters or length.
	 *
	 * @dataProvider provide_passwords
	 *
	 * @param string $password Password.
	 * @return void
	 */
	public function test_any_password_round_trips( string $password ): void {
		$store = self::store();

		$this->assertSame( $password, $store->decrypt( (string) $store->encrypt( $password ) ) );
	}

	/**
	 * Provides unusual passwords.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_passwords(): array {
		return array(
			'one character'  => array( 'x' ),
			'spaces only'    => array( '   ' ),
			'leading space'  => array( ' p ' ),
			'unicode'        => array( 'пароль-密码-🔑' ),
			'quotes'         => array( "p'a\"ss\\word" ),
			'sql chars'      => array( "'; DROP TABLE x; --" ),
			'percent signs'  => array( '%s%d%%' ),
			'null byte'      => array( "pass\0word" ),
			'long'           => array( str_repeat( 'a', 255 ) ),
		);
	}

	/**
	 * The saved value is not the password, and does not contain it.
	 *
	 * @return void
	 */
	public function test_encrypted_value_does_not_reveal_the_password(): void {
		$encrypted = (string) self::store()->encrypt( 'my-very-secret-password' );

		$this->assertStringStartsWith( 'rilm-enc:v1:', $encrypted );
		$this->assertStringNotContainsString( 'my-very-secret-password', $encrypted );
		$this->assertStringNotContainsString( base64_encode( 'my-very-secret-password' ), $encrypted );
	}

	/**
	 * The same password gives a different value each time (random nonce), and both decrypt.
	 *
	 * @return void
	 */
	public function test_each_encryption_is_different(): void {
		$store = self::store();
		$one   = (string) $store->encrypt( 'same' );
		$two   = (string) $store->encrypt( 'same' );

		$this->assertNotSame( $one, $two );
		$this->assertSame( 'same', $store->decrypt( $one ) );
		$this->assertSame( 'same', $store->decrypt( $two ) );
	}

	/**
	 * Another key cannot read the value.
	 *
	 * @return void
	 */
	public function test_other_keys_cannot_decrypt(): void {
		$encrypted = (string) self::store()->encrypt( 'secret' );

		$this->assertNull( self::store( 'a-completely-different-and-long-enough-secret-value-123' )->decrypt( $encrypted ) );
	}

	/**
	 * A damaged or altered value is rejected, not decrypted to something else.
	 *
	 * @return void
	 */
	public function test_tampering_is_detected(): void {
		$store     = self::store();
		$encrypted = (string) $store->encrypt( 'secret' );
		$raw       = base64_decode( substr( $encrypted, strlen( 'rilm-enc:v1:' ) ), true );

		// Flip one bit in the middle of the ciphertext.
		$altered = $raw;
		$middle  = intdiv( strlen( $altered ), 2 );
		$altered[ $middle ] = chr( ord( $altered[ $middle ] ) ^ 1 );

		$this->assertNull( $store->decrypt( 'rilm-enc:v1:' . base64_encode( $altered ) ) );
		$this->assertNull( $store->decrypt( substr( $encrypted, 0, -4 ) ) );
	}

	/**
	 * Values that are not ours are not decrypted.
	 *
	 * @dataProvider provide_foreign_values
	 *
	 * @param string $value Stored value.
	 * @return void
	 */
	public function test_foreign_values_are_rejected( string $value ): void {
		$this->assertNull( self::store()->decrypt( $value ) );
		$this->assertFalse( self::store()->is_encrypted( $value ) );
	}

	/**
	 * Provides values that were never produced by the store.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_foreign_values(): array {
		return array(
			'plain password'   => array( 'hunter2' ),
			'empty'            => array( '' ),
			'prefix only'      => array( 'rilm-enc:v1:' ),
			'bad base64'       => array( 'rilm-enc:v1:!!!not-base64!!!' ),
			'too short'        => array( 'rilm-enc:v1:' . 'QUJD' ),
			'other version'    => array( 'rilm-enc:v2:AAAA' ),
			'similar prefix'   => array( 'rilm-enc-v1:AAAA' ),
		);
	}

	/**
	 * A value the store produced is recognised, so it is not encrypted twice.
	 *
	 * @return void
	 */
	public function test_encrypted_values_are_recognised(): void {
		$store = self::store();

		$this->assertTrue( $store->is_encrypted( (string) $store->encrypt( 'x' ) ) );
	}

	/**
	 * Someone typing something that looks like an encrypted value is not mistaken for one.
	 *
	 * @return void
	 */
	public function test_a_typed_lookalike_is_not_taken_for_encrypted(): void {
		$this->assertFalse( self::store()->is_encrypted( 'rilm-enc:v1:' . base64_encode( str_repeat( 'A', 60 ) ) ) );
	}

	/**
	 * The store is available only with a long, non-sample key.
	 *
	 * @dataProvider provide_availability
	 *
	 * @param string|null $material  Key material.
	 * @param bool        $available Expected availability.
	 * @return void
	 */
	public function test_availability( ?string $material, bool $available ): void {
		$this->assertSame( $available, ( new Secret_Store( $material ) )->is_available() );
	}

	/**
	 * Provides key materials and whether they are acceptable.
	 *
	 * @return array<string, array{string|null, bool}>
	 */
	public static function provide_availability(): array {
		return array(
			'usable keys'            => array( self::KEY, true ),
			'no keys'                => array( null, false ),
			'empty'                  => array( '', false ),
			'too short'              => array( 'short-key', false ),
			'just below the minimum' => array( str_repeat( 'k', 31 ), false ),
			'at the minimum'         => array( str_repeat( 'k', 32 ), true ),
			'sample key both parts'  => array( 'put your unique phrase here|put your unique phrase here', false ),
			'sample key one part'    => array( 'put your unique phrase here|a-real-and-long-enough-unique-salt-value-123456', false ),
			'sample key other case'  => array( 'PUT YOUR UNIQUE PHRASE HERE|PUT YOUR UNIQUE PHRASE HERE', false ),
		);
	}

	/**
	 * Without usable keys nothing is encrypted and nothing can be decrypted.
	 *
	 * @return void
	 */
	public function test_unavailable_store_does_not_encrypt_or_decrypt(): void {
		$weak = new Secret_Store( 'put your unique phrase here|put your unique phrase here' );

		$this->assertNull( $weak->encrypt( 'secret' ) );
		$this->assertNull( $weak->decrypt( (string) self::store()->encrypt( 'secret' ) ) );
		$this->assertNull( ( new Secret_Store() )->encrypt( 'secret' ) );
	}

	/**
	 * Dumping the store reveals neither the key material nor anything encrypted.
	 *
	 * @return void
	 */
	public function test_dump_does_not_reveal_the_key(): void {
		ob_start();
		var_dump( self::store() );
		$dump = ob_get_clean();

		$this->assertStringNotContainsString( 'a-unique-and-long-enough-secret', $dump );
		$this->assertStringNotContainsString( 'another-unique', $dump );
	}
}
