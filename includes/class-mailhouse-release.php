<?php
/**
 * What a signed release says, and whether to believe it.
 *
 * A release is signed off GitHub, by a key GitHub never holds, so that taking
 * over the repository is not enough to ship code to every site. What is signed
 * is not the zip but what the zip must unpack to: the plugin, the version, and
 * the SHA-256 of every file, as built from the signer's own checkout of the
 * tag. The site unpacks the zip it downloaded and compares, file for file,
 * before WordPress installs anything; the zip's own bytes are never trusted.
 *
 * Nothing here needs WordPress, so the release tool and the workflow use the
 * same code the site does (`tools/release.php`).
 *
 * The signature file, `mailhouse-<version>.zip.sig` beside the zip:
 *
 *     {
 *       "scheme": "MH1-RELEASE",
 *       "plugin": "mailhouse",
 *       "version": "1.7.0",
 *       "files": { "mailhouse.php": "<sha256 hex>", ... },
 *       "signatures": [ { "key_id": "r-...", "signature": "<base64>" } ]
 *     }
 *
 * and what each signature is over, one field a line:
 *
 *     MH1-RELEASE
 *     mailhouse
 *     1.7.0
 *     <sha256 hex> <path>     -- one a file, in byte order of path
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, checks and makes release signatures.
 */
class Mailhouse_Release {

	/**
	 * The scheme's name, first in every signed message.
	 *
	 * @var string
	 */
	const SCHEME = 'MH1-RELEASE';

	/**
	 * The plugin a release is of.
	 *
	 * @var string
	 */
	const PLUGIN = 'mailhouse';

	/**
	 * The most a signature file may be, in bytes.
	 *
	 * @var int
	 */
	const MAX_BYTES = 262144;

	/**
	 * The most files a release may have.
	 *
	 * @var int
	 */
	const MAX_FILES = 5000;

	/**
	 * The most signatures one file may carry.
	 *
	 * @var int
	 */
	const MAX_SIGNATURES = 8;

	/**
	 * Whether Ed25519 can be checked here.
	 *
	 * PHP has had sodium since 7.2, but a host may build it out; WordPress
	 * ships a pure-PHP copy for exactly that case, and it is loaded if needed.
	 *
	 * @return bool
	 */
	public static function available() {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) && defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
			$compat = ABSPATH . WPINC . '/sodium_compat/autoload.php';

