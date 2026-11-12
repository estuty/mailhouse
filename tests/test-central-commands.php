<?php
/**
 * Commands: what the centre asks this site to do, and what this site will
 * and will not do about it.
 *
 * No centre is needed. The stand-in below holds a platform key, signs
 * commands the way the real centre does, hands them out in order and
 * keeps the answers. It can also misbehave: hand out a command twice, one
 * signed by somebody else, one made for another website, one out of order,
 * one that has expired.
 *
 * The last part is a property test. Thousands of commands, of every kind
 * and with every sort of payload, are mixed with things that happen on the
 * site itself, and after each command one thing is checked: nobody who
 * had unsubscribed or bounced is back on the list.
 *
 * Usage: php tests/test-central-commands.php
 *        MAILHOUSE_PROPERTY_SEED=1234 php tests/test-central-commands.php
 *
 * @package Mailhouse
 */

require __DIR__ . '/bootstrap.php';

$saved_settings = get_option( 'mailhouse_settings' );
$saved_central  = get_option( 'mailhouse_central', null );

const MHK_CENTRAL = 'https://central.example.test';
const MHK_WEBSITE = '0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
const MHK_OTHER   = '0193a1b2-c3d4-7e5f-8a9b-ffffffffffff';
const MHK_DOMAIN  = 'mhk.example.test';

/**
 * Remove everything this file added.
 */
function mhk_clean() {
	global $wpdb;

	$subscribers = Mailhouse_Install::table( 'subscribers' );
	$pivot       = Mailhouse_Install::table( 'subscriber_tags' );
	$tags        = Mailhouse_Install::table( 'tags' );

	// phpcs:disable WordPress.DB
	$wpdb->query( $wpdb->prepare( "DELETE p FROM {$pivot} p JOIN {$subscribers} s ON s.id = p.subscriber_id WHERE s.email LIKE %s", '%@' . MHK_DOMAIN ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$subscribers} WHERE email LIKE %s", '%@' . MHK_DOMAIN ) );
	$wpdb->query( "DELETE p FROM {$pivot} p JOIN {$tags} t ON t.id = p.tag_id WHERE t.name LIKE 'MHK %'" );
	$wpdb->query( "DELETE FROM {$tags} WHERE name LIKE 'MHK %'" );
	$wpdb->query( 'DELETE FROM ' . Mailhouse_Install::table( 'outbox' ) );
	$wpdb->query( 'DELETE FROM ' . Mailhouse_Install::table( 'inbox' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_mailhouse_confirm_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_mailhouse_confirm_' ) . '%' ) );
	// phpcs:enable

	delete_transient( 'mailhouse_central_inbox_pruned' );
}

register_shutdown_function(
	static function () use ( $saved_settings, $saved_central ) {
		mhk_clean();
		update_option( 'mailhouse_settings', $saved_settings );

		if ( null === $saved_central ) {
			delete_option( 'mailhouse_central' );
		} else {
			update_option( 'mailhouse_central', $saved_central );
		}
	}
);

/** Every message this site tried to send: who to. */
$GLOBALS['mhk_mail'] = array();

add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$GLOBALS['mhk_mail'][] = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];

		return true;
	},
	10,
	2
);

/**
 * The stand-in centre.
 */
final class MHK_Centre {

	/** @var string The platform key pair. */
	public static $keypair = '';

	/** @var string A key pair that is not the platform's. */
	public static $stranger = '';

	/** @var array[] Every command issued, by id: the envelope, and the answer once there is one. */
	public static $commands = array();

	/** @var int The last sequence number given out. */
	public static $seq = 0;

	/** @var array[]|null Hand out exactly these on the next collection, whatever is waiting. */
	public static $serve = null;

	/** @var array[] Every answer received, in order: id, seq, status, result. */
	public static $answers = array();

	/** @var array[] Every event received. */
	public static $events = array();

	/** @var int */
	public static $received = 0;

	/** @var string ok, down, paused, or `ack_down`. */
	public static $mode = 'ok';

	/** @var array[] Every request seen: method, path, query. */
	public static $requests = array();

	/** @var string */
	public static $site_key = '';

	/** @var string */
	public static $token = '';

	/** @var bool Whether every signed request verified, query string included. */
	public static $all_signed = true;

	/**
	 * A centre that has never heard from this website.
	 */
	public static function reset() {
		self::$keypair    = sodium_crypto_sign_keypair();
		self::$stranger   = sodium_crypto_sign_keypair();
		self::$commands   = array();
		self::$seq        = 0;
		self::$serve      = null;
		self::$answers    = array();
		self::$events     = array();
		self::$received   = 0;
		self::$mode       = 'ok';
		self::$requests   = array();
		self::$all_signed = true;
	}

	/**
	 * A fresh enrolment token.
	 */
	public static function issue_token() {
		self::$token = 'mhe_' . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore

		return self::$token;
	}

	/**
	 * Make a command: numbered, signed, and waiting.
	 *
	 * @param string $type    Command type.
	 * @param array  $payload Payload.
	 * @param array  $how     Ways to get it wrong: seq, website, expires, key, unsigned, queue => false.
	 * @return array The envelope.
	 */
	public static function issue( $type, array $payload, array $how = array() ) {
		$seq = isset( $how['seq'] ) ? (int) $how['seq'] : ++self::$seq;
		// An object when empty, as the real centre writes it.
		$json     = $payload ? wp_json_encode( $payload ) : '{}';
		$envelope = array(
			'id'           => wp_generate_uuid4(),
			'website_id'   => isset( $how['website'] ) ? $how['website'] : MHK_WEBSITE,
			'seq'          => $seq,
			'type'         => $type,
			'issued_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'expires_at'   => gmdate( 'Y-m-d\TH:i:s\Z', isset( $how['expires'] ) ? (int) $how['expires'] : time() + 30 * DAY_IN_SECONDS ),
			'payload_json' => isset( $how['json'] ) ? (string) $how['json'] : $json,
			'key_id'       => 'p-test',
		);

		$secret                = sodium_crypto_sign_secretkey( isset( $how['key'] ) ? $how['key'] : self::$keypair );
		$envelope['signature'] = empty( $how['unsigned'] )
			? base64_encode( sodium_crypto_sign_detached( Mailhouse_Central_Protocol::command_message( $envelope ), $secret ) ) // phpcs:ignore
			: base64_encode( str_repeat( "\0", 64 ) ); // phpcs:ignore

		if ( ! isset( $how['queue'] ) || $how['queue'] ) {
			self::$commands[ $envelope['id'] ] = array(
				'envelope' => $envelope,
				'answer'   => null,
			);
		}

		return $envelope;
	}

	/**
	 * How many commands have not been answered.
	 */
	public static function waiting() {
		$waiting = 0;

		foreach ( self::$commands as $command ) {
			if ( null === $command['answer'] ) {
				++$waiting;
			}
		}

		return $waiting;
	}

