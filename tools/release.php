#!/usr/bin/env php
<?php
/**
 * Make a release key, sign a release, check one.
 *
 *   php tools/release.php key <secret-file> [--pin]
 *       Make an Ed25519 key. The secret half is written to <secret-file>,
 *       which must not exist yet, and must never reach GitHub; the public
 *       half and its id are printed. --pin adds the public half to
 *       includes/release-keys.php, to be committed.
 *
 *   php tools/release.php sign <tag> <secret-file> [--zip <attached.zip>] [--out <file>]
 *       Sign what <tag> builds, from this checkout: the files are read from
 *       `git archive` of the tag here, with the settings the release workflow
 *       uses, never from a zip GitHub built. With --zip, the zip attached to
 *       the release is unpacked and compared first, and nothing is signed if
 *       it differs. Writes mailhouse-<version>.zip.sig (or <file>); if one is
 *       there already, for the same files, this key's signature is added.
 *
 *   php tools/release.php verify <zip> <sig> [--keys <release-keys.php>]...
 *       Check a zip and its signature as a site would: the signature by a
 *       pinned key, and every file in the zip what was signed. With several
 *       --keys, each set must accept it (the keys of the version being
 *       released, and of the one before, which is what sites run).
 *
 *   php tools/release.php keys [--keys <release-keys.php>]
 *       List the pinned keys. Exits 1 when there are none.
 *
 * Kept out of the release zip (.gitattributes).
 *
 * @package Mailhouse
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/includes/class-mailhouse-release.php';

/**
 * Print to stderr and exit.
 *
 * @param string $message Message.
 * @param int    $code    Exit status.
 * @return void
 */
function mhr_fail( $message, $code = 1 ) {
	fwrite( STDERR, $message . "\n" );
	exit( $code );
}

/**
 * Split arguments into positional ones and options.
 *
 * @param string[] $argv Arguments after the command.
 * @return array{0:string[],1:array<string,string[]>}
 */
function mhr_args( array $argv ) {
	$plain   = array();
	$options = array();

	for ( $i = 0; $i < count( $argv ); $i++ ) {
		$arg = $argv[ $i ];

		if ( 0 === strpos( $arg, '--' ) ) {
			$name = substr( $arg, 2 );

			if ( 'pin' === $name ) {
				$options['pin'][] = '1';
				continue;
			}

			if ( ! isset( $argv[ $i + 1 ] ) ) {
				mhr_fail( "--$name needs a value", 2 );
			}

			$options[ $name ][] = $argv[ ++$i ];
			continue;
		}

		$plain[] = $arg;
	}

	return array( $plain, $options );
}

/**
 * Remove a folder and everything in it.
 *
 * @param string $dir Folder.
 * @return void
 */
function mhr_remove( $dir ) {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		if ( file_exists( $dir ) || is_link( $dir ) ) {
			unlink( $dir );
		}
		return;
	}

	foreach ( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) as $entry ) {
		mhr_remove( $dir . '/' . $entry );
	}

	rmdir( $dir );
}

/**
 * A new empty folder in the system's temporary directory.
 *
 * @return string
 */
function mhr_temp_dir() {
	$dir = rtrim( str_replace( '\\', '/', sys_get_temp_dir() ), '/' ) . '/mailhouse-release-' . bin2hex( random_bytes( 6 ) );

	if ( ! mkdir( $dir, 0700 ) ) {
		mhr_fail( "cannot make $dir" );
	}

	return $dir;
}

/**
 * Unpack a zip and read every file under its single `mailhouse/` folder.
 *
 * @param string $zip Zip.
 * @return array<string,string> Path => SHA-256 hex.
 */
function mhr_zip_tree( $zip ) {
	$archive = new ZipArchive();

	if ( true !== $archive->open( $zip ) ) {
		mhr_fail( "cannot open $zip" );
	}

	$dir = mhr_temp_dir();
	$ok  = $archive->extractTo( $dir );
	$archive->close();

	if ( ! $ok ) {
		mhr_remove( $dir );
		mhr_fail( "cannot unpack $zip" );
	}

	$top = array_values( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) );

	if ( array( Mailhouse_Release::PLUGIN ) !== $top || ! is_dir( $dir . '/' . Mailhouse_Release::PLUGIN ) ) {
		mhr_remove( $dir );
		mhr_fail( "$zip does not hold a single mailhouse folder: " . implode( ', ', $top ) );
	}

	$tree = Mailhouse_Release::tree( $dir . '/' . Mailhouse_Release::PLUGIN );
	mhr_remove( $dir );

	if ( null === $tree ) {
		mhr_fail( "$zip holds something that is not a plain file" );
	}

	return $tree;
}

