<?php
/**
 * The connector protocol: what this site signs, and how.
 *
 * The centre computes every one of these values as well, and the two must
 * agree to the byte or no request would ever be accepted. Both are held to
 * the same worked examples: tests/fixtures/protocol-v1.json here is a copy
 * of the centre's packages/protocol/vectors/v1.json.
 *
 * Nothing in this file talks to the network or the database.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Canonical strings, signatures and the one spelling of a site address.
 */
class Mailhouse_Central_Protocol {

	/**
	 * The protocol version this connector speaks.
	 */
	const VERSION = 1;

	/**
	 * Header names.
	 */
	const HEADER_WEBSITE   = 'X-MH-Website';
	const HEADER_KEY       = 'X-MH-Key';
	const HEADER_TIMESTAMP = 'X-MH-Timestamp';
	const HEADER_NONCE     = 'X-MH-Nonce';
	const HEADER_SIGNATURE = 'X-MH-Signature';
	const HEADER_PROTOCOL  = 'X-MH-Protocol';

	/**
	 * Whether the signature functions exist. WordPress has bundled a pure
	 * PHP implementation since 5.2, so on a supported site this is true
	 * with or without the PHP extension.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'sodium_crypto_sign_detached' )
			&& function_exists( 'sodium_crypto_sign_keypair' )
			&& function_exists( 'sodium_crypto_sign_verify_detached' );
	}

	/**
	 * The query string in canonical form.
	 *
	 * Every name and value percent-encoded as RFC 3986 has it, the pairs
	 * sorted by encoded name and then encoded value, joined with `&`.
	 *
	 * @param array $pairs List of array( name, value ).
	 * @return string
	 */
	public static function canonical_query( array $pairs ) {
		$encoded = array();

		foreach ( $pairs as $pair ) {
			$encoded[] = array( rawurlencode( (string) $pair[0] ), rawurlencode( (string) $pair[1] ) );
		}

		usort(
			$encoded,
			static function ( $a, $b ) {
				$by_name = strcmp( $a[0], $b[0] );

				return 0 !== $by_name ? $by_name : strcmp( $a[1], $b[1] );
			}
		);

		$parts = array();

		foreach ( $encoded as $pair ) {
			$parts[] = $pair[0] . '=' . $pair[1];
		}

		return implode( '&', $parts );
	}

	/**
	 * The string a request's signature is made over.
	 *
	 * Eight lines joined by a line feed, with none after the last.
	 *
	 * @param string $method     HTTP method.
	 * @param string $path       Path, starting with a slash.
	 * @param array  $query      List of array( name, value ).
	 * @param string $timestamp  Unix seconds, as decimal text.
	 * @param string $nonce      32 hex characters.
	 * @param string $website_id The website's id at the centre.
	 * @param string $body       The body exactly as it will be sent.
	 * @return string
	 */
	public static function canonical_request( $method, $path, array $query, $timestamp, $nonce, $website_id, $body ) {
		return implode(
			"\n",
			array(
				'MH1',
				strtoupper( (string) $method ),
				(string) $path,
				self::canonical_query( $query ),
				(string) $timestamp,
				(string) $nonce,
				(string) $website_id,
				hash( 'sha256', (string) $body ),
			)
		);
	}

	/**
	 * The string signed to prove this site holds the key it is registering.
	 *
	 * @param string $token      Enrolment token.
	 * @param string $site_url   This site's address, exactly as it is sent.
	 * @param string $public_key The public key, base64, exactly as it is sent.
	 * @param string $key_id     The key's id.
	 * @return string
	 */
	public static function registration_message( $token, $site_url, $public_key, $key_id ) {
		return implode( "\n", array( 'MH1-REG', (string) $token, (string) $site_url, (string) $public_key, (string) $key_id ) );
	}

	/**
	 * The string the centre signs for a command.
	 *
	 * @param array $command Command envelope.
	 * @return string
	 */
	public static function command_message( array $command ) {
		return implode(
			"\n",
			array(
				'MH1-CMD',
				(string) $command['id'],
				(string) $command['website_id'],
				(string) $command['seq'],
				(string) $command['type'],
				(string) $command['issued_at'],
				(string) $command['expires_at'],
				hash( 'sha256', (string) $command['payload_json'] ),
			)
		);
	}