			if ( is_readable( $compat ) ) {
				require_once $compat;
			}
		}

		return function_exists( 'sodium_crypto_sign_verify_detached' ) && defined( 'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES' );
	}

	/**
	 * The keys a release must be signed by, as key id => base64 public key.
	 *
	 * Pinned in the plugin itself (`includes/release-keys.php`), so the keys
	 * a site believes are the keys of the version it runs, and a new key
	 * reaches a site only inside a release an old key signed.
	 *
	 * @param string|null $file The keys file; the plugin's own by default.
	 * @return array<string,string>
	 */
	public static function pinned( $file = null ) {
		$file = null === $file ? __DIR__ . '/release-keys.php' : $file;
		$list = is_readable( $file ) ? include $file : array();
		$keys = array();

		if ( ! is_array( $list ) ) {
			return array();
		}

		foreach ( $list as $public ) {
			if ( null !== self::decode( $public, 32 ) ) {
				$keys[ self::key_id( $public ) ] = $public;
			}
		}

		return $keys;
	}

	/**
	 * A key's id: `r-` and the first sixteen hex digits of the SHA-256 of
	 * the raw public key. Worked out, never taken from what a file claims.
	 *
	 * @param string $public Base64 public key.
	 * @return string
	 */
	public static function key_id( $public ) {
		$raw = self::decode( $public, 32 );

		return null === $raw ? '' : 'r-' . substr( hash( 'sha256', $raw ), 0, 16 );
	}

	/**
	 * The message each signature is over.
	 *
	 * @param string               $version Version.
	 * @param array<string,string> $files   Path => SHA-256 hex.
	 * @return string
	 */
	public static function message( $version, array $files ) {
		$paths = array_keys( $files );
		usort( $paths, 'strcmp' );

		$lines = array( self::SCHEME, self::PLUGIN, (string) $version );

		foreach ( $paths as $path ) {
			$lines[] = $files[ $path ] . ' ' . $path;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Sign a release with a secret key.
	 *
	 * @param string               $version Version.
	 * @param array<string,string> $files   Path => SHA-256 hex.
	 * @param string               $secret  Raw 64-byte Ed25519 secret key.
	 * @return array{key_id:string,signature:string}
	 */
	public static function sign( $version, array $files, $secret ) {
		$public = base64_encode( sodium_crypto_sign_publickey_from_secretkey( $secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return array(
			'key_id'    => self::key_id( $public ),
			'signature' => base64_encode( sodium_crypto_sign_detached( self::message( $version, $files ), $secret ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);
	}

	/**
	 * Read a signature file, and believe it only if a pinned key signed
	 * exactly what it says.
	 *
	 * @param string               $text The file, as downloaded.
	 * @param array<string,string> $keys Pinned keys, id => base64 public key.
	 * @param string               $why  Set to the reason, when it is refused.
	 * @return array{version:string,files:array<string,string>,key_id:string}|null
	 */
	public static function read( $text, array $keys, &$why = '' ) {
		$why = '';

		if ( ! self::available() ) {
			$why = 'this server cannot check Ed25519 signatures';
			return null;
		}

		if ( array() === $keys ) {
			$why = 'this version of the plugin pins no release key';
			return null;
		}

		if ( ! is_string( $text ) || '' === $text || strlen( $text ) > self::MAX_BYTES ) {
			$why = 'the signature file is empty or too large';
			return null;
		}

		$data = json_decode( $text, true );

		if ( ! is_array( $data ) || ! isset( $data['scheme'], $data['plugin'], $data['version'], $data['files'], $data['signatures'] ) ) {
			$why = 'the signature file is not one';
			return null;
		}

		if ( self::SCHEME !== $data['scheme'] || self::PLUGIN !== $data['plugin'] ) {
			$why = 'the signature file is for something else';
			return null;
		}

		if ( ! is_string( $data['version'] ) || ! preg_match( '/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', $data['version'] ) ) {
			$why = 'the signature file names no version';
			return null;
		}

		if ( ! is_array( $data['files'] ) || array() === $data['files'] || count( $data['files'] ) > self::MAX_FILES ) {
			$why = 'the signature file lists no files';
			return null;
		}

		$files = array();

		foreach ( $data['files'] as $path => $hash ) {
			if ( ! self::path_ok( (string) $path ) || ! is_string( $hash ) || ! preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
				$why = 'the signature file lists a file it may not';
				return null;
			}

			$files[ (string) $path ] = $hash;
		}

		if ( ! is_array( $data['signatures'] ) || count( $data['signatures'] ) > self::MAX_SIGNATURES ) {
			$why = 'the signature file carries no usable signature';
			return null;
		}

		$message = self::message( $data['version'], $files );

		foreach ( $data['signatures'] as $signature ) {
			if ( ! is_array( $signature ) || ! isset( $signature['key_id'], $signature['signature'] ) || ! is_string( $signature['key_id'] ) ) {
				continue;
			}

			if ( ! isset( $keys[ $signature['key_id'] ] ) ) {
				continue;
			}

			$public = self::decode( $keys[ $signature['key_id'] ], 32 );
			$bytes  = self::decode( $signature['signature'], 64 );

			if ( null === $public || null === $bytes || self::key_id( $keys[ $signature['key_id'] ] ) !== $signature['key_id'] ) {
				continue;
			}

			try {
				if ( sodium_crypto_sign_verify_detached( $bytes, $message, $public ) ) {
					return array(
						'version' => $data['version'],
						'files'   => $files,
						'key_id'  => $signature['key_id'],
					);
				}
			} catch ( Exception $e ) {
				continue;
			}
		}

		$why = 'no key this version of the plugin trusts signed it';
		return null;
	}

	/**
	 * Every file under a folder, as path => SHA-256 hex, or null if the
	 * folder holds something that is not a plain file or folder.
	 *
	 * @param string $dir Folder.
	 * @return array<string,string>|null
	 */
	public static function tree( $dir ) {
		$dir = rtrim( str_replace( '\\', '/', (string) $dir ), '/' );

		if ( '' === $dir || ! is_dir( $dir ) || is_link( $dir ) ) {
			return null;
		}

		$files = array();
		$queue = array( '' );

		while ( $queue ) {
			$relative = array_shift( $queue );
			$entries  = scandir( $dir . ( '' === $relative ? '' : '/' . $relative ) );

			if ( false === $entries ) {
				return null;
			}

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$path = '' === $relative ? $entry : $relative . '/' . $entry;
				$full = $dir . '/' . $path;

				if ( is_link( $full ) ) {
					return null;
				}

				if ( is_dir( $full ) ) {
					$queue[] = $path;
				} elseif ( is_file( $full ) ) {
					$files[ $path ] = hash_file( 'sha256', $full );
				} else {
					return null;
				}
			}
		}

		return $files;
	}

	/**
	 * What differs between the files signed and the files found.
	 *
	 * @param array<string,string>      $signed Path => SHA-256 hex, signed.
	 * @param array<string,string>|null $found  Path => SHA-256 hex, found.
	 * @return string[] One line a difference; empty when they agree.
	 */
	public static function compare( array $signed, $found ) {
		if ( ! is_array( $found ) ) {
			return array( 'the package holds something that is not a plain file' );
		}

		$out = array();

		foreach ( $signed as $path => $hash ) {
			if ( ! isset( $found[ $path ] ) ) {
				$out[] = 'missing ' . $path;
			} elseif ( ! hash_equals( $hash, (string) $found[ $path ] ) ) {
				$out[] = 'changed ' . $path;
			}
		}

		foreach ( array_keys( $found ) as $path ) {
			if ( ! isset( $signed[ $path ] ) ) {
				$out[] = 'not signed ' . $path;
			}
		}

		sort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * Whether a path may be in a release: relative, forward slashes, no
	 * empty, `.` or `..` part, nothing outside printable ASCII.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function path_ok( $path ) {
		if ( '' === $path || strlen( $path ) > 512 || ! preg_match( '/^[\x21-\x7e][\x20-\x7e]*$/', $path ) || false !== strpos( $path, '\\' ) ) {
			return false;
		}

		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Strict base64 of exactly this many bytes, or null.
	 *
	 * @param mixed $text  Text.
	 * @param int   $bytes Length.
	 * @return string|null
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
}
