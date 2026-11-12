<?php
/**
 * The connector to Mailhouse Central: the protocol's worked examples,
 * registration, the signed heartbeat, what happens when the centre is slow,
 * down, or refusing, the cloned-site guard, the kill switch, and that a site
 * which is not connected does nothing at all.
 *
 * No centre is needed. Every request the connector makes is caught before
 * it leaves this process and answered by a stand-in below, which checks the
 * signature the way the real centre does.
 *
 * Usage: php tests/test-central.php
 *
 * @package Mailhouse
 */

require __DIR__ . '/bootstrap.php';

$saved_settings = get_option( 'mailhouse_settings' );
$saved_central  = get_option( 'mailhouse_central', null );

// Put back even if this file dies half-way.
register_shutdown_function(
	static function () use ( $saved_settings, $saved_central ) {
		update_option( 'mailhouse_settings', $saved_settings );

		if ( null === $saved_central ) {
			delete_option( 'mailhouse_central' );
		} else {
			update_option( 'mailhouse_central', $saved_central );
		}
	}
);

const MHC_CENTRAL = 'https://central.example.test';
const MHC_WEBSITE = '0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

/**
 * The stand-in centre.
 */
final class MHC_Centre {

	/** @var array[] Every request seen. */
	public static $requests = array();

	/** @var string How to answer heartbeats: ok, paused, down, 500, 401, 426, clock, or a 423 reason / 410. */
	public static $mode = 'ok';

	/** @var string How to answer a registration: ok, or an error code. */
	public static $register = 'ok';

	/** @var string The token this centre issued. */
	public static $token = '';

	/** @var string Registered public key, base64. */
	public static $site_key = '';

	/** @var string|null Platform secret key, raw. */
	public static $platform = null;

	/** @var int Seconds this centre's clock is ahead of the site's. */
	public static $ahead = 0;

	/** @var bool[] Whether each signed request verified. */
	public static $verified = array();

	/**
	 * Start again.
	 */
	public static function reset() {
		self::$requests = array();
		self::$verified = array();
		self::$mode     = 'ok';
		self::$register = 'ok';
		self::$ahead    = 0;
	}

	/**
	 * A fresh enrolment token.
	 */
	public static function issue_token() {
		self::$token = 'mhe_' . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore

		return self::$token;
	}

	/**
	 * The platform's public key, as a registration hands it over.
	 */
	public static function platform_keys() {
		if ( null === self::$platform ) {
			self::$platform = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		}

		return array(
			array(
				'key_id'     => 'p-test',
				'public_key' => base64_encode( sodium_crypto_sign_publickey_from_secretkey( self::$platform ) ), // phpcs:ignore
				'status'     => 'active',
			),
		);
	}

