<?php
/**
 * Encrypted storage of the database password.
 *
 * @package RagInteractionLoggerMonitor
 */

namespace RILM\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts the database password before it is saved, and decrypts it when it is read.
 *
 * The password is encrypted with libsodium (`secretbox`: authenticated encryption)
 * under a key derived from `SECURE_AUTH_KEY` and `SECURE_AUTH_SALT` of `wp-config.php`.
 * The key is never saved in the database, so a database dump or backup alone does not
 * reveal the password. Someone who can read `wp-config.php` and the database can still
 * recover it: this protects against leaks of the database, not of the whole server.
 *
 * Without unique security keys the store is unavailable: it refuses to encrypt rather
 * than use a key anyone can guess.
 */
class Secret_Store {

	/**
	 * Name of the option holding the encrypted password.
	 */
	public const OPTION_NAME = 'rilm_db_password';

	/**
	 * Marker and version at the start of every encrypted value.
	 */
	private const PREFIX = 'rilm-enc:v1:';

	/**
	 * Sample value of the security keys in `wp-config-sample.php`.
	 */
	private const SAMPLE_KEY = 'put your unique phrase here';

	/**
	 * Shortest key material, in characters, that is accepted.
	 */
	private const MIN_KEY_LENGTH = 32;

	/**
	 * Key material derived from the security keys, or null when they are missing.
	 *
	 * @var string|null
	 */
	private $key_material;

	/**
	 * Constructor.
	 *
	 * @param string|null $key_material Secret the encryption key is derived from; null makes the store unavailable.
	 */
	public function __construct( ?string $key_material = null ) {
		$this->key_material = $key_material;
	}

	/**
	 * Builds the store from the security keys of `wp-config.php`.
	 *
	 * @return self
	 */
	public static function from_environment(): self {
		$parts = array();

		foreach ( array( 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT' ) as $constant ) {
			if ( ! defined( $constant ) || ! is_string( constant( $constant ) ) ) {
				return new self( null );
			}

			$parts[] = constant( $constant );
		}

		return new self( implode( '|', $parts ) );
	}

	/**
	 * Tells whether passwords can be encrypted here.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return null !== $this->key_material
			&& strlen( $this->key_material ) >= self::MIN_KEY_LENGTH
			&& false === stripos( $this->key_material, self::SAMPLE_KEY )
			&& function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'random_bytes' );
	}

	/**
	 * Encrypts a password.
	 *
	 * @param string $plain Password.
	 * @return string|null Value to save, or null when the store is unavailable.
	 */
	public function encrypt( string $plain ): ?string {
		if ( ! $this->is_available() ) {
			return null;
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		// Not obfuscation: the encrypted bytes are stored as text in an option.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->key() ) );
	}

	/**
	 * Decrypts a saved value.
	 *
	 * @param string $stored Value read from the option.
	 * @return string|null The password, or null when the value is not ours, is damaged,
	 *                     or was encrypted under other keys.
	 */
	public function decrypt( string $stored ): ?string {
		if ( ! $this->is_available() || 0 !== strpos( $stored, self::PREFIX ) ) {
			return null;
		}

		// Not obfuscation: it reads back the text form of the encrypted bytes (strict mode).
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$this->key()
		);

		return false === $plain ? null : $plain;
	}

	/**
	 * Tells whether a value is a password this store encrypted.
	 *
	 * Needed because WordPress can run the sanitize callback twice on the first save:
	 * the second time the value is already encrypted and must not be encrypted again.
	 *
	 * @param string $value Value to test.
	 * @return bool
	 */
	public function is_encrypted( string $value ): bool {
		return null !== $this->decrypt( $value );
	}

	/**
	 * Tells whether a password is saved, readable or not.
	 *
	 * @return bool
	 */
	public function has_saved(): bool {
		$stored = get_option( self::OPTION_NAME, '' );

		return is_string( $stored ) && '' !== $stored;
	}

	/**
	 * Reads the saved password.
	 *
	 * @return string|null The password, or null when none is saved or it cannot be decrypted.
	 */
	public function read(): ?string {
		$stored = get_option( self::OPTION_NAME, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		return $this->decrypt( $stored );
	}

	/**
	 * Derives the encryption key from the key material.
	 *
	 * @return string 32 raw bytes.
	 */
	private function key(): string {
		return hash_hmac( 'sha256', 'rilm-db-password-v1', (string) $this->key_material, true );
	}

	/**
	 * Prevents the key material from appearing in `var_dump()` and similar output.
	 *
	 * @return array
	 */
	public function __debugInfo() {
		return array( 'available' => $this->is_available() );
	}
}
