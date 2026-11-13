<?php
/**
 * Telling the centre what has changed: a subscriber's state and its hash,
 * the outbox every change is written to, and the push that sends it.
 *
 * No centre is needed. Every request is caught before it leaves this
 * process and answered by a stand-in below, which keeps a sequence the way
 * the real centre does and refuses what the real centre refuses.
 *
 * Usage: php tests/test-central-sync.php
 *
 * @package Mailhouse
 */

require __DIR__ . '/bootstrap.php';

$saved_settings = get_option( 'mailhouse_settings' );
$saved_central  = get_option( 'mailhouse_central', null );

const MHS_CENTRAL = 'https://central.example.test';
const MHS_WEBSITE = '0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
const MHS_DOMAIN  = 'mhs.example.test';

/**
 * Remove everything this file added.
 */
function mhs_clean() {
	global $wpdb;

	$subscribers = Mailhouse_Install::table( 'subscribers' );
	$pivot       = Mailhouse_Install::table( 'subscriber_tags' );
	$tags        = Mailhouse_Install::table( 'tags' );
	$outbox      = Mailhouse_Install::table( 'outbox' );

	// phpcs:disable WordPress.DB
	$wpdb->query( $wpdb->prepare( "DELETE p FROM {$pivot} p JOIN {$subscribers} s ON s.id = p.subscriber_id WHERE s.email LIKE %s", '%@' . MHS_DOMAIN ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$subscribers} WHERE email LIKE %s", '%@' . MHS_DOMAIN ) );
	$wpdb->query( "DELETE p FROM {$pivot} p JOIN {$tags} t ON t.id = p.tag_id WHERE t.name LIKE 'MHS %'" );
	$wpdb->query( "DELETE FROM {$tags} WHERE name LIKE 'MHS %'" );
	$wpdb->query( "DELETE FROM {$outbox}" );
	// phpcs:enable
}

// Put back even if this file dies half-way.
register_shutdown_function(
	static function () use ( $saved_settings, $saved_central ) {
		mhs_clean();
		update_option( 'mailhouse_settings', $saved_settings );

		if ( null === $saved_central ) {
			delete_option( 'mailhouse_central' );
		} else {
			update_option( 'mailhouse_central', $saved_central );
		}
	}
);

// No mail leaves this process.
add_filter( 'pre_wp_mail', '__return_true' );

/**
 * Length as the centre counts it: UTF-16 units.
 */
function mhs_units( $text ) {
	return strlen( mb_convert_encoding( (string) $text, 'UTF-16LE', 'UTF-8' ) ) / 2;
}

/**
 * The stand-in centre.
 */
final class MHS_Centre {

	/** @var array[] Every request seen. */
	public static $requests = array();

	/** @var string[] The body of every push, as sent. */
	public static $bodies = array();

	/** @var int[] How many events each push carried. */
	public static $batches = array();

	/** @var array<int,array> Events stored, by sequence number. */
	public static $events = array();

	/** @var int The last sequence number stored. */
	public static $received = 0;

	/** @var int */
	public static $epoch = 1;

	/** @var array[] Events received under earlier epochs. */
	public static $archive = array();

	/** @var string ok, down, gap, 500, 401, 429, 410, or a 423 reason. */
	public static $mode = 'ok';

	/** @var int Store this many pushes and then lose the answer. */
	public static $lose_ack = 0;

	/** @var int Refuse, as too large, a push of more events than this. Zero: no limit. */
	public static $max_batch = 0;

	/** @var callable|null Refuse an event this returns true for. */
	public static $reject = null;

	/** @var string[] Why events failed validation. */
	public static $invalid = array();

	/** @var string Registered public key, base64. */
	public static $site_key = '';

	/** @var string The token this centre issued. */
	public static $token = '';

	/** @var bool[] Whether each signed request verified. */
	public static $verified = array();

	/**
	 * Forget the faults, keep the sequence.
	 */
	public static function calm() {
		self::$mode      = 'ok';
		self::$lose_ack  = 0;
		self::$max_batch = 0;
		self::$reject    = null;
		self::$requests  = array();
		self::$batches   = array();
	}

	/**
	 * A centre that has never heard from this website.
	 */
	public static function reset() {
		self::calm();
		self::$events   = array();
		self::$archive  = array();
		self::$received = 0;
		self::$epoch    = 1;
		self::$bodies   = array();
		self::$invalid  = array();
		self::$verified = array();
	}

	/**
	 * Start the website's sequence again, as the real centre does for a
	 * site whose history has gone backwards.
	 */
	public static function new_epoch() {
		self::$archive  = array_merge( self::$archive, array_values( self::$events ) );
		self::$events   = array();
		self::$received = 0;

		return ++self::$epoch;
	}

	/**
	 * A fresh enrolment token.
	 */
	public static function issue_token() {
		self::$token = 'mhe_' . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore

		return self::$token;
	}

	/**
	 * A response in the shape WordPress's HTTP API returns.
	 */
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

	/**
	 * Whether a request carries the registered key's signature of exactly
	 * what was sent.
	 */
	protected static function signed( $method, $path, $headers, $body ) {
		foreach ( array( 'X-MH-Website', 'X-MH-Key', 'X-MH-Timestamp', 'X-MH-Nonce', 'X-MH-Signature' ) as $name ) {
			if ( empty( $headers[ $name ] ) ) {
				return false;
			}
		}

		$message = implode(
			"\n",
			array( 'MH1', $method, $path, '', $headers['X-MH-Timestamp'], $headers['X-MH-Nonce'], $headers['X-MH-Website'], hash( 'sha256', $body ) )
		);

		return MHS_WEBSITE === $headers['X-MH-Website']
			&& sodium_crypto_sign_verify_detached( base64_decode( $headers['X-MH-Signature'], true ), $message, base64_decode( self::$site_key, true ) ); // phpcs:ignore
	}

	/**
	 * Why the centre's schema would refuse an event, or an empty string.
	 */
	public static function problem( $event ) {
		$stamp = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

		if ( ! is_array( $event ) ) {
			return 'not an object';
		}

		foreach ( array( 'seq', 'event_id', 'type', 'occurred_at', 'origin', 'subject' ) as $key ) {
			if ( ! isset( $event[ $key ] ) ) {
				return "no $key";
			}
		}

		if ( ! is_int( $event['seq'] ) || $event['seq'] < 1 ) {
			return 'seq';
		}

		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $event['event_id'] ) ) {
			return 'event_id';
		}

		if ( strlen( $event['type'] ) > 40 || ! preg_match( '/^[a-z]+(\.[a-z_]+)+$/', $event['type'] ) ) {
			return 'type';
		}

		if ( ! preg_match( $stamp, (string) $event['occurred_at'] ) || false === strtotime( $event['occurred_at'] ) ) {
			return 'occurred_at';
		}

		if ( ! preg_match( '/^(local|command:[0-9a-f-]{36})$/', (string) $event['origin'] ) ) {
			return 'origin';
		}

		if ( ! in_array( $event['subject']['type'], array( 'subscriber', 'tag', 'site' ), true ) || ! is_int( $event['subject']['local_id'] ) || $event['subject']['local_id'] < 0 ) {
			return 'subject';
		}

		if ( isset( $event['data'] ) && ( ! is_array( $event['data'] ) || strlen( wp_json_encode( $event['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) > 4096 ) ) {
			return 'data';
		}

		$is_subscriber = 0 === strpos( $event['type'], 'subscriber.' );

		if ( $is_subscriber && ! isset( $event['state'] ) ) {
			return 'no state';
		}

		if ( ! isset( $event['state'] ) ) {
			return '';
		}

		$state = $event['state'];

		foreach ( array( 'local_id', 'email', 'first_name', 'last_name', 'fields', 'status', 'source', 'created_at', 'tags', 'revision', 'hash' ) as $key ) {
			if ( ! isset( $state[ $key ] ) ) {
				return "state has no $key";
			}
		}

		foreach ( array( 'signup_ip', 'confirmed_at', 'unsubscribed_at' ) as $key ) {
			if ( ! array_key_exists( $key, $state ) ) {
				return "state has no $key";
			}
		}

		if ( $state['local_id'] !== $event['subject']['local_id'] || $state['local_id'] < 1 ) {
			return 'state is not the subject’s';
		}

		if ( mhs_units( $state['email'] ) < 3 || mhs_units( $state['email'] ) > 320 || mhs_units( $state['first_name'] ) > 200 || mhs_units( $state['last_name'] ) > 200 || mhs_units( $state['source'] ) > 60 ) {
			return 'state text too long';
		}

		if ( ! is_array( $state['fields'] ) || count( $state['fields'] ) > 100 ) {
			return 'fields';
		}

		foreach ( $state['fields'] as $name => $value ) {
			if ( '' === (string) $name || mhs_units( $name ) > 60 || ! is_string( $value ) || mhs_units( $value ) > 2000 ) {
				return 'a field';
			}
		}

		if ( ! in_array( $state['status'], array( 'subscribed', 'pending', 'unsubscribed', 'bounced' ), true ) ) {
			return 'status';
		}

		if ( null !== $state['signup_ip'] && ( ! is_string( $state['signup_ip'] ) || strlen( $state['signup_ip'] ) > 45 ) ) {
			return 'signup_ip';
		}

		foreach ( array( 'created_at', 'confirmed_at', 'unsubscribed_at' ) as $key ) {
			if ( null === $state[ $key ] && 'created_at' !== $key ) {
				continue;
			}

			if ( ! is_string( $state[ $key ] ) || ! preg_match( $stamp, $state[ $key ] ) || false === strtotime( $state[ $key ] ) ) {
				return $key;
			}
		}

		if ( ! is_array( $state['tags'] ) || count( $state['tags'] ) > 1000 || array_filter( $state['tags'], 'is_int' ) !== $state['tags'] ) {
			return 'tags';
		}

		if ( ! is_int( $state['revision'] ) || $state['revision'] < 0 ) {
			return 'revision';
		}

		if ( ! preg_match( '/^[0-9a-f]{64}$/', (string) $state['hash'] ) ) {
			return 'hash';
		}

		// Text PostgreSQL cannot hold.
		if ( false !== strpos( wp_json_encode( $event ), '\u0000' ) ) {
			return 'a NUL character';
		}

		return '';
	}

	/**
	 * Store a push, as the real centre does.
	 */
	protected static function receive( $body ) {
		$json = json_decode( $body, true );

		self::$bodies[]  = $body;
		self::$batches[] = is_array( $json ) && isset( $json['events'] ) && is_array( $json['events'] ) ? count( $json['events'] ) : 0;

		if ( self::$max_batch > 0 && end( self::$batches ) > self::$max_batch ) {
			return self::answer( 413, array( 'code' => 'too_large' ) );
		}

		if ( ! is_array( $json ) || ! isset( $json['epoch'], $json['events'] ) || ! is_int( $json['epoch'] ) || ! is_array( $json['events'] ) || ! $json['events'] || count( $json['events'] ) > 500 ) {
			self::$invalid[] = 'the batch';

			return self::answer( 422, array( 'code' => 'validation_failed' ) );
		}

		// An empty object must be sent as one.
		if ( false !== strpos( $body, '"fields":[]' ) || false !== strpos( $body, '"data":[]' ) ) {
			self::$invalid[] = 'an empty object sent as a list';

			return self::answer( 422, array( 'code' => 'validation_failed' ) );
		}

		$events = $json['events'];

		foreach ( $events as $index => $event ) {
			$problem = self::problem( $event );

			if ( '' === $problem && null !== self::$reject && call_user_func( self::$reject, $event ) ) {
				$problem = 'rejected by the test';
			} elseif ( '' !== $problem ) {
				self::$invalid[] = $problem;
			}

			if ( '' === $problem && $event['seq'] !== $events[0]['seq'] + $index ) {
				$problem         = 'not consecutive';
				self::$invalid[] = $problem;
			}

			if ( '' !== $problem ) {
				return self::answer( 422, array( 'code' => 'validation_failed' ) );
			}
		}

		if ( 'gap' === self::$mode ) {
			// A centre that is never satisfied.
			return self::answer( 409, array( 'code' => 'sequence_gap', 'expected_seq' => $events[0]['seq'] + 1 ) );
		}

		if ( $json['epoch'] !== self::$epoch ) {
			return self::answer( 409, array( 'code' => 'epoch_mismatch', 'epoch' => self::$epoch ) );
		}

		if ( $events[0]['seq'] > self::$received + 1 ) {
			return self::answer( 409, array( 'code' => 'sequence_gap', 'expected_seq' => self::$received + 1 ) );
		}

		$duplicates = 0;
		$fresh      = array();
		$ids        = array_column( self::$events, 'event_id' );

		foreach ( $events as $event ) {
			if ( $event['seq'] <= self::$received ) {
				if ( ! isset( self::$events[ $event['seq'] ] ) || self::$events[ $event['seq'] ]['event_id'] !== $event['event_id'] ) {
					return self::answer( 409, array( 'code' => 'sequence_regression', 'expected_seq' => self::$received + 1 ) );
				}

				++$duplicates;
				continue;
			}

			if ( in_array( $event['event_id'], $ids, true ) ) {
				return self::answer( 409, array( 'code' => 'conflict' ) );
			}

			$fresh[] = $event;
		}

		foreach ( $fresh as $event ) {
			self::$events[ $event['seq'] ] = $event;
			self::$received                = $event['seq'];
		}

		if ( self::$lose_ack > 0 ) {
			--self::$lose_ack;

			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}

		return self::answer(
			200,
			array(
				'accepted_through_seq' => self::$received,
				'duplicates'           => $duplicates,
			)
		);
	}

	/**
	 * Catch a request and answer it.
	 */
	public static function handle( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://central.' ) ) {
			return $pre;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$body = isset( $args['body'] ) ? (string) $args['body'] : '';

		self::$requests[] = array(
			'path' => $path,
			'body' => $body,
			'json' => json_decode( $body, true ),
		);

		if ( 'down' === self::$mode ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		}

		if ( '/api/v1/connector/register' === $path ) {
			$json = json_decode( $body, true );

			if ( $json['enrolment_token'] !== self::$token ) {
				return self::answer( 410, array( 'code' => 'gone' ) );
			}

			self::$site_key = $json['public_key'];
			self::$token    = '';

			return self::answer(
				201,
				array(
					'website_id'            => MHS_WEBSITE,
					'platform_keys'         => array(
						array(
							'key_id'     => 'p-test',
							'public_key' => base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) ), // phpcs:ignore
							'status'     => 'active',
						),
					),
					'protocol'              => 1,
					'endpoints'             => array( 'base' => MHS_CENTRAL . '/api/v1/connector' ),
					'poll_interval_seconds' => 60,
					'sync'                  => array(
						'epoch'             => self::$epoch,
						'last_seq_received' => self::$received,
					),
				)
			);
		}

		$signed           = self::signed( $args['method'], $path, $args['headers'], $body );
		self::$verified[] = $signed;

		if ( ! $signed ) {
			return self::answer( 401, array( 'code' => 'unauthenticated' ) );
		}

		switch ( self::$mode ) {
			case '500':
				return self::answer( 500, array( 'code' => 'internal' ) );
			case '401':
				return self::answer( 401, array( 'code' => 'unauthenticated' ) );
			case '429':
				return self::answer( 429, array( 'code' => 'rate_limited' ) );
			case '410':
				return self::answer( 410, array( 'code' => 'gone' ) );
			case 'key_revoked':
			case 'disconnected':
				return self::answer( 423, array( 'code' => 'website_suspended', 'reason' => self::$mode ) );
		}

		if ( '/api/v1/connector/sync/events' === $path ) {
			return self::receive( $body );
		}

		if ( '/api/v1/connector/disconnect' === $path ) {
			return self::answer( 200, array( 'disconnected' => true ) );
		}

		return self::answer(
			200,
			array(
				'status'           => 'connected',
				'commands_pending' => 0,
				'ack_through_seq'  => self::$received,
				'want'             => array(),
				'epoch'            => self::$epoch,
			)
		);
	}

	/**
	 * How many pushes were made since the last call.
	 */
	public static function pushes() {
		$count = 0;

		foreach ( self::$requests as $request ) {
			if ( '/api/v1/connector/sync/events' === $request['path'] ) {
				++$count;
			}
		}

		self::$requests = array();

		return $count;
	}
}

add_filter( 'pre_http_request', array( 'MHS_Centre', 'handle' ), 10, 3 );

// The dev site is on plain http. A connected site is an https one.
add_filter(
	'home_url',
	static function ( $url, $path ) {
		return 'https://shop.example.test' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
	},
	10,
	2
);

/**
 * Forget any connection, as a site that never had one.
 */
function mhs_forget() {
	delete_option( 'mailhouse_central' );
	Mailhouse_Settings::set(
		array(
			'central_connected'   => false,
			'central_private_key' => '',
		)
	);
	Mailhouse_Central_Identity::flush();
	Mailhouse_Central_Outbox::reset();
}

/**
 * Connect to the stand-in.
 */
function mhs_connect() {
	mhs_forget();

	$result = Mailhouse_Central_Identity::register( MHS_CENTRAL, MHS_Centre::issue_token() );

	MHS_Centre::$requests = array();

	return $result;
}

/**
 * Let the next push go, whatever the last one decided.
 */
function mhs_due() {
	Mailhouse_Central_Identity::update( array( 'push_next_at' => 0 ) );
}

/**
 * The id of the newest outbox row.
 */
function mhs_mark() {
	return Mailhouse_Central_Outbox::highest();
}

/**
 * Outbox rows written after a mark, decoded.
 */
function mhs_since( $mark ) {
	global $wpdb;

	$table = Mailhouse_Central_Outbox::table();
	// phpcs:ignore WordPress.DB
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC", $mark ) );
	$out  = array();

	foreach ( $rows as $row ) {
		$payload = json_decode( $row->payload, true );

		$out[] = array(
			'id'      => (int) $row->id,
			'type'    => $row->type,
			'origin'  => $row->origin,
			'subject' => $row->subject_type . ':' . $row->subject_id,
			'data'    => isset( $payload['data'] ) ? $payload['data'] : null,
			'state'   => isset( $payload['state'] ) ? $payload['state'] : null,
			'raw'     => $row->payload,
		);
	}

	return $out;
}

/**
 * The types of the events written after a mark, as one string.
 */
function mhs_types( $mark ) {
	return implode( ' ', array_column( mhs_since( $mark ), 'type' ) );
}

/**
 * How many rows are in the outbox table.
 */
function mhs_rows() {
	global $wpdb;

	$table = Mailhouse_Central_Outbox::table();

	// phpcs:ignore WordPress.DB
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
}

/**
 * How many outbox rows say something, in what they would send.
 *
 * @param string $text What to look for.
 * @return int
 */
function mhs_mentions( $text ) {
	global $wpdb;

	$table = Mailhouse_Central_Outbox::table();

	// phpcs:ignore WordPress.DB
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE payload LIKE %s", '%' . $wpdb->esc_like( $text ) . '%' ) );
}

/**
 * An address nobody else is using.
 */
function mhs_address( $name = 'reader' ) {
	static $n = 0;

	return $name . '-' . ( ++$n ) . '-' . wp_rand( 1000, 9999 ) . '@' . MHS_DOMAIN;
}

/**
 * Add a subscriber quietly and return the row.
 */
function mhs_subscriber( $status = 'subscribed', $args = array() ) {
	$result = Mailhouse_Subscribers::add(
		array_merge(
			array(
				'email'       => mhs_address(),
				'status'      => $status,
				'source'      => 'form',
				'send_emails' => false,
			),
			$args
		)
	);

	return Mailhouse_Subscribers::get( (int) $result['id'] );
}

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$admin  = $admins ? (int) $admins[0] : 0;

mhs_clean();
mhs_forget();
MHS_Centre::reset();
Mailhouse_Settings::set( array( 'default_tags' => array() ) );
wp_set_current_user( 0 );

// ---------------------------------------------------------------------------
echo "\nA subscriber's state, and its hash\n";

$vectors = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/protocol-v1.json' ), true ); // phpcs:ignore

check( 'the worked examples include states', is_array( $vectors ) && ! empty( $vectors['states'] ) && ! empty( $vectors['changed_states'] ) );

$wrong = array();

foreach ( $vectors['states'] as $case ) {
	if ( Mailhouse_Central_Protocol::canonical_state( $case['state'] ) !== $case['canonical'] || Mailhouse_Central_Protocol::state_hash( $case['state'] ) !== $case['hash'] ) {
		$wrong[] = $case['name'];
	}
}
check( count( $vectors['states'] ) . ' states: the same canonical form and the same hash as the centre', ! $wrong, implode( ' | ', $wrong ) );

$wrong  = array();
$hashes = array( $vectors['states'][0]['hash'] => true );

foreach ( $vectors['changed_states'] as $case ) {
	if ( Mailhouse_Central_Protocol::state_hash( $case['state'] ) !== $case['hash'] ) {
		$wrong[] = $case['name'];
	}

	$hashes[ $case['hash'] ] = true;
}
check( count( $vectors['changed_states'] ) . ' states that differ in one thing each: the same hashes as the centre', ! $wrong, implode( ' | ', $wrong ) );
check( 'and every one of them a different hash', count( $hashes ) === count( $vectors['changed_states'] ) + 1 );

$shuffled           = $vectors['states'][0]['state'];
$shuffled['tags']   = array_reverse( $shuffled['tags'] );
$shuffled['fields'] = array_reverse( $shuffled['fields'], true );
check( 'the order tags and fields are held in does not change the hash', Mailhouse_Central_Protocol::state_hash( $shuffled ) === $vectors['states'][0]['hash'] );

$row = (object) array(
	'id'              => 5521,
	'email'           => 'jane@example.com',
	'first_name'      => 'Jane',
	'last_name'       => 'Doe',
	'fields'          => '{"size":"10","company":"Acme"}',
	'status'          => 'unsubscribed',
	'source'          => 'form',
	'ip'              => '203.0.113.9',
	'user_id'         => 7,
	'confirm_key'     => 'aaaaaaaaaaaaaaaa',
	'manage_key'      => 'bbbbbbbbbbbbbbbb',
	'sent'            => 12,
	'opens'           => 3,
	'clicks'          => 1,
	'bounces'         => 0,
	'created_at'      => '2025-03-02 10:11:12',
	'updated_at'      => '2026-11-12 09:29:51',
	'confirmed_at'    => '2025-03-02 10:14:40',
	'unsubscribed_at' => '2026-11-12 09:29:51',
	'revision'        => 8,
);

$state = Mailhouse_Central_Protocol::subscriber_state( $row, array( 9, 3 ) );
check( 'a row from the table becomes the state of the first worked example, hash and all', $vectors['states'][0]['hash'] === $state['hash'] && array( 3, 9 ) === $state['tags'] );
check(
	'a state has exactly the fourteen things that are synchronised',
	array( 'local_id', 'email', 'first_name', 'last_name', 'fields', 'status', 'source', 'signup_ip', 'created_at', 'confirmed_at', 'unsubscribed_at', 'tags', 'revision', 'hash' ) === array_keys( $state )
);
$encoded = wp_json_encode( $state );
check( 'never the keys, the user id or the counters', false === strpos( $encoded, 'aaaaaaaa' ) && false === strpos( $encoded, 'bbbbbbbb' ) && false === strpos( $encoded, 'user_id' ) && false === strpos( $encoded, 'opens' ) );

$odd                  = clone $row;
$odd->first_name      = "Ja\0ne \xC3\x28 " . str_repeat( '😀', 150 );
$odd->last_name       = str_repeat( 'é', 250 );
$odd->fields          = wp_json_encode(
	array(
		'nested' => array( 'not', 'text' ),
		'long'   => str_repeat( 'x', 2500 ),
		'number' => 42,
		''       => 'no name',
	)
);
$odd->ip              = 'unknown';
$odd->created_at      = '0000-00-00 00:00:00';
$odd->confirmed_at    = '2026-02-31 10:00:00';
$odd->unsubscribed_at = null;
$state                = Mailhouse_Central_Protocol::subscriber_state( $odd, array( 3, 3, 0, 9 ) );
$problem              = MHS_Centre::problem(
	array(
		'seq'         => 1,
		'event_id'    => wp_generate_uuid4(),
		'type'        => 'subscriber.created',
		'occurred_at' => '2026-11-12T10:00:00Z',
		'origin'      => 'local',
		'subject'     => array(
			'type'     => 'subscriber',
			'local_id' => 5521,
		),
		'data'        => array(),
		'state'       => json_decode( wp_json_encode( $state ), true ),
	)
);
check( 'a row full of things the centre would refuse becomes a state it accepts', '' === $problem, $problem );
check( 'a NUL character and a broken byte are removed, not sent', false === strpos( $state['first_name'], "\0" ) && 1 === preg_match( '//u', $state['first_name'] ) );
check( 'a name too long is cut where the centre would stop counting', mhs_units( $state['first_name'] ) <= 200 && mhs_units( $state['first_name'] ) >= 198 && 200 === mhs_units( $state['last_name'] ) );
check( 'a custom field that is not text is left out; one too long is cut; a number is text', array( 'long', 'number' ) === array_keys( $state['fields'] ) && 2000 === strlen( $state['fields']['long'] ) && '42' === $state['fields']['number'] );
check( 'something that is not an address is no address', null === $state['signup_ip'] );
check( 'a date that never was is no date, and a row always has a date it was made', null === $state['confirmed_at'] && '1970-01-01T00:00:00Z' === $state['created_at'] );
check( 'tags are told once each, in order', array( 3, 9 ) === $state['tags'] );
check( 'and the hash is the hash of what is sent', Mailhouse_Central_Protocol::state_hash( $state ) === $state['hash'] );

$six     = clone $row;
$six->ip = '2001:0DB8:0000:0000:0000:0000:0000:0007';
check( 'an address is told in one spelling', '2001:db8::7' === Mailhouse_Central_Protocol::subscriber_state( $six, array() )['signup_ip'] );

$empty         = clone $row;
$empty->fields = null;
check( 'no custom fields is an empty object, not an empty list', false !== strpos( wp_json_encode( Mailhouse_Central_Protocol::subscriber_state( $empty, array() ) ), '"fields":{}' ) );

// ---------------------------------------------------------------------------
echo "\nA site that is not connected\n";

global $wpdb;

$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( Mailhouse_Central_Outbox::table() ) ) ); // phpcs:ignore WordPress.DB
check( 'the outbox table exists, and is InnoDB', $status && 'innodb' === strtolower( (string) $status->Engine ) ); // phpcs:ignore WordPress.NamingConventions
check( 'it is one of the tables the connection checks', 'InnoDB' === Mailhouse_Central_Identity::db_engine() );

