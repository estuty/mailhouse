<?php
/**
 * Signed updates: a release with a bad or missing signature is not installed.
 *
 * The signatures are checked by code alone first (Mailhouse_Release), then by
 * WordPress's own upgrader, run for real: a release is published to a stand-in
 * for GitHub, `wp_update_plugins()` finds it, and `Plugin_Upgrader` is asked to
 * install it, single and bulk. Every way a release can be wrong is put in front
 * of it -- no signature, someone else's key, a file changed, added or taken
 * out, the wrong folder, the wrong version, a signature tampered with -- and
 * each must stop before WordPress would have deactivated the plugin or touched
 * its folder.
 *
 * Nothing is ever installed, signed or not: a filter at the head of
 * `upgrader_pre_install` stops every upgrade of this plugin that gets that far
 * and records that it did. Getting that far is what a good release must do,
 * and what a bad one must never do. The folder the plugin runs from is hashed
 * before and after, and must not change.
 *
 * Then the release tool (tools/release.php), where git is available: a key,
 * a signature from this checkout, and a check that refuses a changed zip.
 *
 * Usage: php tests/test-signed-updates.php
 *
 * @package Mailhouse
 */

require __DIR__ . '/bootstrap.php';

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

if ( ! defined( 'FS_METHOD' ) ) {
	define( 'FS_METHOD', 'direct' );
}

$updater = mailhouse()->module( 'updater' );

/**
 * Hash every file the plugin runs from, so the test can show none changed.
 *
 * @return string
 */
function mhs_installed() {
	$tree = Mailhouse_Release::tree( MAILHOUSE_PATH );

	if ( null === $tree ) {
		// A checkout may hold things the tree walk refuses (a symlink); the
		// files that run are what matter.
		$tree = array();
		foreach ( array( 'mailhouse.php', 'includes/class-mailhouse-updater.php', 'includes/class-mailhouse-release.php', 'includes/release-keys.php' ) as $file ) {
			$tree[ $file ] = hash_file( 'sha256', MAILHOUSE_PATH . $file );
		}
	}

	ksort( $tree );

	return hash( 'sha256', wp_json_encode( $tree ) );
}

$mhs_before = mhs_installed();
$mhs_dir    = get_temp_dir() . 'mailhouse-signed-' . wp_generate_password( 8, false, false );
wp_mkdir_p( $mhs_dir );

$mhs_pinned   = sodium_crypto_sign_keypair();
$mhs_stranger = sodium_crypto_sign_keypair();
$mhs_next     = sodium_crypto_sign_keypair();

/**
 * A key pair's public half, base64.
 *
 * @param string $pair Key pair.
 * @return string
 */