	/**
	 * The answer given for a command, or null.
	 */
	public static function answer_for( $envelope ) {
		return isset( self::$commands[ $envelope['id'] ] ) ? self::$commands[ $envelope['id'] ]['answer'] : null;
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
	 * what was sent, its query string included.
	 */
	protected static function signed( $method, $path, $query, $headers, $body ) {
		foreach ( array( 'X-MH-Website', 'X-MH-Key', 'X-MH-Timestamp', 'X-MH-Nonce', 'X-MH-Signature' ) as $name ) {
			if ( empty( $headers[ $name ] ) ) {
				return false;
			}
		}

		$pairs = array();

		foreach ( '' === $query ? array() : explode( '&', $query ) as $pair ) {
			$parts   = explode( '=', $pair, 2 );
			$pairs[] = rawurlencode( rawurldecode( $parts[0] ) ) . '=' . rawurlencode( rawurldecode( isset( $parts[1] ) ? $parts[1] : '' ) );
		}

		sort( $pairs, SORT_STRING );

		$message = implode(
			"\n",
			array( 'MH1', $method, $path, implode( '&', $pairs ), $headers['X-MH-Timestamp'], $headers['X-MH-Nonce'], $headers['X-MH-Website'], hash( 'sha256', $body ) )
		);

		return MHK_WEBSITE === $headers['X-MH-Website']
			&& sodium_crypto_sign_verify_detached( base64_decode( $headers['X-MH-Signature'], true ), $message, base64_decode( self::$site_key, true ) ); // phpcs:ignore
	}

	/**
	 * Catch a request and answer it.
	 */
	public static function handle( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://central.' ) ) {
			return $pre;
		}

		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$body  = isset( $args['body'] ) ? (string) $args['body'] : '';
		$json  = json_decode( $body, true );

		parse_str( $query, $asked );

		self::$requests[] = array(
			'method' => $args['method'],
			'path'   => $path,
			'query'  => $asked,
			'json'   => $json,
		);

		if ( 'down' === self::$mode ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		}

		if ( '/api/v1/connector/register' === $path ) {
			if ( $json['enrolment_token'] !== self::$token ) {
				return self::answer( 410, array( 'code' => 'gone' ) );
			}

			self::$site_key = $json['public_key'];
			self::$token    = '';

			return self::answer(
				201,
				array(
					'website_id'            => MHK_WEBSITE,
					'platform_keys'         => array(
						array(
							'key_id'     => 'p-test',
							'public_key' => base64_encode( sodium_crypto_sign_publickey( self::$keypair ) ), // phpcs:ignore
							'status'     => 'active',
						),
					),
					'protocol'              => 1,
					'endpoints'             => array( 'base' => MHK_CENTRAL . '/api/v1/connector' ),
					'poll_interval_seconds' => 60,
					'sync'                  => array(
						'epoch'             => 1,
						'last_seq_received' => self::$received,
					),
				)
			);
		}

		if ( ! self::signed( $args['method'], $path, $query, $args['headers'], $body ) ) {
			self::$all_signed = false;

			return self::answer( 401, array( 'code' => 'unauthenticated' ) );
		}

		if ( '/api/v1/connector/sync/events' === $path ) {
			foreach ( $json['events'] as $event ) {
				if ( $event['seq'] > self::$received ) {
					self::$events[] = $event;
					self::$received = $event['seq'];
				}
			}

			return self::answer( 200, array( 'accepted_through_seq' => self::$received, 'duplicates' => 0 ) );
		}

		if ( '/api/v1/connector/sync/reconcile' === $path ) {
			return self::answer( 200, array( 'send_state_for' => array(), 'confirm_absent' => array(), 'forced' => false ) );
		}

		if ( '/api/v1/connector/disconnect' === $path ) {
			return self::answer( 200, array( 'disconnected' => true ) );
		}

		if ( '/api/v1/connector/commands' === $path ) {
			if ( 'paused' === self::$mode ) {
				return self::answer( 200, array( 'commands' => array(), 'more' => false ) );
			}

			if ( null !== self::$serve ) {
				$serve       = self::$serve;
				self::$serve = null;

				return self::answer( 200, array( 'commands' => $serve, 'more' => false ) );
			}

			$after = isset( $asked['after_seq'] ) ? (int) $asked['after_seq'] : 0;
			$limit = isset( $asked['limit'] ) ? (int) $asked['limit'] : 20;
			$due   = array();

			foreach ( self::$commands as $command ) {
				if ( null === $command['answer'] && $command['envelope']['seq'] > $after ) {
					$due[] = $command['envelope'];
				}
			}

			usort(
				$due,
				static function ( $a, $b ) {
					return $a['seq'] - $b['seq'];
				}
			);

			return self::answer(
				200,
				array(
					'commands' => array_slice( $due, 0, $limit ),
					'more'     => count( $due ) > $limit,
				)
			);
		}

		if ( '/api/v1/connector/commands/ack' === $path ) {
			if ( 'ack_down' === self::$mode ) {
				return self::answer( 500, array( 'code' => 'internal' ) );
			}

			$through = 0;

			foreach ( $json['results'] as $result ) {
				self::$answers[] = $result;
				$through         = max( $through, (int) $result['seq'] );

				// The first answer stands.
				if ( isset( self::$commands[ $result['id'] ] ) && null === self::$commands[ $result['id'] ]['answer'] ) {
					self::$commands[ $result['id'] ]['answer'] = $result;
				}
			}

			return self::answer( 200, array( 'acked_through_seq' => $through ) );
		}

		// A heartbeat.
		return self::answer(
			200,
			array(
				'status'           => 'paused' === self::$mode ? 'paused' : 'connected',
				'commands_pending' => 'paused' === self::$mode ? 0 : self::waiting() + ( null === self::$serve ? 0 : count( self::$serve ) ),
				'ack_through_seq'  => self::$received,
				'want'             => array(),
				'epoch'            => 1,
			)
		);
	}

	/**
	 * How many requests were made to a path since the last call.
	 */
	public static function took( $path ) {
		$found = 0;

		foreach ( self::$requests as $request ) {
			if ( $request['path'] === $path ) {
				++$found;
			}
		}

		self::$requests = array();

		return $found;
	}
}

add_filter( 'pre_http_request', array( 'MHK_Centre', 'handle' ), 10, 3 );

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
function mhk_forget() {
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
 * Connect to the stand-in, with the first copy of the list already made.
 */
function mhk_connect() {
	mhk_forget();

	$result = Mailhouse_Central_Identity::register( MHK_CENTRAL, MHK_Centre::issue_token() );

	Mailhouse_Central_Identity::update(
		array(
			'backfill' => array(
				'run_id'       => wp_generate_uuid4(),
				'phase'        => 'done',
				'cursor'       => 0,
				'completed_at' => time(),
			),
		)
	);
	Mailhouse_Central_Outbox::listen();
	MHK_Centre::$requests = array();

	return $result;
}

/**
 * Report in, and deal with whatever the centre is holding: what one
 * scheduled run does before the mail queue.
 *
 * @return array What became of the commands.
 */
function mhk_run() {
	Mailhouse_Central_Identity::update(
		array(
			'last_heartbeat_at' => 0,
			'next_attempt_at'   => 0,
		)
	);
	Mailhouse_Central::heartbeat();

	return Mailhouse_Central_Commands::work();
}

/**
 * Issue one command, let the site collect it, and return the site's answer.
 */
function mhk_do( $type, array $payload, array $how = array() ) {
	$envelope = MHK_Centre::issue( $type, $payload, $how );

	mhk_run();

	return array( $envelope, MHK_Centre::answer_for( $envelope ) );
}

/**
 * A subscriber made here, by the site itself, in a given status.
 */
function mhk_person( $name, $status = 'subscribed', array $more = array() ) {
	$added = Mailhouse_Subscribers::add(
		array_merge(
			array(
				'email'       => "$name@" . MHK_DOMAIN,
				'first_name'  => ucfirst( $name ),
				'last_name'   => 'Reader',
				'source'      => 'form',
				'status'      => in_array( $status, array( 'subscribed', 'pending' ), true ) ? $status : 'subscribed',
				'send_emails' => false,
			),
			$more
		)
	);

	$row = Mailhouse_Subscribers::get( (int) $added['id'] );

	if ( 'unsubscribed' === $status ) {
		Mailhouse_Subscribers::unsubscribe( $row, 'link' );
	} elseif ( 'bounced' === $status ) {
		Mailhouse_Subscribers::mark_bounced( $row, 'mailbox does not exist' );
	}

	return (int) $added['id'];
}

/**
 * A subscriber's row, fresh from the table.
 */
function mhk_row( $id ) {
	return Mailhouse_Subscribers::get( (int) $id );
}

/**
 * The outbox rows a command brought about.
 */
function mhk_events_of( $envelope ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT type, subject_id, payload FROM ' . Mailhouse_Install::table( 'outbox' ) . ' WHERE origin = %s ORDER BY id', 'command:' . $envelope['id'] ) );
}

/**
 * The inbox row for a command, or null.
 */
function mhk_inbox( $envelope ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Mailhouse_Install::table( 'inbox' ) . ' WHERE command_id = %s', $envelope['id'] ) );
}

/**
 * Whether an answer is what was expected.
 */
function mhk_is( $answer, $status, array $result = array() ) {
	if ( ! is_array( $answer ) || $answer['status'] !== $status ) {
		return false;
	}

	foreach ( $result as $key => $value ) {
		if ( ! array_key_exists( $key, (array) $answer['result'] ) || $answer['result'][ $key ] !== $value ) {
			return false;
		}
	}

	return true;
}

mhk_clean();
mhk_forget();
MHK_Centre::reset();

Mailhouse_Install::install( true );

// ---------------------------------------------------------------------------
echo "\nThe record of what was asked\n";

global $wpdb;

$inbox   = Mailhouse_Install::table( 'inbox' );
$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$inbox}" ); // phpcs:ignore WordPress.DB
check( 'the inbox table exists, and holds no column a subscriber could be in', array( 'id', 'command_id', 'seq', 'type', 'status', 'result', 'received_at', 'acked' ) === $columns, implode( ',', $columns ) );
check( 'a command id can be written down only once', (bool) $wpdb->get_var( "SHOW INDEX FROM {$inbox} WHERE Key_name = 'command_id' AND Non_unique = 0" ) ); // phpcs:ignore WordPress.DB
check( 'the schema version is 1.4.0', '1.4.0' === Mailhouse_Install::DB_VERSION );

$handler = (string) file_get_contents( dirname( __DIR__ ) . '/includes/central/class-mailhouse-central-commands.php' ); // phpcs:ignore
check( 'nothing a command says chooses what is called: each kind is spelled out with its one function', false === strpos( $handler, 'call_user_func' ) && false === strpos( $handler, 'eval(' ) && 0 === preg_match( '/\$[a-z_]+\s*\(/', $handler ) && count( Mailhouse_Central_Commands::TYPES ) === preg_match_all( "/^\t\t\tcase '[a-z_.]+':$/m", $handler ) );
check( 'and none of those kinds is about subscribing', array() === preg_grep( '/subscribe$|resubscribe|confirm$|status/', array_diff( Mailhouse_Central_Commands::TYPES, array( 'subscriber.unsubscribe', 'subscriber.reconfirm' ) ) ) && in_array( 'subscriber.unsubscribe', Mailhouse_Central_Commands::TYPES, true ) );

check( 'a site that is not connected does nothing about commands', array( 'applied' => 0, 'rejected' => 0, 'failed' => 0, 'error' => '' ) === Mailhouse_Central_Commands::work() && 0 === count( MHK_Centre::$requests ) );