	/**
	 * A response in the shape WordPress's HTTP API returns.
	 */
	protected static function answer( $status, $body ) {
		$body['server_time'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + self::$ahead );

		return array(
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( array( 'date' => gmdate( 'D, d M Y H:i:s', time() + self::$ahead ) . ' GMT' ) ),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Whether a signed request carries the registered key's signature of
	 * exactly what was sent, with a clock this centre accepts.
	 */
	protected static function check( $method, $path, $headers, $body ) {
		foreach ( array( 'X-MH-Website', 'X-MH-Key', 'X-MH-Timestamp', 'X-MH-Nonce', 'X-MH-Signature' ) as $name ) {
			if ( empty( $headers[ $name ] ) ) {
				return 'unauthenticated';
			}
		}

		if ( abs( (int) $headers['X-MH-Timestamp'] - ( time() + self::$ahead ) ) > 300 ) {
			return 'clock_skew';
		}

		$message = implode(
			"\n",
			array( 'MH1', $method, $path, '', $headers['X-MH-Timestamp'], $headers['X-MH-Nonce'], $headers['X-MH-Website'], hash( 'sha256', $body ) )
		);

		$valid = MHC_WEBSITE === $headers['X-MH-Website']
			&& sodium_crypto_sign_verify_detached( base64_decode( $headers['X-MH-Signature'], true ), $message, base64_decode( self::$site_key, true ) ); // phpcs:ignore

		return $valid ? 'ok' : 'unauthenticated';
	}

	/**
	 * Catch a request and answer it.
	 */
	public static function handle( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://central.' ) ) {
			return $pre;
		}

		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		$body    = isset( $args['body'] ) ? (string) $args['body'] : '';
		$request = array(
			'url'     => $url,
			'method'  => $args['method'],
			'path'    => $path,
			'headers' => $args['headers'],
			'body'    => $body,
			'json'    => json_decode( $body, true ),
			'args'    => $args,
		);

		self::$requests[] = $request;

		if ( 'down' === self::$mode ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		}

		if ( '/api/health' === $path ) {
			return self::answer( 200, array( 'status' => 'ok' ) );
		}

		if ( '/api/v1/connector/register' === $path ) {
			$json = $request['json'];

			if ( 'ok' !== self::$register ) {
				$statuses = array(
					'gone'              => 410,
					'site_url_mismatch' => 422,
					'unauthenticated'   => 401,
					'rate_limited'      => 429,
					'malformed'         => 201,
				);

				return self::answer( $statuses[ self::$register ], 'malformed' === self::$register ? array( 'website_id' => 'nonsense' ) : array( 'code' => self::$register ) );
			}

			$proof = implode( "\n", array( 'MH1-REG', $json['enrolment_token'], $json['site_url'], $json['public_key'], $json['key_id'] ) );

			if ( $json['enrolment_token'] !== self::$token ) {
				return self::answer( 410, array( 'code' => 'gone' ) );
			}

			if ( ! sodium_crypto_sign_verify_detached( base64_decode( $json['proof'], true ), $proof, base64_decode( $json['public_key'], true ) ) ) { // phpcs:ignore
				return self::answer( 401, array( 'code' => 'unauthenticated' ) );
			}

			self::$site_key = $json['public_key'];
			self::$token    = '';

			return self::answer(
				201,
				array(
					'website_id'            => MHC_WEBSITE,
					'platform_keys'         => self::platform_keys(),
					'protocol'              => 1,
					'endpoints'             => array( 'base' => 'https://somewhere-else.example.test/api/v1/connector' ),
					'poll_interval_seconds' => 60,
				)
			);
		}

		$outcome          = self::check( $args['method'], $path, $args['headers'], $body );
		self::$verified[] = 'ok' === $outcome;

		if ( 'ok' !== $outcome ) {
			return self::answer( 401, array( 'code' => $outcome ) );
		}

		if ( '/api/v1/connector/disconnect' === $path ) {
			return self::answer( 200, array( 'disconnected' => true ) );
		}

		switch ( self::$mode ) {
			case 'paused':
				return self::answer( 200, array( 'status' => 'paused', 'commands_pending' => 0 ) );
			case '500':
				return self::answer( 500, array( 'code' => 'internal' ) );
			case '401':
				return self::answer( 401, array( 'code' => 'unauthenticated' ) );
			case '426':
				return self::answer( 426, array( 'code' => 'protocol_unsupported' ) );
			case '429':
				return self::answer( 429, array( 'code' => 'rate_limited' ) );
			case 'gone':
				return self::answer( 410, array( 'code' => 'gone' ) );
			case 'key_revoked':
			case 'disconnected':
			case 'site_url_mismatch':
				return self::answer( 423, array( 'code' => 'website_suspended', 'reason' => self::$mode ) );
		}

		return self::answer( 200, array( 'status' => 'connected', 'commands_pending' => 0, 'ack_through_seq' => 0, 'want' => array() ) );
	}
}

add_filter( 'pre_http_request', array( 'MHC_Centre', 'handle' ), 10, 3 );

// The dev site is on plain http. A connected site is an https one.
$mhc_home = 'https://shop.example.test';
add_filter(
	'home_url',
	static function ( $url, $path ) use ( &$mhc_home ) {
		return $mhc_home . ( $path ? '/' . ltrim( $path, '/' ) : '' );
	},
	10,
	2
);

/**
 * How many requests reached the stand-in since the last call.
 */
function mhc_requests() {
	$count                 = count( MHC_Centre::$requests );
	MHC_Centre::$requests = array();

	return $count;
}

/**
 * Forget any connection, as a site that never had one.
 */
function mhc_forget() {
	delete_option( 'mailhouse_central' );
	Mailhouse_Settings::set(
		array(
			'central_connected'   => false,
			'central_private_key' => '',
		)
	);
	Mailhouse_Central_Identity::flush();
	MHC_Centre::reset();
}

/**
 * Connect to the stand-in.
 */
function mhc_connect() {
	mhc_forget();

	$result = Mailhouse_Central_Identity::register( MHC_CENTRAL, MHC_Centre::issue_token() );
	mhc_requests();

	return $result;
}

/**
 * Let the next tick send, whatever the last one decided.
 */
function mhc_due() {
	Mailhouse_Central_Identity::update(
		array(
			'next_attempt_at'   => 0,
			'last_heartbeat_at' => 0,
		)
	);
}

mhc_forget();

// ---------------------------------------------------------------------------
echo "\nThe protocol's worked examples\n";

$fixture = __DIR__ . '/fixtures/protocol-v1.json';
$vectors = is_readable( $fixture ) ? json_decode( (string) file_get_contents( $fixture ), true ) : null; // phpcs:ignore

check( 'the worked examples are here, and are for protocol 1', is_array( $vectors ) && 1 === $vectors['protocol'] );
check( 'sodium is available', Mailhouse_Central_Protocol::available() );

$keys = array();

foreach ( $vectors['keys'] as $key ) {
	$described = Mailhouse_Central_Protocol::describe_key( sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( hex2bin( $key['seed_hex'] ) ) ) );