Mailhouse_Central_Outbox::listen();

$quiet = mhs_subscriber( 'pending', array( 'tags' => array( 'MHS Quiet' ) ) );
Mailhouse_Subscribers::confirm( $quiet );
Mailhouse_Subscribers::unsubscribe( Mailhouse_Subscribers::get( (int) $quiet->id ), 'link' );
Mailhouse_Subscribers::delete( array( (int) $quiet->id ) );

check( 'writes nothing down, whatever happens to its subscribers', 0 === mhs_rows() && false === Mailhouse_Central_Outbox::capturing() );
check( 'and asks nothing of anyone', 0 === count( MHS_Centre::$requests ) );
check( 'a push from it sends nothing', 0 === Mailhouse_Central_Sync::push()['sent'] && 0 === count( MHS_Centre::$requests ) );

// ---------------------------------------------------------------------------
echo "\nRegistering starts the sequence\n";

MHS_Centre::$received = 40;
$connected            = mhs_connect();

check( 'the site registers', true === $connected, is_wp_error( $connected ) ? $connected->get_error_message() : '' );
check( 'and is told where the centre’s count stands', 1 === (int) Mailhouse_Central_Identity::get( 'epoch' ) && 40 === (int) Mailhouse_Central_Identity::get( 'seq_offset' ) + (int) Mailhouse_Central_Identity::get( 'outbox_acked' ) );
check( 'changes are now written down', Mailhouse_Central_Outbox::capturing() );
check( 'it announces that it can', in_array( 'sync.v1', Mailhouse_Central_Status::capabilities(), true ) );