check( 'connecting', true === mhk_connect(), wp_json_encode( Mailhouse_Central_Identity::get( 'last_error' ) ) );

$report = Mailhouse_Central_Status::report();
check( 'a connected site announces that it takes commands', in_array( 'subscribers.commands.v1', $report['capabilities'], true ) );
check( 'and says how far through the centre’s sequence it has got', array( 'last_applied_seq' => 0 ) === $report['inbox'] );

add_filter( 'mailhouse_central_accept_commands', '__return_false' );
check( 'a site that refuses commands does not announce it', ! in_array( 'subscribers.commands.v1', Mailhouse_Central_Status::capabilities(), true ) );
MHK_Centre::issue( 'tag.create', array( 'name' => 'MHK Refused' ) );
$refused = mhk_run();
check( 'and does not collect any, whatever the centre says is waiting', 0 === MHK_Centre::took( '/api/v1/connector/commands' ) && 0 === $refused['applied'] && ! Mailhouse_Tags::get_by_slug( 'mhk-refused' ) );
remove_filter( 'mailhouse_central_accept_commands', '__return_false' );
MHK_Centre::$commands = array();
MHK_Centre::$seq      = 0;

// ---------------------------------------------------------------------------
echo "\nEach kind of command, there and back\n";

// tag.create
list( $made, $answer ) = mhk_do( 'tag.create', array( 'name' => 'MHK Customers' ) );
$customers             = Mailhouse_Tags::get_by_slug( 'mhk-customers' );
check( 'tag.create makes the tag', $customers && mhk_is( $answer, 'applied', array( 'local_id' => (int) $customers->id, 'events' => 1 ) ), wp_json_encode( $answer ) );
$events = mhk_events_of( $made );
check( 'and the event that tells the centre so is marked as that command’s doing', 1 === count( $events ) && 'tag.saved' === $events[0]->type );
check( 'the request for it was signed, query string and all', MHK_Centre::$all_signed );

list( , $answer ) = mhk_do( 'tag.create', array( 'name' => 'MHK Customers' ) );
check( 'asking for a tag that is already there changes nothing and says so', mhk_is( $answer, 'applied', array( 'local_id' => (int) $customers->id, 'events' => 0, 'noop' => true ) ), wp_json_encode( $answer ) );

list( , $answer ) = mhk_do( 'tag.create', array( 'name' => 'MHK Press' ) );
$press            = Mailhouse_Tags::get_by_slug( 'mhk-press' );

// tag.rename
list( $renamed, $answer ) = mhk_do( 'tag.rename', array( 'local_id' => (int) $press->id, 'name' => 'MHK Journalists' ) );
check( 'tag.rename renames it', 'MHK Journalists' === Mailhouse_Tags::get( (int) $press->id )->name && mhk_is( $answer, 'applied', array( 'events' => 1 ) ) && 1 === count( mhk_events_of( $renamed ) ) );
list( , $answer ) = mhk_do( 'tag.rename', array( 'local_id' => (int) $press->id, 'name' => 'MHK Customers' ) );
check( 'but not to a name another tag has', 'MHK Journalists' === Mailhouse_Tags::get( (int) $press->id )->name && mhk_is( $answer, 'rejected', array( 'reason' => 'conflict', 'local_id' => (int) $customers->id ) ), wp_json_encode( $answer ) );
list( , $answer ) = mhk_do( 'tag.rename', array( 'local_id' => 999999999, 'name' => 'MHK Nobody' ) );
check( 'nor a tag that is not there', mhk_is( $answer, 'rejected', array( 'reason' => 'not_found' ) ) );

// subscriber.create
$GLOBALS['mhk_mail']      = array();
list( $created, $answer ) = mhk_do(
	'subscriber.create',
	array(
		'email'      => 'New.Reader@' . MHK_DOMAIN,
		'first_name' => 'New',
		'last_name'  => 'Reader',
		'fields'     => array( 'city' => 'York' ),
		'tags'       => array( (int) $customers->id, 999999999 ),
		'mode'       => 'opt_in',
	)
);
$new                      = Mailhouse_Subscribers::get_by_email( 'new.reader@' . MHK_DOMAIN );
check( 'subscriber.create (opt_in) adds them as pending, whatever this site’s own setting', $new && 'pending' === $new->status && 'central' === $new->source && 'New' === $new->first_name, $new ? $new->status : 'no row' );
check( 'with the fields and the tags that exist here', $new && array( 'city' => 'York' ) === Mailhouse_Subscribers::fields( $new ) && array( (int) $customers->id ) === array_values( array_intersect( Mailhouse_Tags::ids_for( (int) $new->id ), array( (int) $customers->id, (int) $press->id ) ) ) );
check( 'and this site sends them its own confirmation message', array( 'new.reader@' . MHK_DOMAIN ) === $GLOBALS['mhk_mail'], implode( ',', $GLOBALS['mhk_mail'] ) );
check( 'the answer says who they are here', $new && mhk_is( $answer, 'applied', array( 'local_id' => (int) $new->id, 'status' => 'pending' ) ) && $answer['result']['events'] >= 1, wp_json_encode( $answer ) );
$events = mhk_events_of( $created );
check( 'and the centre is told of a new subscriber, by that command', in_array( 'subscriber.created', wp_list_pluck( $events, 'type' ), true ) && array( (int) $new->id ) === array_values( array_unique( array_map( 'intval', wp_list_pluck( $events, 'subject_id' ) ) ) ), wp_json_encode( wp_list_pluck( $events, 'type' ) ) );

$GLOBALS['mhk_mail'] = array();
list( , $answer )    = mhk_do( 'subscriber.create', array( 'email' => 'imported@' . MHK_DOMAIN, 'mode' => 'import', 'consent_basis' => 'Paper form, March' ) );
$imported            = Mailhouse_Subscribers::get_by_email( 'imported@' . MHK_DOMAIN );
check( 'subscriber.create (import) adds them as subscribed, and sends nothing', $imported && 'subscribed' === $imported->status && array() === $GLOBALS['mhk_mail'] && mhk_is( $answer, 'applied', array( 'status' => 'subscribed' ) ) );

list( , $answer ) = mhk_do( 'subscriber.create', array( 'email' => 'imported@' . MHK_DOMAIN, 'first_name' => 'Changed', 'mode' => 'import' ) );
check( 'somebody already here is left exactly as they are', '' === mhk_row( $imported->id )->first_name && mhk_is( $answer, 'applied', array( 'noop' => true, 'reason' => 'exists', 'local_id' => (int) $imported->id, 'events' => 0 ) ), wp_json_encode( $answer ) );

list( , $answer ) = mhk_do( 'subscriber.create', array( 'email' => 'not an address', 'mode' => 'opt_in' ) );
check( 'what is not an address is refused', mhk_is( $answer, 'rejected', array( 'reason' => 'invalid_email' ) ) );

// subscriber.update_profile
$jane                     = mhk_person( 'jane', 'subscribed', array( 'fields' => array( 'city' => 'Leeds' ) ) );
$revision                 = (int) mhk_row( $jane )->revision;
list( $updated, $answer ) = mhk_do( 'subscriber.update_profile', array( 'local_id' => $jane, 'first_name' => 'Joan', 'fields' => array( 'city' => 'York', 'role' => 'Editor' ), 'expected_revision' => $revision ) );
$row                      = mhk_row( $jane );
check( 'subscriber.update_profile changes the name and the fields', 'Joan' === $row->first_name && 'Reader' === $row->last_name && array( 'city' => 'York', 'role' => 'Editor' ) === Mailhouse_Subscribers::fields( $row ) );
check( 'the answer carries the new revision', mhk_is( $answer, 'applied', array( 'revision' => $revision + 1, 'events' => 1 ) ), wp_json_encode( $answer ) );
$events = mhk_events_of( $updated );
check( 'and one event tells the centre, by that command', 1 === count( $events ) && 'subscriber.profile_updated' === $events[0]->type && (int) $events[0]->subject_id === $jane, wp_json_encode( wp_list_pluck( $events, 'type' ) ) );

list( , $answer ) = mhk_do( 'subscriber.update_profile', array( 'local_id' => $jane, 'first_name' => 'Joan', 'expected_revision' => $revision + 1 ) );
check( 'a change to what is already so is carried out as nothing', mhk_is( $answer, 'applied', array( 'noop' => true, 'events' => 0, 'revision' => $revision + 1 ) ), wp_json_encode( $answer ) );

list( , $answer ) = mhk_do( 'subscriber.update_profile', array( 'local_id' => $jane, 'first_name' => str_repeat( 'x', 101 ), 'expected_revision' => $revision + 1 ) );
check( 'a name too long to store whole is refused, not cut short', 'Joan' === mhk_row( $jane )->first_name && mhk_is( $answer, 'rejected', array( 'reason' => 'too_long' ) ), wp_json_encode( $answer ) );

list( , $answer ) = mhk_do( 'subscriber.update_profile', array( 'local_id' => $jane, 'fields' => array(), 'expected_revision' => $revision + 1 ) );
check( 'the fields given are the whole set: an empty set clears them', array() === Mailhouse_Subscribers::fields( mhk_row( $jane ) ) && mhk_is( $answer, 'applied', array( 'revision' => $revision + 2 ) ) );