	$keys[ $key['name'] ] = $described;

	check( "key {$key['name']}: public key and id from the seed", $described['public'] === $key['public_key'] && 'k-' . $key['fingerprint'] === $described['key_id'] );
}

$wrong = array();

foreach ( $vectors['canonical_query'] as $case ) {
	if ( Mailhouse_Central_Protocol::canonical_query( $case['pairs'] ) !== $case['output'] ) {
		$wrong[] = wp_json_encode( $case['pairs'] );
	}
}
check( count( $vectors['canonical_query'] ) . ' query strings are ordered and encoded as the centre does', ! $wrong, implode( ' ', $wrong ) );

$wrong = array();

foreach ( $vectors['site_urls'] as $case ) {
	list( $canonical, $problem ) = Mailhouse_Central_Protocol::canonical_site_url( $case['input'], $case['allow_loopback_http'] );

	$agrees = $canonical === $case['canonical']
		&& ( null === $canonical ? $problem === $case['problem'] : Mailhouse_Central_Protocol::site_url_hash( $canonical ) === $case['hash'] );

	if ( ! $agrees ) {
		$wrong[] = $case['input'];
	}
}
check( count( $vectors['site_urls'] ) . ' site addresses are read as the centre reads them', ! $wrong, implode( ' | ', $wrong ) );

$wrong = array();

foreach ( $vectors['requests'] as $request ) {
	$canonical = Mailhouse_Central_Protocol::canonical_request( $request['method'], $request['path'], $request['query'], $request['timestamp'], $request['nonce'], $request['website_id'], $request['body'] );

	if ( $canonical !== $request['canonical'] || Mailhouse_Central_Protocol::sign( $canonical, $keys[ $request['key'] ]['secret'] ) !== $request['signature'] ) {
		$wrong[] = $request['name'];
	}
}
check( count( $vectors['requests'] ) . ' requests: the same canonical string and the same signature', ! $wrong, implode( ' | ', $wrong ) );

$wrong = array();

foreach ( $vectors['registrations'] as $registration ) {
	$message = Mailhouse_Central_Protocol::registration_message( $registration['enrolment_token'], $registration['site_url'], $registration['public_key'], $registration['key_id'] );

	if ( $message !== $registration['message'] || Mailhouse_Central_Protocol::sign( $message, $keys[ $registration['key'] ]['secret'] ) !== $registration['proof'] ) {
		$wrong[] = $registration['name'];
	}
}
check( count( $vectors['registrations'] ) . ' registrations: the same message and the same proof', ! $wrong, implode( ' | ', $wrong ) );

$pinned = array(
	array(
		'key_id'     => 'p-test',
		'public_key' => $keys['platform']['public'],
	),
);
$wrong  = array();

foreach ( $vectors['commands'] as $command ) {
	if ( Mailhouse_Central_Protocol::command_message( $command ) !== $command['message'] || ! Mailhouse_Central_Protocol::verify_command( $command, $pinned ) ) {
		$wrong[] = $command['name'];
	}
}
check( count( $vectors['commands'] ) . ' commands signed by the platform key are accepted', ! $wrong, implode( ' | ', $wrong ) );

$wrong = array();

foreach ( $vectors['tampered_commands'] as $command ) {
	// Checked against the key the example names. One example changes
	// nothing but that: the same command, held up to a site's key instead
	// of the platform's.
	$against = array(
		array(
			'key_id'     => 'p-test',
			'public_key' => $keys[ $command['key'] ]['public'],
		),
	);

	if ( Mailhouse_Central_Protocol::verify_command( $command, $against ) ) {
		$wrong[] = $command['name'];
	}
}
check( count( $vectors['tampered_commands'] ) . ' commands changed after signing are refused', ! $wrong, implode( ' | ', $wrong ) );

$good = $vectors['commands'][0];