	/**
	 * Whether a command envelope was signed by one of the platform keys
	 * this site pinned when it registered.
	 *
	 * This checks the signature and nothing else. That the command is for
	 * this website, is next in sequence, has not expired and is of a type
	 * this site accepts are the caller's checks, made after this one.
	 *
	 * @param mixed $envelope      Command envelope, as decoded from JSON.
	 * @param array $platform_keys Pinned keys: list of array( key_id, public_key ).
	 * @return bool
	 */
	public static function verify_command( $envelope, array $platform_keys ) {
		if ( ! is_array( $envelope ) || ! self::available() ) {
			return false;
		}

		foreach ( array( 'id', 'website_id', 'seq', 'type', 'issued_at', 'expires_at', 'payload_json', 'key_id', 'signature' ) as $field ) {
			if ( ! isset( $envelope[ $field ] ) || ! is_scalar( $envelope[ $field ] ) ) {
				return false;
			}
		}

		$signature = self::decode( $envelope['signature'], SODIUM_CRYPTO_SIGN_BYTES );

		if ( null === $signature ) {
			return false;
		}

		foreach ( $platform_keys as $key ) {
			if ( ! is_array( $key ) || ! isset( $key['key_id'], $key['public_key'] ) || ! hash_equals( (string) $key['key_id'], (string) $envelope['key_id'] ) ) {
				continue;
			}

			$public = self::decode( $key['public_key'], SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES );

			if ( null === $public ) {
				return false;
			}

			try {
				return sodium_crypto_sign_verify_detached( $signature, self::command_message( $envelope ), $public );
			} catch ( Exception $e ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Strict base64: the one canonical spelling of exactly this many bytes.
	 *
	 * @param mixed $text  Text to decode.
	 * @param int   $bytes Expected length.
	 * @return string|null Raw bytes, or null.
	 */
	public static function decode( $text, $bytes ) {
		if ( ! is_string( $text ) ) {
			return null;
		}

		$raw = base64_decode( $text, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw || strlen( $raw ) !== $bytes || base64_encode( $raw ) !== $text ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			return null;
		}

		return $raw;
	}

	/**
	 * A new Ed25519 key pair.
	 *
	 * @return array{secret:string,public:string,key_id:string} Both keys base64.
	 */
	public static function generate_keypair() {
		$pair = sodium_crypto_sign_keypair();

		return self::describe_key( sodium_crypto_sign_secretkey( $pair ) );
	}

	/**
	 * The public key and key id that go with a secret key.
	 *
	 * @param string $secret Raw 64-byte secret key.
	 * @return array{secret:string,public:string,key_id:string}
	 */
	public static function describe_key( $secret ) {
		$public = sodium_crypto_sign_publickey_from_secretkey( $secret );

		return array(
			'secret' => base64_encode( $secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'public' => base64_encode( $public ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			// Made from the key itself, so a new key can never repeat an old
			// id. The centre refuses a registration that reuses one.
			'key_id' => 'k-' . substr( hash( 'sha256', $public ), 0, 16 ),
		);
	}

	/**
	 * Sign a message.
	 *
	 * @param string $message Message.
	 * @param string $secret  Secret key, base64.
	 * @return string Signature, base64; empty when the key is not a key.
	 */
	public static function sign( $message, $secret ) {
		$raw = self::decode( $secret, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES );

		if ( null === $raw ) {
			return '';
		}

		return base64_encode( sodium_crypto_sign_detached( (string) $message, $raw ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Whether a host name can only ever mean this machine.
	 *
	 * @param string $host Host, lower case.
	 * @return bool
	 */
	public static function is_loopback( $host ) {
		return 'localhost' === $host
			|| '.localhost' === substr( $host, -10 )
			|| '[::1]' === $host
			|| 1 === preg_match( '/^127(\.\d{1,3}){3}$/', $host );
	}

	/**
	 * The one spelling of a site address: scheme://host[:port][/path].
	 *
	 * Scheme and host in lower case, the port only when it is not the
	 * scheme's own, the path without a trailing slash. An address with a
	 * user name, a query or a fragment is refused rather than trimmed.
	 *
	 * @param string $input               Address.
	 * @param bool   $allow_loopback_http Accept plain http for a site on this machine.
	 * @return array{0:?string,1:?string} Canonical address and problem; one is null.
	 */
	public static function canonical_site_url( $input, $allow_loopback_http = false ) {
		$text = trim( (string) $input );

		if ( strlen( $text ) > 2000 ) {
			return array( null, 'too_long' );
		}

		if ( ! preg_match( '#^https?://#i', $text ) ) {
			return array( null, preg_match( '#^[a-z][a-z0-9+.-]*://#i', $text ) ? 'scheme' : 'not_a_url' );
		}

		$parts = wp_parse_url( $text );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return array( null, 'not_a_url' );
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );

		// The centre sees an international name in its ASCII form.
		if ( preg_match( '/[^\x20-\x7e]/', $host ) ) {
			$ascii = function_exists( 'idn_to_ascii' ) ? idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ) : false;

			if ( ! $ascii ) {
				return array( null, 'host' );
			}

			$host = strtolower( $ascii );
		}

		if ( 'https' !== $scheme && ! ( $allow_loopback_http && self::is_loopback( $host ) ) ) {
			return array( null, 'scheme' );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return array( null, 'credentials' );
		}

		if ( false !== strpbrk( $text, '?#' ) ) {
			return array( null, 'query_or_fragment' );
		}

		if ( '.' === substr( $host, -1 ) ) {
			return array( null, 'host' );
		}

		$port = '';

		if ( isset( $parts['port'] ) && (int) $parts['port'] !== ( 'https' === $scheme ? 443 : 80 ) ) {
			$port = ':' . (int) $parts['port'];
		}

		$path = rtrim( isset( $parts['path'] ) ? $parts['path'] : '', '/' );

		return array( $scheme . '://' . $host . $port . $path, null );
	}

	/**
	 * What is sent as `site_url_hash`: sha256 of the canonical address.
	 *
	 * @param string $canonical Canonical address.
	 * @return string Lower-case hex.
	 */
	public static function site_url_hash( $canonical ) {
		return hash( 'sha256', (string) $canonical );
	}
}