$mark = mhs_mark();
$jane = mhs_subscriber( 'pending', array( 'ip' => '203.0.113.9' ) );
$rows = mhs_since( $mark );

check( 'a change is one local row, and no request', 1 === count( $rows ) && 0 === count( MHS_Centre::$requests ) );
check( 'the table had been used and emptied before; the numbering is right all the same', 41 === $rows[0]['id'] + (int) Mailhouse_Central_Identity::get( 'seq_offset' ) && $rows[0]['id'] > 1 );

$push = Mailhouse_Central_Sync::push();

check( 'a push sends it, signed', 1 === $push['sent'] && array( true ) === MHS_Centre::$verified );
check( 'numbered one more than the last the centre had', array( 41 ) === array_keys( MHS_Centre::$events ) );
check( 'as an event the centre’s schema accepts', array() === MHS_Centre::$invalid, implode( ' | ', MHS_Centre::$invalid ) );

$sent = MHS_Centre::$events[41];
check( 'saying what happened, to whom, when and why', 'subscriber.created' === $sent['type'] && 'local' === $sent['origin'] && (int) $jane->id === $sent['subject']['local_id'] && $rows[0]['state']['hash'] === $sent['state']['hash'] );
check( 'with the subscriber’s whole state', $jane->email === $sent['state']['email'] && 'pending' === $sent['state']['status'] && '203.0.113.9' === $sent['state']['signup_ip'] && 'form' === $sent['state']['source'] );
check( 'the row stays until the centre has it, then goes -- all but the newest', 1 === mhs_rows() && 0 === Mailhouse_Central_Outbox::waiting()['depth'] );
check( 'and the one that is left keeps its number and not what it said', 0 === mhs_mentions( $jane->email ) && 0 === mhs_mentions( 'hash' ) );