check( 'a command is refused when its key is not one this site pinned', ! Mailhouse_Central_Protocol::verify_command( $good, array( array( 'key_id' => 'p-other', 'public_key' => $keys['platform']['public'] ) ) ) );
check( 'a command is refused when the pinned key of that name is a different key', ! Mailhouse_Central_Protocol::verify_command( $good, array( array( 'key_id' => 'p-test', 'public_key' => $keys['site-a']['public'] ) ) ) );
check( 'a command is refused with no pinned keys at all', ! Mailhouse_Central_Protocol::verify_command( $good, array() ) );
check(
	'something that is not a command is refused, not choked on',
	! Mailhouse_Central_Protocol::verify_command( null, $pinned )
	&& ! Mailhouse_Central_Protocol::verify_command( 'a string', $pinned )
	&& ! Mailhouse_Central_Protocol::verify_command( array( 'id' => 'x' ), $pinned )
	&& ! Mailhouse_Central_Protocol::verify_command( array_merge( $good, array( 'signature' => 'not base64' ) ), $pinned )
	&& ! Mailhouse_Central_Protocol::verify_command( array_merge( $good, array( 'payload_json' => array( 'nested' => true ) ) ), $pinned )
);

// ---------------------------------------------------------------------------
echo "\nA site that is not connected\n";

mhc_forget();

check( 'has no status and no stored connection', '' === Mailhouse_Central_Identity::status() && false === get_option( 'mailhouse_central', false ) );
check( 'has no scheduled work: the tick is not hooked', false === has_action( 'mailhouse_process_queue', array( 'Mailhouse_Central', 'tick' ) ) );

