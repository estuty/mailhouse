<?php
/**
 * Keys (milestone 10.5): the platform keys this site trusts, which change
 * only by a set signed by a key it already trusts; and this site's own key,
 * which it changes by having the centre register a new one, signed with the
 * key in use.
 *
 * Against a stand-in for the centre that checks every signature it is sent.
 *
 *   php tests/test-central-keys.php
 */

require __DIR__ . '/bootstrap.php';

$saved_settings = get_option( 'mailhouse_settings' );
$saved_central  = get_option( 'mailhouse_central', null );

register_shutdown_function(
	static function () use ( $saved_settings, $saved_central ) {
		global $wpdb;

		// The commands this suite had the site collect: on a rig site, the
		// centre's own check later counts every command the site answered.
		$ids = class_exists( 'MHY_Centre' ) ? MHY_Centre::$issued : array();
		if ( array() !== $ids ) {
			// phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Mailhouse_Central_Commands::table() . ' WHERE command_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . ')', $ids ) );
		}

		update_option( 'mailhouse_settings', $saved_settings );

		if ( null === $saved_central ) {
			delete_option( 'mailhouse_central' );
		} else {
			update_option( 'mailhouse_central', $saved_central );
		}
	}
);

const MHY_CENTRAL = 'https://central.example.test';
const MHY_WEBSITE = '0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

final class MHY_Centre {

	/** @var array[] Every request, as it arrived. */
	public static $requests = array();

	/** @var string[] Platform secret keys, raw, by id. */
	public static $platform = array();

	/** @var string The platform key the centre registers sites with. */
	public static $signing = 'p-one';

	/** @var array|null The set of platform keys heartbeat answers carry. */
	public static $announce = null;

	/** @var array[] The site's keys: id => array( public, until ). */
	public static $site_keys = array();

	/** @var string How to answer a key change: ok, lost (taken, answer lost), refuse. */
	public static $rotate = 'ok';

	/** @var array[] Commands waiting, by id. */
	public static $commands = array();

	/** @var string[] Every command id issued, kept across resets, so that they can be taken out of the site's inbox at the end. */
	public static $issued = array();

	/** @var array[] Answers to commands, with the key that signed each answer. */
	public static $answers = array();

	/** @var string The token this centre issued. */
	public static $token = '';

	public static function reset() {
		self::$requests  = array();
		self::$announce  = null;
		self::$site_keys = array();
		self::$rotate    = 'ok';
		self::$commands  = array();
		self::$answers   = array();
		self::$signing   = 'p-one';
	}

	public static function key( $id ) {
		if ( ! isset( self::$platform[ $id ] ) ) {
			self::$platform[ $id ] = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		}

		return self::$platform[ $id ];
	}

	public static function public_of( $id ) {
		return base64_encode( sodium_crypto_sign_publickey_from_secretkey( self::key( $id ) ) ); // phpcs:ignore
	}

	/**
	 * A set of platform keys, signed by the keys named. Written here from the
	 * specification, not with the plugin's own function.
	 */
	public static function set_of( array $keys, $issued_at, array $signers ) {
		$listed = array();

		foreach ( $keys as $id => $status ) {
			$listed[] = array(
				'key_id'     => $id,
				'public_key' => self::public_of( $id ),
				'status'     => $status,
			);
		}

		$sorted = $listed;
		usort(
			$sorted,
			static function ( $a, $b ) {
				return strcmp( $a['key_id'], $b['key_id'] );
			}
		);

		$lines = array( 'MH1-KEYS', $issued_at );

		foreach ( $sorted as $key ) {
			$lines[] = $key['key_id'] . ' ' . $key['status'] . ' ' . $key['public_key'];
		}

		$message    = implode( "\n", $lines );
		$signatures = array();

		foreach ( $signers as $id ) {
			$signatures[] = array(
				'key_id'    => $id,
				'signature' => base64_encode( sodium_crypto_sign_detached( $message, self::key( $id ) ) ), // phpcs:ignore
			);
		}

		return array(
			'keys'       => $listed,
			'issued_at'  => $issued_at,
			'signatures' => $signatures,
		);
	}