/**
 * Read a secret key file.
 *
 * @param string $file File.
 * @return string Raw 64-byte secret key.
 */
function mhr_secret( $file ) {
	$text = is_readable( $file ) ? trim( (string) file_get_contents( $file ) ) : '';
	$raw  = Mailhouse_Release::decode( $text, 64 );

	if ( null === $raw ) {
		mhr_fail( "$file is not a release secret key" );
	}

	return $raw;
}

/**
 * Run git in this checkout.
 *
 * @param string $args Arguments, already escaped.
 * @param int    $code Set to git's exit status.
 * @return string Output.
 */
function mhr_git( $args, &$code = 0 ) {
	$out = array();
	exec( 'git -C ' . escapeshellarg( dirname( __DIR__ ) ) . ' ' . $args . ' 2>&1', $out, $code );

	return implode( "\n", $out );
}

if ( ! Mailhouse_Release::available() ) {
	mhr_fail( 'PHP here has no sodium extension, which this needs' );
}

$command                = isset( $argv[1] ) ? $argv[1] : '';
list( $plain, $options ) = mhr_args( array_slice( $argv, 2 ) );

switch ( $command ) {
	case 'key':
		if ( 1 !== count( $plain ) ) {
			mhr_fail( 'usage: release.php key <secret-file> [--pin]', 2 );
		}

		$file = $plain[0];

		if ( file_exists( $file ) ) {
			mhr_fail( "$file exists already; a key is never overwritten" );
		}

		$pair   = sodium_crypto_sign_keypair();
		$public = base64_encode( sodium_crypto_sign_publickey( $pair ) );
		$id     = Mailhouse_Release::key_id( $public );

		if ( false === file_put_contents( $file, base64_encode( sodium_crypto_sign_secretkey( $pair ) ) . "\n" ) ) {
			mhr_fail( "cannot write $file" );
		}

		chmod( $file, 0600 );

		echo "key id      $id\n";
		echo "public key  $public\n";
		echo "secret key  written to $file -- keep it off GitHub, and keep a copy\n";

		if ( isset( $options['pin'] ) ) {
			$keys   = dirname( __DIR__ ) . '/includes/release-keys.php';
			$text   = (string) file_get_contents( $keys );
			$marker = "\t// Keys go above this line.";

			if ( 1 !== substr_count( $text, $marker ) ) {
				mhr_fail( "cannot find where keys go in $keys" );
			}

			$line = "\t'" . $public . "', // " . $id . ', added ' . gmdate( 'Y-m-d' ) . "\n";
			file_put_contents( $keys, str_replace( $marker, $line . $marker, $text ) );
			echo "pinned in   includes/release-keys.php -- commit it\n";
		}
		break;

	case 'sign':
		if ( 2 !== count( $plain ) ) {
			mhr_fail( 'usage: release.php sign <tag> <secret-file> [--zip <attached.zip>] [--out <file>]', 2 );
		}

		list( $ref, $secret_file ) = $plain;
		$secret                    = mhr_secret( $secret_file );
		$code                      = 0;

		mhr_git( 'rev-parse --verify ' . escapeshellarg( $ref . '^{commit}' ), $code );

		if ( 0 !== $code ) {
			mhr_fail( "$ref is not a commit in this checkout" );
		}

		$dir   = mhr_temp_dir();
		$built = $dir . '/built.zip';
		$out   = mhr_git( '-c core.autocrlf=false -c core.eol=lf archive --format=zip -6 --prefix=mailhouse/ ' . escapeshellarg( $ref ) . ' -o ' . escapeshellarg( $built ), $code );

		if ( 0 !== $code ) {
			mhr_remove( $dir );
			mhr_fail( "git archive failed: $out" );
		}

		$files = mhr_zip_tree( $built );
		mhr_remove( $dir );

		foreach ( array_keys( $files ) as $path ) {
			if ( ! Mailhouse_Release::path_ok( $path ) ) {
				mhr_fail( "$ref holds a file a release may not: $path" );
			}
		}

		if ( ! isset( $files['mailhouse.php'] ) ) {
			mhr_fail( "$ref has no mailhouse.php" );
		}

		$main = mhr_git( 'show ' . escapeshellarg( $ref . ':mailhouse.php' ) );

		if ( ! preg_match( '/^[ \t\/*#@]*Version:\s*(\d+\.\d+\.\d+)\s*$/m', $main, $match ) ) {
			mhr_fail( "cannot read the version from $ref's mailhouse.php" );
		}

		$version = $match[1];

		if ( preg_match( '/^v\d/', $ref ) && 'v' . $version !== $ref ) {
			mhr_fail( "$ref says version $version" );
		}

		if ( isset( $options['zip'] ) ) {
			$diff = Mailhouse_Release::compare( $files, mhr_zip_tree( $options['zip'][0] ) );

			if ( array() !== $diff ) {
				mhr_fail( $options['zip'][0] . " is not what $ref builds here, so nothing was signed:\n  " . implode( "\n  ", array_slice( $diff, 0, 20 ) ) );
			}

			echo $options['zip'][0] . " holds exactly what $ref builds here (" . count( $files ) . " files)\n";
		}

		ksort( $files, SORT_STRING );

		$file      = isset( $options['out'] ) ? $options['out'][0] : 'mailhouse-' . $version . '.zip.sig';
		$signature = Mailhouse_Release::sign( $version, $files, $secret );
		$document  = array(
			'scheme'     => Mailhouse_Release::SCHEME,
			'plugin'     => Mailhouse_Release::PLUGIN,
			'version'    => $version,
			'files'      => $files,
			'signatures' => array(),
		);

		if ( file_exists( $file ) ) {
			$existing = json_decode( (string) file_get_contents( $file ), true );

			if ( ! is_array( $existing ) || ! isset( $existing['version'], $existing['files'], $existing['signatures'] ) || $existing['version'] !== $version || $existing['files'] != $files || ! is_array( $existing['signatures'] ) ) { // phpcs:ignore Universal.Operators.StrictComparisons
				mhr_fail( "$file is there already, for something else; move it away first" );
			}

			$document['signatures'] = $existing['signatures'];
		}

		foreach ( $document['signatures'] as $held ) {
			if ( isset( $held['key_id'] ) && $held['key_id'] === $signature['key_id'] ) {
				mhr_fail( "$file already carries a signature by " . $signature['key_id'] );
			}
		}

		$document['signatures'][] = $signature;

		file_put_contents( $file, json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

		echo 'signed      mailhouse ' . $version . ', ' . count( $files ) . " files, from $ref in this checkout\n";
		echo 'by          ' . $signature['key_id'] . "\n";
		echo "written to  $file -- attach it to the release beside the zip\n";
		break;

	case 'verify':
		if ( 2 !== count( $plain ) ) {
			mhr_fail( 'usage: release.php verify <zip> <sig> [--keys <release-keys.php>]...', 2 );
		}

		list( $zip, $sig ) = $plain;
		$sets              = isset( $options['keys'] ) ? $options['keys'] : array( dirname( __DIR__ ) . '/includes/release-keys.php' );
		$tree              = mhr_zip_tree( $zip );
		$text              = is_readable( $sig ) ? (string) file_get_contents( $sig ) : '';
		$bad               = false;

		foreach ( $sets as $set ) {
			$why    = '';
			$signed = Mailhouse_Release::read( $text, Mailhouse_Release::pinned( $set ), $why );

			if ( null === $signed ) {
				echo "REFUSED by the keys in $set: $why\n";
				$bad = true;
				continue;
			}

			$diff = Mailhouse_Release::compare( $signed['files'], $tree );

			if ( array() !== $diff ) {
				echo "REFUSED by the keys in $set: what $zip holds is not what was signed\n  " . implode( "\n  ", array_slice( $diff, 0, 20 ) ) . "\n";
				$bad = true;
				continue;
			}

			echo 'accepted    by the keys in ' . $set . ': mailhouse ' . $signed['version'] . ', ' . count( $tree ) . ' files, signed by ' . $signed['key_id'] . "\n";
		}

		exit( $bad ? 1 : 0 );

	case 'keys':
		$set  = isset( $options['keys'] ) ? $options['keys'][0] : dirname( __DIR__ ) . '/includes/release-keys.php';
		$keys = Mailhouse_Release::pinned( $set );

		foreach ( $keys as $id => $public ) {
			echo "$id  $public\n";
		}

		if ( array() === $keys ) {
			echo "no release key is pinned in $set\n";
			exit( 1 );
		}
		break;

	default:
		mhr_fail( "usage: release.php key|sign|verify|keys ...\n(see the top of tools/release.php)", 2 );
}