$report = Mailhouse_Central_Status::report();
check( 'the heartbeat says the outbox is empty and how far the centre has acknowledged', 0 === $report['outbox']['depth'] && 41 === $report['outbox']['last_acked_seq'] && false === $report['outbox']['needs_reconcile'] && null === $report['outbox']['oldest'] );

MHS_Centre::pushes();
Mailhouse_Central_Sync::push();
check( 'with nothing waiting, a push asks nothing', 0 === MHS_Centre::pushes() );

// ---------------------------------------------------------------------------
echo "\nEvery way a subscriber changes\n";

/**
 * The one event written after a mark, or an empty array.
 */
function mhs_one( $mark ) {
	$rows = mhs_since( $mark );

	return 1 === count( $rows ) ? $rows[0] : array( 'type' => count( $rows ) . ' events: ' . implode( ' ', array_column( $rows, 'type' ) ), 'data' => array(), 'state' => array(), 'origin' => '' );
}

// 1-3. The public form, the comment checkbox, the registration checkbox.
foreach ( array( 'form', 'comment', 'registration' ) as $source ) {
	$mark  = mhs_mark();
	$new   = mhs_subscriber( 'pending', array( 'source' => $source ) );
	$event = mhs_one( $mark );

	check( "1-3. a sign-up by $source: created, pending, from $source", 'subscriber.created' === $event['type'] && 'pending' === $event['state']['status'] && $source === $event['state']['source'] && (int) $new->revision === $event['state']['revision'] );
}

$mark = mhs_mark();
Mailhouse_Subscribers::add(
	array(
		'email'       => $jane->email,
		'status'      => 'pending',
		'send_emails' => false,
	)
);
check( '1. signing up again when nothing changes says nothing', '' === mhs_types( $mark ) );

$mark   = mhs_mark();
$tagged = mhs_subscriber( 'pending', array( 'tags' => array( 'MHS Readers' ) ) );
$rows   = mhs_since( $mark );
$last   = end( $rows );
check( '1. a sign-up with a new tag: the tag, then the subscriber holding it', 'tag.saved' === $rows[0]['type'] && 'MHS Readers' === $rows[0]['data']['name'] && 'subscriber.created' === $last['type'] && 1 === count( $last['state']['tags'] ) );

// 4. The confirmation link.
$mark = mhs_mark();
Mailhouse_Subscribers::confirm( Mailhouse_Subscribers::get( (int) $jane->id ) );
$event = mhs_one( $mark );
check( '4. the confirmation link: confirmed, by the owner', 'subscriber.confirmed' === $event['type'] && array( 'by' => 'owner' ) === $event['data'] && 'subscribed' === $event['state']['status'] && null !== $event['state']['confirmed_at'] );

$mark = mhs_mark();
Mailhouse_Subscribers::confirm( Mailhouse_Subscribers::get( (int) $jane->id ) );
check( '4. the link clicked a second time says nothing', '' === mhs_types( $mark ) );

// 5, 6. Unsubscribing.
$mark = mhs_mark();
Mailhouse_Subscribers::unsubscribe( Mailhouse_Subscribers::get( (int) $jane->id ), 'link' );
$event = mhs_one( $mark );
check( '5. the unsubscribe link: unsubscribed, by link, with the date', 'subscriber.unsubscribed' === $event['type'] && array( 'source' => 'link' ) === $event['data'] && 'unsubscribed' === $event['state']['status'] && null !== $event['state']['unsubscribed_at'] );

$other = mhs_subscriber();
$mark  = mhs_mark();
Mailhouse_Subscribers::unsubscribe( $other, 'one_click' );
$event = mhs_one( $mark );
check( '6. one-click unsubscribe: unsubscribed, by one_click', 'subscriber.unsubscribed' === $event['type'] && array( 'source' => 'one_click' ) === $event['data'] );

// 8. Coming back from the preferences page.
$mark = mhs_mark();
Mailhouse_Subscribers::resubscribe( Mailhouse_Subscribers::get( (int) $jane->id ), 'owner' );
$event = mhs_one( $mark );
check( '8. the preferences page, resubscribing: resubscribed, by the owner', 'subscriber.resubscribed' === $event['type'] && array( 'by' => 'owner' ) === $event['data'] && 'subscribed' === $event['state']['status'] );

// 7. The preferences page: name and tags.
$readers = Mailhouse_Tags::create( 'MHS Readers' );
$writers = Mailhouse_Tags::create( 'MHS Writers' );
$before  = Mailhouse_Subscribers::get( (int) $jane->id );
$mark    = mhs_mark();
Mailhouse_Subscribers::update( (int) $jane->id, array( 'first_name' => 'Janet' ) );
Mailhouse_Tags::set( (int) $jane->id, array( $readers, $writers ) );
$rows = mhs_since( $mark );
check( '7. the preferences page: a profile update, then a change of tags', 'subscriber.profile_updated subscriber.tags_changed' === mhs_types( $mark ) );
check( '7. each saying what changed', array( 'changed' => array( 'first_name' ) ) === $rows[0]['data'] && array( $readers, $writers ) === $rows[1]['data']['added'] && array() === $rows[1]['data']['removed'] );
check( '7. each with the state as it then was, one revision apart', 'Janet' === $rows[0]['state']['first_name'] && array() === $rows[0]['state']['tags'] && 2 === count( $rows[1]['state']['tags'] ) && (int) $before->revision + 2 === $rows[1]['state']['revision'] );

$mark = mhs_mark();
Mailhouse_Subscribers::update( (int) $jane->id, array( 'first_name' => 'Janet' ) );
Mailhouse_Tags::set( (int) $jane->id, array( $writers, $readers ) );
check( '7. saving the page with nothing changed says nothing', '' === mhs_types( $mark ) );

// 9. An administrator adds somebody.
wp_set_current_user( $admin );
$mark  = mhs_mark();
$added = mhs_subscriber( 'subscribed', array( 'source' => 'admin', 'allow_resubscribe' => true ) );
$event = mhs_one( $mark );
check( '9. added in the admin: created, subscribed, from admin', $admin > 0 && 'subscriber.created' === $event['type'] && 'subscribed' === $event['state']['status'] && 'admin' === $event['state']['source'] );

