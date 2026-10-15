<?php
/**
 * Encryption for stored secrets.
 *
 * The threat this addresses is narrow and real: database dumps travel. A
 * backup on a laptop, a copy handed to a contractor, or a leaked export should
 * not also hand over the mail credentials that can send as your domain.
 *
 * It is not protection against someone who already has the file system -- with
 * wp-config.php in hand the key is derivable. For that case, put the secret in
 * wp-config.php as a constant instead and leave the database field empty.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Symmetric encryption for settings values.
 */
class Mailhouse_Crypto {

	/**
	 * Marker prefixing every ciphertext, so plaintext left over from an
	 * import is still readable.
	 */
	const PREFIX = 'mh1:';

	/**
	 * Encrypt a value.
	 *
	 * @param string $value Plaintext.
	 * @return string Ciphertext, or the value unchanged when empty.
	 */
	public static function encrypt( $value ) {
		$value = (string) $value;

		if ( '' === $value || self::is_encrypted( $value ) ) {
			return $value;
		}

		$key = self::key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $value, $nonce, substr( $key, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );

			return self::PREFIX . 's.' . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			// Separate keys for encryption and authentication, derived from
			// the one master key. Values written by 1.0.0 (`o.`, one key for
			// both) still decrypt below.
			$iv     = random_bytes( 16 );
			$cipher = openssl_encrypt( $value, 'aes-256-cbc', self::subkey( $key, 'enc' ), OPENSSL_RAW_DATA, $iv );
			$mac    = hash_hmac( 'sha256', $iv . $cipher, self::subkey( $key, 'mac' ), true );

			return self::PREFIX . 'p.' . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		// No crypto extension available. Storing plaintext is the honest
		// outcome; the settings screen says so rather than pretending.
		return $value;
	}

	/**
	 * Decrypt a value.
	 *
	 * @param string $value Ciphertext, or plaintext left from an import.
	 * @return string
	 */
	public static function decrypt( $value ) {
		$value = (string) $value;

		if ( ! self::is_encrypted( $value ) ) {
			return $value;
		}

		$payload = substr( $value, strlen( self::PREFIX ) );
		$scheme  = substr( $payload, 0, 2 );
		$binary  = base64_decode( substr( $payload, 2 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $binary ) {
			return '';
		}

		$key = self::key();

		if ( 's.' === $scheme && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$nonce  = substr( $binary, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $binary, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, substr( $key, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );

			return false === $plain ? '' : $plain;
		}

		if ( ( 'o.' === $scheme || 'p.' === $scheme ) && function_exists( 'openssl_decrypt' ) ) {
			$iv      = substr( $binary, 0, 16 );
			$mac     = substr( $binary, 16, 32 );
			$cipher  = substr( $binary, 48 );
			$enc_key = 'p.' === $scheme ? self::subkey( $key, 'enc' ) : $key;
			$mac_key = 'p.' === $scheme ? self::subkey( $key, 'mac' ) : $key;

			if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, $mac_key, true ), $mac ) ) {
				return '';
			}

			$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv );

			return false === $plain ? '' : $plain;
		}

		return '';
	}

	/**
	 * Whether a stored value is one of ours.
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		return 0 === strpos( (string) $value, self::PREFIX );
	}

	/**
	 * Whether any encryption is available on this host.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'sodium_crypto_secretbox' ) || function_exists( 'openssl_encrypt' );
	}

	/**
	 * A purpose-specific key derived from the master key.
	 *
	 * @param string $key     Master key.
	 * @param string $purpose `enc` or `mac`.
	 * @return string
	 */
	protected static function subkey( $key, $purpose ) {
		return hash_hmac( 'sha256', 'mailhouse-' . $purpose, $key, true );
	}

	/**
	 * Whether the key lives in the database next to what it protects.
	 *
	 * Only on a site with no salts in wp-config.php and no
	 * MAILHOUSE_ENCRYPTION_KEY. A database dump then carries both, so the
	 * settings screen warns about it.
	 *
	 * @return bool
	 */
	public static function key_in_database() {
		if ( defined( 'MAILHOUSE_ENCRYPTION_KEY' ) && MAILHOUSE_ENCRYPTION_KEY ) {
			return false;
		}

		return ! ( defined( 'AUTH_KEY' ) && AUTH_KEY && 'put your unique phrase here' !== AUTH_KEY );
	}

	/**
	 * The 32-byte encryption key.
	 *
	 * Derived from the site's own salts, so it changes if the salts are
	 * rotated -- at which point the stored secrets have to be re-entered.
	 * Define MAILHOUSE_ENCRYPTION_KEY to pin it to something stable instead.
	 *
	 * @return string
	 */
	protected static function key() {
		if ( defined( 'MAILHOUSE_ENCRYPTION_KEY' ) && MAILHOUSE_ENCRYPTION_KEY ) {
			$material = MAILHOUSE_ENCRYPTION_KEY;
		} elseif ( defined( 'AUTH_KEY' ) && AUTH_KEY && 'put your unique phrase here' !== AUTH_KEY ) {
			$material = AUTH_KEY . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
		} else {
			// A site with no salts still needs a key. It goes in its own
			// option so a settings export does not carry it along.
			$material = get_option( 'mailhouse_key' );

			if ( ! $material ) {
				$material = mailhouse_token( 32 );
				update_option( 'mailhouse_key', $material, false );
			}
		}

		return hash( 'sha256', 'mailhouse|' . $material, true );
	}
}