	/** A command signed with the platform key named. */
	public static function issue( $type, $seq, $key_id ) {
		$envelope = array(
			'id'           => wp_generate_uuid4(),
			'website_id'   => MHY_WEBSITE,
			'seq'          => $seq,
			'type'         => $type,
			'issued_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'expires_at'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ),
			'payload_json' => '{}',
			'key_id'       => $key_id,
		);
		$message  = implode(
			"\n",
			array( 'MH1-CMD', $envelope['id'], $envelope['website_id'], $envelope['seq'], $envelope['type'], $envelope['issued_at'], $envelope['expires_at'], hash( 'sha256', '{}' ) )
		);

		$envelope['signature']             = base64_encode( sodium_crypto_sign_detached( $message, self::key( $key_id ) ) ); // phpcs:ignore
		self::$commands[ $envelope['id'] ] = $envelope;
		self::$issued[]                    = $envelope['id'];

		return $envelope;
	}

	protected static function answer( $status, $body ) {
		$body['server_time'] = gmdate( 'Y-m-d\TH:i:s\Z' );

		return array(
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( array( 'date' => gmdate( 'D, d M Y H:i:s' ) . ' GMT' ) ),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** The key a signed request is signed with, if it is one this centre accepts now; else null. */
	protected static function signer( $method, $path, $query, $headers, $body ) {
		foreach ( array( 'X-MH-Website', 'X-MH-Key', 'X-MH-Timestamp', 'X-MH-Nonce', 'X-MH-Signature' ) as $name ) {
			if ( empty( $headers[ $name ] ) ) {
				return null;
			}
		}

		$id = (string) $headers['X-MH-Key'];

		if ( ! isset( self::$site_keys[ $id ] ) || ( null !== self::$site_keys[ $id ]['until'] && self::$site_keys[ $id ]['until'] <= time() ) ) {
			return null;
		}

		$message = implode(
			"\n",
			array( 'MH1', $method, $path, $query, $headers['X-MH-Timestamp'], $headers['X-MH-Nonce'], $headers['X-MH-Website'], hash( 'sha256', $body ) )
		);

		return MHY_WEBSITE === $headers['X-MH-Website'] && sodium_crypto_sign_verify_detached( base64_decode( $headers['X-MH-Signature'], true ), $message, base64_decode( self::$site_keys[ $id ]['public'], true ) ) // phpcs:ignore
			? $id
			: null;
	}

	public static function handle( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://central.' ) ) {
			return $pre;
		}

		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$body  = isset( $args['body'] ) ? (string) $args['body'] : '';
		$json  = json_decode( $body, true );

		self::$requests[] = array(
			'path'    => $path,
			'headers' => $args['headers'],
			'json'    => $json,
		);

		if ( '/api/v1/connector/register' === $path ) {
			$proof = implode( "\n", array( 'MH1-REG', $json['enrolment_token'], $json['site_url'], $json['public_key'], $json['key_id'] ) );

			if ( $json['enrolment_token'] !== self::$token || ! sodium_crypto_sign_verify_detached( base64_decode( $json['proof'], true ), $proof, base64_decode( $json['public_key'], true ) ) ) { // phpcs:ignore
				return self::answer( 401, array( 'code' => 'unauthenticated' ) );
			}

			self::$site_keys = array(
				$json['key_id'] => array(
					'public' => $json['public_key'],
					'until'  => null,
				),
			);
			self::$token     = '';

			return self::answer(
				201,
				array(
					'website_id'    => MHY_WEBSITE,
					'platform_keys' => array(
						array(
							'key_id'     => self::$signing,
							'public_key' => self::public_of( self::$signing ),
							'status'     => 'active',
						),
					),
					'protocol'      => 1,
				)
			);
		}

		$signer = self::signer( $args['method'], $path, $query, $args['headers'], $body );

		if ( null === $signer ) {
			return self::answer( 401, array( 'code' => 'unauthenticated' ) );
		}

		if ( '/api/v1/connector/keys/rotate' === $path ) {
			if ( 'refuse' === self::$rotate ) {
				return self::answer( 409, array( 'code' => 'conflict' ) );
			}

			$message = implode( "\n", array( 'MH1-ROT', MHY_WEBSITE, $signer, $json['new_key_id'], $json['new_public_key'] ) );

			if ( ! sodium_crypto_sign_verify_detached( base64_decode( $json['proof'], true ), $message, base64_decode( $json['new_public_key'], true ) ) ) { // phpcs:ignore
				return self::answer( 401, array( 'code' => 'unauthenticated' ) );
			}

			$until = time() + DAY_IN_SECONDS;

			if ( ! isset( self::$site_keys[ $json['new_key_id'] ] ) ) {
				self::$site_keys[ $json['new_key_id'] ] = array(
					'public' => $json['new_public_key'],
					'until'  => null,
				);
				self::$site_keys[ $signer ]['until']    = $until;
			}

			if ( 'lost' === self::$rotate ) {
				self::$rotate = 'ok';

				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			}

			return self::answer(
				200,
				array(
					'key_id'              => $json['new_key_id'],
					'active_from'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'old_key_valid_until' => gmdate( 'Y-m-d\TH:i:s\Z', (int) self::$site_keys[ $signer ]['until'] ),
				)
			);
		}

		if ( '/api/v1/connector/commands' === $path ) {
			return self::answer( 200, array( 'commands' => array_values( self::$commands ), 'more' => false ) );
		}

		if ( '/api/v1/connector/commands/ack' === $path ) {
			foreach ( $json['results'] as $result ) {
				self::$answers[] = array_merge( $result, array( 'signed_by' => $signer ) );
				unset( self::$commands[ $result['id'] ] );
			}

			return self::answer( 200, array( 'acked_through_seq' => 0 ) );
		}

		if ( '/api/v1/connector/heartbeat' === $path ) {
			return self::answer(
				200,
				array_merge(
					array(
						'status'           => 'connected',
						'commands_pending' => count( self::$commands ),
						'ack_through_seq'  => 0,
						'want'             => array(),
						'epoch'            => 1,
					),
					null === self::$announce ? array() : array( 'platform_keys' => self::$announce )
				)
			);
		}

		return self::answer( 200, array() );
	}
}

add_filter( 'pre_http_request', array( 'MHY_Centre', 'handle' ), 10, 3 );
add_filter(
	'home_url',
	static function ( $url, $path ) {
		return 'https://shop.example.test' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
	},
	10,
	2
);

function mhy_connect() {
	delete_option( 'mailhouse_central' );
	Mailhouse_Settings::set(
		array(
			'central_connected'        => false,
			'central_private_key'      => '',
			'central_private_key_next' => '',
		)
	);
	Mailhouse_Central_Identity::flush();
	MHY_Centre::reset();
	MHY_Centre::$token = 'mhe_' . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore

	return Mailhouse_Central_Identity::register( MHY_CENTRAL, MHY_Centre::$token );
}

function mhy_heartbeat() {
	return Mailhouse_Central::heartbeat();
}

function mhy_trusted() {
	return wp_list_pluck( (array) Mailhouse_Central_Identity::get( 'platform_keys' ), 'key_id' );
}

function mhy_last( $path ) {
	foreach ( array_reverse( MHY_Centre::$requests ) as $request ) {
		if ( $path === $request['path'] ) {
			return $request;
		}
	}

	return null;
}

function mhy_due() {
	Mailhouse_Central_Identity::update(
		array(
			'next_attempt_at'   => 0,
			'last_heartbeat_at' => 0,
		)
	);
}

// ---------------------------------------------------------------------------
echo "\nThe protocol's worked examples\n";

$vectors = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/protocol-v1.json' ), true ); // phpcs:ignore
check( 'the worked examples include sets of platform keys and a change of key', ! empty( $vectors['key_sets'] ) && ! empty( $vectors['rotations'] ) );

$wrong = array();
foreach ( (array) $vectors['key_sets'] as $set ) {
	if ( Mailhouse_Central_Protocol::key_set_message( $set['keys'], $set['issued_at'] ) !== $set['message'] ) {
		$wrong[] = $set['name'] . ': message';
	}
	foreach ( $set['signatures'] as $signature ) {
		$signer = wp_list_filter( $set['keys'], array( 'key_id' => $signature['key_id'] ) );
		$taken  = Mailhouse_Central_Protocol::read_key_set(
			array(
				'keys'       => $set['keys'],
				'issued_at'  => $set['issued_at'],
				'signatures' => array( $signature ),
			),
			array_values( $signer )
		);
		if ( $set['keys'] !== $taken ) {
			$wrong[] = $set['name'] . ': trusted by ' . $signature['key_id'];
		}
	}
}
check( count( (array) $vectors['key_sets'] ) . ' sets of platform keys: the same message, trusted by each signer', ! $wrong, implode( ' | ', $wrong ) );

$wrong = array();
foreach ( (array) $vectors['rotations'] as $rotation ) {
	if ( Mailhouse_Central_Protocol::rotation_message( $rotation['website_id'], $rotation['old_key_id'], $rotation['new_key_id'], $rotation['new_public_key'] ) !== $rotation['message'] ) {
		$wrong[] = $rotation['name'];
	}
}
check( count( (array) $vectors['rotations'] ) . ' changes of key: the same message', ! $wrong, implode( ' | ', $wrong ) );

// ---------------------------------------------------------------------------
echo "\nThe platform keys this site trusts\n";

$connected = mhy_connect();
check( 'a site registers, and trusts the one platform key it is given', true === $connected && array( 'p-one' ) === mhy_trusted() );

check( 'it says which keys it trusts when it reports in', in_array( 'keys.rotate.v1', Mailhouse_Central_Status::capabilities(), true ) && array( 'p-one' ) === Mailhouse_Central_Status::report()['platform_keys'] );

// The centre announces the next key, signed by the one in use.
MHY_Centre::$announce = MHY_Centre::set_of( array( 'p-one' => 'active', 'p-two' => 'next' ), '2026-11-25T09:00:00.000Z', array( 'p-one', 'p-two' ) );
mhy_heartbeat();
check( 'a new key announced in a heartbeat answer, signed by a key it trusts, is trusted from then on', array( 'p-one', 'p-two' ) === mhy_trusted() );
mhy_heartbeat();
check( 'and the next report says so', array( 'p-one', 'p-two' ) === mhy_last( '/api/v1/connector/heartbeat' )['json']['platform_keys'] );

$cmd = MHY_Centre::issue( 'site.reconcile', 1, 'p-two' );
check( 'a command signed by the new key passes the signature check', Mailhouse_Central_Protocol::verify_command( $cmd, (array) Mailhouse_Central_Identity::get( 'platform_keys' ) ) );
MHY_Centre::$commands = array();

// An older set, replayed, changes nothing.
$older = MHY_Centre::set_of( array( 'p-one' => 'active' ), '2026-11-24T09:00:00.000Z', array( 'p-one' ) );
check( 'an older set, signed by a trusted key, is not taken', false === Mailhouse_Central_Identity::adopt_platform_keys( $older ) && array( 'p-one', 'p-two' ) === mhy_trusted() );
check( 'nor the same set again', false === Mailhouse_Central_Identity::adopt_platform_keys( MHY_Centre::$announce ) );

// A set signed by nobody it trusts.
$forged = MHY_Centre::set_of( array( 'p-evil' => 'active' ), '2026-11-26T09:00:00.000Z', array( 'p-evil' ) );
check( 'a set signed only by a key it does not trust is not taken', false === Mailhouse_Central_Identity::adopt_platform_keys( $forged ) && array( 'p-one', 'p-two' ) === mhy_trusted() );

// A set changed after it was signed.
$changed                        = MHY_Centre::set_of( array( 'p-one' => 'active', 'p-two' => 'active' ), '2026-11-26T09:00:00.000Z', array( 'p-one' ) );
$changed['keys'][1]['public_key'] = MHY_Centre::public_of( 'p-evil' );
check( 'a set altered after it was signed is not taken', false === Mailhouse_Central_Identity::adopt_platform_keys( $changed ) );

$no_active = MHY_Centre::set_of( array( 'p-two' => 'next' ), '2026-11-26T09:00:00.000Z', array( 'p-one' ) );
check( 'a set with no key in use is not taken', false === Mailhouse_Central_Identity::adopt_platform_keys( $no_active ) );

$malformed                         = MHY_Centre::set_of( array( 'p-one' => 'active' ), '2026-11-26T09:00:00.000Z', array( 'p-one' ) );
$malformed['keys'][0]['public_key'] = 'not a key';
check( 'nor one with a key that is not a key', false === Mailhouse_Central_Identity::adopt_platform_keys( $malformed ) && false === Mailhouse_Central_Identity::adopt_platform_keys( array( 'keys' => 'x' ) ) && false === Mailhouse_Central_Identity::adopt_platform_keys( null ) );

$sloppy = MHY_Centre::set_of( array( 'p-one' => 'active', 'p-two' => 'active' ), '2026-11-26 09:00:00', array( 'p-one' ) );
check( 'nor one whose time is not written as the centre writes it', false === Mailhouse_Central_Identity::adopt_platform_keys( $sloppy ) );

// The switch: the new key in use, the old one kept a while, signed by the new.
$switched = MHY_Centre::set_of( array( 'p-one' => 'active', 'p-two' => 'active' ), '2026-11-26T09:00:00.000Z', array( 'p-two' ) );
check( 'after the switch, a set signed by the new key alone is taken', true === Mailhouse_Central_Identity::adopt_platform_keys( $switched ) && array( 'p-one', 'p-two' ) === mhy_trusted() );

$retired = MHY_Centre::set_of( array( 'p-two' => 'active' ), '2026-11-27T09:00:00.000Z', array( 'p-two' ) );
check( 'and when the old key is retired, it is no longer trusted', true === Mailhouse_Central_Identity::adopt_platform_keys( $retired ) && array( 'p-two' ) === mhy_trusted() );
$old_cmd = MHY_Centre::issue( 'site.reconcile', 2, 'p-one' );
check( 'a command signed by the retired key then fails the signature check', ! Mailhouse_Central_Protocol::verify_command( $old_cmd, (array) Mailhouse_Central_Identity::get( 'platform_keys' ) ) );
MHY_Centre::$commands = array();

// A site that missed every announcement.
mhy_connect();
$straggler = MHY_Centre::set_of( array( 'p-one' => 'active', 'p-three' => 'active' ), '2026-11-28T09:00:00.000Z', array( 'p-three', 'p-one' ) );
check( 'a site that missed the announcement catches up while a key it trusts still signs the set', true === Mailhouse_Central_Identity::adopt_platform_keys( $straggler ) && array( 'p-one', 'p-three' ) === mhy_trusted() );

// ---------------------------------------------------------------------------
echo "\nThis site's own key\n";

mhy_connect();
$first_key  = (string) Mailhouse_Central_Identity::get( 'key_id' );
$first_priv = (string) Mailhouse_Settings::get( 'central_private_key' );
$done       = Mailhouse_Central_Identity::rotate_key();
$rotation   = mhy_last( '/api/v1/connector/keys/rotate' );
check( 'the request for a new key is signed with the key in use', is_array( $rotation ) && $first_key === $rotation['headers']['X-MH-Key'] );
check(
	'it carries a new key, and a proof made with it over this website, the old key and the new',
	is_array( $rotation ) && $rotation['json']['new_key_id'] !== $first_key
		&& sodium_crypto_sign_verify_detached(
			base64_decode( $rotation['json']['proof'], true ), // phpcs:ignore
			implode( "\n", array( 'MH1-ROT', MHY_WEBSITE, $first_key, $rotation['json']['new_key_id'], $rotation['json']['new_public_key'] ) ),
			base64_decode( $rotation['json']['new_public_key'], true ) // phpcs:ignore
		)
);
$second_key = (string) Mailhouse_Central_Identity::get( 'key_id' );
check(
	'once the centre has taken it, the site signs with the new key, and the old one is gone from it',
	is_array( $done ) && $done['key_id'] === $second_key && $second_key !== $first_key
		&& $first_priv !== (string) Mailhouse_Settings::get( 'central_private_key' )
		&& '' === (string) Mailhouse_Settings::get( 'central_private_key_next' )
		&& '' !== $done['old_key_valid_until'],
	wp_json_encode( $done )
);
check( 'its next report is signed with the new key, and accepted', true === mhy_heartbeat() && $second_key === mhy_last( '/api/v1/connector/heartbeat' )['headers']['X-MH-Key'] );
check( 'the new private key is stored encrypted, as the old one was', ! str_contains( (string) wp_json_encode( get_option( 'mailhouse_settings' ) ), (string) Mailhouse_Settings::get( 'central_private_key' ) ) );

// The answer is lost: the centre took the key and the site did not hear.
MHY_Centre::$rotate = 'lost';
$lost               = Mailhouse_Central_Identity::rotate_key();
$pending            = (string) Mailhouse_Settings::get( 'central_private_key_next' );
check( 'when the answer is lost, the site keeps signing with the key in use, and keeps the new one', is_wp_error( $lost ) && $second_key === Mailhouse_Central_Identity::get( 'key_id' ) && '' !== $pending && '' !== (string) Mailhouse_Central_Identity::get( 'key_error' ) );
$asked_for = mhy_last( '/api/v1/connector/keys/rotate' )['json']['new_key_id'];
$again     = Mailhouse_Central_Identity::rotate_key();
check( 'and asks again for the same new key, which the centre answers as before', is_array( $again ) && $asked_for === $again['key_id'] && $asked_for === mhy_last( '/api/v1/connector/keys/rotate' )['json']['new_key_id'] && $asked_for === Mailhouse_Central_Identity::get( 'key_id' ) && '' === (string) Mailhouse_Central_Identity::get( 'key_error' ) );

// The centre will not take it.
MHY_Centre::$rotate = 'refuse';
$before             = (string) Mailhouse_Central_Identity::get( 'key_id' );
$refused            = Mailhouse_Central_Identity::rotate_key();
check( 'a key the centre refuses is dropped, and the key in use stays', is_wp_error( $refused ) && $before === Mailhouse_Central_Identity::get( 'key_id' ) && '' === (string) Mailhouse_Settings::get( 'central_private_key_next' ) );
MHY_Centre::$rotate = 'ok';

// Asked by the centre.
mhy_connect();
$key_before = (string) Mailhouse_Central_Identity::get( 'key_id' );
$order      = MHY_Centre::issue( 'site.rotate_key', 1, 'p-one' );
mhy_heartbeat();
Mailhouse_Central_Commands::work();
$answer = MHY_Centre::$answers ? MHY_Centre::$answers[0] : array();
check(
	'asked by the centre, the site changes its key, and answers with the new one, signed with it',
	isset( $answer['status'] ) && 'applied' === $answer['status'] && $order['id'] === $answer['id']
		&& $key_before !== Mailhouse_Central_Identity::get( 'key_id' )
		&& Mailhouse_Central_Identity::get( 'key_id' ) === $answer['result']['key_id']
		&& Mailhouse_Central_Identity::get( 'key_id' ) === $answer['signed_by'],
	wp_json_encode( $answer )
);

// A year on.
mhy_connect();
Mailhouse_Central_Identity::update( array( 'key_since' => time() - YEAR_IN_SECONDS + HOUR_IN_SECONDS ) );
check( 'a key not yet a year old is left alone', ! Mailhouse_Central_Identity::key_due() );
Mailhouse_Central_Identity::update( array( 'key_since' => time() - YEAR_IN_SECONDS - 1 ) );
$aged = (string) Mailhouse_Central_Identity::get( 'key_id' );
check( 'a key a year old is due', Mailhouse_Central_Identity::key_due() );
mhy_due();
Mailhouse_Central::tick();
check( 'and the next scheduled run changes it without anybody asking', $aged !== Mailhouse_Central_Identity::get( 'key_id' ) && ! Mailhouse_Central_Identity::key_due() );

// A key fixed in wp-config.php is the administrator's to change. Last: a
// constant cannot be taken back.
mhy_connect();
define( 'MAILHOUSE_CENTRAL_PRIVATE_KEY', (string) Mailhouse_Settings::get( 'central_private_key' ) );
$fixed = Mailhouse_Central_Identity::rotate_key();
check( 'a key set in wp-config.php is not changed, and the site says why', is_wp_error( $fixed ) && 'mailhouse_central_key_fixed' === $fixed->get_error_code() && ! Mailhouse_Central_Identity::key_due() );
MHY_Centre::issue( 'site.rotate_key', 2, 'p-one' );
mhy_heartbeat();
Mailhouse_Central_Commands::work();
$last = end( MHY_Centre::$answers );
check( 'and asked by the centre, it refuses, saying so', is_array( $last ) && 'rejected' === $last['status'] && 'key_fixed_in_config' === $last['result']['reason'], wp_json_encode( $last ) );

mailhouse_test_results();