function mhs_public( $pair ) {
	return base64_encode( sodium_crypto_sign_publickey( $pair ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

/**
 * Pin these keys for the rest of the run, in place of the plugin's own.
 *
 * @param string[] $pairs Key pairs.
 * @return void
 */
function mhs_pin( array $pairs ) {
	remove_all_filters( 'mailhouse_release_keys' );
	$publics = array_map( 'mhs_public', $pairs );
	add_filter(
		'mailhouse_release_keys',
		static function () use ( $publics ) {
			return $publics;
		}
	);
}

mhs_pin( array( $mhs_pinned ) );

/**
 * The files of a release of version $version.
 *
 * @param string $version Version.
 * @return array<string,string> Path => content.
 */
function mhs_files( $version ) {
	return array(
		'mailhouse.php'                  => "<?php\n/**\n * Plugin Name: Mailhouse\n * Version: $version\n */\n",
		'includes/class-mailhouse-a.php' => "<?php\n// The first file.\n",
		'includes/deeper/b.php'          => "<?php\n// The second file.\n",
		'readme.txt'                     => "=== Mailhouse ===\nStable tag: $version\n",
	);
}

/**
 * Path => SHA-256 of a release's files.
 *
 * @param array<string,string> $files Path => content.
 * @return array<string,string>
 */
function mhs_hashes( array $files ) {
	return array_map(
		static function ( $content ) {
			return hash( 'sha256', $content );
		},
		$files
	);
}

/**
 * A zip of these files under one folder.
 *
 * @param string               $name  File name, without .zip.
 * @param array<string,string> $files Path => content.
 * @param string               $root  The folder.
 * @return string The zip's path.
 */
function mhs_zip( $name, array $files, $root = 'mailhouse' ) {
	$path = $GLOBALS['mhs_dir'] . '/' . $name . '.zip';
	$zip  = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

	foreach ( $files as $file => $content ) {
		$zip->addFromString( ( '' === $root ? '' : $root . '/' ) . $file, $content );
	}

	$zip->close();

	return $path;
}

/**
 * A signature file for a release, signed by each of these keys.
 *
 * @param string               $version Version.
 * @param array<string,string> $hashes  Path => SHA-256.
 * @param string[]             $pairs   Key pairs to sign with.
 * @return string
 */
function mhs_sig( $version, array $hashes, array $pairs ) {
	$signatures = array();

	foreach ( $pairs as $pair ) {
		$signatures[] = Mailhouse_Release::sign( $version, $hashes, sodium_crypto_sign_secretkey( $pair ) );
	}

	return wp_json_encode(
		array(
			'scheme'     => 'MH1-RELEASE',
			'plugin'     => 'mailhouse',
			'version'    => $version,
			'files'      => $hashes,
			'signatures' => $signatures,
		)
	);
}

/**
 * Stand in for GitHub: the API, the zip and the signature.
 *
 * @param string          $version  The release's version.
 * @param string          $zip      The zip to serve.
 * @param string|int|null $sig      The signature to serve, a status to fail with, or null for none attached.
 * @return void
 */
function mhs_publish( $version, $zip, $sig ) {
	$base    = 'https://github.com/estuty/mailhouse/releases/download/v' . $version . '/';
	$package = $base . 'mailhouse-' . $version . '.zip';
	$assets  = array(
		array(
			'name'                 => 'mailhouse-' . $version . '.zip',
			'browser_download_url' => $package,
		),
	);

	if ( null !== $sig ) {
		$assets[] = array(
			'name'                 => 'mailhouse-' . $version . '.zip.sig',
			'browser_download_url' => $package . '.sig',
		);
	}

	$GLOBALS['mhs_asked'] = array();
	$GLOBALS['mhs_serve'] = array(
		'release'       => array(
			'tag_name'     => 'v' . $version,
			'name'         => 'Mailhouse ' . $version,
			'body'         => 'Notes.',
			'html_url'     => 'https://github.com/estuty/mailhouse/releases/tag/v' . $version,
			'published_at' => '2026-11-25T12:00:00Z',
			'assets'       => $assets,
		),
		$package        => (string) file_get_contents( $zip ),
		$package . '.sig' => null === $sig ? 404 : $sig,
	);

	delete_site_transient( 'mailhouse_latest_release' );
	delete_site_transient( 'update_plugins' );
}

/**
 * Answer an HTTP request as an HTTP request would be answered.
 *
 * @param int    $code Status.
 * @param string $body Body.
 * @param array  $args The request's arguments.
 * @return array
 */
function mhs_answer( $code, $body, $args ) {
	if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
		file_put_contents( $args['filename'], $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	return array(
		'headers'  => array(),
		'body'     => ! empty( $args['stream'] ) ? '' : $body,
		'response' => array(
			'code'    => $code,
			'message' => 200 === $code ? 'OK' : 'Error',
		),
		'cookies'  => array(),
		'filename' => ! empty( $args['stream'] ) ? $args['filename'] : null,
	);
}

remove_all_filters( 'pre_http_request' );
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( false !== strpos( $url, 'api.wordpress.org' ) ) {
			return mhs_answer(
				200,
				wp_json_encode(
					array(
						'plugins'      => array(),
						'translations' => array(),
						'no_update'    => array(),
					)
				),
				$args
			);
		}

		$GLOBALS['mhs_asked'][] = $url;

		if ( false !== strpos( $url, 'api.github.com' ) ) {
			return mhs_answer( 200, wp_json_encode( $GLOBALS['mhs_serve']['release'] ), $args );
		}

		if ( ! isset( $GLOBALS['mhs_serve'][ $url ] ) ) {
			return mhs_answer( 404, 'Not Found', $args );
		}

		$served = $GLOBALS['mhs_serve'][ $url ];

		return is_int( $served ) ? mhs_answer( $served, 'Not Found', $args ) : mhs_answer( 200, $served, $args );
	},
	10,
	3
);

// Nothing is installed. An upgrade of this plugin that reaches the install
// step is recorded and stopped there, before WordPress's own filters at the
// same hook deactivate anything.
add_filter(
	'upgrader_pre_install',
	static function ( $response, $extra ) {
		if ( is_array( $extra ) && isset( $extra['plugin'] ) && MAILHOUSE_BASENAME === $extra['plugin'] ) {
			$GLOBALS['mhs_reached'] = true;
			return new WP_Error( 'mhs_test_stop', 'Stopped by the test before anything is installed.' );
		}

		return $response;
	},
	1,
	2
);

/**
 * A skin that keeps the errors it is shown.
 */
class MHS_Skin extends Automatic_Upgrader_Skin {

	/**
	 * Errors shown.
	 *
	 * @var WP_Error[]
	 */
	public $errors = array();

	/**
	 * Keep an error.
	 *
	 * @param string|WP_Error $errors Error.
	 * @return void
	 */
	public function error( $errors ) {
		if ( is_wp_error( $errors ) ) {
			$this->errors[] = $errors;
		}

		parent::error( $errors );
	}
}

/**
 * The bulk skin, keeping the errors it is shown.
 */
class MHS_Bulk_Skin extends Bulk_Plugin_Upgrader_Skin {

	/**
	 * Errors shown.
	 *
	 * @var WP_Error[]
	 */
	public $errors = array();

	/**
	 * Keep an error.
	 *
	 * @param string|WP_Error $errors Error.
	 * @return void
	 */
	public function error( $errors ) {
		if ( is_wp_error( $errors ) ) {
			$this->errors[] = $errors;
		}

		parent::error( $errors );
	}

	/**
	 * The bulk skin flushes every output buffer as it goes; here nothing is
	 * shown, so there is nothing to flush.
	 *
	 * @return void
	 */
	public function flush_output() {}
}

/**
 * Ask WordPress to install the update on offer, and say what happened.
 *
 * @param bool $bulk Through a bulk upgrade.
 * @return array{refused:string,reached:bool,codes:string[]}
 */
function mhs_upgrade( $bulk = false ) {
	$GLOBALS['mhs_reached'] = false;

	if ( $bulk ) {
		$skin     = new MHS_Bulk_Skin( array( 'nonce' => 'bulk-update-plugins', 'url' => 'update.php' ) );
		$upgrader = new Plugin_Upgrader( $skin );
		ob_start();
		$upgrader->bulk_upgrade( array( MAILHOUSE_BASENAME ) );
		ob_end_clean();
	} else {
		$skin     = new MHS_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$upgrader->upgrade( MAILHOUSE_BASENAME );
	}

	// After a failed install WordPress restores a backup on shutdown, if one
	// was made. None ever is here; this makes sure of it.
	remove_action( 'shutdown', array( $upgrader, 'restore_temp_backup' ), 10 );

	$codes   = array();
	$refused = '';

	foreach ( $skin->errors as $error ) {
		foreach ( $error->get_error_codes() as $code ) {
			$codes[] = $code;

			if ( 'mailhouse_update_refused' === $code ) {
				$refused = $error->get_error_message( $code );
			}
		}
	}

	return array(
		'refused' => $refused,
		'reached' => (bool) $GLOBALS['mhs_reached'],
		'codes'   => $codes,
	);
}

/**
 * Publish a release, let WordPress find it, and try to install it.
 *
 * @param string          $version Version.
 * @param string          $zip     Zip.
 * @param string|int|null $sig     Signature, status, or null.
 * @param bool            $bulk    Through a bulk upgrade.
 * @return array{refused:string,reached:bool,codes:string[],offered:bool}
 */
function mhs_try( $version, $zip, $sig, $bulk = false ) {
	mhs_publish( $version, $zip, $sig );
	wp_update_plugins();

	$state   = get_site_transient( 'update_plugins' );
	$offered = is_object( $state ) && isset( $state->response[ MAILHOUSE_BASENAME ] );

	$result            = $offered ? mhs_upgrade( $bulk ) : array(
		'refused' => '',
		'reached' => false,
		'codes'   => array(),
	);
	$result['offered'] = $offered;

	return $result;
}

/**
 * Check that an attempt was refused, for the reason given, before install.
 *
 * @param string $label  What was tried.
 * @param array  $result What happened.
 * @param string $reason Words the refusal must contain.
 * @return void
 */
function mhs_refused( $label, array $result, $reason ) {
	check( "$label: it was offered, and WordPress tried to install it", $result['offered'] );
	check(
		"$label: it is refused, saying why",
		'' !== $result['refused'] && false !== stripos( $result['refused'], $reason ),
		$result['refused'] ? $result['refused'] : implode( ',', $result['codes'] )
	);
	check( "$label: and never reaches the install", ! $result['reached'] );

	$GLOBALS['mhs_bad'][ $label ] = $result['offered'] && '' !== $result['refused'] && false !== stripos( $result['refused'], $reason ) && ! $result['reached'];
}

/**
 * Whether every one of these attempts was refused before the install.
 *
 * @param string[] $labels What was tried.
 * @return bool
 */
function mhs_all_refused( array $labels ) {
	foreach ( $labels as $label ) {
		if ( empty( $GLOBALS['mhs_bad'][ $label ] ) ) {
			return false;
		}
	}

	return true;
}

$GLOBALS['mhs_bad'] = array();

$mhs_backup = WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/' . dirname( MAILHOUSE_BASENAME );
$mhs_safe   = ! file_exists( $mhs_backup );

echo "== The signature, by itself ==\n";

$files  = mhs_files( '9.9.0' );
$hashes = mhs_hashes( $files );
$keys   = array( Mailhouse_Release::key_id( mhs_public( $mhs_pinned ) ) => mhs_public( $mhs_pinned ) );
$good   = mhs_sig( '9.9.0', $hashes, array( $mhs_pinned ) );
$why    = '';

check( 'Ed25519 can be checked here', Mailhouse_Release::available() );
check( 'a key id is r- and sixteen hex digits', 1 === preg_match( '/^r-[0-9a-f]{16}$/', Mailhouse_Release::key_id( mhs_public( $mhs_pinned ) ) ) );
check( 'and is not given for something that is not a key', '' === Mailhouse_Release::key_id( 'not a key' ) );

$read = Mailhouse_Release::read( $good, $keys, $why );
check( 'a signature by a pinned key is believed', is_array( $read ) && '9.9.0' === $read['version'], $why );
check( 'with the files it lists', is_array( $read ) && $hashes === $read['files'] );

$reversed = array_reverse( $hashes, true );
check( 'the order files are listed in does not matter', Mailhouse_Release::message( '9.9.0', $hashes ) === Mailhouse_Release::message( '9.9.0', $reversed ) );
check( 'the message names the scheme, the plugin and the version first', 0 === strpos( Mailhouse_Release::message( '9.9.0', $hashes ), "MH1-RELEASE\nmailhouse\n9.9.0\n" ) );

check( 'no pinned key: nothing is believed', null === Mailhouse_Release::read( $good, array(), $why ) && false !== strpos( $why, 'pins no release key' ), $why );
check( 'a stranger\'s key is not believed', null === Mailhouse_Release::read( mhs_sig( '9.9.0', $hashes, array( $mhs_stranger ) ), $keys, $why ) && false !== strpos( $why, 'no key' ), $why );

$claimed                               = json_decode( mhs_sig( '9.9.0', $hashes, array( $mhs_stranger ) ), true );
$claimed['signatures'][0]['key_id']    = Mailhouse_Release::key_id( mhs_public( $mhs_pinned ) );
check( 'nor one that claims a pinned key\'s id', null === Mailhouse_Release::read( wp_json_encode( $claimed ), $keys, $why ), $why );

$edited                                = json_decode( $good, true );
$edited['files']['includes/deeper/b.php'] = hash( 'sha256', 'something else' );
check( 'a file\'s hash changed after signing is refused', null === Mailhouse_Release::read( wp_json_encode( $edited ), $keys, $why ), $why );

$edited            = json_decode( $good, true );
$edited['version'] = '9.9.1';
check( 'so is the version changed after signing', null === Mailhouse_Release::read( wp_json_encode( $edited ), $keys, $why ), $why );

$edited                    = json_decode( $good, true );
$edited['files']['new.php'] = hash( 'sha256', 'x' );
check( 'and a file added to the list', null === Mailhouse_Release::read( wp_json_encode( $edited ), $keys, $why ), $why );

$edited = json_decode( $good, true );
$bytes  = base64_decode( $edited['signatures'][0]['signature'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
$bytes[10] = chr( ord( $bytes[10] ) ^ 1 );
$edited['signatures'][0]['signature'] = base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
check( 'one bit of the signature changed is refused', null === Mailhouse_Release::read( wp_json_encode( $edited ), $keys, $why ), $why );

check( 'two signatures, one by a stranger, one pinned: believed', is_array( Mailhouse_Release::read( mhs_sig( '9.9.0', $hashes, array( $mhs_stranger, $mhs_pinned ) ), $keys, $why ) ), $why );

foreach (
	array(
		'not JSON'                 => 'nothing like a signature',
		'empty'                    => '',
		'another scheme'           => str_replace( 'MH1-RELEASE', 'MH2-RELEASE', $good ),
		'another plugin'           => str_replace( '"plugin":"mailhouse"', '"plugin":"other"', $good ),
		'a version that is not one' => str_replace( '"version":"9.9.0"', '"version":"latest"', $good ),
		'too large'                => str_repeat( ' ', Mailhouse_Release::MAX_BYTES + 1 ),
	) as $label => $text
) {
	check( "a signature file that is $label is refused", null === Mailhouse_Release::read( $text, $keys, $why ) && '' !== $why, $why );
}

foreach ( array( '../escape.php', '/absolute.php', 'a//b.php', 'a/./b.php', 'back\\slash.php', '', 'trailing/' ) as $path ) {
	check( "a path like '$path' may not be in a release", ! Mailhouse_Release::path_ok( $path ) );
	$edited                  = json_decode( $good, true );
	$edited['files'][ $path ] = hash( 'sha256', 'x' );
	check( "and a list that has it is refused before any key is tried ('$path')", null === Mailhouse_Release::read( wp_json_encode( $edited ), $keys, $why ) && false !== strpos( $why, 'may not' ), $why );
}

check( 'an ordinary path may', Mailhouse_Release::path_ok( 'includes/class-mailhouse-updater.php' ) );

$found                  = $hashes;
$found['extra.php']     = hash( 'sha256', 'x' );
$found['readme.txt']    = hash( 'sha256', 'changed' );
unset( $found['includes/deeper/b.php'] );
$diff = Mailhouse_Release::compare( $hashes, $found );
check(
	'a comparison names what is missing, changed and not signed',
	array( 'changed readme.txt', 'missing includes/deeper/b.php', 'not signed extra.php' ) === $diff,
	implode( '; ', $diff )
);
check( 'and finds nothing when they agree', array() === Mailhouse_Release::compare( $hashes, $hashes ) );

$pinned_now = Mailhouse_Release::pinned();
check( 'the plugin\'s own keys file is read', is_array( $pinned_now ) );
check( 'every key in it has the id worked out from it', array_keys( $pinned_now ) === array_map( array( 'Mailhouse_Release', 'key_id' ), array_values( $pinned_now ) ) );

echo "== Offering only what is signed ==\n";

$headers = get_plugin_data( MAILHOUSE_FILE, false, false );
$zip     = mhs_zip( 'good', $files );

mhs_publish( '9.9.0', $zip, null );
check( 'a release with no signature beside its zip is not offered', false === $updater->offer( false, $headers, MAILHOUSE_BASENAME ) );
check( 'the status panel says why', false !== strpos( Mailhouse_Updater::status(), 'without a signature' ), Mailhouse_Updater::status() );
$timeout = (int) get_site_option( '_site_transient_timeout_mailhouse_latest_release', 0 );
if ( ! $timeout ) {
	$timeout = (int) get_option( '_site_transient_timeout_mailhouse_latest_release', 0 );
}
check( 'and it is asked about again in fifteen minutes, not twelve hours', $timeout > 0 && $timeout - time() <= Mailhouse_Updater::RETRY, (string) ( $timeout - time() ) );

mhs_publish( '9.9.0', $zip, $good );
$offer = $updater->offer( false, $headers, MAILHOUSE_BASENAME );
check( 'with one, it is offered', is_array( $offer ) && '9.9.0' === $offer['new_version'] );
check( 'and the package is the zip, beside which the signature is fetched', is_array( $offer ) && $offer['package'] . '.sig' === Mailhouse_Updater::release()['signature'] );

echo "== Through WordPress's upgrader: a good release ==\n";

if ( ! $mhs_safe ) {
	echo "  -- $mhs_backup exists, left from an update that failed: the upgrader is not run, so WordPress cannot restore it over this plugin\n";
	check( 'there is no backup of an earlier update waiting to be restored', false );
} else {
	delete_site_option( Mailhouse_Updater::REFUSED );
	$result = mhs_try( '9.9.0', $zip, $good );
	check( 'a release signed by a pinned key is offered', $result['offered'] );
	check( 'and reaches the install, which the test stops', $result['reached'] && '' === $result['refused'], $result['refused'] . ' ' . implode( ',', $result['codes'] ) );
	check( 'its signature and its zip were both fetched', count( array_filter( $GLOBALS['mhs_asked'], static function ( $url ) { return '.sig' === substr( $url, -4 ); } ) ) >= 1 && count( array_filter( $GLOBALS['mhs_asked'], static function ( $url ) { return '.zip' === substr( $url, -4 ); } ) ) >= 1, implode( ' ', $GLOBALS['mhs_asked'] ) );
	check( 'nothing is remembered as refused', false === get_site_option( Mailhouse_Updater::REFUSED ) );
	check( 'the plugin is still active', is_plugin_active( MAILHOUSE_BASENAME ) );

	mhs_pin( array( $mhs_pinned, $mhs_next ) );
	$result = mhs_try( '9.9.0', $zip, mhs_sig( '9.9.0', $hashes, array( $mhs_next ) ) );
	check( 'a release signed only by a next key this version pins also installs', $result['reached'], $result['refused'] );
	mhs_pin( array( $mhs_pinned ) );

	echo "== Through WordPress's upgrader: everything that must not install ==\n";

	mhs_refused( 'no signature at the address beside the zip', mhs_try( '9.9.0', $zip, 404 ), 'no signature' );
	mhs_refused( 'signed by a key this version does not pin', mhs_try( '9.9.0', $zip, mhs_sig( '9.9.0', $hashes, array( $mhs_stranger ) ) ), 'no key this version' );
	mhs_refused( 'a signature file that is not one', mhs_try( '9.9.0', $zip, '<html>Not Found</html>' ), 'not one' );

	$tampered = json_decode( $good, true );
	$tampered['files']['includes/class-mailhouse-a.php'] = hash( 'sha256', "<?php\n// Something else.\n" );
	mhs_refused( 'a hash in the signature file changed to match a changed file', mhs_try( '9.9.0', mhs_zip( 'retouched', array_merge( $files, array( 'includes/class-mailhouse-a.php' => "<?php\n// Something else.\n" ) ) ), wp_json_encode( $tampered ) ), 'no key this version' );

	$changed = array_merge( $files, array( 'includes/class-mailhouse-a.php' => "<?php\n// Something else.\n" ) );
	mhs_refused( 'one file changed in the zip', mhs_try( '9.9.0', mhs_zip( 'changed', $changed ), $good ), 'changed includes/class-mailhouse-a.php' );

	$added = array_merge( $files, array( 'includes/backdoor.php' => "<?php\n// Not signed.\n" ) );
	mhs_refused( 'a file added to the zip', mhs_try( '9.9.0', mhs_zip( 'added', $added ), $good ), 'not signed includes/backdoor.php' );

	$taken = $files;
	unset( $taken['includes/deeper/b.php'] );
	mhs_refused( 'a file taken out of the zip', mhs_try( '9.9.0', mhs_zip( 'taken', $taken ), $good ), 'missing includes/deeper/b.php' );

	mhs_refused( 'the right files in another folder', mhs_try( '9.9.0', mhs_zip( 'folder', $files, 'mailhouse-main' ), $good ), 'single mailhouse folder' );
	mhs_refused( 'the right files with no folder at all', mhs_try( '9.9.0', mhs_zip( 'flat', $files, '' ), $good ), 'single mailhouse folder' );
	$garbage = $mhs_dir . '/garbage.zip';
	file_put_contents( $garbage, 'Not a zip at all.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	mhs_refused( 'a zip that is not a zip', mhs_try( '9.9.0', $garbage, $good ), 'could not be opened' );

	$old = mhs_files( '9.8.0' );
	mhs_refused( 'a release of 9.8.0, signed, offered as 9.9.0', mhs_try( '9.9.0', mhs_zip( 'old', $old ), mhs_sig( '9.8.0', mhs_hashes( $old ), array( $mhs_pinned ) ) ), 'signature is for version 9.8.0' );

	mhs_pin( array() );
	mhs_refused( 'a version that pins no key', mhs_try( '9.9.0', $zip, $good ), 'pins no release key' );
	mhs_pin( array( $mhs_pinned ) );

	$result = mhs_try( '9.9.0', mhs_zip( 'changed', $changed ), $good, true );
	mhs_refused( 'one file changed, through a bulk update', $result, 'changed includes/class-mailhouse-a.php' );
	check( 'and the bulk update took the site out of maintenance again', ! file_exists( ABSPATH . '.maintenance' ) );

	check(
		'no release whose signature is missing, by another key, retouched or not a signature reached the install',
		mhs_all_refused( array( 'no signature at the address beside the zip', 'signed by a key this version does not pin', 'a signature file that is not one', 'a hash in the signature file changed to match a changed file' ) )
	);
	check(
		'no zip with a file changed, added or taken out, or in another folder, reached the install',
		mhs_all_refused( array( 'one file changed in the zip', 'a file added to the zip', 'a file taken out of the zip', 'the right files in another folder', 'the right files with no folder at all', 'a zip that is not a zip' ) )
	);
	check(
		'no release signed for another version, or met by a version that pins no key, reached the install',
		mhs_all_refused( array( 'a release of 9.8.0, signed, offered as 9.9.0', 'a version that pins no key' ) )
	);
	check( 'nor did a changed zip through a bulk update', mhs_all_refused( array( 'one file changed, through a bulk update' ) ) );

	echo "== Another plugin handing WordPress a package of its own ==\n";

	$handed = mhs_zip( 'handed', $added );
	$hand   = static function ( $reply, $package, $upgrader, $extra ) use ( &$handed ) {
		if ( is_array( $extra ) && isset( $extra['plugin'] ) && MAILHOUSE_BASENAME === $extra['plugin'] ) {
			$copy = wp_tempnam( 'handed.zip' );
			copy( $handed, $copy );
			return $copy;
		}
		return $reply;
	};
	add_filter( 'upgrader_pre_download', $hand, 5, 4 );
	mhs_refused( 'an unsigned package put in place of the download', mhs_try( '9.9.0', $zip, $good ), 'not signed includes/backdoor.php' );

	$handed = $zip;
	$result = mhs_try( '9.9.0', $zip, $good );
	check( 'the signed package put in place of the download is checked the same way, and installs', $result['reached'], $result['refused'] );
	check( 'nor did an unsigned package put in place of the download by another plugin', mhs_all_refused( array( 'an unsigned package put in place of the download' ) ) );
	remove_filter( 'upgrader_pre_download', $hand, 5 );

	echo "== The folder about to be put in place, checked again ==\n";

	// The last good attempt left what it checked on the updater, as it
	// would be between the download and the install.
	$unpacked = $mhs_dir . '/unpacked/mailhouse';
	foreach ( $files as $path => $content ) {
		wp_mkdir_p( dirname( $unpacked . '/' . $path ) );
		file_put_contents( $unpacked . '/' . $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	$extra = array( 'plugin' => MAILHOUSE_BASENAME );
	check( 'the folder that was checked passes', $unpacked . '/' === $updater->check_source( $unpacked . '/', $mhs_dir . '/unpacked', null, $extra ) );

	file_put_contents( $unpacked . '/includes/deeper/b.php', "<?php\n// Changed after the check.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$checked = $updater->check_source( $unpacked . '/', $mhs_dir . '/unpacked', null, $extra );
	check( 'a file changed after the check is caught', is_wp_error( $checked ) && false !== strpos( $checked->get_error_message(), 'changed includes/deeper/b.php' ), is_wp_error( $checked ) ? $checked->get_error_message() : 'passed' );

	check( 'another plugin\'s folder is left alone', 'elsewhere/' === $updater->check_source( 'elsewhere/', '', null, array( 'plugin' => 'other/other.php' ) ) );

	$fresh   = new Mailhouse_Updater();
	$checked = $fresh->check_source( $unpacked . '/', $mhs_dir . '/unpacked', null, $extra );
	remove_filter( 'update_plugins_github.com', array( $fresh, 'offer' ), 10 );
	remove_filter( 'plugins_api', array( $fresh, 'details' ), 10 );
	remove_filter( 'plugin_row_meta', array( $fresh, 'row_meta' ), 10 );
	remove_action( 'admin_post_mailhouse_check_updates', array( $fresh, 'handle_check' ) );
	remove_action( 'upgrader_process_complete', array( $fresh, 'forget' ), 10 );
	remove_filter( 'upgrader_pre_download', array( $fresh, 'check_download' ), 10 );
	remove_filter( 'upgrader_source_selection', array( $fresh, 'check_source' ), 999 );
	check( 'a folder nothing checked before it was unpacked is refused', is_wp_error( $checked ) && false !== strpos( $checked->get_error_message(), 'not checked' ), is_wp_error( $checked ) ? $checked->get_error_message() : 'passed' );
}

echo "== What it will not do, whatever the signature ==\n";

$GLOBALS['mhs_asked'] = array();
check( 'another plugin\'s download is left alone, and costs no request', false === $updater->check_download( false, 'https://example.com/other.zip', null, array( 'plugin' => 'other/other.php' ) ) && array() === $GLOBALS['mhs_asked'] );
check( 'an administrator\'s upload is not an update, and is left alone', false === $updater->check_download( false, $zip, null, array( 'type' => 'plugin', 'action' => 'install' ) ) );

$state                                  = new stdClass();
$state->response                        = array();
$state->response[ MAILHOUSE_BASENAME ]  = (object) array(
	'new_version' => MAILHOUSE_VERSION,
	'package'     => 'https://github.com/estuty/mailhouse/releases/download/v' . MAILHOUSE_VERSION . '/mailhouse-' . MAILHOUSE_VERSION . '.zip',
);
set_site_transient( 'update_plugins', $state );
$refusal = $updater->check_download( false, $state->response[ MAILHOUSE_BASENAME ]->package, null, array( 'plugin' => MAILHOUSE_BASENAME ) );
check( 'the version already installed is not installed again', is_wp_error( $refusal ) && false !== strpos( $refusal->get_error_message(), 'not newer' ), is_wp_error( $refusal ) ? $refusal->get_error_message() : 'allowed' );

$state->response[ MAILHOUSE_BASENAME ]->new_version = '1.0.0';
set_site_transient( 'update_plugins', $state );
$refusal = $updater->check_download( false, 'https://github.com/estuty/mailhouse/releases/download/v1.0.0/mailhouse-1.0.0.zip', null, array( 'plugin' => MAILHOUSE_BASENAME ) );
check( 'nor an older one', is_wp_error( $refusal ) && false !== strpos( $refusal->get_error_message(), 'not newer' ) );

$state->response[ MAILHOUSE_BASENAME ]->new_version = '9.9.0';
set_site_transient( 'update_plugins', $state );
$refusal = $updater->check_download( false, 'http://github.com/estuty/mailhouse/releases/download/v9.9.0/mailhouse-9.9.0.zip', null, array( 'plugin' => MAILHOUSE_BASENAME ) );
check( 'nor anything not fetched over https', is_wp_error( $refusal ) );

delete_site_transient( 'update_plugins' );
delete_site_transient( 'mailhouse_latest_release' );
$refusal = $updater->check_download( false, 'https://github.com/estuty/mailhouse/releases/download/v9.9.0/mailhouse-9.9.0.zip', null, array( 'plugin' => MAILHOUSE_BASENAME ) );
check( 'nor anything when no update is on offer', is_wp_error( $refusal ) && false !== strpos( $refusal->get_error_message(), 'no update' ) );

echo "== Saying so ==\n";

update_site_option(
	Mailhouse_Updater::REFUSED,
	array(
		'version' => '9.9.0',
		'reason'  => 'its release has no signature',
		'at'      => time(),
	)
);
check( 'the status panel names the version refused and why', '9.9.0 was not installed: its release has no signature' === Mailhouse_Updater::status(), Mailhouse_Updater::status() );

update_site_option(
	Mailhouse_Updater::REFUSED,
	array(
		'version' => MAILHOUSE_VERSION,
		'reason'  => 'old news',
		'at'      => time(),
	)
);
check( 'and stops once that version or a newer one is installed', false === strpos( Mailhouse_Updater::status(), 'old news' ), Mailhouse_Updater::status() );
delete_site_option( Mailhouse_Updater::REFUSED );

echo "== The release tool ==\n";

$git  = array();
$code = 1;
exec( 'git --version 2>&1', $git, $code );
$repo = array();
exec( 'git -C ' . escapeshellarg( MAILHOUSE_PATH ) . ' rev-parse --verify HEAD 2>&1', $repo, $repo_code );

if ( 0 !== $code || 0 !== $repo_code || ! function_exists( 'exec' ) ) {
	echo "  -- no git here, or the plugin is not a checkout: the tool is not run\n";
} else {
	$probe = array();
	exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( 'echo extension_loaded("sodium") ? 1 : 0;' ), $probe );
	$php = escapeshellarg( PHP_BINARY ) . ( '1' === trim( implode( '', $probe ) ) ? '' : ' -d extension_dir=' . escapeshellarg( (string) ini_get( 'extension_dir' ) ) . ' -d extension=sodium' );

	/**
	 * Run the tool.
	 *
	 * @param string $php  PHP, with what it needs.
	 * @param string $args Arguments, escaped.
	 * @return array{0:int,1:string}
	 */
	$tool = static function ( $args ) use ( $php ) {
		$out  = array();
		$code = 0;
		exec( $php . ' ' . escapeshellarg( MAILHOUSE_PATH . 'tools/release.php' ) . ' ' . $args . ' 2>&1', $out, $code );
		return array( $code, implode( "\n", $out ) );
	};

	$secret = $mhs_dir . '/tool.key';
	list( $code, $out ) = $tool( 'key ' . escapeshellarg( $secret ) );
	check( 'it makes a key', 0 === $code && is_readable( $secret ) && false !== strpos( $out, 'key id      r-' ), $out );
	check( 'and prints no secret', false === strpos( $out, trim( (string) file_get_contents( $secret ) ) ) );

	list( $code, $out ) = $tool( 'key ' . escapeshellarg( $secret ) );
	check( 'it never writes over a key', 0 !== $code && false !== strpos( $out, 'never overwritten' ), $out );

	$raw    = base64_decode( trim( (string) file_get_contents( $secret ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	$keyset = $mhs_dir . '/keys.php';
	file_put_contents( $keyset, "<?php\nreturn array( '" . base64_encode( sodium_crypto_sign_publickey_from_secretkey( $raw ) ) . "' );\n" ); // phpcs:ignore

	$built = $mhs_dir . '/head.zip';
	exec( 'git -C ' . escapeshellarg( MAILHOUSE_PATH ) . ' -c core.autocrlf=false -c core.eol=lf archive --format=zip -6 --prefix=mailhouse/ HEAD -o ' . escapeshellarg( $built ) . ' 2>&1' );

	$sig = $mhs_dir . '/head.sig';
	list( $code, $out ) = $tool( 'sign HEAD ' . escapeshellarg( $secret ) . ' --zip ' . escapeshellarg( $built ) . ' --out ' . escapeshellarg( $sig ) );
	check( 'it signs what HEAD builds in this checkout, having compared the zip', 0 === $code && is_readable( $sig ) && false !== strpos( $out, 'holds exactly what HEAD builds' ), $out );

	$document = json_decode( (string) file_get_contents( $sig ), true );
	check( 'the signature file lists the files of the tag, not the tests', is_array( $document ) && isset( $document['files']['mailhouse.php'] ) && ! isset( $document['files']['tests/bootstrap.php'] ) && ! isset( $document['files']['tools/release.php'] ) );
	check( 'and names the version in the plugin\'s header', is_array( $document ) && MAILHOUSE_VERSION === $document['version'] );

	list( $code, $out ) = $tool( 'verify ' . escapeshellarg( $built ) . ' ' . escapeshellarg( $sig ) . ' --keys ' . escapeshellarg( $keyset ) );
	check( 'it accepts the zip with the keys that signed it', 0 === $code && false !== strpos( $out, 'accepted' ), $out );

	$empty = $mhs_dir . '/none.php';
	file_put_contents( $empty, "<?php\nreturn array();\n" ); // phpcs:ignore
	list( $code, $out ) = $tool( 'verify ' . escapeshellarg( $built ) . ' ' . escapeshellarg( $sig ) . ' --keys ' . escapeshellarg( $keyset ) . ' --keys ' . escapeshellarg( $empty ) );
	check( 'and refuses it when any set of keys asked does not accept it', 0 !== $code && false !== strpos( $out, 'REFUSED' ), $out );

	$changed_zip = $mhs_dir . '/changed-head.zip';
	copy( $built, $changed_zip );
	$archive = new ZipArchive();
	$archive->open( $changed_zip );
	$archive->addFromString( 'mailhouse/mailhouse.php', $archive->getFromName( 'mailhouse/mailhouse.php' ) . "\n// changed\n" );
	$archive->close();

	list( $code, $out ) = $tool( 'verify ' . escapeshellarg( $changed_zip ) . ' ' . escapeshellarg( $sig ) . ' --keys ' . escapeshellarg( $keyset ) );
	check( 'it refuses a zip with a file changed, naming it', 0 !== $code && false !== strpos( $out, 'changed mailhouse.php' ), $out );

	$other = $mhs_dir . '/other.sig';
	list( $code, $out ) = $tool( 'sign HEAD ' . escapeshellarg( $secret ) . ' --zip ' . escapeshellarg( $changed_zip ) . ' --out ' . escapeshellarg( $other ) );
	check( 'and signs nothing when the zip attached is not what the tag builds', 0 !== $code && ! file_exists( $other ) && false !== strpos( $out, 'nothing was signed' ), $out );

	list( $code, $out ) = $tool( 'sign HEAD ' . escapeshellarg( $secret ) . ' --out ' . escapeshellarg( $sig ) );
	check( 'it will not sign twice with one key', 0 !== $code && false !== strpos( $out, 'already carries' ), $out );

	list( $code, $out ) = $tool( 'keys --keys ' . escapeshellarg( $empty ) );
	check( 'it says when no key is pinned, and fails', 0 !== $code && false !== strpos( $out, 'no release key' ), $out );

	// What the tool signed, a site believes: the same code reads it.
	$read = Mailhouse_Release::read( (string) file_get_contents( $sig ), Mailhouse_Release::pinned( $keyset ), $why );
	check( 'what the tool signs, the plugin believes', is_array( $read ) && MAILHOUSE_VERSION === $read['version'], $why );
}

echo "== Nothing was installed ==\n";

check( "the plugin's files are exactly as they were", mhs_installed() === $mhs_before );
check( 'it is still active', is_plugin_active( MAILHOUSE_BASENAME ) );
check( 'and no backup was left to be restored over it', ! file_exists( $mhs_backup ) || ! $mhs_safe );

remove_all_filters( 'pre_http_request' );
remove_all_filters( 'mailhouse_release_keys' );
delete_site_transient( 'mailhouse_latest_release' );
delete_site_transient( 'update_plugins' );
delete_site_option( Mailhouse_Updater::REFUSED );

global $wp_filesystem;
if ( $wp_filesystem ) {
	$wp_filesystem->delete( $mhs_dir, true );
}

mailhouse_test_results();