// subscriber.set_tags
list( $tagged, $answer ) = mhk_do( 'subscriber.set_tags', array( 'local_id' => $jane, 'add' => array( (int) $customers->id, (int) $press->id ), 'remove' => array() ) );
check( 'subscriber.set_tags attaches tags', array( (int) $customers->id, (int) $press->id ) === Mailhouse_Tags::ids_for( $jane ) && mhk_is( $answer, 'applied' ) && $answer['result']['events'] >= 1 && count( mhk_events_of( $tagged ) ) >= 1 );
list( , $answer ) = mhk_do( 'subscriber.set_tags', array( 'local_id' => $jane, 'add' => array(), 'remove' => array( (int) $press->id, 999999999 ) ) );
check( 'and detaches them, and only the ones named', array( (int) $customers->id ) === Mailhouse_Tags::ids_for( $jane ) && mhk_is( $answer, 'applied' ) );
list( , $answer ) = mhk_do( 'subscriber.set_tags', array( 'local_id' => $jane, 'add' => array( 999999999 ), 'remove' => array() ) );
check( 'a tag that is not here is refused, and nothing is attached', array( (int) $customers->id ) === Mailhouse_Tags::ids_for( $jane ) && mhk_is( $answer, 'rejected', array( 'reason' => 'unknown_tag', 'tag' => 999999999 ) ) );
list( , $answer ) = mhk_do( 'subscriber.set_tags', array( 'local_id' => $jane, 'add' => array(), 'remove' => array() ) );
check( 'taking away nothing in particular does not take away everything', array( (int) $customers->id ) === Mailhouse_Tags::ids_for( $jane ) );

// subscriber.reconfirm
$pending             = mhk_person( 'waiting', 'pending' );
$GLOBALS['mhk_mail'] = array();
list( , $answer )    = mhk_do( 'subscriber.reconfirm', array( 'local_id' => $pending ) );
check( 'subscriber.reconfirm sends this site’s confirmation message again', array( 'waiting@' . MHK_DOMAIN ) === $GLOBALS['mhk_mail'] && mhk_is( $answer, 'applied', array( 'sent' => true ) ) && 'pending' === mhk_row( $pending )->status, wp_json_encode( $answer ) );
list( , $answer ) = mhk_do( 'subscriber.reconfirm', array( 'local_id' => $pending ) );
check( 'but not twice in a few minutes, whoever asks', 1 === count( $GLOBALS['mhk_mail'] ) && mhk_is( $answer, 'rejected', array( 'reason' => 'throttled' ) ) );
list( , $answer ) = mhk_do( 'subscriber.reconfirm', array( 'local_id' => $jane ) );
check( 'and not to somebody who has already confirmed', mhk_is( $answer, 'rejected', array( 'reason' => 'not_pending', 'status' => 'subscribed' ) ) );

// subscriber.unsubscribe
list( $left, $answer ) = mhk_do( 'subscriber.unsubscribe', array( 'local_id' => $jane, 'source' => 'central', 'reason' => 'asked by phone' ) );
$row                   = mhk_row( $jane );
check( 'subscriber.unsubscribe takes them off the list, with the date', 'unsubscribed' === $row->status && ! empty( $row->unsubscribed_at ) && mhk_is( $answer, 'applied' ) );
$events = mhk_events_of( $left );
$data   = $events ? json_decode( $events[0]->payload, true ) : array();
check( 'and the event says the centre was the source', $events && 'subscriber.unsubscribed' === $events[0]->type && isset( $data['data']['source'] ) && 'central' === $data['data']['source'], $events ? $events[0]->payload : 'no event' );
list( , $answer ) = mhk_do( 'subscriber.unsubscribe', array( 'local_id' => $jane ) );
check( 'somebody already off the list: carried out as nothing', mhk_is( $answer, 'applied', array( 'noop' => true, 'events' => 0 ) ) );
list( , $answer ) = mhk_do( 'subscriber.unsubscribe', array( 'local_id' => 999999999 ) );
check( 'somebody who is not here: the centre is told so', mhk_is( $answer, 'rejected', array( 'reason' => 'not_found' ) ) );

// subscriber.mark_bounced
$bob                      = mhk_person( 'bob' );
list( $bounced, $answer ) = mhk_do( 'subscriber.mark_bounced', array( 'local_id' => $bob, 'reason' => 'Mailbox does not exist' ) );
check( 'subscriber.mark_bounced retires the address', 'bounced' === mhk_row( $bob )->status && mhk_is( $answer, 'applied' ) && 'subscriber.bounced' === mhk_events_of( $bounced )[0]->type );
list( , $answer ) = mhk_do( 'subscriber.mark_bounced', array( 'local_id' => $bob, 'reason' => 'again' ) );
check( 'once', mhk_is( $answer, 'applied', array( 'noop' => true ) ) );

// subscriber.delete
$carol                    = mhk_person( 'carol' );
list( $removed, $answer ) = mhk_do( 'subscriber.delete', array( 'local_id' => $carol, 'mode' => 'remove' ) );
$events                   = mhk_events_of( $removed );
$data                     = $events ? json_decode( $events[0]->payload, true ) : array();
check( 'subscriber.delete (remove) removes them', null === mhk_row( $carol ) && mhk_is( $answer, 'applied', array( 'events' => 1 ) ) );
check( 'and the event says it was a command, not a request to be forgotten', $events && 'subscriber.deleted' === $events[0]->type && 'command' === $data['data']['reason'] );
$dave                    = mhk_person( 'dave' );
list( $erased, $answer ) = mhk_do( 'subscriber.delete', array( 'local_id' => $dave, 'mode' => 'erase' ) );
$events                  = mhk_events_of( $erased );
$data                    = $events ? json_decode( $events[0]->payload, true ) : array();
check( 'subscriber.delete (erase) removes them, and the event says it is an erasure', null === mhk_row( $dave ) && $events && 'erasure' === $data['data']['reason'] && mhk_is( $answer, 'applied' ) );
list( , $answer ) = mhk_do( 'subscriber.delete', array( 'local_id' => $dave, 'mode' => 'erase' ) );
check( 'somebody already gone: carried out as nothing', mhk_is( $answer, 'applied', array( 'noop' => true ) ) );
list( , $answer ) = mhk_do( 'subscriber.delete', array( 'local_id' => $bob, 'mode' => 'everything' ) );
check( 'a way of deleting nobody defined is refused', mhk_row( $bob ) && mhk_is( $answer, 'rejected', array( 'reason' => 'malformed' ) ) );

// tag.delete
list( $dropped, $answer ) = mhk_do( 'tag.delete', array( 'local_id' => (int) $press->id ) );
check( 'tag.delete deletes the tag', null === Mailhouse_Tags::get( (int) $press->id ) && mhk_is( $answer, 'applied' ) && 'tag.deleted' === mhk_events_of( $dropped )[0]->type );
list( , $answer ) = mhk_do( 'tag.delete', array( 'local_id' => (int) $press->id ) );
check( 'a tag already gone: carried out as nothing', mhk_is( $answer, 'applied', array( 'noop' => true ) ) );

// site.reconcile
Mailhouse_Central_Identity::update( array( 'want_reconcile' => false ) );
list( , $answer ) = mhk_do( 'site.reconcile', array() );
check( 'site.reconcile has this site compare its list with the centre’s copy', true === Mailhouse_Central_Identity::get( 'want_reconcile' ) && mhk_is( $answer, 'applied', array( 'events' => 0 ) ) && ! isset( $answer['result']['noop'] ), wp_json_encode( $answer ) );
Mailhouse_Central_Identity::update( array( 'want_reconcile' => false ) );

check( 'every command so far was answered, in the centre’s order', 0 === MHK_Centre::waiting() && MHK_Centre::$seq === (int) Mailhouse_Central_Identity::get( 'inbox_seq' ) );
check( 'and the next report in says how far this site has got', MHK_Centre::$seq === Mailhouse_Central_Status::report()['inbox']['last_applied_seq'] );

// ---------------------------------------------------------------------------
echo "\nA command that arrives twice\n";

$erin                      = mhk_person( 'erin' );
$before                    = (int) mhk_row( $erin )->revision;
list( $envelope, $answer ) = mhk_do( 'subscriber.update_profile', array( 'local_id' => $erin, 'first_name' => 'Erin the First', 'expected_revision' => $before ) );
check( 'carried out the first time', 'Erin the First' === mhk_row( $erin )->first_name && $before + 1 === (int) mhk_row( $erin )->revision );

// The site changes her afterwards. A replay must not put the old name back.
Mailhouse_Subscribers::update( $erin, array( 'first_name' => 'Erin, later' ) );
$answers_before     = count( MHK_Centre::$answers );
MHK_Centre::$serve  = array( $envelope, $envelope );
$again              = mhk_run();
$row                = mhk_row( $erin );
$replayed           = array_slice( MHK_Centre::$answers, $answers_before );
check( 'handed over again, twice more, it is not carried out again', 'Erin, later' === $row->first_name && $before + 2 === (int) $row->revision && 0 === $again['applied'] );
check( 'the centre is given the first answer again, not a new one', $replayed && $replayed[0]['id'] === $envelope['id'] && 'applied' === $replayed[0]['status'] && $before + 1 === $replayed[0]['result']['revision'], wp_json_encode( $replayed ) );
check( 'and one record of it is kept', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$inbox} WHERE command_id = %s", $envelope['id'] ) ) ); // phpcs:ignore WordPress.DB
check( 'only one event ever said that command changed her', 1 === count( mhk_events_of( $envelope ) ) );

