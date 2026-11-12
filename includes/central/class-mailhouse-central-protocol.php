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

	// -------------------------------------------------------------------------
	// A subscriber's state

	/**
	 * Text as the centre will accept it: valid UTF-8, no NUL characters,
	 * and no longer than the centre allows.
	 *
	 * The limit is counted the way the centre counts it, in UTF-16 units,
	 * so a name made of emoji is cut where the centre would have refused it.
	 *
	 * @param string $value Text.
	 * @param int    $max   Most UTF-16 units.
	 * @return string
	 */
	public static function text( $value, $max ) {
		$value = str_replace( "\0", '', (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( ! preg_match( '//u', $value ) ) {
			$value = (string) wp_check_invalid_utf8( $value, true );
		}

		// Shorter in bytes than the limit is in units: nothing to cut.
		if ( strlen( $value ) <= $max ) {
			return $value;
		}

		$units = static function ( $text ) {
			return mb_strlen( $text, 'UTF-8' ) + (int) preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $text );
		};

		while ( '' !== $value && $units( $value ) > $max ) {
			$over  = $units( $value ) - $max;
			$value = mb_substr( $value, 0, max( 0, mb_strlen( $value, 'UTF-8' ) - (int) ceil( $over / 2 ) ), 'UTF-8' );
		}

		return $value;
	}

	/**
	 * A date from this site's tables, as the protocol writes one:
	 * `2026-11-12T09:30:00Z`. The tables hold UTC.
	 *
	 * @param string|null $datetime `Y-m-d H:i:s`, or nothing.
	 * @param bool        $required Whether something must be returned.
	 * @return string|null Null for a date that is absent or never was.
	 */
	public static function timestamp( $datetime, $required = false ) {
		$datetime = (string) $datetime;

		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $datetime, $m )
			&& (int) $m[1] > 0 && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] )
			&& (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60 ) {
			return $m[1] . '-' . $m[2] . '-' . $m[3] . 'T' . $m[4] . ':' . $m[5] . ':' . $m[6] . 'Z';
		}

		return $required ? '1970-01-01T00:00:00Z' : null;
	}

	/**
	 * A subscriber as the centre is told about one: everything that is
	 * synchronised, and the hash of it.
	 *
	 * Never part of it: the subscriber's keys, the WordPress user id, and
	 * the send, open and click counters.
	 *
	 * @param object $row     Subscriber row.
	 * @param int[]  $tag_ids The ids of the tags they hold.
	 * @return array
	 */
	public static function subscriber_state( $row, array $tag_ids ) {
		$fields  = array();
		$decoded = isset( $row->fields ) && is_string( $row->fields ) && '' !== $row->fields ? json_decode( $row->fields, true ) : array();

		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $name => $value ) {
				$name = self::text( (string) $name, 60 );

				if ( '' === $name || ! is_scalar( $value ) ) {
					continue;
				}

				if ( count( $fields ) >= 100 ) {
					break;
				}

				$fields[ $name ] = self::text( (string) $value, 2000 );
			}
		}

		$tags = array_values( array_unique( array_filter( array_map( 'intval', $tag_ids ) ) ) );
		sort( $tags, SORT_NUMERIC );

		// An address is told only in the one spelling both ends agree on.
		$packed = isset( $row->ip ) && '' !== (string) $row->ip ? @inet_pton( (string) $row->ip ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$ip     = false !== $packed ? inet_ntop( $packed ) : false;

		$state = array(
			'local_id'        => (int) $row->id,
			'email'           => self::text( (string) $row->email, 320 ),
			'first_name'      => self::text( (string) $row->first_name, 200 ),
			'last_name'       => self::text( (string) $row->last_name, 200 ),
			// An object, even when empty.
			'fields'          => $fields ? $fields : new stdClass(),
			'status'          => (string) $row->status,
			'source'          => self::text( (string) $row->source, 60 ),
			'signup_ip'       => false !== $ip ? $ip : null,
			'created_at'      => self::timestamp( isset( $row->created_at ) ? $row->created_at : '', true ),
			'confirmed_at'    => self::timestamp( isset( $row->confirmed_at ) ? $row->confirmed_at : '' ),
			'unsubscribed_at' => self::timestamp( isset( $row->unsubscribed_at ) ? $row->unsubscribed_at : '' ),
			'tags'            => array_slice( $tags, 0, 1000 ),
			'revision'        => isset( $row->revision ) ? (int) $row->revision : 0,
		);

		$state['hash'] = self::state_hash( $state );

		return $state;
	}

	/**
	 * The one spelling of a state, which is what is hashed.
	 *
	 * Fourteen lines joined by a line feed, with none after the last. Text
	 * is percent-encoded exactly as in a signed request's query; custom
	 * fields are ordered by name and tags by number; an absent date or
	 * address is an empty value.
	 *
	 * @param array $state A state, with or without its hash.
	 * @return string
	 */
	public static function canonical_state( array $state ) {
		$pairs = array();

		foreach ( (array) $state['fields'] as $name => $value ) {
			$pairs[] = array( (string) $name, (string) $value );
		}

		$tags = array_map( 'intval', (array) $state['tags'] );
		sort( $tags, SORT_NUMERIC );

		$text = static function ( $value ) {
			return null === $value ? '' : rawurlencode( (string) $value );
		};

		return implode(
			"\n",
			array(
				'MH1-STATE',
				'local_id=' . (int) $state['local_id'],
				'email=' . $text( $state['email'] ),
				'first_name=' . $text( $state['first_name'] ),
				'last_name=' . $text( $state['last_name'] ),
				'fields=' . self::canonical_query( $pairs ),
				'status=' . (string) $state['status'],
				'source=' . $text( $state['source'] ),
				'signup_ip=' . $text( $state['signup_ip'] ),
				'created_at=' . (string) $state['created_at'],
				'confirmed_at=' . ( null === $state['confirmed_at'] ? '' : (string) $state['confirmed_at'] ),
				'unsubscribed_at=' . ( null === $state['unsubscribed_at'] ? '' : (string) $state['unsubscribed_at'] ),
				'tags=' . implode( ',', $tags ),
				'revision=' . (int) $state['revision'],
			)
		);
	}

	/**
	 * The hash of a state: how two copies of a subscriber are compared
	 * without comparing every field.
	 *
	 * @param array $state A state.
	 * @return string Lower-case hex.
	 */
	public static function state_hash( array $state ) {
		return hash( 'sha256', self::canonical_state( $state ) );
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
