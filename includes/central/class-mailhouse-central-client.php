<?php
/**
 * The connector's only way of talking to the centre.
 *
 * Every request goes to the one address fixed at registration, over https
 * with the certificate checked, follows no redirect, and -- registration
 * apart -- is signed with this site's key over the exact bytes sent. The
 * centre's answer is plain JSON; anything in it that would make this site
 * act is separately signed by the centre and checked before use
 * (Mailhouse_Central_Protocol::verify_command).
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Signed HTTPS client.
 */
class Mailhouse_Central_Client {

	/**
	 * Seconds to wait for the centre.
	 */
	const TIMEOUT = 15;

	/**
	 * A signed request to the centre this site is registered with.
	 *
	 * If the centre says this site's clock is out, the difference it
	 * reports is remembered and the request is made once more with the
	 * corrected time, so a drifting server does not lock itself out.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path, starting with a slash.
	 * @param array|null $body    Body to send as JSON, or null for none.
	 * @param int        $timeout Seconds.
	 * @return array{status:int,body:mixed}|WP_Error
	 */
	public static function signed( $method, $path, $body = null, $timeout = self::TIMEOUT ) {
		$state = Mailhouse_Central_Identity::state();

		if ( '' === $state['central_url'] || '' === $state['website_id'] ) {
			return new WP_Error( 'mailhouse_central_unregistered', __( 'This site is not connected.', 'mailhouse' ) );
		}

		$answer = self::send( $state['central_url'], $method, $path, $body, $state, $timeout );

		if ( ! is_wp_error( $answer ) && 401 === $answer['status'] && is_array( $answer['body'] ) && isset( $answer['body']['code'] ) && 'clock_skew' === $answer['body']['code'] ) {
			$offset = self::offset_from( $answer['body'] );

			if ( $offset !== (int) $state['clock_offset'] ) {
				Mailhouse_Central_Identity::update( array( 'clock_offset' => $offset ) );

				$answer = self::send( $state['central_url'], $method, $path, $body, Mailhouse_Central_Identity::state(), $timeout );
			}
		}

		return $answer;
	}

	/**
	 * Make one request.
	 *
	 * @param string     $central The centre's origin, already validated.
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path, starting with a slash.
	 * @param array|null $body    Body to send as JSON, or null for none.
	 * @param array|null $state   Connection state to sign with, or null to send unsigned.
	 * @param int        $timeout Seconds.
	 * @return array{status:int,body:mixed}|WP_Error
	 */
	public static function send( $central, $method, $path, $body, $state, $timeout = self::TIMEOUT ) {
		if ( Mailhouse_Central::disabled() ) {
			return new WP_Error( 'mailhouse_central_disabled', __( 'The connector is switched off.', 'mailhouse' ) );
		}

		// A string is a body already encoded, and is sent as it is.
		$json    = null === $body ? '' : ( is_string( $body ) ? $body : wp_json_encode( $body ) );
		$headers = array(
			'Accept'                                  => 'application/json',
			Mailhouse_Central_Protocol::HEADER_PROTOCOL => (string) Mailhouse_Central_Protocol::VERSION,
		);

		if ( '' !== $json ) {
			$headers['Content-Type'] = 'application/json';
		}

		if ( null !== $state ) {
			$timestamp = (string) ( time() + (int) $state['clock_offset'] );
			$nonce     = bin2hex( random_bytes( 16 ) );
			$signature = Mailhouse_Central_Protocol::sign(
				Mailhouse_Central_Protocol::canonical_request( $method, $path, array(), $timestamp, $nonce, $state['website_id'], $json ),
				Mailhouse_Central_Identity::private_key()
			);

			if ( '' === $signature ) {
				return new WP_Error( 'mailhouse_central_key', __( 'This site’s key is missing or cannot be read. Disconnect and connect again.', 'mailhouse' ) );
			}

			$headers[ Mailhouse_Central_Protocol::HEADER_WEBSITE ]   = $state['website_id'];
			$headers[ Mailhouse_Central_Protocol::HEADER_KEY ]       = $state['key_id'];
			$headers[ Mailhouse_Central_Protocol::HEADER_TIMESTAMP ] = $timestamp;
			$headers[ Mailhouse_Central_Protocol::HEADER_NONCE ]     = $nonce;
			$headers[ Mailhouse_Central_Protocol::HEADER_SIGNATURE ] = $signature;
		}

		$args = array(
			'method'      => strtoupper( $method ),
			'headers'     => $headers,
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'user-agent'  => 'Mailhouse/' . MAILHOUSE_VERSION . '; connector',
		);

		if ( '' !== $json ) {
			$args['body'] = $json;
		}

		$url = $central . $path;

		// The safe variant refuses private addresses and unusual ports,
		// which is right for a real centre. The development switch exists
		// for a rig whose centre is another container on the same machine.
		$response = Mailhouse_Central_Identity::insecure_allowed()
			? wp_remote_request( $url, $args )
			: wp_safe_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'mailhouse_central_unreachable', $response->get_error_message() );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => is_array( $decoded ) ? $decoded : null,
			'date'   => (string) wp_remote_retrieve_header( $response, 'date' ),
		);
	}

	/**
	 * How far the centre's clock is from this server's, from the
	 * `server_time` every answer carries.
	 *
	 * @param mixed $body Decoded response body.
	 * @return int Seconds to add to this server's clock; zero when unknown.
	 */
	public static function offset_from( $body ) {
		if ( ! is_array( $body ) || empty( $body['server_time'] ) || ! is_string( $body['server_time'] ) ) {
			return 0;
		}

		$theirs = strtotime( $body['server_time'] );

		if ( false === $theirs ) {
			return 0;
		}

		$offset = $theirs - time();

		// A second or two is the journey, not the clock.
		return abs( $offset ) < 5 ? 0 : $offset;
	}
}