$unsub = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $erin ) );
mhk_run();
Mailhouse_Subscribers::resubscribe( mhk_row( $erin ), 'owner' );
MHK_Centre::$serve = array( $unsub );
mhk_run();
check( 'an unsubscribe replayed after she chose to come back does not take her off again', 'subscribed' === mhk_row( $erin )->status );

// ---------------------------------------------------------------------------
echo "\nCommands this site must not carry out\n";

$frank = mhk_person( 'frank' );

/**
 * Serve some envelopes and say whether Frank is untouched and nothing was answered.
 */
function mhk_untouched( $frank, array $envelopes ) {
	$answers           = count( MHK_Centre::$answers );
	MHK_Centre::$serve = $envelopes;

	Mailhouse_Central_Identity::update( array( 'command_error' => '' ) );
	$done = mhk_run();
	$row  = mhk_row( $frank );

	return array(
		'untouched'  => $row && 'subscribed' === $row->status && 'Frank' === $row->first_name,
		'unanswered' => count( MHK_Centre::$answers ) === $answers,
		'answers'    => array_slice( MHK_Centre::$answers, $answers ),
		'error'      => (string) Mailhouse_Central_Identity::get( 'command_error' ),
		'done'       => $done,
	);
}

$forged = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $frank ), array( 'unsigned' => true, 'queue' => false ) );
$seen   = mhk_untouched( $frank, array( $forged ) );
check( 'unsigned: not carried out, not answered, not written down', $seen['untouched'] && $seen['unanswered'] && null === mhk_inbox( $forged ), wp_json_encode( $seen ) );
check( 'and this site says so, here and in its next report', '' !== $seen['error'] && false !== strpos( (string) Mailhouse_Central_Status::report()['last_error'], 'not signed' ) );

$stranger = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $frank ), array( 'key' => MHK_Centre::$stranger, 'queue' => false ) );
$seen     = mhk_untouched( $frank, array( $stranger ) );
check( 'signed by somebody else’s key: the same', $seen['untouched'] && $seen['unanswered'] && null === mhk_inbox( $stranger ) && '' !== $seen['error'] );

$genuine = MHK_Centre::issue( 'subscriber.update_profile', array( 'local_id' => $frank, 'first_name' => 'Francis', 'expected_revision' => (int) mhk_row( $frank )->revision ), array( 'queue' => false ) );

foreach (
	array(
		'the payload'    => array( 'payload_json' => wp_json_encode( array( 'local_id' => $frank, 'first_name' => 'Mallory', 'expected_revision' => (int) mhk_row( $frank )->revision ) ) ),
		'the type'       => array( 'type' => 'subscriber.delete' ),
		'the number'     => array( 'seq' => $genuine['seq'] + 50 ),
		'the expiry'     => array( 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 900 * DAY_IN_SECONDS ) ),
		'the website'    => array( 'website_id' => MHK_OTHER ),
		'the command id' => array( 'id' => wp_generate_uuid4() ),
	) as $what => $change
) {
	$altered = array_merge( $genuine, $change );
	$seen    = mhk_untouched( $frank, array( $altered ) );
	check( "a genuine command with $what altered on the way: not carried out, not answered", $seen['untouched'] && $seen['unanswered'] && '' !== $seen['error'], wp_json_encode( $seen ) );
}

$elsewhere = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $frank ), array( 'website' => MHK_OTHER, 'queue' => false ) );
$seen      = mhk_untouched( $frank, array( $elsewhere ) );
check( 'made for another website, and genuinely signed: not carried out, not answered', $seen['untouched'] && $seen['unanswered'] && null === mhk_inbox( $elsewhere ) && false !== strpos( $seen['error'], 'different website' ), $seen['error'] );

$behind = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $frank ), array( 'queue' => false ) );
$seen   = mhk_untouched( $frank, array( $forged, $behind ) );
check( 'nothing behind a command that cannot be trusted is looked at either', $seen['untouched'] && $seen['unanswered'] && null === mhk_inbox( $behind ) );
$seen = mhk_untouched( $frank, array( 'not a command', array( 'id' => 'x' ), null ) );
check( 'something that is not a command at all: the same', $seen['untouched'] && $seen['unanswered'] );
MHK_Centre::$seq = $behind['seq'];

// Out of order.
$through = (int) Mailhouse_Central_Identity::get( 'inbox_seq' );
$late    = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $frank ), array( 'seq' => max( 1, $through - 3 ) ) );
$seen    = mhk_untouched( $frank, array( $late ) );
check( 'numbered before something already dealt with: not carried out', $seen['untouched'] && mhk_is( MHK_Centre::answer_for( $late ), 'rejected', array( 'reason' => 'out_of_order' ) ), wp_json_encode( $seen['answers'] ) );
check( 'and this site’s place in the sequence does not go back', $through === (int) Mailhouse_Central_Identity::get( 'inbox_seq' ) );
$same = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $frank ), array( 'seq' => $through ) );
$seen = mhk_untouched( $frank, array( $same ) );
check( 'nor one with the very number of the last', $seen['untouched'] && mhk_is( MHK_Centre::answer_for( $same ), 'rejected', array( 'reason' => 'out_of_order' ) ) );

// Two in one batch, the second numbered before the first.
$first  = MHK_Centre::issue( 'subscriber.update_profile', array( 'local_id' => $frank, 'last_name' => 'First', 'expected_revision' => (int) mhk_row( $frank )->revision ), array( 'seq' => $through + 12 ) );
$second = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $frank ), array( 'seq' => $through + 11 ) );
MHK_Centre::$serve = array( $first, $second );
mhk_run();
check( 'handed over in the wrong order: the later-numbered one is done, the earlier one is refused', 'First' === mhk_row( $frank )->last_name && 'subscribed' === mhk_row( $frank )->status && mhk_is( MHK_Centre::answer_for( $second ), 'rejected', array( 'reason' => 'out_of_order' ) ) );
MHK_Centre::$seq = $through + 12;

// Expired.
$expired = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $frank ), array( 'expires' => time() - 60 ) );
$seen    = mhk_untouched( $frank, array( $expired ) );
check( 'past its expiry: not carried out, and the centre is told it was too late', $seen['untouched'] && mhk_is( MHK_Centre::answer_for( $expired ), 'rejected', array( 'reason' => 'expired' ) ) );
$nonsense = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $frank ), array( 'expires' => 0 ) );
mhk_run();
check( 'an expiry in 1970 is past', 'subscribed' === mhk_row( $frank )->status && mhk_is( MHK_Centre::answer_for( $nonsense ), 'rejected', array( 'reason' => 'expired' ) ) );

// A clock that is wrong here, corrected by what the centre says.
Mailhouse_Central_Identity::update( array( 'clock_offset' => 3600 ) );
$soon = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $frank ), array( 'expires' => time() + 600, 'queue' => false ) );
check( 'expiry is judged by the centre’s clock, not a slow one here', 'rejected' === Mailhouse_Central_Commands::receive( $soon ) && 'subscribed' === mhk_row( $frank )->status );
Mailhouse_Central_Identity::update( array( 'clock_offset' => 0 ) );
MHK_Centre::$seq = $soon['seq'];

// A type this site does not do, a payload that is not one.
list( , $answer ) = mhk_do( 'subscriber.resubscribe', array( 'local_id' => $frank ) );
check( 'a kind of command nobody defined is refused', mhk_is( $answer, 'rejected', array( 'reason' => 'unsupported' ) ) );
list( , $answer ) = mhk_do( 'subscriber.set_status', array( 'local_id' => $frank, 'status' => 'subscribed' ) );
check( 'in particular, there is none that sets a status', mhk_is( $answer, 'rejected', array( 'reason' => 'unsupported' ) ) );
list( , $answer ) = mhk_do( 'subscriber.unsubscribe', array(), array( 'json' => '{not json' ) );
check( 'a payload that cannot be read is refused', mhk_is( $answer, 'rejected', array( 'reason' => 'malformed' ) ) && 'subscribed' === mhk_row( $frank )->status );

add_filter(
	'mailhouse_central_command_types',
	static function ( $types ) {
		return array_diff( $types, array( 'subscriber.delete' ) );
	}
);
list( , $answer ) = mhk_do( 'subscriber.delete', array( 'local_id' => $frank, 'mode' => 'remove' ) );
check( 'a site may decline a kind of command altogether', mhk_row( $frank ) && mhk_is( $answer, 'rejected', array( 'reason' => 'unsupported' ) ) );
remove_all_filters( 'mailhouse_central_command_types' );

check( 'after all of that Frank is as he was, apart from the one change properly asked for', 'subscribed' === mhk_row( $frank )->status && 'Frank' === mhk_row( $frank )->first_name && 'First' === mhk_row( $frank )->last_name );

// ---------------------------------------------------------------------------
echo "\nAn edit made here between the centre looking and the centre saving\n";