$left = mhs_subscriber();
Mailhouse_Subscribers::unsubscribe( $left, 'link' );
$mark = mhs_mark();
Mailhouse_Subscribers::add(
	array(
		'email'             => $left->email,
		'status'            => 'subscribed',
		'allow_resubscribe' => true,
		'send_emails'       => true,
	)
);
$event = mhs_one( $mark );
check( '9. an administrator adding somebody who had left: resubscribed, by admin -- not by the owner', 'subscriber.resubscribed' === $event['type'] && array( 'by' => 'admin' ) === $event['data'] );

// 10. An administrator edits somebody: address, name, status, tags.
$edited = mhs_subscriber();
$new    = mhs_address( 'moved' );
$mark   = mhs_mark();
Mailhouse_Subscribers::update(
	(int) $edited->id,
	array(
		'email'      => $new,
		'first_name' => 'Edited',
		'fields'     => wp_json_encode( array( 'company' => 'Acme' ) ),
	)
);
Mailhouse_Subscribers::set_status( Mailhouse_Subscribers::get( (int) $edited->id ), 'unsubscribed', 'admin' );
Mailhouse_Tags::set( (int) $edited->id, array( $readers ) );
$rows = mhs_since( $mark );
check( '10. an edit in the admin: the address, the profile, the status and the tags, in that order', 'subscriber.email_changed subscriber.profile_updated subscriber.unsubscribed subscriber.tags_changed' === mhs_types( $mark ) );
check( '10. the change of address says what it was', array( 'previous_email' => $edited->email ) === $rows[0]['data'] && $new === $rows[0]['state']['email'] );
check( '10. two events from one write carry the same state and revision', $rows[0]['state'] === $rows[1]['state'] && array( 'first_name', 'fields' ) === $rows[1]['data']['changed'] && array( 'company' => 'Acme' ) === $rows[1]['state']['fields'] );
check( '10. the unsubscribe is the administrator’s', array( 'source' => 'admin' ) === $rows[2]['data'] && null !== $rows[2]['state']['unsubscribed_at'] );

$mark = mhs_mark();
Mailhouse_Subscribers::set_status( Mailhouse_Subscribers::get( (int) $edited->id ), 'bounced', 'admin' );
$event = mhs_one( $mark );
check( '10. an administrator retiring an address: bounced, hard, with the reason', 'subscriber.bounced' === $event['type'] && 'hard' === $event['data']['kind'] && '' !== $event['data']['reason'] );

$mark = mhs_mark();
Mailhouse_Subscribers::set_status( Mailhouse_Subscribers::get( (int) $edited->id ), 'pending', 'admin' );
$event = mhs_one( $mark );
check( '10. an administrator sending somebody back to pending: resubscribed, by admin, and pending', 'subscriber.resubscribed' === $event['type'] && array( 'by' => 'admin' ) === $event['data'] && 'pending' === $event['state']['status'] );

// 11, 12. Bulk actions.
$mark = mhs_mark();
Mailhouse_Subscribers::update(
	(int) $edited->id,
	array(
		'status'       => 'subscribed',
		'confirmed_at' => mailhouse_now(),
		'confirm_key'  => null,
	)
);
$event = mhs_one( $mark );
check( '11. bulk subscribe on somebody pending: confirmed, by admin', 'subscriber.confirmed' === $event['type'] && array( 'by' => 'admin' ) === $event['data'] );

$mark = mhs_mark();
Mailhouse_Subscribers::unsubscribe( Mailhouse_Subscribers::get( (int) $edited->id ), 'admin' );
$event = mhs_one( $mark );
check( '12. bulk unsubscribe: unsubscribed, by admin', 'subscriber.unsubscribed' === $event['type'] && array( 'source' => 'admin' ) === $event['data'] );

// 13. Deleting.
$doomed = array( mhs_subscriber(), mhs_subscriber( 'pending' ), mhs_subscriber() );
Mailhouse_Tags::attach( (int) $doomed[0]->id, array( $readers ) );
$doomed[0] = Mailhouse_Subscribers::get( (int) $doomed[0]->id );
$mark      = mhs_mark();
Mailhouse_Subscribers::delete( array( (int) $doomed[0]->id, (int) $doomed[1]->id, (int) $doomed[2]->id ) );
$rows = mhs_since( $mark );
check( '13. deleting three: three events', 'subscriber.deleted subscriber.deleted subscriber.deleted' === mhs_types( $mark ) );
check( '13. each with the last state there was, tags included, and why', array( 'last_status' => 'subscribed', 'reason' => 'admin' ) === $rows[0]['data'] && $doomed[0]->email === $rows[0]['state']['email'] && array( $readers ) === $rows[0]['state']['tags'] && (int) $doomed[0]->revision === $rows[0]['state']['revision'] && 'pending' === $rows[1]['data']['last_status'] );

wp_set_current_user( 0 );

// 14, 15. Imports.
$mark     = mhs_mark();
$imported = mhs_subscriber( 'subscribed', array( 'source' => 'csv' ) );
Mailhouse_Subscribers::update( (int) $imported->id, array( 'created_at' => '2021-05-06 07:08:09' ) );
$rows = mhs_since( $mark );
check( '14-15. an import: created, from csv; then the date it carries', 'subscriber.created subscriber.profile_updated' === mhs_types( $mark ) && 'csv' === $rows[0]['state']['source'] && array( 'changed' => array( 'created_at' ) ) === $rows[1]['data'] && '2021-05-06T07:08:09Z' === $rows[1]['state']['created_at'] );

// 16-19. What a mail provider reports.
$apply = new ReflectionMethod( 'Mailhouse_Webhooks', 'apply' );
$apply->setAccessible( true );
$report = static function ( $type, $subscriber, $reason = '' ) use ( $apply ) {
	return $apply->invoke(
		null,
		array(
			'type'   => $type,
			'email'  => $subscriber->email,
			'reason' => $reason,
		)
	);
};

$hard = mhs_subscriber();
$mark = mhs_mark();
$report( 'hard', $hard, '550 5.1.1 no such user' );
$event = mhs_one( $mark );
check( '16. a hard bounce: bounced, hard, with what the provider said -- and nothing for the counter', 'subscriber.bounced' === $event['type'] && array( 'kind' => 'hard', 'reason' => '550 5.1.1 no such user' ) === $event['data'] && 'bounced' === $event['state']['status'] );

Mailhouse_Settings::set( array( 'soft_bounce_limit' => 3 ) );
$soft = mhs_subscriber();
$mark = mhs_mark();
$report( 'soft', $soft, 'mailbox full' );
$report( 'soft', Mailhouse_Subscribers::get( (int) $soft->id ), 'mailbox full' );
check( '17. soft bounces below the limit say nothing', '' === mhs_types( $mark ) );
$report( 'soft', Mailhouse_Subscribers::get( (int) $soft->id ), 'mailbox still full' );
$event = mhs_one( $mark );
check( '17. the one that reaches the limit: bounced, as a soft limit', 'subscriber.bounced' === $event['type'] && array( 'kind' => 'soft_limit', 'reason' => 'mailbox still full' ) === $event['data'] );

$annoyed = mhs_subscriber();
$mark    = mhs_mark();
$report( 'complaint', $annoyed );
$event = mhs_one( $mark );
check( '18. a spam complaint: unsubscribed, as a complaint', 'subscriber.unsubscribed' === $event['type'] && array( 'source' => 'complaint' ) === $event['data'] );

$elsewhere = mhs_subscriber();
$mark      = mhs_mark();
$report( 'unsubscribe', $elsewhere );
$event = mhs_one( $mark );
check( '18. an unsubscribe at the provider: unsubscribed, by provider', 'subscriber.unsubscribed' === $event['type'] && array( 'source' => 'provider' ) === $event['data'] );

$wobbly = mhs_subscriber();
$report( 'soft', $wobbly, 'mailbox full' );
$mark     = mhs_mark();
$revision = (int) Mailhouse_Subscribers::get( (int) $wobbly->id )->revision;
$report( 'delivered', Mailhouse_Subscribers::get( (int) $wobbly->id ) );
check( '19. a delivery that clears the bounce count says nothing', '' === mhs_types( $mark ) && 0 === (int) Mailhouse_Subscribers::get( (int) $wobbly->id )->bounces );

// 20, 21. Counters.
Mailhouse_Subscribers::update(
	(int) $wobbly->id,
	array(
		'sent'   => 5,
		'opens'  => 2,
		'clicks' => 1,
	)
);
check( '20-21. sends, opens and clicks say nothing, and do not move the revision', '' === mhs_types( $mark ) && $revision === (int) Mailhouse_Subscribers::get( (int) $wobbly->id )->revision );

// 22. The privacy eraser.
$forgotten = mhs_subscriber();
$mark      = mhs_mark();
Mailhouse_Subscribers::erase_personal_data( $forgotten->email );
$event = mhs_one( $mark );
check( '22. the privacy eraser: deleted, as an erasure', 'subscriber.deleted' === $event['type'] && array( 'last_status' => 'subscribed', 'reason' => 'erasure' ) === $event['data'] && $forgotten->email === $event['state']['email'] );
check( 'which the outbox holds, with who they were, until the centre has it', mhs_mentions( $forgotten->email ) >= 1 );

