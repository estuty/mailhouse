<?php
/**
 * Runs the connector with MAILHOUSE_CENTRAL_DISABLED defined, as it would
 * be in wp-config.php, and reports what it did. A constant cannot be taken
 * back once defined, so this needs a process of its own; tests/test-central.php
 * starts it against a site it has already connected.
 *
 * Prints one line of JSON.
 *
 * @package Mailhouse
 */

define( 'MAILHOUSE_CENTRAL_DISABLED', true );

ob_start();
require dirname( __DIR__ ) . '/bootstrap.php';
ob_end_clean();

$requests = 0;

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$requests ) {
		if ( false !== strpos( $url, 'central.' ) ) {
			++$requests;

			return new WP_Error( 'http_request_failed', 'no request should have been made' );
		}

		return $pre;
	},
	10,
	3
);

add_filter(
	'home_url',
	static function () {
		return 'https://shop.example.test/blog';
	}
);

Mailhouse_Central::tick();
do_action( 'mailhouse_process_queue' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

$heartbeat = Mailhouse_Central::heartbeat();
$signed    = Mailhouse_Central_Client::signed( 'POST', '/api/v1/connector/heartbeat', array() );
$register  = Mailhouse_Central_Identity::register( 'https://central.example.test', 'mhe_' . str_repeat( 'A', 43 ) );

echo wp_json_encode(
	array(
		'status'    => Mailhouse_Central_Identity::status(),
		'hooked'    => false !== has_action( 'mailhouse_process_queue', array( 'Mailhouse_Central', 'tick' ) ),
		'requests'  => $requests,
		'heartbeat' => is_wp_error( $heartbeat ) ? $heartbeat->get_error_code() : 'sent',
		'signed'    => is_wp_error( $signed ) ? $signed->get_error_code() : 'sent',
		'register'  => is_wp_error( $register ) ? $register->get_error_code() : 'registered',
	)
), "\n";