$grace  = mhk_person( 'grace' );
$looked = (int) mhk_row( $grace )->revision;
// Somebody here changes her name after the centre last saw her.
Mailhouse_Subscribers::update( $grace, array( 'first_name' => 'Gracie' ) );
list( $stale, $answer ) = mhk_do( 'subscriber.update_profile', array( 'local_id' => $grace, 'first_name' => 'Grace M.', 'last_name' => 'Hopper', 'expected_revision' => $looked ) );
$row                    = mhk_row( $grace );
check( 'the centre’s change is refused as stale, and nothing of it is written', 'Gracie' === $row->first_name && 'Reader' === $row->last_name && mhk_is( $answer, 'rejected', array( 'reason' => 'stale', 'revision' => $looked + 1, 'events' => 0 ) ), wp_json_encode( $answer ) );
check( 'no event says that command changed her', array() === mhk_events_of( $stale ) );
list( , $answer ) = mhk_do( 'subscriber.update_profile', array( 'local_id' => $grace, 'last_name' => 'Hopper', 'expected_revision' => $looked + 1 ) );
check( 'asked again against what is here now, it is carried out', 'Hopper' === mhk_row( $grace )->last_name && 'Gracie' === mhk_row( $grace )->first_name && mhk_is( $answer, 'applied' ) );
Mailhouse_Tags::attach( $grace, array( (int) $customers->id ) );
list( , $answer ) = mhk_do( 'subscriber.update_profile', array( 'local_id' => $grace, 'first_name' => 'G', 'expected_revision' => $looked + 2 ) );
check( 'a tag added here counts as a change too', 'Gracie' === mhk_row( $grace )->first_name && mhk_is( $answer, 'rejected', array( 'reason' => 'stale' ) ) );

// ---------------------------------------------------------------------------
echo "\nNobody is brought back\n";

$gone    = mhk_person( 'gone', 'unsubscribed' );
$dead    = mhk_person( 'dead', 'bounced' );
$waiting = mhk_person( 'unsure', 'pending' );

foreach ( array( 'gone' => array( $gone, 'unsubscribed' ), 'dead' => array( $dead, 'bounced' ) ) as $name => $who ) {
	list( $id, $status ) = $who;

	foreach ( array( 'opt_in', 'import' ) as $mode ) {
		$GLOBALS['mhk_mail'] = array();
		list( , $answer )    = mhk_do( 'subscriber.create', array( 'email' => "$name@" . MHK_DOMAIN, 'first_name' => 'Back', 'mode' => $mode, 'consent_basis' => 'They said so' ) );
		$row                 = mhk_row( $id );
		check( "subscriber.create ($mode) for somebody $status: refused, the row untouched, no message sent", $status === $row->status && 'Back' !== $row->first_name && array() === $GLOBALS['mhk_mail'] && mhk_is( $answer, 'rejected', array( 'reason' => "kept_$status", 'local_id' => $id ) ), wp_json_encode( $answer ) );
	}

	$GLOBALS['mhk_mail'] = array();
	list( , $answer )    = mhk_do( 'subscriber.reconfirm', array( 'local_id' => $id ) );
	check( "subscriber.reconfirm for somebody $status: refused, no message sent", $status === mhk_row( $id )->status && array() === $GLOBALS['mhk_mail'] && mhk_is( $answer, 'rejected', array( 'reason' => 'not_pending' ) ) );

	list( , $answer ) = mhk_do( 'subscriber.update_profile', array( 'local_id' => $id, 'first_name' => 'Renamed', 'status' => 'subscribed', 'email' => 'other@' . MHK_DOMAIN, 'confirmed_at' => '2026-01-01 00:00:00', 'expected_revision' => (int) mhk_row( $id )->revision ) );
	$row              = mhk_row( $id );
	check( "a profile change for somebody $status changes the name and nothing else, whatever else the payload says", 'Renamed' === $row->first_name && $status === $row->status && "$name@" . MHK_DOMAIN === $row->email && mhk_is( $answer, 'applied' ) );

	list( , $answer ) = mhk_do( 'subscriber.set_tags', array( 'local_id' => $id, 'add' => array( (int) $customers->id ), 'remove' => array(), 'status' => 'subscribed' ) );
	check( "tagging somebody $status leaves them $status", $status === mhk_row( $id )->status );
}

// In the same instant: they unsubscribe after this site has looked for them
// and before it adds them. The plugin reads a setting between the two, and
// that is where this test slips the row in.
$racer = 'racer@' . MHK_DOMAIN;
$raced = false;
$race  = static function ( $value, $key ) use ( $racer, &$raced ) {
	global $wpdb;

	if ( 'default_tags' === $key && ! $raced ) {
		$raced = true;
		$now   = mailhouse_now();

		// phpcs:ignore WordPress.DB
		$wpdb->insert(
			Mailhouse_Subscribers::table(),
			array(
				'email'           => $racer,
				'status'          => 'unsubscribed',
				'manage_key'      => mailhouse_token( 16 ),
				'created_at'      => $now,
				'updated_at'      => $now,
				'unsubscribed_at' => $now,
			)
		);
	}

	return $value;
};

add_filter( 'mailhouse_option', $race, 10, 2 );
list( , $answer ) = mhk_do( 'subscriber.create', array( 'email' => $racer, 'mode' => 'import', 'consent_basis' => 'They said so' ) );
remove_filter( 'mailhouse_option', $race, 10 );
$row = Mailhouse_Subscribers::get_by_email( $racer );
check( 'somebody who unsubscribes in the very moment they are being added stays unsubscribed', $raced && $row && 'unsubscribed' === $row->status && mhk_is( $answer, 'rejected', array( 'reason' => 'kept_unsubscribed' ) ), wp_json_encode( $answer ) );

list( , $answer ) = mhk_do( 'subscriber.unsubscribe', array( 'local_id' => $dead ) );
check( 'unsubscribing somebody bounced leaves the fact about their mailbox as it is', 'bounced' === mhk_row( $dead )->status && mhk_is( $answer, 'applied', array( 'noop' => true ) ) );
list( , $answer ) = mhk_do( 'subscriber.mark_bounced', array( 'local_id' => $gone, 'reason' => 'x' ) );
check( 'marking somebody unsubscribed as bounced does not put them on the list', 'bounced' === mhk_row( $gone )->status );
list( , $answer ) = mhk_do( 'subscriber.create', array( 'email' => 'unsure@' . MHK_DOMAIN, 'mode' => 'import', 'consent_basis' => 'x' ) );
check( 'somebody who has not confirmed is not confirmed for them by an import', 'pending' === mhk_row( $waiting )->status && mhk_is( $answer, 'applied', array( 'noop' => true ) ) );

// ---------------------------------------------------------------------------
echo "\nWhen things get in the way\n";

// The centre cannot be told what happened.
$henry             = mhk_person( 'henry' );
$answers           = count( MHK_Centre::$answers );
$told              = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $henry ) );
MHK_Centre::$mode  = 'ack_down';
$done              = mhk_run();
check( 'the answer cannot be delivered: the command was still carried out, once', 'unsubscribed' === mhk_row( $henry )->status && 1 === $done['applied'] && null === MHK_Centre::answer_for( $told ) );
$row = mhk_inbox( $told );
check( 'and the answer is kept, owed', $row && 'applied' === $row->status && 0 === (int) $row->acked && 1 === Mailhouse_Central_Commands::counts()['unanswered'] );
Mailhouse_Subscribers::resubscribe( mhk_row( $henry ), 'owner' );
MHK_Centre::$mode = 'ok';
$done             = mhk_run();
check( 'on the next run the answer goes, and the command is not done again', mhk_is( MHK_Centre::answer_for( $told ), 'applied' ) && 'subscribed' === mhk_row( $henry )->status && 0 === $done['applied'] && 1 === (int) mhk_inbox( $told )->acked );

// The centre is down altogether.
$queued           = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $henry ) );
MHK_Centre::$mode = 'down';
Mailhouse_Central_Identity::update( array( 'commands_pending' => 1 ) );
$done = Mailhouse_Central_Commands::work();
check( 'the centre cannot be reached: nothing happens, nothing breaks', 'subscribed' === mhk_row( $henry )->status && '' !== $done['error'] );
MHK_Centre::$mode = 'ok';
mhk_run();
check( 'and the command is carried out when it can be collected', 'unsubscribed' === mhk_row( $henry )->status && mhk_is( MHK_Centre::answer_for( $queued ), 'applied' ) );

// Paused.
$ivy              = mhk_person( 'ivy' );
$held             = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $ivy ) );
MHK_Centre::$mode = 'paused';
MHK_Centre::took( 'x' );
mhk_run();
check( 'while the centre has this website paused, nothing is collected', 'subscribed' === mhk_row( $ivy )->status && 0 === MHK_Centre::took( '/api/v1/connector/commands' ) );
MHK_Centre::$mode = 'ok';
mhk_run();
check( 'and it is when the pause is lifted', 'unsubscribed' === mhk_row( $ivy )->status && mhk_is( MHK_Centre::answer_for( $held ), 'applied' ) );