// A sign-up by somebody who had left, confirmed by the link they were sent.
$returning = mhs_subscriber();
Mailhouse_Subscribers::unsubscribe( $returning, 'link' );
$mark = mhs_mark();
Mailhouse_Subscribers::add(
	array(
		'email'        => $returning->email,
		'double_optin' => true,
	)
);
check( 'somebody who left signing up again is sent a link, and nothing has changed yet', '' === mhs_types( $mark ) && 'unsubscribed' === Mailhouse_Subscribers::get( (int) $returning->id )->status );
Mailhouse_Subscribers::confirm( Mailhouse_Subscribers::get( (int) $returning->id ) );
$event = mhs_one( $mark );
check( 'clicking it: resubscribed, by the owner', 'subscriber.resubscribed' === $event['type'] && array( 'by' => 'owner' ) === $event['data'] );

// ---------------------------------------------------------------------------
echo "\nTags\n";

$mark = mhs_mark();
$tag  = Mailhouse_Tags::create( 'MHS Press' );
$row  = mhs_one( $mark );
check( 'a new tag: saved, with its id, name and slug', 'tag.saved' === $row['type'] && $tag === $row['data']['local_id'] && 'MHS Press' === $row['data']['name'] && '' !== $row['data']['slug'] && null === $row['state'] && "tag:$tag" === $row['subject'] );

$mark = mhs_mark();
Mailhouse_Tags::create( 'MHS Press' );
check( 'making a tag that is already there says nothing', '' === mhs_types( $mark ) );

$mark = mhs_mark();
Mailhouse_Tags::update( $tag, 'MHS Newspapers' );
$row = mhs_one( $mark );
check( 'a renamed tag: saved again, same id, new name and slug', 'tag.saved' === $row['type'] && $tag === $row['data']['local_id'] && 'MHS Newspapers' === $row['data']['name'] );

$holders = array( mhs_subscriber(), mhs_subscriber() );
Mailhouse_Tags::attach( (int) $holders[0]->id, array( $tag, $readers ) );
Mailhouse_Tags::attach( (int) $holders[1]->id, array( $tag ) );
$before = (int) Mailhouse_Subscribers::get( (int) $holders[0]->id )->revision;
$mark   = mhs_mark();
Mailhouse_Tags::delete( $tag );
$row = mhs_one( $mark );
check( 'a deleted tag: one event for the tag, not one for each holder', 'tag.deleted' === $row['type'] && array( 'local_id' => $tag ) === $row['data'] );

$after = Mailhouse_Subscribers::get( (int) $holders[0]->id );
$state = Mailhouse_Central_Outbox::state_of( $after );
check( 'each holder is one revision on and holds one tag fewer, which is what the centre works out for itself', $before + 1 === (int) $after->revision && array( $readers ) === $state['tags'] );

$mark = mhs_mark();
Mailhouse_Tags::detach( (int) $holders[0]->id, array( $readers ) );
$row = mhs_one( $mark );
check( 'a tag taken off somebody: a change of tags, saying which', 'subscriber.tags_changed' === $row['type'] && array( $readers ) === $row['data']['removed'] && array() === $row['state']['tags'] );

// ---------------------------------------------------------------------------
echo "\nBecause the centre asked\n";

$command = wp_generate_uuid4();
$subject = mhs_subscriber();
$mark    = mhs_mark();
$result  = Mailhouse_Central::with_origin(
	$command,
	static function () use ( $subject ) {
		Mailhouse_Subscribers::unsubscribe( $subject, 'central' );

		return 'done';
	}
);
$event = mhs_one( $mark );
check( 'a change made for a command is marked as that command’s', 'done' === $result && "command:$command" === $event['origin'] && array( 'source' => 'central' ) === $event['data'] );
check( 'and the next change is the site’s own again', 'local' === Mailhouse_Central::origin() );

$mark = mhs_mark();
Mailhouse_Central::with_origin(
	$command,
	static function () use ( $subject ) {
		Mailhouse_Subscribers::delete( array( (int) $subject->id ) );
	}
);
$event = mhs_one( $mark );
check( 'a deletion the centre asked for says so', 'command' === $event['data']['reason'] && "command:$command" === $event['origin'] );

try {
	Mailhouse_Central::with_origin(
		$command,
		static function () {
			throw new RuntimeException( 'half-way' );
		}
	);
} catch ( RuntimeException $e ) {
	$caught = true;
}
check( 'a command that fails half-way does not leave its mark on what follows', ! empty( $caught ) && 'local' === Mailhouse_Central::origin() );

Mailhouse_Central::with_origin(
	'not an id',
	static function () use ( &$inside ) {
		$inside = Mailhouse_Central::origin();
	}
);
check( 'something that is not a command id marks nothing', 'local' === $inside );

// ---------------------------------------------------------------------------
echo "\nEverything so far, sent\n";

$waiting = Mailhouse_Central_Outbox::waiting()['depth'];
MHS_Centre::calm();
$push = Mailhouse_Central_Sync::push();

check( 'one push sends all of it', $waiting > 40 && $waiting === $push['sent'] && 1 === MHS_Centre::pushes() );
check( 'every event passes the centre’s schema', array() === MHS_Centre::$invalid, implode( ' | ', array_unique( MHS_Centre::$invalid ) ) );
check( 'in order, with no number missing', array_keys( MHS_Centre::$events ) === range( 41, 41 + $waiting ) );
check( 'and now that the centre has it, the outbox does not say who was erased: the row left for its number says nothing', 0 === mhs_mentions( $forgotten->email ) && 0 === mhs_mentions( '@' ) && 1 === mhs_rows() );

$wrong = array();

foreach ( MHS_Centre::$events as $event ) {
	if ( isset( $event['state'] ) && Mailhouse_Central_Protocol::state_hash( $event['state'] ) !== $event['state']['hash'] ) {
		$wrong[] = $event['seq'];
	}
}
check( 'every state arrives with the hash of what arrived', ! $wrong, implode( ' ', $wrong ) );

$everything = implode( "\n", MHS_Centre::$bodies );
check( 'nothing sent names a key, a user id or a counter', false === strpos( $everything, 'manage_key' ) && false === strpos( $everything, 'confirm_key' ) && false === strpos( $everything, 'user_id' ) && false === strpos( $everything, '"opens"' ) && false === strpos( $everything, '"sent"' ) );

$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT manage_key FROM ' . Mailhouse_Install::table( 'subscribers' ) . ' WHERE email LIKE %s', '%@' . MHS_DOMAIN ) ); // phpcs:ignore WordPress.DB
$leak = array_filter(
	$keys,
	static function ( $key ) use ( $everything ) {
		return '' !== (string) $key && false !== strpos( $everything, (string) $key );
	}
);
check( 'and no subscriber’s key is in it anywhere', count( $keys ) > 10 && ! $leak );

// ---------------------------------------------------------------------------
echo "\nThe centre is down\n";

MHS_Centre::calm();
MHS_Centre::$mode = 'down';

$mark     = mhs_mark();
$expected = array();

for ( $i = 0; $i < 5; $i++ ) {
	$expected[] = (int) mhs_subscriber()->id;
}

$push = Mailhouse_Central_Sync::push();
check( 'the site carries on: subscribers are added, and the changes wait', 5 === Mailhouse_Central_Outbox::waiting()['depth'] && 0 === $push['sent'] && '' !== $push['error'] );

$wait = (int) Mailhouse_Central_Identity::get( 'push_next_at' ) - time();
check( 'the next attempt is a minute or so away', 1 === (int) Mailhouse_Central_Identity::get( 'push_failures' ) && $wait >= 59 && $wait <= 73, (string) $wait );

MHS_Centre::pushes();
Mailhouse_Central_Sync::push();
check( 'until then, a push does not even try', 0 === MHS_Centre::pushes() );

$waits = array();

for ( $i = 0; $i < 6; $i++ ) {
	mhs_due();
	Mailhouse_Central_Sync::push();
	$waits[] = (int) Mailhouse_Central_Identity::get( 'push_next_at' ) - time();
}
check( 'the wait doubles, and stops growing at a quarter of an hour or a little over', $waits[0] >= 119 && $waits[1] >= 239 && $waits[2] >= 479 && $waits[3] >= 899 && max( $waits ) <= 1081, implode( ' ', $waits ) );

$report = Mailhouse_Central_Status::report();
check( 'the heartbeat would say how much is waiting, and since when', 5 === $report['outbox']['depth'] && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $report['outbox']['oldest'] ) );

for ( $i = 0; $i < 3; $i++ ) {
	$expected[] = (int) mhs_subscriber()->id;
}

MHS_Centre::calm();
mhs_due();
$before = MHS_Centre::$received;
$push   = Mailhouse_Central_Sync::push();
$got    = array();

