<?php
/**
 * Test bootstrap.
 *
 * Loads a real WordPress rather than mocking one. These are integration tests:
 * the things worth checking here -- dbDelta actually creating the tables, a
 * rewrite rule actually matching, PHPMailer actually assembling a message --
 * are exactly the things a mock would get wrong.
 *
 * Point it at a site with:
 *
 *   MAILHOUSE_WP_ROOT=/path/to/wordpress php tests/test-core.php
 *
 * With nothing set it walks up from this file looking for wp-load.php, which
 * covers the normal case of the plugin sitting in wp-content/plugins.
 *
 * @package Mailhouse
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'These tests run from the command line.' );
}

if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}

$_SERVER['HTTP_HOST']      = getenv( 'MAILHOUSE_TEST_HOST' ) ? getenv( 'MAILHOUSE_TEST_HOST' ) : 'localhost';
$_SERVER['SERVER_NAME']    = $_SERVER['HTTP_HOST'];
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

$mailhouse_wp_load = '';
$mailhouse_root    = getenv( 'MAILHOUSE_WP_ROOT' );

if ( $mailhouse_root && is_readable( rtrim( $mailhouse_root, '/\\' ) . '/wp-load.php' ) ) {
	$mailhouse_wp_load = rtrim( $mailhouse_root, '/\\' ) . '/wp-load.php';
} else {
	$mailhouse_dir = __DIR__;

	for ( $i = 0; $i < 6; $i++ ) {
		$mailhouse_dir = dirname( $mailhouse_dir );

		if ( is_readable( $mailhouse_dir . '/wp-load.php' ) ) {
			$mailhouse_wp_load = $mailhouse_dir . '/wp-load.php';
			break;
		}
	}
}

if ( ! $mailhouse_wp_load ) {
	exit( "Could not find wp-load.php. Set MAILHOUSE_WP_ROOT to the WordPress directory.\n" );
}

require $mailhouse_wp_load;

if ( ! function_exists( 'mailhouse' ) ) {
	exit( "Mailhouse is not active on that site. Activate it and run this again.\n" );
}

$GLOBALS['mailhouse_pass'] = 0;
$GLOBALS['mailhouse_fail'] = 0;

/**
 * Assert something, and say so either way.
 *
 * @param string $label     What is being checked.
 * @param bool   $condition The result.
 * @param string $detail    Printed on failure.
 * @return void
 */
function check( $label, $condition, $detail = '' ) {
	if ( $condition ) {
		++$GLOBALS['mailhouse_pass'];
		echo "  ok   $label\n";
	} else {
		++$GLOBALS['mailhouse_fail'];
		echo "  FAIL $label" . ( $detail ? " -- $detail" : '' ) . "\n";
	}
}

/**
 * Print the tally and exit with a status a CI job can read.
 *
 * @return void
 */
function mailhouse_test_results() {
	printf( "\n%d passed, %d failed\n", $GLOBALS['mailhouse_pass'], $GLOBALS['mailhouse_fail'] );

	exit( $GLOBALS['mailhouse_fail'] ? 1 : 0 );
}