// A run that died half-way through a command.
$jack     = mhk_person( 'jack' );
$half     = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $jack ) );
$wpdb->insert( // phpcs:ignore WordPress.DB
	$inbox,
	array(
		'command_id'  => $half['id'],
		'seq'         => $half['seq'],
		'type'        => $half['type'],
		'status'      => 'applying',
		'result'      => '{}',
		'received_at' => gmdate( 'Y-m-d H:i:s', time() - 30 ),
		'acked'       => 0,
	)
);
Mailhouse_Central_Identity::update( array( 'inbox_seq' => $half['seq'] ) );
MHK_Centre::$serve = array( $half );
mhk_run();
check( 'a command another run began half a minute ago is left to that run', 'subscribed' === mhk_row( $jack )->status && 'applying' === mhk_inbox( $half )->status && null === MHK_Centre::answer_for( $half ) );
$wpdb->update( $inbox, array( 'received_at' => gmdate( 'Y-m-d H:i:s', time() - 900 ) ), array( 'command_id' => $half['id'] ) ); // phpcs:ignore WordPress.DB
mhk_run();
check( 'one begun a quarter of an hour ago was interrupted: it is not tried again, and the centre is told it failed', 'subscribed' === mhk_row( $jack )->status && mhk_is( MHK_Centre::answer_for( $half ), 'failed', array( 'reason' => 'interrupted' ) ) );

// A handler that throws.
add_action(
	'mailhouse_subscriber_unsubscribed',
	$thrower = static function () {
		throw new RuntimeException( 'another plugin fell over' );
	}
);
$kate             = mhk_person( 'kate' );
list( , $answer ) = mhk_do( 'subscriber.unsubscribe', array( 'local_id' => $kate ) );
remove_action( 'mailhouse_subscriber_unsubscribed', $thrower );
check( 'something else on the site falling over mid-command is reported as a failure, not left hanging', mhk_is( $answer, 'failed', array( 'reason' => 'error' ) ) && 'local' === Mailhouse_Central::origin() );
list( , $answer ) = mhk_do( 'tag.create', array( 'name' => 'MHK After the failure' ) );
check( 'and the commands after it are carried out as usual', mhk_is( $answer, 'applied' ) );

// Many at once.
$ids = array();

for ( $i = 1; $i <= 45; $i++ ) {
	$ids[ $i ] = mhk_person( "crowd$i" );
	MHK_Centre::issue( 'subscriber.update_profile', array( 'local_id' => $ids[ $i ], 'last_name' => "Number $i", 'expected_revision' => (int) mhk_row( $ids[ $i ] )->revision ) );
}

MHK_Centre::took( 'x' );
$done = mhk_run();
check( 'forty-five waiting are collected twenty at a time, in order, in one run', 45 === $done['applied'] && 3 === MHK_Centre::took( '/api/v1/connector/commands' ) && 'Number 45' === mhk_row( $ids[45] )->last_name && 0 === MHK_Centre::waiting(), wp_json_encode( $done ) );

// The scheduled run itself.
$liam  = mhk_person( 'liam' );
$timed = MHK_Centre::issue( 'subscriber.unsubscribe', array( 'local_id' => $liam ) );
Mailhouse_Central_Identity::update(
	array(
		'last_heartbeat_at' => 0,
		'next_attempt_at'   => 0,
	)
);
Mailhouse_Central::tick();
check( 'a scheduled run reports in and then does what was asked, before the mail queue', 'unsubscribed' === mhk_row( $liam )->status && mhk_is( MHK_Centre::answer_for( $timed ), 'applied' ) );

check( 'every request this site made was signed, query string included', MHK_Centre::$all_signed );

// ---------------------------------------------------------------------------
echo "\nThe settings tab\n";

wp_set_current_user( 1 );
$screen = new Mailhouse_Central_Screen();
ob_start();
$screen->render();
$html = (string) ob_get_clean();
check( 'says that changes from the centre are accepted, and how many there have been', false !== strpos( $html, 'Changes asked for by the centre' ) && false !== strpos( $html, 'carried out' ) );
Mailhouse_Central_Identity::update( array( 'command_error' => 'A command arrived that was not signed.' ) );
ob_start();
$screen->render();
$html = (string) ob_get_clean();
check( 'and shows what was wrong with the last command that could not be trusted', false !== strpos( $html, 'A command arrived that was not signed.' ) );
Mailhouse_Central_Identity::update( array( 'command_error' => '' ) );
wp_set_current_user( 0 );

// ---------------------------------------------------------------------------
echo "\nThousands of commands, in any order\n";

$seed = getenv( 'MAILHOUSE_PROPERTY_SEED' ) ? (int) getenv( 'MAILHOUSE_PROPERTY_SEED' ) : (int) ( time() % 1000000 );
mt_srand( $seed );
echo "  seed $seed\n";

$population = 36;
$statuses   = array( 'subscribed', 'pending', 'unsubscribed', 'bounced' );
$addresses  = array();

for ( $i = 1; $i <= $population; $i++ ) {
	$addresses[] = "p$i@" . MHK_DOMAIN;

	if ( 0 !== $i % 6 ) {
		mhk_person( "p$i", $statuses[ $i % 4 ] );
	}
}

$tag_ids = array( (int) $customers->id, (int) Mailhouse_Tags::create( 'MHK Random one' ), (int) Mailhouse_Tags::create( 'MHK Random two' ) );

/**
 * Everybody in the test population, as they stand: id => array( email, status ).
 */
function mhk_census() {
	global $wpdb;

	$rows = array();

	// phpcs:ignore WordPress.DB
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, email, status FROM ' . Mailhouse_Subscribers::table() . ' WHERE email LIKE %s', 'p%@' . MHK_DOMAIN ) ) as $row ) {
		$rows[ (int) $row->id ] = array( (string) $row->email, (string) $row->status );
	}

	return $rows;
}

/**
 * A payload for a type: usually well formed, often aimed at somebody who
 * has opted out, sometimes carrying things no payload should.
 */
function mhk_random_payload( $type, array $census, array $addresses, array $tag_ids ) {
	$ids    = array_keys( $census );
	$id     = $ids && mt_rand( 0, 19 ) ? $ids[ mt_rand( 0, count( $ids ) - 1 ) ] : mt_rand( 1, 999999 );
	$extras = array(
		array(),
		array(),
		array(),
		array( 'status' => 'subscribed' ),
		array( 'status' => 'subscribed', 'confirmed_at' => '2026-01-01 00:00:00', 'confirm_key' => null ),
		array( 'allow_resubscribe' => true, 'resubscribe' => true, 'double_optin' => false ),
		array( 'email' => 'moved@' . MHK_DOMAIN, 'unsubscribed_at' => null ),
	);
	$extra  = $extras[ mt_rand( 0, count( $extras ) - 1 ) ];
	$names  = array( 'Jane', 'Joan', '', 'Zoë', "O'Brien", '<b>bold</b>', str_repeat( 'x', 250 ) );
	$name   = $names[ mt_rand( 0, count( $names ) - 1 ) ];

	switch ( $type ) {
		case 'subscriber.create':
			return array_merge(
				array(
					'email'         => mt_rand( 0, 9 ) ? $addresses[ mt_rand( 0, count( $addresses ) - 1 ) ] : 'stranger' . mt_rand( 1, 99999 ) . '@' . MHK_DOMAIN,
					'first_name'    => $name,
					'last_name'     => 'Random',
					'fields'        => mt_rand( 0, 1 ) ? array( 'city' => 'York' ) : array(),
					'tags'          => mt_rand( 0, 1 ) ? array( $tag_ids[ mt_rand( 0, 2 ) ] ) : array(),
					'mode'          => mt_rand( 0, 1 ) ? 'opt_in' : 'import',
					'consent_basis' => 'They said so',
				),
				$extra
			);

		case 'subscriber.update_profile':
			return array_merge(
				array(
					'local_id'          => $id,
					'first_name'        => $name,
					'fields'            => mt_rand( 0, 1 ) ? array( 'status' => 'subscribed', 'city' => 'Leeds' ) : array(),
					// Usually the right revision, so that most of them are really carried out.
					'expected_revision' => mt_rand( 0, 4 ) && isset( $census[ $id ] ) ? (int) Mailhouse_Subscribers::get( $id )->revision : mt_rand( 0, 5 ),
				),
				$extra
			);

		case 'subscriber.set_tags':
			return array_merge(
				array(
					'local_id' => $id,
					'add'      => mt_rand( 0, 1 ) ? array( $tag_ids[ mt_rand( 0, 2 ) ] ) : array(),
					'remove'   => mt_rand( 0, 1 ) ? array( $tag_ids[ mt_rand( 0, 2 ) ] ) : array(),
				),
				$extra
			);

		case 'subscriber.unsubscribe':
			return array_merge( array( 'local_id' => $id, 'source' => mt_rand( 0, 1 ) ? 'central' : 'global_suppression' ), $extra );

		case 'subscriber.mark_bounced':
			return array_merge( array( 'local_id' => $id, 'reason' => 'Random' ), $extra );

		case 'subscriber.reconfirm':
			return array_merge( array( 'local_id' => $id ), $extra );

		case 'subscriber.delete':
			return array_merge( array( 'local_id' => $id, 'mode' => mt_rand( 0, 3 ) ? 'remove' : 'erase' ), $extra );

		case 'tag.create':
			return array( 'name' => 'MHK Random ' . mt_rand( 1, 6 ) );

		case 'tag.rename':
			return array( 'local_id' => $tag_ids[ mt_rand( 0, 2 ) ], 'name' => 'MHK Random ' . mt_rand( 1, 6 ) );

		case 'tag.delete':
			return array( 'local_id' => mt_rand( 0, 3 ) ? mt_rand( 1, 999999 ) : $tag_ids[ mt_rand( 1, 2 ) ] );
	}

	return $extra;
}