Mailhouse_Central::tick();
do_action( 'mailhouse_process_queue' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

check( 'sends nothing when the queue runs', 0 === mhc_requests() );
check( 'writes nothing: there is still no stored connection', false === get_option( 'mailhouse_central', false ) );
check( 'a signed request is refused before it is made', is_wp_error( Mailhouse_Central_Client::signed( 'POST', '/api/v1/connector/heartbeat', array() ) ) && 0 === mhc_requests() );
check( 'the private key is a secret setting, encrypted and never printed', in_array( 'central_private_key', Mailhouse_Settings::SECRETS, true ) && 'MAILHOUSE_CENTRAL_PRIVATE_KEY' === Mailhouse_Settings::constant_for( 'central_private_key' ) );

// ---------------------------------------------------------------------------
echo "\nRegistering\n";

foreach (
	array(
		'plain http'              => 'http://central.example.test',
		'no scheme'               => 'central.example.test',
		'a path'                  => 'https://central.example.test/dashboard',
		'a user name'             => 'https://admin@central.example.test',
		'a query'                 => 'https://central.example.test/?x=1',
		'nothing'                 => '',
	) as $what => $address
) {
	// A development site with the switch on in wp-config.php may use plain
	// http, which is the one thing that switch is for.
	if ( 'plain http' === $what && Mailhouse_Central_Identity::insecure_allowed() ) {
		continue;
	}

	$result = Mailhouse_Central_Identity::register( $address, MHC_Centre::issue_token() );

	check( "a central address with $what is refused, and nothing is sent", is_wp_error( $result ) && 'mailhouse_central_url' === $result->get_error_code() && 0 === mhc_requests() );
}

$result = Mailhouse_Central_Identity::register( MHC_CENTRAL, 'not-a-token' );
check( 'something that is not a token is refused, and nothing is sent', is_wp_error( $result ) && 'mailhouse_central_token' === $result->get_error_code() && 0 === mhc_requests() );

$mhc_home = 'http://shop.example.test';
$result   = Mailhouse_Central_Identity::register( MHC_CENTRAL, MHC_Centre::issue_token() );
check( 'a site that is not https cannot register, and nothing is sent', is_wp_error( $result ) && 'mailhouse_central_site_url' === $result->get_error_code() && 0 === mhc_requests() );
$mhc_home = 'https://Shop.Example.TEST/blog/';

foreach ( array( 'gone', 'site_url_mismatch', 'unauthenticated', 'rate_limited', 'malformed' ) as $refusal ) {
	mhc_forget();
	MHC_Centre::$register = $refusal;

	$result = Mailhouse_Central_Identity::register( MHC_CENTRAL, MHC_Centre::issue_token() );

	check(
		"refused by the centre ($refusal): an error, and nothing is kept",
		is_wp_error( $result )
		&& '' === Mailhouse_Central_Identity::status()
		&& false === get_option( 'mailhouse_central', false )
		&& '' === Mailhouse_Central_Identity::private_key()
	);
}

mhc_forget();
$token  = MHC_Centre::issue_token();
$result = Mailhouse_Central_Identity::register( MHC_CENTRAL . '/', "  $token  " );
$sent   = MHC_Centre::$requests[0];
$state  = Mailhouse_Central_Identity::state();
$stored = get_option( 'mailhouse_settings' );
mhc_requests();

check( 'a good token registers the site', true === $result && 'connected' === $state['status'] );
check( 'the request went to the address typed, over https, with certificates checked and no redirects', MHC_CENTRAL . '/api/v1/connector/register' === $sent['url'] && true === $sent['args']['sslverify'] && 0 === $sent['args']['redirection'] );
check( 'it sent this site’s address in the one canonical spelling', 'https://shop.example.test/blog' === $sent['json']['site_url'] && 'https://shop.example.test/blog' === $state['registered_url'] );
check( 'it sent a public key, an id made from that key, and what the site runs', 32 === strlen( base64_decode( $sent['json']['public_key'], true ) ) && 'k-' . substr( hash( 'sha256', base64_decode( $sent['json']['public_key'], true ) ), 0, 16 ) === $sent['json']['key_id'] && MAILHOUSE_VERSION === $sent['json']['plugin_version'] && array( 1 ) === $sent['json']['protocol_versions'] && 'InnoDB' === $sent['json']['environment']['db_engine'] ); // phpcs:ignore
check( 'the stand-in verified the proof of possession', MHC_Centre::$site_key === $sent['json']['public_key'] );
check( 'the private key was not sent', false === strpos( $sent['body'], Mailhouse_Central_Identity::private_key() ) && 64 === strlen( base64_decode( Mailhouse_Central_Identity::private_key(), true ) ) ); // phpcs:ignore
check( 'the private key is stored encrypted', 0 === strpos( $stored['central_private_key'], 'mh1:' ) && false === strpos( wp_json_encode( $stored ), Mailhouse_Central_Identity::private_key() ) );
check( 'the token is not kept anywhere', false === strpos( wp_json_encode( $stored ) . wp_json_encode( get_option( 'mailhouse_central' ) ), $token ) );
check( 'the centre’s id for this site and its public key are pinned', MHC_WEBSITE === $state['website_id'] && 'p-test' === $state['platform_keys'][0]['key_id'] && 1 === $state['protocol'] );
check( 'the address the centre suggests for itself is ignored: the one typed is kept', MHC_CENTRAL === $state['central_url'] );
check( 'a command signed by the pinned platform key would be accepted', Mailhouse_Central_Protocol::verify_command( array_merge( $good, array( 'key_id' => 'p-test' ) ), array( array( 'key_id' => 'p-test', 'public_key' => $keys['platform']['public'] ) ) ) );

$again = Mailhouse_Central_Identity::register( MHC_CENTRAL, MHC_Centre::issue_token() );
check( 'a connected site cannot register again without disconnecting', is_wp_error( $again ) && 'mailhouse_central_connected' === $again->get_error_code() && 0 === mhc_requests() );

// ---------------------------------------------------------------------------
echo "\nReporting in\n";

Mailhouse_Settings::set(
	array(
		'mailer'    => 'smtp',
		'smtp_host' => 'smtp.secret-host.example',
		'smtp_user' => 'mailer-user',
		'smtp_pass' => 'hunter2-hunter2',
	)
);

$result = Mailhouse_Central::heartbeat();
$sent   = MHC_Centre::$requests[0];
$state  = Mailhouse_Central_Identity::state();
mhc_requests();

check( 'a heartbeat is accepted', true === $result && $state['last_heartbeat_at'] > 0 && 'connected' === $state['centre_status'] );
check( 'it carried a signature the stand-in verified against the registered key', array( true ) === MHC_Centre::$verified );
check( 'it named the protocol, the website and the key', '1' === $sent['headers']['X-MH-Protocol'] && MHC_WEBSITE === $sent['headers']['X-MH-Website'] && $state['key_id'] === $sent['headers']['X-MH-Key'] );
check( 'it said who it is by the hash of its address', hash( 'sha256', 'https://shop.example.test/blog' ) === $sent['json']['site_url_hash'] );
check( 'it reported versions, counts and which transport, as numbers and names', MAILHOUSE_VERSION === $sent['json']['plugin_version'] && is_int( $sent['json']['subscribers']['subscribed'] ) && is_int( $sent['json']['queue']['pending'] ) && 'smtp' === $sent['json']['transport']['site'] && true === $sent['json']['transport']['ready'] );
check( 'it carried no host, user name or password', false === strpos( $sent['body'], 'secret-host' ) && false === strpos( $sent['body'], 'mailer-user' ) && false === strpos( $sent['body'], 'hunter2' ) );
check( 'it carried no private key', false === strpos( $sent['body'] . wp_json_encode( $sent['headers'] ), Mailhouse_Central_Identity::private_key() ) );

Mailhouse_Central::heartbeat();
$second = MHC_Centre::$requests[0];
mhc_requests();

check( 'every request has a nonce of its own', $sent['headers']['X-MH-Nonce'] !== $second['headers']['X-MH-Nonce'] && 1 === preg_match( '/^[0-9a-f]{32}$/', $second['headers']['X-MH-Nonce'] ) );

MHC_Centre::$mode = 'paused';
Mailhouse_Central::heartbeat();
mhc_requests();
check( 'a paused site is told so, and stays connected', 'paused' === Mailhouse_Central_Identity::get( 'centre_status' ) && 'connected' === Mailhouse_Central_Identity::status() );
MHC_Centre::$mode = 'ok';

echo "\nThe scheduled tick\n";

mhc_due();
Mailhouse_Central::tick();
check( 'reports in when it is due', 1 === mhc_requests() );

Mailhouse_Central::tick();
Mailhouse_Central::tick();
check( 'does not report in again within the same minute, however often cron runs', 0 === mhc_requests() );

// ---------------------------------------------------------------------------
echo "\nA clock that is wrong\n";

MHC_Centre::$ahead    = 1000;
MHC_Centre::$verified = array();
$result               = Mailhouse_Central::heartbeat();

check( 'the centre’s refusal is followed by one corrected attempt, which is accepted', true === $result && 2 === count( MHC_Centre::$requests ) );
check( 'the second attempt was signed with the centre’s time', abs( (int) MHC_Centre::$requests[1]['headers']['X-MH-Timestamp'] - ( time() + 1000 ) ) < 5 );
check( 'the difference is remembered', abs( (int) Mailhouse_Central_Identity::get( 'clock_offset' ) - 1000 ) < 5 );
mhc_requests();

Mailhouse_Central::heartbeat();
check( 'so the next request is right first time', 1 === mhc_requests() );

MHC_Centre::$ahead = 0;
Mailhouse_Central::heartbeat();
mhc_requests();
check( 'and is forgotten once the clocks agree again', 0 === (int) Mailhouse_Central_Identity::get( 'clock_offset' ) );

// ---------------------------------------------------------------------------
echo "\nThe centre is down\n";

MHC_Centre::$mode = 'down';
$waits            = array();

for ( $i = 0; $i < 7; $i++ ) {
	mhc_due();
	$before = time();
	Mailhouse_Central::tick();
	$waits[] = (int) Mailhouse_Central_Identity::get( 'next_attempt_at' ) - $before;
}
mhc_requests();

check( 'each failure is recorded, with the reason', 7 === (int) Mailhouse_Central_Identity::get( 'failures' ) && '' !== Mailhouse_Central_Identity::get( 'last_error' ) );
check( 'it waits longer each time: one minute, two, four, eight, then fifteen', array( 60, 120, 240, 480, 900, 900, 900 ) === $waits, implode( ',', $waits ) );
check( 'the site is still connected, as far as it knows', 'connected' === Mailhouse_Central_Identity::status() );

Mailhouse_Central::tick();
check( 'and it does not try again before the wait is over', 0 === mhc_requests() );

mhc_due();
Mailhouse_Central::tick();
check( 'a tick waits at most eight seconds for the centre', Mailhouse_Central::TICK_TIMEOUT === MHC_Centre::$requests[0]['args']['timeout'] && Mailhouse_Central::TICK_TIMEOUT <= 8 );
mhc_requests();

// The site's own work goes on regardless.
$address    = strtolower( 'central-' . wp_generate_password( 8, false ) . '@example.com' );
$subscriber = Mailhouse_Subscribers::add( array( 'email' => $address ) );
$subscriber = is_wp_error( $subscriber ) ? null : Mailhouse_Subscribers::get_by_email( $address );

check( 'with the centre down, a visitor can still sign up', $subscriber && 'pending' === $subscriber->status );

if ( $subscriber ) {
	Mailhouse_Subscribers::confirm( $subscriber );
	check( 'and confirm', 'subscribed' === Mailhouse_Subscribers::get( $subscriber->id )->status );

	Mailhouse_Subscribers::unsubscribe( Mailhouse_Subscribers::get( $subscriber->id ), 'test' );
	check( 'and unsubscribe', 'unsubscribed' === Mailhouse_Subscribers::get( $subscriber->id )->status );

	Mailhouse_Subscribers::delete( array( (int) $subscriber->id ) );
}

$ran = false;

try {
	Mailhouse_Queue::process();
	$ran = true;
} catch ( Throwable $e ) {
	$ran = false;
}
check( 'and the send queue runs without complaint', $ran );
mhc_requests();

foreach ( array( '500', '401', '426', '429' ) as $mode ) {
	MHC_Centre::$mode = $mode;
	mhc_due();
	$result = Mailhouse_Central::heartbeat();
	mhc_requests();

	check( "an answer of $mode is a failure to try again after, not a change of standing", is_wp_error( $result ) && 'connected' === Mailhouse_Central_Identity::status() && Mailhouse_Central_Identity::get( 'next_attempt_at' ) > time() );
}

MHC_Centre::$mode = 'ok';
mhc_due();
Mailhouse_Central::tick();
mhc_requests();
check( 'when the centre is back, the failures are forgotten', 0 === (int) Mailhouse_Central_Identity::get( 'failures' ) && '' === Mailhouse_Central_Identity::get( 'last_error' ) && 0 === (int) Mailhouse_Central_Identity::get( 'next_attempt_at' ) );

// ---------------------------------------------------------------------------
echo "\nThe centre refuses this site\n";

MHC_Centre::$mode = 'key_revoked';
mhc_due();
$result = Mailhouse_Central::heartbeat();
mhc_requests();

check( 'a revoked key suspends the connection, and says why', is_wp_error( $result ) && 'suspended' === Mailhouse_Central_Identity::status() && 'key_revoked' === Mailhouse_Central_Identity::get( 'suspended_reason' ) );

Mailhouse_Central::tick();
check( 'a suspended site asks again only every quarter of an hour', 0 === mhc_requests() && Mailhouse_Central_Identity::get( 'next_attempt_at' ) - time() > 14 * MINUTE_IN_SECONDS );

MHC_Centre::$mode = 'ok';
mhc_due();
Mailhouse_Central::tick();
mhc_requests();
check( 'and is connected again as soon as the centre says so', 'connected' === Mailhouse_Central_Identity::status() && '' === Mailhouse_Central_Identity::get( 'suspended_reason' ) );

MHC_Centre::$mode = 'gone';
mhc_due();
Mailhouse_Central::heartbeat();
mhc_requests();
check( 'a website removed at the centre is marked gone', 'gone' === Mailhouse_Central_Identity::status() );

mhc_due();
Mailhouse_Central::tick();
check( 'and nothing more is sent', 0 === mhc_requests() );

// ---------------------------------------------------------------------------
echo "\nA copy of a connected site\n";

mhc_connect();
$mhc_home = 'https://staging.example.test';
mhc_due();
Mailhouse_Central::tick();

check( 'a site at a different address from the one it registered sends nothing', 0 === mhc_requests() );
check( 'and knows it is a copy', 'clone' === Mailhouse_Central_Identity::status() && 'https://shop.example.test/blog' === Mailhouse_Central_Identity::get( 'registered_url' ) );

Mailhouse_Central::tick();
do_action( 'mailhouse_process_queue' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
check( 'however often it is asked', 0 === mhc_requests() );

$told = Mailhouse_Central_Identity::disconnect();
check( 'disconnecting the copy tells the centre nothing: the key is the original’s', false === $told && 0 === mhc_requests() );
check( 'and clears the copy', '' === Mailhouse_Central_Identity::status() && false === get_option( 'mailhouse_central', false ) && '' === Mailhouse_Central_Identity::private_key() );

$mhc_home = 'https://Shop.Example.TEST/blog/';
mhc_connect();
$mhc_home = 'https://staging.example.test';
Mailhouse_Central::tick();
$mhc_home = 'https://shop.example.test/blog';
mhc_due();
Mailhouse_Central::tick();
check( 'a site that is back at its own address is connected again, and reports in', 'connected' === Mailhouse_Central_Identity::status() && 1 === mhc_requests() );

// ---------------------------------------------------------------------------
echo "\nThe kill switch\n";

$before = get_option( 'mailhouse_central' );
$php    = PHP_BINARY;
$ini    = php_ini_loaded_file();
$helper = __DIR__ . '/helpers/central-disabled.php';
$output = array();

// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
exec( escapeshellarg( $php ) . ( $ini ? ' -c ' . escapeshellarg( $ini ) : '' ) . ' ' . escapeshellarg( $helper ) . ' 2>&1', $output, $exit );

$report = json_decode( (string) end( $output ), true );
Mailhouse_Central_Identity::flush();

check( 'with MAILHOUSE_CENTRAL_DISABLED the helper ran', 0 === $exit && is_array( $report ), implode( "\n", $output ) );

if ( is_array( $report ) ) {
	check( 'the connection is still stored: the switch does not disconnect', 'connected' === $report['status'] );
	check( 'the tick is not hooked', false === $report['hooked'] );
	check( 'a tick, a queue run, a heartbeat and a signed request send nothing', 0 === $report['requests'] );
	check( 'a change to the list is not written down for the centre, and nothing is pushed', 0 === $report['captured'] && 0 === $report['pushed'] );
	check( 'registering and reporting in are refused', 'mailhouse_central_disabled' === $report['register'] && 'mailhouse_central_disabled' === $report['heartbeat'] );
	check( 'and nothing about the connection is changed', get_option( 'mailhouse_central' ) === $before );
}

// ---------------------------------------------------------------------------
echo "\nDisconnecting\n";

MHC_Centre::$verified = array();
$told                 = Mailhouse_Central_Identity::disconnect();
$sent                 = MHC_Centre::$requests ? MHC_Centre::$requests[0] : null;
mhc_requests();

check( 'tells the centre, with a signed request and no body', true === $told && $sent && '/api/v1/connector/disconnect' === $sent['path'] && '' === $sent['body'] && array( true ) === MHC_Centre::$verified );
check( 'forgets the connection and the key', '' === Mailhouse_Central_Identity::status() && false === get_option( 'mailhouse_central', false ) && '' === Mailhouse_Central_Identity::private_key() && ! mailhouse_option( 'central_connected' ) );

Mailhouse_Central::tick();
check( 'and from then on sends nothing', 0 === mhc_requests() );

mhc_connect();
MHC_Centre::$mode = 'down';
$told             = Mailhouse_Central_Identity::disconnect();
mhc_requests();
check( 'with the centre down, the site is disconnected all the same', false === $told && '' === Mailhouse_Central_Identity::status() && '' === Mailhouse_Central_Identity::private_key() );

mhc_connect();
MHC_Centre::$mode = 'key_revoked';
mhc_due();
Mailhouse_Central::heartbeat();
mhc_requests();
$told = Mailhouse_Central_Identity::disconnect();
check( 'a suspended site disconnects locally, without asking a centre that would refuse', false === $told && 0 === mhc_requests() && '' === Mailhouse_Central_Identity::status() );

// ---------------------------------------------------------------------------
echo "\nThe settings tab\n";

mhc_forget();
wp_set_current_user( 1 );

// The admin's own helpers, which wp-admin loads and the command line does not.
require_once ABSPATH . 'wp-admin/includes/template.php';

$screen = new Mailhouse_Central_Screen();
$tabs   = apply_filters( 'mailhouse_settings_tabs', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

check( 'adds a Central tab that draws itself', isset( $tabs['central']['render'] ) && is_callable( $tabs['central']['render'] ) && array() === $tabs['central']['sections'] );

ob_start();
$screen->render();
$html = ob_get_clean();

check( 'not connected: a form for the address and the token, with a nonce', false !== strpos( $html, 'name="mailhouse_central_url"' ) && false !== strpos( $html, 'name="mailhouse_central_token"' ) && false !== strpos( $html, 'name="_wpnonce"' ) );
check( 'the token box does not echo what is typed', 1 === preg_match( '/<input type="password"[^>]*name="mailhouse_central_token"/', $html ) );
check( 'and the checks are listed', false !== strpos( $html, 'InnoDB' ) );

$checks = Mailhouse_Central_Screen::checklist( MHC_CENTRAL );
$labels = wp_list_pluck( $checks, 'ok', 'label' );
mhc_requests();

check( 'the checklist asks the centre whether it is there, and is satisfied', 7 === count( $checks ) && ! in_array( false, array_map( static function ( $c ) { return $c['ok'] || ! $c['blocking']; }, $checks ), true ), wp_json_encode( $labels ) );

MHC_Centre::$mode = 'down';
$checks           = Mailhouse_Central_Screen::checklist( MHC_CENTRAL );
$blocking         = array_filter( $checks, static function ( $c ) { return $c['blocking'] && ! $c['ok']; } );
mhc_requests();
check( 'a centre that cannot be reached is a reason not to connect', 1 === count( $blocking ) );

mhc_connect();
ob_start();
$screen->render();
$html = ob_get_clean();

check( 'connected: shows the centre, the website id and the key’s id', false !== strpos( $html, MHC_CENTRAL ) && false !== strpos( $html, MHC_WEBSITE ) && false !== strpos( $html, Mailhouse_Central_Identity::get( 'key_id' ) ) );
check( 'and never the private key', false === strpos( $html, Mailhouse_Central_Identity::private_key() ) );
check( 'with a way to disconnect', false !== strpos( $html, 'value="mailhouse_central_disconnect"' ) );

wp_set_current_user( 0 );
ob_start();
$screen->render();
check( 'someone who may not manage the plugin is shown nothing', '' === ob_get_clean() );

mhc_forget();

mailhouse_test_results();