foreach ( MHS_Centre::$events as $seq => $event ) {
	if ( $seq > $before ) {
		$got[] = $event['subject']['local_id'];
	}
}
check( 'when the centre is back, the backlog drains in the order it happened', 8 === $push['sent'] && $expected === $got );
check( 'and the failures are forgotten', 0 === (int) Mailhouse_Central_Identity::get( 'push_failures' ) && '' === (string) Mailhouse_Central_Identity::get( 'push_error' ) && 0 === Mailhouse_Central_Outbox::waiting()['depth'] );

// ---------------------------------------------------------------------------
echo "\nAn answer that never arrives\n";

MHS_Centre::calm();
MHS_Centre::$lose_ack = 1;
mhs_subscriber();
mhs_subscriber();
$stored = count( MHS_Centre::$events );
Mailhouse_Central_Sync::push();

check( 'the centre stored the events; the site, not knowing, keeps them', $stored + 2 === count( MHS_Centre::$events ) && 2 === Mailhouse_Central_Outbox::waiting()['depth'] );

mhs_subscriber();
mhs_due();
$push = Mailhouse_Central_Sync::push();
check( 'sent again with what came after, they are stored once', 3 === $push['sent'] && $stored + 3 === count( MHS_Centre::$events ) && 0 === Mailhouse_Central_Outbox::waiting()['depth'] );
check( 'and the centre’s schema had nothing to say about any of it', array() === MHS_Centre::$invalid );

// ---------------------------------------------------------------------------
echo "\nMore than one request’s worth\n";

MHS_Centre::calm();

$table = Mailhouse_Central_Outbox::table();
$one   = mhs_subscriber();
Mailhouse_Central_Sync::push();
MHS_Centre::calm();

$state  = Mailhouse_Central_Outbox::state_of( $one );
$values = array();

for ( $i = 0; $i < 1203; $i++ ) {
	$state['revision'] = 100 + $i;
	$state['hash']     = Mailhouse_Central_Protocol::state_hash( $state );
	$values[]          = $wpdb->prepare( '(%s, %s, %s, %d, %s, %s, %s)', wp_generate_uuid4(), 'subscriber.profile_updated', 'subscriber', (int) $one->id, 'local', wp_json_encode( array( 'data' => array( 'changed' => array( 'first_name' ) ), 'state' => $state ) ), mailhouse_now() );
}