// How often each kind is asked for. Deleting is kept rare so that there
// are always people left to do things to.
$kinds = array_merge(
	array_fill( 0, 22, 'subscriber.create' ),
	array_fill( 0, 22, 'subscriber.update_profile' ),
	array_fill( 0, 14, 'subscriber.set_tags' ),
	array_fill( 0, 12, 'subscriber.unsubscribe' ),
	array_fill( 0, 8, 'subscriber.mark_bounced' ),
	array_fill( 0, 12, 'subscriber.reconfirm' ),
	array_fill( 0, 3, 'subscriber.delete' ),
	array( 'tag.create', 'tag.rename', 'tag.delete', 'site.reconcile', 'subscriber.resubscribe', 'subscriber.set_status', 'subscriber.confirm' )
);

$steps      = getenv( 'MAILHOUSE_PROPERTY_STEPS' ) ? (int) getenv( 'MAILHOUSE_PROPERTY_STEPS' ) : 4000;
$commands   = 0;
$outcomes   = array( 'applied' => 0, 'rejected' => 0, 'failed' => 0, 'repeat' => 0, 'untrusted' => 0 );
$violations = array();
$failures   = array();
$local      = array( 'came back by their own act' => 0, 'left' => 0, 'signed up' => 0 );
$sent       = array();
$attempts   = 0;
$GLOBALS['mhk_mail'] = array();

for ( $step = 0; $step < $steps; $step++ ) {
	$census = mhk_census();

	if ( 0 === mt_rand( 0, 4 ) ) {
		// Something happens on the site itself. These may bring somebody
		// back: they are the person's own acts, and are not what is being tested.
		$ids = array_keys( $census );
		$row = $ids ? Mailhouse_Subscribers::get( $ids[ mt_rand( 0, count( $ids ) - 1 ) ] ) : null;

		switch ( mt_rand( 0, 5 ) ) {
			case 0:
				if ( $row && 'unsubscribed' === $row->status ) {
					Mailhouse_Subscribers::resubscribe( $row, 'owner' );
					++$local['came back by their own act'];
				}
				break;
			case 1:
				if ( $row && 'pending' === $row->status ) {
					Mailhouse_Subscribers::confirm( $row );
					++$local['came back by their own act'];
				}
				break;
			case 2:
				if ( $row ) {
					Mailhouse_Subscribers::unsubscribe( $row, 'link' );
					++$local['left'];
				}
				break;
			case 3:
				if ( $row ) {
					Mailhouse_Subscribers::mark_bounced( $row, 'Random' );
					++$local['left'];
				}
				break;
			default:
				Mailhouse_Subscribers::add(
					array(
						'email'       => $addresses[ mt_rand( 0, count( $addresses ) - 1 ) ],
						'source'      => 'form',
						'send_emails' => false,
					)
				);
				++$local['signed up'];
		}

		continue;
	}

	// A command. Mostly genuine; now and then forged, for another website,
	// expired, out of order, or a copy of one already dealt with.
	$type    = $kinds[ mt_rand( 0, count( $kinds ) - 1 ) ];
	$payload = mhk_random_payload( $type, $census, $addresses, $tag_ids );
	$how     = array( 'queue' => false );
	$twist   = mt_rand( 0, 39 );

	if ( 0 === $twist ) {
		$how['unsigned'] = true;
	} elseif ( 1 === $twist ) {
		$how['key'] = MHK_Centre::$stranger;
	} elseif ( 2 === $twist ) {
		$how['website'] = MHK_OTHER;
	} elseif ( 3 === $twist ) {
		$how['expires'] = time() - mt_rand( 1, 100000 );
	} elseif ( 4 === $twist ) {
		$how['seq'] = max( 1, (int) Mailhouse_Central_Identity::get( 'inbox_seq' ) - mt_rand( 0, 30 ) );
	}

	$envelope = 5 === $twist && $sent ? $sent[ mt_rand( 0, count( $sent ) - 1 ) ] : MHK_Centre::issue( $type, $payload, $how );

	if ( isset( $how['seq'] ) ) {
		--MHK_Centre::$seq;
	}

	if ( $twist > 5 && count( $sent ) < 200 ) {
		$sent[] = $envelope;
	}

	// One address may be sent a confirmation only every few minutes. Lifted
	// here, so that every refusal seen is a rule about consent and not that.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_mailhouse_confirm_' ) . '%' ) ); // phpcs:ignore WordPress.DB
	wp_cache_flush();

	$mail_before = count( $GLOBALS['mhk_mail'] );
	$outcome     = Mailhouse_Central_Commands::receive( $envelope );
	$after       = mhk_census();

	++$commands;
	++$outcomes[ null === $outcome ? 'untrusted' : $outcome ];

	if ( 'failed' === $outcome ) {
		$failures[] = "step $step: $type " . wp_json_encode( $payload ) . ' -> ' . (string) mhk_inbox( $envelope )->result;
	}

	if ( 'applied' === $outcome && in_array( $type, array( 'subscriber.resubscribe', 'subscriber.set_status', 'subscriber.confirm' ), true ) ) {
		$violations[] = "step $step: $type was carried out";
	}

	$by_address = array();

	foreach ( $after as $who ) {
		$by_address[ $who[0] ] = $who[1];
	}

	$opted_out = array();

	foreach ( $census as $id => $who ) {
		if ( 'unsubscribed' !== $who[1] && 'bounced' !== $who[1] ) {
			continue;
		}

		$opted_out[ $who[0] ] = true;

		// The row, by its id.
		if ( isset( $after[ $id ] ) && in_array( $after[ $id ][1], array( 'subscribed', 'pending' ), true ) ) {
			$violations[] = "step $step: $type moved subscriber $id from {$who[1]} to {$after[ $id ][1]} -- " . wp_json_encode( $payload );
		}

		// And the address, in case a row was removed and another made.
		if ( isset( $by_address[ $who[0] ] ) && in_array( $by_address[ $who[0] ], array( 'subscribed', 'pending' ), true ) ) {
			$violations[] = "step $step: $type left {$who[0]}, who was {$who[1]}, as {$by_address[ $who[0] ]} -- " . wp_json_encode( $payload );
		}
	}

	// Nor is anybody who had opted out written to because the centre asked.
	foreach ( array_slice( $GLOBALS['mhk_mail'], $mail_before ) as $to ) {
		if ( isset( $opted_out[ $to ] ) ) {
			$violations[] = "step $step: $type sent a message to $to, who had opted out";
		}
	}

	// A command that could not be trusted changed nothing at all.
	if ( null === $outcome && $after !== $census ) {
		$violations[] = "step $step: a command that could not be trusted changed something";
	}
}

echo '  ' . $commands . ' commands: ' . wp_json_encode( $outcomes ) . "\n";
echo '  beside them, on the site itself: ' . wp_json_encode( $local ) . "\n";

check( 'in ' . number_format( $commands ) . ' commands, nobody who had unsubscribed or bounced was put back, by row or by address, nor written to', array() === $violations, implode( '; ', array_slice( $violations, 0, 5 ) ) );
check( 'none failed: every one was either carried out or refused by a rule', array() === $failures, implode( '; ', array_slice( $failures, 0, 3 ) ) );
check( 'enough of them were really carried out for that to mean something', $outcomes['applied'] > $commands / 5 && $outcomes['rejected'] > $commands / 10, wp_json_encode( $outcomes ) );
check( 'and enough could not be trusted, or were copies', $outcomes['untrusted'] > 20 && $outcomes['repeat'] > 10, wp_json_encode( $outcomes ) );
check( 'while people did come back by their own act, which no rule here forbids', $local['came back by their own act'] > 5, wp_json_encode( $local ) );
check( 'no command was left half done', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$inbox} WHERE status = 'applying' AND received_at > '" . gmdate( 'Y-m-d H:i:s', time() - 600 ) . "'" ) ); // phpcs:ignore WordPress.DB

// ---------------------------------------------------------------------------
echo "\nLeaving\n";

Mailhouse_Central_Identity::disconnect();
check( 'a disconnected site collects nothing', array( 'applied' => 0, 'rejected' => 0, 'failed' => 0, 'error' => '' ) === Mailhouse_Central_Commands::work() );

define( 'MAILHOUSE_CENTRAL_READ_ONLY', true );
check( 'with MAILHOUSE_CENTRAL_READ_ONLY set, commands are refused and the capability is not announced', ! Mailhouse_Central_Commands::accepting() && ! in_array( 'subscribers.commands.v1', Mailhouse_Central_Status::capabilities(), true ) );

// Read, not run: running it would drop this site's tables.
check( 'uninstalling removes the inbox table with the others', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-mailhouse-install.php' ), "array( 'inbox', 'outbox', 'log', 'queue', 'subscriber_tags', 'tags', 'subscribers' )" ) ); // phpcs:ignore

mailhouse_test_results();