foreach ( array_chunk( $values, 300 ) as $chunk ) {
	$wpdb->query( "INSERT INTO {$table} (event_id, type, subject_type, subject_id, origin, payload, occurred_at) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore WordPress.DB
}

$before = count( MHS_Centre::$events );
$push   = Mailhouse_Central_Sync::push( 60 );
check( '1,203 events go in three requests of at most five hundred', 1203 === $push['sent'] && array( 500, 500, 203 ) === MHS_Centre::$batches && $before + 1203 === count( MHS_Centre::$events ) );
check( 'leaving one row behind, so that the table never counts from one again', 1 === mhs_rows() && 0 === Mailhouse_Central_Outbox::waiting()['depth'] );

for ( $i = 0; $i < 40; $i++ ) {
	Mailhouse_Central_Outbox::record( 'tag.saved', 'tag', 1, array( 'local_id' => 1, 'name' => 'MHS Many', 'slug' => 'mhs-many' ) );
}
MHS_Centre::calm();
$push = Mailhouse_Central_Sync::push( 0 );
check( 'a push that is out of time stops, and the rest wait for the next', $push['sent'] <= 40 && 1 === count( MHS_Centre::$batches ) );
Mailhouse_Central_Sync::push();

// ---------------------------------------------------------------------------
echo "\nThe centre and the site disagree about the sequence\n";

// The centre has lost its last twenty events: restored from a backup.
MHS_Centre::calm();
$lost = MHS_Centre::$received;

for ( $seq = $lost - 19; $seq <= $lost; $seq++ ) {
	unset( MHS_Centre::$events[ $seq ] );
}
MHS_Centre::$received = $lost - 20;

$gap  = mhs_subscriber();
$push = Mailhouse_Central_Sync::push();
$last = end( MHS_Centre::$events );
check( 'a centre that has lost events is told the next change under the number it expects', 1 === $push['sent'] && $lost - 19 === $last['seq'] && (int) $gap->id === $last['subject']['local_id'] && 2 === MHS_Centre::pushes() );
check( 'and the site knows the two copies need comparing', 'sequence_gap' === Mailhouse_Central_Identity::get( 'needs_reconcile' ) && true === Mailhouse_Central_Status::report()['outbox']['needs_reconcile'] );

// The site's table starts again from a lower number: restored from a backup.
Mailhouse_Central_Identity::update(
	array(
		'needs_reconcile' => '',
		'seq_offset'      => (int) Mailhouse_Central_Identity::get( 'seq_offset' ) - 10,
	)
);
MHS_Centre::calm();
$rewound = mhs_subscriber();
$push    = Mailhouse_Central_Sync::push();
$last    = end( MHS_Centre::$events );
check( 'a site whose numbers have gone backwards is refused, renumbers, and is accepted', 1 === $push['sent'] && (int) $rewound->id === $last['subject']['local_id'] && MHS_Centre::$received === $last['seq'] && 'sequence_regression' === Mailhouse_Central_Identity::get( 'needs_reconcile' ) );

// The centre has moved the website to a new epoch: it has found this site
// counting from an older number, as one restored from a backup does.
MHS_Centre::calm();
Mailhouse_Central_Identity::update( array( 'needs_reconcile' => '' ) );
$epoch = MHS_Centre::new_epoch();
$kept  = array( mhs_subscriber(), mhs_subscriber() );
$push  = Mailhouse_Central_Sync::push();
check( 'a centre on another epoch says which; what was waiting is dropped, not sent under it', 0 === $push['sent'] && $epoch === (int) Mailhouse_Central_Identity::get( 'epoch' ) && 0 === Mailhouse_Central_Outbox::waiting()['depth'] && array() === MHS_Centre::$events && 1 === MHS_Centre::pushes() );
check( 'the site asks for the two copies to be compared, which is how the centre learns of what was dropped', 'new_epoch' === Mailhouse_Central_Identity::get( 'needs_reconcile' ) );
check( 'no subscriber is touched by it', 'subscribed' === Mailhouse_Subscribers::get( (int) $kept[0]->id )->status && null !== Mailhouse_Subscribers::get( (int) $kept[1]->id ) );

$next = mhs_subscriber();
$push = Mailhouse_Central_Sync::push();
check( 'the next change is numbered one, under the new epoch', 1 === $push['sent'] && array( 1 ) === array_keys( MHS_Centre::$events ) && (int) $next->id === MHS_Centre::$events[1]['subject']['local_id'] );

$epoch = MHS_Centre::new_epoch();
mhs_subscriber();
Mailhouse_Central_Identity::update(
	array(
		'last_heartbeat_at' => 0,
		'next_attempt_at'   => 0,
	)
);
Mailhouse_Central::heartbeat();
check( 'a heartbeat learns of a new epoch too, and does the same', $epoch === (int) Mailhouse_Central_Identity::get( 'epoch' ) && 0 === Mailhouse_Central_Outbox::waiting()['depth'] );

mhs_subscriber();
Mailhouse_Central_Sync::push();
check( 'and the count starts from one again', array( 1 ) === array_keys( MHS_Centre::$events ) );

// An event id the centre already holds under another number.
MHS_Centre::calm();
$again           = mhs_subscriber();
$taken           = end( MHS_Centre::$events );
$wpdb->update( $table, array( 'event_id' => $taken['event_id'] ), array( 'id' => mhs_mark() ) ); // phpcs:ignore WordPress.DB
$push = Mailhouse_Central_Sync::push();
$last = end( MHS_Centre::$events );
check( 'an event whose id is taken is given another and sent', 1 === $push['sent'] && (int) $again->id === $last['subject']['local_id'] && $taken['event_id'] !== $last['event_id'] );

// A centre that keeps refusing.
MHS_Centre::calm();
MHS_Centre::$mode = 'gap';
mhs_subscriber();
$push = Mailhouse_Central_Sync::push();
check( 'a repair that does not work is tried once, not for ever', 0 === $push['sent'] && 2 === MHS_Centre::pushes() && (int) Mailhouse_Central_Identity::get( 'push_next_at' ) > time() );
MHS_Centre::calm();
mhs_due();
Mailhouse_Central_Sync::push();
mhs_due();
Mailhouse_Central_Sync::push();
check( 'and it is put right at the next attempt', 0 === Mailhouse_Central_Outbox::waiting()['depth'] );

// ---------------------------------------------------------------------------
echo "\nAn event the centre will not accept\n";

MHS_Centre::calm();
Mailhouse_Central_Identity::update( array( 'needs_reconcile' => '' ) );

$good = array();

for ( $i = 0; $i < 9; $i++ ) {
	$subscriber = mhs_subscriber( 'subscribed', array( 'first_name' => 4 === $i ? 'Poison' : 'Fine' ) );
	$good[]     = (int) $subscriber->id;
}
$poison = $good[4];

MHS_Centre::$reject = static function ( $event ) {
	return isset( $event['state'] ) && 'Poison' === $event['state']['first_name'];
};

$before = MHS_Centre::$received;
$push   = Mailhouse_Central_Sync::push( 60 );
$types  = array();
$ids    = array();

foreach ( MHS_Centre::$events as $seq => $event ) {
	if ( $seq > $before ) {
		$types[] = $event['type'];
		$ids[]   = $event['subject']['local_id'];
	}
}

check( 'it does not hold up the eight around it', 9 === $push['sent'] && 0 === Mailhouse_Central_Outbox::waiting()['depth'] && 8 === count( array_keys( $types, 'subscriber.created', true ) ) );
check( 'its place in the sequence is kept by a note that there was one', 'site.event_unsendable' === $types[4] && 0 === $ids[4] && range( $before + 1, $before + 9 ) === array_slice( array_keys( MHS_Centre::$events ), -9 ) );

$note = MHS_Centre::$events[ $before + 5 ];
check( 'the note says what kind of event it was and whose, and nothing about them', array( 'type' => 'subscriber.created', 'subject_type' => 'subscriber', 'local_id' => $poison, 'status' => 422 ) === $note['data'] && ! isset( $note['state'] ) );
check( 'and the site knows that subscriber’s copy needs comparing', 'event_unsendable' === Mailhouse_Central_Identity::get( 'needs_reconcile' ) );
check( 'the subscriber is untouched', 'Poison' === Mailhouse_Subscribers::get( $poison )->first_name );

MHS_Centre::calm();
MHS_Centre::$max_batch = 2;

for ( $i = 0; $i < 7; $i++ ) {
	mhs_subscriber();
}
$push = Mailhouse_Central_Sync::push( 60 );
check( 'a batch the centre finds too large is sent in smaller ones', 7 === $push['sent'] && 7 === MHS_Centre::$batches[0] && count( MHS_Centre::$batches ) > 3 && 0 === Mailhouse_Central_Outbox::waiting()['depth'], implode( ' ', MHS_Centre::$batches ) );

// ---------------------------------------------------------------------------
echo "\nRefused by the centre\n";

MHS_Centre::calm();
MHS_Centre::$mode = '401';
mhs_subscriber();
$push = Mailhouse_Central_Sync::push();
check( 'a signature the centre does not accept: nothing is removed, and it says why', 0 === $push['sent'] && 1 === Mailhouse_Central_Outbox::waiting()['depth'] && false !== strpos( $push['error'], 'signature' ) );

MHS_Centre::calm();
MHS_Centre::$mode = '429';
mhs_due();
$push = Mailhouse_Central_Sync::push();
check( 'told to slow down, it does', 0 === $push['sent'] && (int) Mailhouse_Central_Identity::get( 'push_next_at' ) > time() );

MHS_Centre::calm();
MHS_Centre::$mode = '500';
mhs_due();
$push = Mailhouse_Central_Sync::push();
check( 'a centre that is failing: the rows wait', 0 === $push['sent'] && 1 === Mailhouse_Central_Outbox::waiting()['depth'] );

MHS_Centre::calm();
MHS_Centre::$mode = 'key_revoked';
mhs_due();
$push = Mailhouse_Central_Sync::push();
check( 'suspended: the site notes it, and stops sending', 0 === $push['sent'] && 'suspended' === Mailhouse_Central_Identity::status() && 'key_revoked' === Mailhouse_Central_Identity::get( 'suspended_reason' ) );

MHS_Centre::pushes();
mhs_subscriber();
mhs_due();
Mailhouse_Central_Sync::push();
check( 'while suspended it still writes changes down, and sends none', 2 === Mailhouse_Central_Outbox::waiting()['depth'] && 0 === MHS_Centre::pushes() );

MHS_Centre::calm();
Mailhouse_Central_Identity::update(
	array(
		'last_heartbeat_at' => 0,
		'next_attempt_at'   => 0,
	)
);
Mailhouse_Central::heartbeat();
mhs_due();
$push = Mailhouse_Central_Sync::push();
check( 'let back in by a heartbeat, it sends what waited', 'connected' === Mailhouse_Central_Identity::status() && 2 === $push['sent'] );

// ---------------------------------------------------------------------------
echo "\nA copy of the site\n";

$real_home = 'https://shop.example.test';
add_filter(
	'home_url',
	$staging = static function () {
		return 'https://staging.example.test';
	},
	20
);
MHS_Centre::calm();
Mailhouse_Central_Outbox::record( 'tag.saved', 'tag', 1, array( 'local_id' => 1, 'name' => 'MHS Copy', 'slug' => 'mhs-copy' ) );
$push = Mailhouse_Central_Sync::push();
check( 'at another address, holding the original’s key, it sends nothing', 0 === $push['sent'] && 0 === MHS_Centre::pushes() );
check( 'and from then on writes nothing down either', 'clone' === Mailhouse_Central_Identity::status() && false === Mailhouse_Central_Outbox::capturing() );
remove_filter( 'home_url', $staging, 20 );
Mailhouse_Central_Identity::update( array( 'status' => 'connected' ) );
mhs_due();
Mailhouse_Central_Sync::push();

// ---------------------------------------------------------------------------
echo "\nCut off for a month\n";

MHS_Centre::calm();
Mailhouse_Central_Identity::update( array( 'needs_reconcile' => '' ) );

$kept = array( mhs_subscriber(), mhs_subscriber(), mhs_subscriber() );
$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET occurred_at = %s WHERE id > %d ORDER BY id ASC LIMIT 1", gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ), (int) Mailhouse_Central_Identity::get( 'outbox_acked' ) ) ); // phpcs:ignore WordPress.DB

$before = count( MHS_Centre::$events );
$push   = Mailhouse_Central_Sync::push();
check( 'changes older than thirty days are not replayed: the outbox is emptied', 0 === $push['sent'] && $before === count( MHS_Centre::$events ) && 0 === Mailhouse_Central_Outbox::waiting()['depth'] && 1 === mhs_rows() );
check( 'the site asks for the two copies to be compared instead', 'outbox_overflow' === Mailhouse_Central_Identity::get( 'needs_reconcile' ) );
check( 'and not one subscriber is touched by it', 'subscribed' === Mailhouse_Subscribers::get( (int) $kept[0]->id )->status && null !== Mailhouse_Subscribers::get( (int) $kept[2]->id ) );

$after = mhs_subscriber();
$push  = Mailhouse_Central_Sync::push();
$last  = end( MHS_Centre::$events );
check( 'what changes next is sent, numbered as the centre expects', 1 === $push['sent'] && (int) $after->id === $last['subject']['local_id'] && MHS_Centre::$received === $last['seq'] );

check( 'a hundred thousand rows is the other bound', 100000 === Mailhouse_Central_Outbox::MAX_ROWS && 30 * DAY_IN_SECONDS === Mailhouse_Central_Outbox::MAX_AGE );

// ---------------------------------------------------------------------------
echo "\nThe scheduled run\n";

new Mailhouse_Central();
check( 'the heartbeat runs before this site’s mail, and the push after it', 5 === has_action( 'mailhouse_process_queue', array( 'Mailhouse_Central', 'tick' ) ) && 20 === has_action( 'mailhouse_process_queue', array( 'Mailhouse_Central', 'push' ) ) );

MHS_Centre::calm();
// The first copy of the list and the comparison have a suite of their own.
Mailhouse_Central_Identity::update(
	array(
		'backfill'        => array(),
		'needs_reconcile' => '',
		'want_reconcile'  => false,
	)
);
mhs_subscriber();
mhs_due();
Mailhouse_Central::push();
check( 'the scheduled push sends what is waiting', 1 === MHS_Centre::pushes() && 0 === Mailhouse_Central_Outbox::waiting()['depth'] );

// ---------------------------------------------------------------------------
echo "\nDisconnecting, and connecting again\n";

MHS_Centre::calm();
mhs_subscriber();
mhs_subscriber();
$highest  = mhs_mark();
$received = MHS_Centre::$received;

Mailhouse_Central_Identity::disconnect();
check( 'disconnecting drops what was waiting, and keeps the table counting', 1 === mhs_rows() && $highest === mhs_mark() );
check( 'and forgets the connection', '' === Mailhouse_Central_Identity::status() && false === get_option( 'mailhouse_central' ) );

$mark = mhs_mark();
mhs_subscriber();
check( 'a disconnected site writes nothing down', '' === mhs_types( $mark ) );

MHS_Centre::$epoch = 1;
$connected         = Mailhouse_Central_Identity::register( MHS_CENTRAL, MHS_Centre::issue_token() );
$back              = mhs_subscriber();
$push              = Mailhouse_Central_Sync::push();
$last              = end( MHS_Centre::$events );
check( 'connected again, it carries on from the number the centre had reached', true === $connected && 1 === $push['sent'] && $received + 1 === $last['seq'] && (int) $back->id === $last['subject']['local_id'] );

check( 'uninstalling removes the outbox table with the others', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-mailhouse-install.php' ), "array( 'inbox', 'outbox', 'log', 'queue', 'subscriber_tags', 'tags', 'subscribers' )" ) ); // phpcs:ignore

Mailhouse_Central_Identity::disconnect();

// ---------------------------------------------------------------------------
mailhouse_test_results();
