<?php
/**
 * Copying this site's list to the centre when it connects, comparing the
 * two afterwards, and what happens when this site's database is put back
 * to an earlier time.
 *
 * No centre is needed. The stand-in below keeps a copy of this site's
 * subscribers the way the real centre does -- in order, guarded by
 * revision -- and answers a comparison the way the real centre does, so
 * every check at the end is the same one: is the centre's copy this site's
 * list, subscriber for subscriber, hash for hash?
 *
 * Usage: php tests/test-central-reconcile.php
 *
 * @package Mailhouse
 */

require __DIR__ . '/bootstrap.php';

$saved_settings = get_option( 'mailhouse_settings' );
$saved_central  = get_option( 'mailhouse_central', null );

const MHR_CENTRAL = 'https://central.example.test';
const MHR_WEBSITE = '0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
const MHR_DOMAIN  = 'mhr.example.test';
const MHR_SEEDED  = 2600;

/**
 * Remove everything this file added.
 */
function mhr_clean() {
	global $wpdb;

	$subscribers = Mailhouse_Install::table( 'subscribers' );
	$pivot       = Mailhouse_Install::table( 'subscriber_tags' );
	$tags        = Mailhouse_Install::table( 'tags' );
	$outbox      = Mailhouse_Install::table( 'outbox' );

	// phpcs:disable WordPress.DB
	$wpdb->query( $wpdb->prepare( "DELETE p FROM {$pivot} p JOIN {$subscribers} s ON s.id = p.subscriber_id WHERE s.email LIKE %s", '%@' . MHR_DOMAIN ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$subscribers} WHERE email LIKE %s", '%@' . MHR_DOMAIN ) );
	$wpdb->query( "DELETE p FROM {$pivot} p JOIN {$tags} t ON t.id = p.tag_id WHERE t.name LIKE 'MHR %'" );
	$wpdb->query( "DELETE FROM {$tags} WHERE name LIKE 'MHR %'" );
	$wpdb->query( "DELETE FROM {$outbox}" );

	foreach ( array( 'subscribers', 'subscriber_tags', 'tags', 'outbox' ) as $name ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Mailhouse_Install::table( $name ) . '_mhr_backup' );
	}
	// phpcs:enable
}

// Put back even if this file dies half-way.
register_shutdown_function(
	static function () use ( $saved_settings, $saved_central ) {
		mhr_clean();
		update_option( 'mailhouse_settings', $saved_settings );

		if ( null === $saved_central ) {
			delete_option( 'mailhouse_central' );
		} else {
			update_option( 'mailhouse_central', $saved_central );
		}
	}
);

add_filter( 'pre_wp_mail', '__return_true' );

/**
 * The stand-in centre.
 */
final class MHR_Centre {

	/** @var array[] Every request seen: path and decoded body. */
	public static $requests = array();

	/** @var array[] Every event received, in order, across epochs. */
	public static $events = array();

	/** @var array<int,array> The centre's copy: local id => revision, hash, status, deleted. */
	public static $copy = array();

	/** @var array<int,string> Tags by local id. */
	public static $tags = array();

	/** @var int The last sequence number received in this epoch. */
	public static $received = 0;

	/** @var int */
	public static $epoch = 1;

	/** @var string ok, down, 500, or a 423 reason. */
	public static $mode = 'ok';

	/** @var bool Whether the next heartbeat asks for a comparison. */
	public static $want = false;

	/** @var bool Whether the next comparison takes the site's state as it is. */
	public static $force_next = false;

	/** @var array|null The comparison under way: run_id, forced, pages. */
	public static $run = null;

	/** @var array[] Comparison pages received: after, final, size. */
	public static $pages = array();

	/** @var array[] How each finished run came out. */
	public static $finished = array();

	/** @var int[] Opt-outs a forced comparison found the site had forgotten. */
	public static $forgotten = array();

	/** @var int Answer this many comparison pages with "still applying". */
	public static $busy = 0;

	/** @var int[] Ids to ask for that the page did not contain. */
	public static $ask_for = array();

	/** @var string[] Whatever was wrong with something received. */
	public static $invalid = array();

	/** @var string Registered public key, base64. */
	public static $site_key = '';

	/** @var string The token this centre issued. */
	public static $token = '';

	/** @var bool Whether every signed request verified. */
	public static $all_signed = true;

	/**
	 * A centre that has never heard from this website.
	 */
	public static function reset() {
		self::$requests   = array();
		self::$events     = array();
		self::$copy       = array();
		self::$tags       = array();
		self::$received   = 0;
		self::$epoch      = 1;
		self::$mode       = 'ok';
		self::$want       = false;
		self::$force_next = false;
		self::$run        = null;
		self::$pages      = array();
		self::$finished   = array();
		self::$forgotten  = array();
		self::$busy       = 0;
		self::$ask_for    = array();
		self::$invalid    = array();
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
	 * Start the website's sequence again, and take its next comparison as
	 * the truth: what the real centre does for a site whose history has
	 * gone backwards.
	 */
	public static function new_epoch() {
		self::$received   = 0;
		self::$force_next = true;
		self::$want       = true;
		self::$run        = null;

		return ++self::$epoch;
	}

	/**
	 * How many live subscribers the copy holds, by status.
	 */
	public static function counts() {
		$counts = array(
			'subscribed'   => 0,
			'pending'      => 0,
			'unsubscribed' => 0,
			'bounced'      => 0,
		);

		foreach ( self::$copy as $row ) {
			if ( ! $row['deleted'] ) {
				++$counts[ $row['status'] ];
			}
		}

		return $counts;
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

		return MHR_WEBSITE === $headers['X-MH-Website']
			&& sodium_crypto_sign_verify_detached( base64_decode( $headers['X-MH-Signature'], true ), $message, base64_decode( self::$site_key, true ) ); // phpcs:ignore
	}

	/**
	 * Apply one event to the copy, as the real centre's worker does.
	 */
	protected static function apply( $event ) {
		$type = $event['type'];
		$data = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();

		if ( 'tag.saved' === $type ) {
			self::$tags[ (int) $data['local_id'] ] = (string) $data['name'];

			return;
		}

		if ( 'tag.deleted' === $type ) {
			unset( self::$tags[ (int) $data['local_id'] ] );

			return;
		}

		if ( 'site.subscriber_absent' === $type ) {
			if ( isset( self::$copy[ (int) $data['local_id'] ] ) ) {
				self::$copy[ (int) $data['local_id'] ]['deleted'] = true;
			}

			return;
		}

		if ( 'site.backfill_completed' === $type || 'site.reconcile_completed' === $type ) {
			if ( ! isset( $data['run_id'], $data['counts'] ) || array( 'subscribed', 'pending', 'unsubscribed', 'bounced' ) !== array_keys( $data['counts'] ) ) {
				self::$invalid[] = "$type: malformed";
			}

			self::$finished[] = array(
				'type'   => $type,
				'run_id' => $data['run_id'],
				'site'   => $data['counts'],
				'centre' => self::counts(),
				'match'  => self::counts() === $data['counts'],
				'forced' => 'site.reconcile_completed' === $type && null !== self::$run && self::$run['forced'],
			);

			if ( 'site.reconcile_completed' === $type ) {
				self::$run = null;
			}

			return;
		}

		if ( 0 !== strpos( $type, 'subscriber.' ) ) {
			return;
		}

		$state = isset( $event['state'] ) ? $event['state'] : null;

		if ( ! is_array( $state ) || Mailhouse_Central_Protocol::state_hash( $state ) !== $state['hash'] || $state['local_id'] !== $event['subject']['local_id'] ) {
			self::$invalid[] = "$type: a state that is not whole";

			return;
		}

		foreach ( array( 'manage_key', 'confirm_key', 'user_id', 'sent', 'opens', 'clicks' ) as $never ) {
			if ( array_key_exists( $never, $state ) ) {
				self::$invalid[] = "$type: carries $never";
			}
		}

		$id       = (int) $state['local_id'];
		$existing = isset( self::$copy[ $id ] ) ? self::$copy[ $id ] : null;
		$snapshot = 'subscriber.snapshot' === $type;
		$forced   = $snapshot && null !== self::$run && self::$run['forced'] && self::$run['run_id'] === ( isset( $data['run_id'] ) ? $data['run_id'] : '' );

		if ( $snapshot && ( ! isset( $data['run_id'], $data['reason'] ) || ! in_array( $data['reason'], array( 'backfill', 'reconcile' ), true ) ) ) {
			self::$invalid[] = 'a snapshot that does not say which run it belongs to';
		}

		// Older than what is held: nothing changes. Unless the run is forced.
		if ( ! $forced && null !== $existing && ! $existing['deleted'] && $state['revision'] < $existing['revision'] ) {
			return;
		}

		// A restore must never resubscribe anyone: noted, to be issued again.
		if ( $forced && null !== $existing && in_array( $existing['status'], array( 'unsubscribed', 'bounced' ), true ) && in_array( $state['status'], array( 'subscribed', 'pending' ), true ) ) {
			self::$forgotten[] = $id;
		}

		self::$copy[ $id ] = array(
			'revision' => $state['revision'],
			'hash'     => $state['hash'],
			'status'   => $state['status'],
			'email'    => $state['email'],
			'tags'     => $state['tags'],
			'deleted'  => 'subscriber.deleted' === $type,
		);
	}

	/**
	 * Store and apply a push.
	 */
	protected static function receive( $json ) {
		if ( ! is_array( $json ) || ! isset( $json['epoch'], $json['events'] ) || ! $json['events'] || count( $json['events'] ) > 500 ) {
			return self::answer( 422, array( 'code' => 'validation_failed' ) );
		}

		if ( $json['epoch'] !== self::$epoch ) {
			return self::answer( 409, array( 'code' => 'epoch_mismatch', 'epoch' => self::$epoch ) );
		}

		$events = $json['events'];

		if ( $events[0]['seq'] > self::$received + 1 ) {
			return self::answer( 409, array( 'code' => 'sequence_gap', 'expected_seq' => self::$received + 1 ) );
		}

		$known = array();

		foreach ( self::$events as $stored ) {
			if ( $stored['epoch'] === self::$epoch ) {
				$known[ $stored['seq'] ] = $stored['event_id'];
			}
		}

		foreach ( $events as $index => $event ) {
			if ( $event['seq'] !== $events[0]['seq'] + $index ) {
				return self::answer( 422, array( 'code' => 'validation_failed' ) );
			}

			if ( $event['seq'] <= self::$received && ( ! isset( $known[ $event['seq'] ] ) || $known[ $event['seq'] ] !== $event['event_id'] ) ) {
				// Numbers already used for other events: the site has gone back.
				return self::answer( 409, array( 'code' => 'epoch_mismatch', 'epoch' => self::new_epoch() ) );
			}
		}

		foreach ( $events as $event ) {
			if ( $event['seq'] <= self::$received ) {
				continue;
			}

			$event['epoch'] = self::$epoch;
			self::$events[] = $event;
			self::$received = $event['seq'];
			self::apply( $event );
		}

		return self::answer(
			200,
			array(
				'accepted_through_seq' => self::$received,
				'duplicates'           => 0,
			)
		);
	}

	/**
	 * Answer one page of a comparison, as the real centre does.
	 */
	protected static function compare( $json ) {
		if ( ! is_array( $json ) || ! isset( $json['run_id'], $json['after_local_id'], $json['final'], $json['digest'] ) || ! is_array( $json['digest'] ) || count( $json['digest'] ) > 5000 ) {
			self::$invalid[] = 'a comparison page that is not one';

			return self::answer( 422, array( 'code' => 'validation_failed' ) );
		}

		$previous = (int) $json['after_local_id'];

		foreach ( $json['digest'] as $row ) {
			if ( ! is_array( $row ) || 3 !== count( $row ) || ! is_int( $row[0] ) || ! is_int( $row[1] ) || ! preg_match( '/^[0-9a-f]{64}$/', (string) $row[2] ) || $row[0] <= $previous ) {
				self::$invalid[] = 'a digest row that is not one, or out of order';

				return self::answer( 422, array( 'code' => 'validation_failed' ) );
			}

			$previous = $row[0];
		}

		if ( self::$busy > 0 ) {
			--self::$busy;

			return self::answer( 409, array( 'code' => 'sync_backlog' ) );
		}

		if ( null === self::$run || self::$run['run_id'] !== $json['run_id'] ) {
			self::$run        = array(
				'run_id' => $json['run_id'],
				'forced' => self::$force_next,
			);
			self::$force_next = false;
			self::$want       = false;
		}

		self::$pages[] = array(
			'run_id' => $json['run_id'],
			'after'  => (int) $json['after_local_id'],
			'final'  => (bool) $json['final'],
			'size'   => count( $json['digest'] ),
		);

		$send      = array();
		$mentioned = array();

		foreach ( $json['digest'] as $row ) {
			list( $id, $revision, $hash ) = $row;
			$mentioned[ $id ]             = true;
			$held                         = isset( self::$copy[ $id ] ) ? self::$copy[ $id ] : null;

			if ( null !== $held && ! $held['deleted'] && $revision < $held['revision'] && ! self::$run['forced'] ) {
				// The site holds an older revision than it once reported.
				return self::answer( 409, array( 'code' => 'epoch_mismatch', 'epoch' => self::new_epoch() ) );
			}

			if ( null === $held || $held['deleted'] || $held['revision'] !== $revision || $held['hash'] !== $hash ) {
				$send[] = $id;
			}
		}

		$absent = array();

		foreach ( self::$copy as $id => $held ) {
			if ( ! $held['deleted'] && $id > (int) $json['after_local_id'] && ( $json['final'] || $id <= $previous ) && ! isset( $mentioned[ $id ] ) ) {
				$absent[] = $id;
			}
		}

		sort( $absent );

		return self::answer(
			200,
			array(
				'send_state_for' => array_merge( $send, self::$ask_for ),
				'confirm_absent' => $absent,
				'forced'         => self::$run['forced'],
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
		$json = json_decode( $body, true );

		self::$requests[] = array(
			'path' => $path,
			'json' => $json,
			'body' => '/api/v1/connector/sync/events' === $path ? $body : '',
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
					'website_id'            => MHR_WEBSITE,
					'platform_keys'         => array(
						array(
							'key_id'     => 'p-test',
							'public_key' => base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) ), // phpcs:ignore
							'status'     => 'active',
						),
					),
					'protocol'              => 1,
					'endpoints'             => array( 'base' => MHR_CENTRAL . '/api/v1/connector' ),
					'poll_interval_seconds' => 60,
					'sync'                  => array(
						'epoch'             => self::$epoch,
						'last_seq_received' => self::$received,
					),
				)
			);
		}

		if ( ! self::signed( $args['method'], $path, $args['headers'], $body ) ) {
			self::$all_signed = false;

			return self::answer( 401, array( 'code' => 'unauthenticated' ) );
		}

		if ( '500' === self::$mode ) {
			return self::answer( 500, array( 'code' => 'internal' ) );
		}

		if ( 'key_revoked' === self::$mode ) {
			return self::answer( 423, array( 'code' => 'website_suspended', 'reason' => 'key_revoked' ) );
		}

		if ( '/api/v1/connector/sync/events' === $path ) {
			return self::receive( $json );
		}

		if ( '/api/v1/connector/sync/reconcile' === $path ) {
			return self::compare( $json );
		}

		if ( '/api/v1/connector/disconnect' === $path ) {
			return self::answer( 200, array( 'disconnected' => true ) );
		}

		// A heartbeat. A site that has acknowledged fewer events than the
		// centre holds, with nothing waiting to make up the difference,
		// has forgotten some: it has been restored from a backup.
		$outbox = isset( $json['outbox'] ) ? $json['outbox'] : array();

		if ( isset( $outbox['depth'], $outbox['last_acked_seq'] ) && $outbox['last_acked_seq'] + $outbox['depth'] < self::$received ) {
			self::new_epoch();
		}

		return self::answer(
			200,
			array(
				'status'           => 'connected',
				'commands_pending' => 0,
				'ack_through_seq'  => self::$received,
				'want'             => self::$want ? array( 'reconcile' ) : array(),
				'epoch'            => self::$epoch,
			)
		);
	}

	/**
	 * The requests made to a path since the last call, and forget them.
	 */
	public static function took( $path ) {
		$found = array();

		foreach ( self::$requests as $request ) {
			if ( false !== strpos( $request['path'], $path ) ) {
				$found[] = $request;
			}
		}

		self::$requests = array();

		return $found;
	}
}

add_filter( 'pre_http_request', array( 'MHR_Centre', 'handle' ), 10, 3 );

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
function mhr_forget() {
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
function mhr_connect() {
	mhr_forget();

	$result = Mailhouse_Central_Identity::register( MHR_CENTRAL, MHR_Centre::issue_token() );

	MHR_Centre::$requests = array();

	return $result;
}

/**
 * One scheduled run of the connector's sending, with nothing holding it back.
 */
function mhr_run() {
	Mailhouse_Central_Identity::update( array( 'push_next_at' => 0 ) );
	Mailhouse_Central::push();
}

/**
 * A heartbeat, now.
 */
function mhr_beat() {
	Mailhouse_Central_Identity::update(
		array(
			'last_heartbeat_at' => 0,
			'next_attempt_at'   => 0,
		)
	);

	return Mailhouse_Central::heartbeat();
}

/**
 * Scheduled runs until both jobs are finished and nothing is waiting.
 *
 * @return int How many runs it took.
 */
function mhr_settle( $most = 40 ) {
	for ( $runs = 1; $runs <= $most; $runs++ ) {
		mhr_run();

		$state    = Mailhouse_Central_Identity::state();
		$backfill = (array) $state['backfill'];

		if ( ( ! $backfill || 'done' === $backfill['phase'] ) && ! $state['reconcile'] && '' === $state['needs_reconcile'] && ! $state['want_reconcile'] && 0 === Mailhouse_Central_Outbox::waiting()['depth'] ) {
			return $runs;
		}
	}

	return $most + 1;
}

/**
 * Every way the centre's copy differs from this site's list. Empty when
 * the two are in step.
 *
 * @return string[]
 */
function mhr_differences() {
	global $wpdb;

	$table = Mailhouse_Subscribers::table();
	$out   = array();
	$seen  = array();
	$last  = 0;

	do {
		// phpcs:ignore WordPress.DB
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT 1000", $last ) );
		$ids  = array();

		foreach ( $rows as $row ) {
			$ids[] = (int) $row->id;
		}

		$tags = Mailhouse_Central_Outbox::tags_of( $ids );

		foreach ( $rows as $row ) {
			$id          = (int) $row->id;
			$last        = $id;
			$seen[ $id ] = true;
			$state       = Mailhouse_Central_Protocol::subscriber_state( $row, isset( $tags[ $id ] ) ? $tags[ $id ] : array() );
			$held        = isset( MHR_Centre::$copy[ $id ] ) ? MHR_Centre::$copy[ $id ] : null;

			if ( null === $held || $held['deleted'] ) {
				$out[] = "$id is not at the centre";
			} elseif ( $held['hash'] !== $state['hash'] || $held['revision'] !== $state['revision'] ) {
				$out[] = "$id differs ({$held['status']} r{$held['revision']} there, {$state['status']} r{$state['revision']} here)";
			}
		}
	} while ( $rows );

	foreach ( MHR_Centre::$copy as $id => $held ) {
		if ( ! $held['deleted'] && ! isset( $seen[ $id ] ) ) {
			$out[] = "$id is at the centre and not here";
		}
	}

	return $out;
}

/**
 * A fingerprint of every subscriber and tag this site holds.
 */
function mhr_fingerprint() {
	global $wpdb;

	$parts = array();

	foreach ( array( 'subscribers', 'subscriber_tags', 'tags' ) as $name ) {
		// phpcs:ignore WordPress.DB
		$row     = $wpdb->get_row( 'CHECKSUM TABLE ' . Mailhouse_Install::table( $name ), ARRAY_N );
		$parts[] = $row ? (string) $row[1] : 'none';
	}

	return implode( ':', $parts );
}

/**
 * Put rows straight into the subscribers table, as though they had been
 * there for years: no hooks fire, so nothing is written down for the centre.
 */
function mhr_seed( $count ) {
	global $wpdb;

	$table    = Mailhouse_Subscribers::table();
	$statuses = array( 'subscribed', 'subscribed', 'subscribed', 'pending', 'unsubscribed', 'subscribed', 'bounced', 'subscribed' );
	$values   = array();

	for ( $i = 1; $i <= $count; $i++ ) {
		$status   = $statuses[ $i % count( $statuses ) ];
		$values[] = $wpdb->prepare(
			'(%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d)',
			"seed-$i@" . MHR_DOMAIN,
			0 === $i % 97 ? 'Zoë 😀' : "First $i",
			0 === $i % 89 ? "O'Brien & Sons" : "Last $i",
			$status,
			0 === $i % 5 ? 'csv' : 'form',
			0 === $i % 3 ? '203.0.113.' . ( $i % 250 ) : '',
			mailhouse_token( 16 ),
			0 === $i % 11 ? wp_json_encode( array( 'company' => "Company $i" ) ) : '',
			'2024-05-06 07:08:09',
			'pending' === $status ? '' : '2024-05-06 07:10:00',
			'unsubscribed' === $status ? '2025-01-02 03:04:05' : '',
			'2025-01-02 03:04:05',
			$i % 7
		);

		if ( 0 === $i % 200 || $i === $count ) {
			// phpcs:ignore WordPress.DB
			$wpdb->query( "INSERT INTO {$table} (email, first_name, last_name, status, source, ip, manage_key, fields, created_at, confirmed_at, unsubscribed_at, updated_at, revision) VALUES " . implode( ',', $values ) );
			$values = array();
		}
	}

	// Empty text where the table means "none".
	// phpcs:disable WordPress.DB
	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET fields = NULL WHERE fields = '' AND email LIKE %s", '%@' . MHR_DOMAIN ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET confirmed_at = NULL WHERE status = 'pending' AND email LIKE %s", '%@' . MHR_DOMAIN ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET unsubscribed_at = NULL WHERE status <> 'unsubscribed' AND email LIKE %s", '%@' . MHR_DOMAIN ) );
	// phpcs:enable
}

/**
 * The id of a seeded subscriber.
 */
function mhr_id( $n ) {
	$row = Mailhouse_Subscribers::get_by_email( "seed-$n@" . MHR_DOMAIN );

	return $row ? (int) $row->id : 0;
}

/**
 * Change a subscriber behind the plugin's back: the row moves, and no
 * event is written. This is what a lost event looks like afterwards.
 */
function mhr_silently( $id, array $columns ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . Mailhouse_Subscribers::table() . ' SET revision = revision + 1 WHERE id = %d', $id ) );
	$wpdb->update( Mailhouse_Subscribers::table(), $columns, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
}

mhr_clean();
mhr_forget();
MHR_Centre::reset();
Mailhouse_Settings::set( array( 'default_tags' => array() ) );
wp_set_current_user( 0 );

// ---------------------------------------------------------------------------
echo "\nA site with a list of its own\n";

global $wpdb;

mhr_seed( MHR_SEEDED );

$tag_ids = array();

foreach ( array( 'MHR Weekly', 'MHR Offers', 'MHR Press' ) as $name ) {
	$tag_ids[] = (int) Mailhouse_Tags::create( $name );
}

$pivot  = Mailhouse_Tags::pivot();
$values = array();

for ( $i = 1; $i <= MHR_SEEDED; $i += 4 ) {
	$values[] = $wpdb->prepare( '(%d, %d, %s)', mhr_id( 1 ) + $i - 1, $tag_ids[ $i % 3 ], '2024-05-06 07:08:09' );
}

foreach ( array_chunk( $values, 300 ) as $chunk ) {
	$wpdb->query( "INSERT IGNORE INTO {$pivot} (subscriber_id, tag_id, added_at) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore WordPress.DB
}

$total  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Mailhouse_Subscribers::table() ); // phpcs:ignore WordPress.DB
$all_tags = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Mailhouse_Tags::table() ); // phpcs:ignore WordPress.DB

check( 'has ' . number_format( MHR_SEEDED ) . ' subscribers the centre has never heard of', $total >= MHR_SEEDED && mhr_id( MHR_SEEDED ) === mhr_id( 1 ) + MHR_SEEDED - 1 );
check( 'and nothing written down, because it is not connected', 0 === Mailhouse_Central_Outbox::highest() );
check( 'unconnected, neither job does anything', false === Mailhouse_Central_Reconcile::work( time() + 5 ) && array() === MHR_Centre::$requests );

// ---------------------------------------------------------------------------
echo "\nConnecting starts the first copy\n";

$fingerprint = mhr_fingerprint();
$connected   = mhr_connect();
$backfill    = (array) Mailhouse_Central_Identity::get( 'backfill' );

check( 'the site registers', true === $connected, is_wp_error( $connected ) ? $connected->get_error_message() : '' );
check( 'with a copy of its list still to make', 'tags' === $backfill['phase'] && 0 === $backfill['cursor'] && 1 === preg_match( '/^[0-9a-f-]{36}$/', $backfill['run_id'] ) );
check( 'it says it can be compared with', in_array( 'reconcile.v1', Mailhouse_Central_Status::capabilities(), true ) );

$report = Mailhouse_Central_Status::report();
check( 'the heartbeat says the copy has not begun, and how far there is to go', 'waiting' === $report['sync']['backfill'] && 0 === $report['sync']['backfill_through'] && $report['sync']['backfill_of'] >= mhr_id( MHR_SEEDED ) && 'idle' === $report['sync']['reconcile'] );

// A piece of work, with nothing sent in between.
$wrote = Mailhouse_Central_Reconcile::work( time() + 20 );
$depth = Mailhouse_Central_Outbox::waiting()['depth'];
$mid   = (array) Mailhouse_Central_Identity::get( 'backfill' );

check( 'the first piece writes the tags and the first subscribers to the outbox', true === $wrote && 'subscribers' === $mid['phase'] && $mid['cursor'] > 0 );
check( 'and stops when enough is waiting: the copy feeds the push, it does not run ahead of it', $depth >= Mailhouse_Central_Reconcile::HIGH_WATER && $depth < Mailhouse_Central_Reconcile::HIGH_WATER + Mailhouse_Central_Reconcile::CHUNK + $all_tags + 1, (string) $depth );
check( 'asked again with that much waiting, it writes nothing', false === Mailhouse_Central_Reconcile::work( time() + 20 ) && $depth === Mailhouse_Central_Outbox::waiting()['depth'] );
check( 'nothing has been asked of the centre yet', array() === MHR_Centre::$requests );

$report = Mailhouse_Central_Status::report();
check( 'the heartbeat says how far it has got', 'running' === $report['sync']['backfill'] && $mid['cursor'] === $report['sync']['backfill_through'] );

// While the copy is being made, life goes on.
$sent_already = Mailhouse_Subscribers::get( mhr_id( 5 ) );
$not_yet_sent = Mailhouse_Subscribers::get( mhr_id( MHR_SEEDED - 5 ) );
Mailhouse_Subscribers::update( (int) $sent_already->id, array( 'first_name' => 'Changed after' ) );
Mailhouse_Subscribers::update( (int) $not_yet_sent->id, array( 'first_name' => 'Changed before' ) );
$newcomer = Mailhouse_Subscribers::add(
	array(
		'email'       => 'newcomer@' . MHR_DOMAIN,
		'status'      => 'subscribed',
		'send_emails' => false,
	)
);
Mailhouse_Subscribers::delete( array( mhr_id( 9 ) ) );

// The process dies, and another starts with nothing in memory.
Mailhouse_Central_Identity::flush();
Mailhouse_Central_Outbox::reset();

// And the centre cannot be reached for a while.
MHR_Centre::$mode = 'down';
mhr_run();
check( 'with the centre unreachable nothing more is written, and nothing is lost', (array) Mailhouse_Central_Identity::get( 'backfill' ) === $mid && Mailhouse_Central_Outbox::waiting()['depth'] >= $depth );

MHR_Centre::$mode = 'ok';
$runs             = mhr_settle();
$done             = (array) Mailhouse_Central_Identity::get( 'backfill' );

check( 'when it can, the copy carries on from where it was and finishes', $runs <= 40 && 'done' === $done['phase'] && ! empty( $done['completed_at'] ), "$runs runs" );
check( 'every request was signed, and the centre found nothing wrong with any of it', MHR_Centre::$all_signed && array() === MHR_Centre::$invalid, implode( ' | ', array_unique( MHR_Centre::$invalid ) ) );

$types    = array();
$snapshot = array();
$reasons  = array();

foreach ( MHR_Centre::$events as $event ) {
	$types[ $event['type'] ] = isset( $types[ $event['type'] ] ) ? $types[ $event['type'] ] + 1 : 1;

	if ( 'subscriber.snapshot' === $event['type'] ) {
		$snapshot[]                         = $event['subject']['local_id'];
		$reasons[ $event['data']['reason'] ] = true;
	}
}

$sorted = $snapshot;
sort( $sorted );

check( 'the tags went first', 'tag.saved' === MHR_Centre::$events[0]['type'] && $all_tags === $types['tag.saved'] && count( MHR_Centre::$tags ) === $all_tags );
check( 'then every subscriber, once each, in the order of their ids', $snapshot === $sorted && count( array_unique( $snapshot ) ) === count( $snapshot ) && count( $snapshot ) >= $total - 1 && array( 'backfill' => true ) === $reasons, count( $snapshot ) . " of $total" );
check( 'and last, the count the centre checks its copy against', 1 === $types['site.backfill_completed'] && 'site.backfill_completed' === MHR_Centre::$finished[0]['type'] && $done['run_id'] === MHR_Centre::$finished[0]['run_id'] );

$counts = Mailhouse_Subscribers::counts();
unset( $counts['all'] );

check( 'the counts match, status for status', true === MHR_Centre::$finished[0]['match'] && MHR_Centre::$finished[0]['site'] === $counts, wp_json_encode( MHR_Centre::$finished[0] ) );
check( 'the centre’s copy is this site’s list, subscriber for subscriber, hash for hash', array() === mhr_differences(), implode( ' | ', array_slice( mhr_differences(), 0, 5 ) ) );
check( 'someone who changed after their copy was sent, and someone who changed before, are both right', MHR_Centre::$copy[ (int) $sent_already->id ]['revision'] === (int) Mailhouse_Subscribers::get( (int) $sent_already->id )->revision && MHR_Centre::$copy[ (int) $not_yet_sent->id ]['revision'] === (int) Mailhouse_Subscribers::get( (int) $not_yet_sent->id )->revision );
check( 'someone who joined while it was being made is there; someone deleted is not', isset( MHR_Centre::$copy[ (int) $newcomer['id'] ] ) && ( ! isset( MHR_Centre::$copy[ mhr_id( 1 ) + 8 ] ) || MHR_Centre::$copy[ mhr_id( 1 ) + 8 ]['deleted'] ) );
check( 'names with accents, emoji, apostrophes and ampersands arrived as they are', array() === array_filter( MHR_Centre::$invalid ) );

$everything = '';

foreach ( MHR_Centre::$requests as $request ) {
	$everything .= $request['body'];
}

$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT manage_key FROM ' . Mailhouse_Subscribers::table() . ' WHERE email LIKE %s LIMIT 50', '%@' . MHR_DOMAIN ) ); // phpcs:ignore WordPress.DB
$leak = array_filter(
	$keys,
	static function ( $key ) use ( $everything ) {
		return false !== strpos( $everything, (string) $key );
	}
);
check( 'no subscriber’s key is anywhere in what was sent', strlen( $everything ) > 100000 && ! $leak );

$report = Mailhouse_Central_Status::report();
check( 'the heartbeat says the copy is done', 'done' === $report['sync']['backfill'] && ! isset( $report['sync']['backfill_through'] ) );
check( 'no comparison follows by itself: nobody asked for one', array() === MHR_Centre::$pages );

// A chunk written twice, as after a crash between writing and noting it.
MHR_Centre::$requests = array();
$before               = count( MHR_Centre::$events );
Mailhouse_Central_Identity::update(
	array(
		'backfill' => array_merge(
			$done,
			array(
				'phase'  => 'subscribers',
				'cursor' => mhr_id( MHR_SEEDED - 700 ),
			)
		),
	)
);
mhr_settle();
check( 'a part of the copy sent twice changes nothing, and the counts still match', count( MHR_Centre::$events ) > $before + 700 && array() === mhr_differences() && true === MHR_Centre::$finished[1]['match'] );

// ---------------------------------------------------------------------------
echo "\nComparing, when nothing is wrong\n";

add_filter(
	'mailhouse_central_reconcile_page',
	static function () {
		return 1000;
	}
);

MHR_Centre::$requests = array();
MHR_Centre::$pages    = array();
MHR_Centre::$want     = true;
$events_before        = count( MHR_Centre::$events );

mhr_beat();
check( 'the centre asks, in its answer to a heartbeat', true === Mailhouse_Central_Identity::get( 'want_reconcile' ) );

$runs  = mhr_settle();
$pages = MHR_Centre::$pages;
$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Mailhouse_Subscribers::table() ); // phpcs:ignore WordPress.DB
$sizes = array_sum( array_column( $pages, 'size' ) );
$after = array_column( $pages, 'after' );
$rises = $after;
sort( $rises );

check( 'the site sends its list a page at a time: ids, revisions and hashes', count( $pages ) === (int) floor( $total / 1000 ) + 1 && $sizes === $total && 0 === $pages[0]['after'], count( $pages ) . " pages, $sizes rows" );
check( 'each page starting where the last ended, and only the last marked as the end', $after === $rises && count( array_unique( $after ) ) === count( $after ) && array( true ) === array_values( array_unique( array_slice( array_column( $pages, 'final' ), -1 ) ) ) && 1 === count( array_filter( array_column( $pages, 'final' ) ) ) );
check( 'all of them one run', 1 === count( array_unique( array_column( $pages, 'run_id' ) ) ) );

$sent = array_slice( MHR_Centre::$events, $events_before );
check( 'nothing differs, so nothing is sent but the count at the end', 1 === count( $sent ) && 'site.reconcile_completed' === $sent[0]['type'] && true === end( MHR_Centre::$finished )['match'] );

$last = (array) Mailhouse_Central_Identity::get( 'last_reconcile' );
check( 'the site notes when, how many it checked, and how many it had to send again', $total === $last['checked'] && 0 === $last['sent'] && false === $last['forced'] && (int) Mailhouse_Central_Identity::get( 'last_reconcile_at' ) >= time() - 60 );
check( 'and is no longer asked', false === Mailhouse_Central_Identity::get( 'want_reconcile' ) && array() === (array) Mailhouse_Central_Identity::get( 'reconcile' ) );

// ---------------------------------------------------------------------------
echo "\nComparing, when events have been lost\n";

$unsubscribed = mhr_id( 100 );
$renamed      = mhr_id( 1500 );
$deleted      = mhr_id( 2000 );

// Three changes the centre never hears of: the rows move, no event is written.
mhr_silently(
	$unsubscribed,
	array(
		'status'          => 'unsubscribed',
		'unsubscribed_at' => '2026-11-10 07:00:00',
	)
);
mhr_silently( $renamed, array( 'first_name' => 'Renamed in silence' ) );
// phpcs:disable WordPress.DB
$wpdb->delete( Mailhouse_Subscribers::table(), array( 'id' => $deleted ) );
$wpdb->insert(
	Mailhouse_Subscribers::table(),
	array(
		'email'      => 'silent-arrival@' . MHR_DOMAIN,
		'status'     => 'subscribed',
		'manage_key' => mailhouse_token( 16 ),
		'created_at' => mailhouse_now(),
		'updated_at' => mailhouse_now(),
		'revision'   => 1,
	)
);
// phpcs:enable
$arrived = (int) $wpdb->insert_id;

check( 'four differences, and nothing waiting to tell the centre about any of them', 4 === count( mhr_differences() ) && 0 === Mailhouse_Central_Outbox::waiting()['depth'], implode( ' | ', mhr_differences() ) );

// Somebody presses "Compare with the centre".
Mailhouse_Central_Identity::update( array( 'needs_reconcile' => 'requested' ) );
$fingerprint   = mhr_fingerprint();
$events_before = count( MHR_Centre::$events );
mhr_settle();

$sent  = array_slice( MHR_Centre::$events, $events_before );
$types = array();

foreach ( $sent as $event ) {
	$types[] = $event['type'] . ':' . ( 'site.subscriber_absent' === $event['type'] ? $event['data']['local_id'] : $event['subject']['local_id'] );
}
sort( $types );

$expected = array(
	"site.reconcile_completed:0",
	"site.subscriber_absent:$deleted",
	"subscriber.snapshot:$arrived",
	"subscriber.snapshot:$renamed",
	"subscriber.snapshot:$unsubscribed",
);
sort( $expected );

check( 'the comparison sends exactly what differs: three states, and one person reported gone', $types === $expected, implode( ' ', $types ) );
check( 'and the centre’s copy is this site’s list again', array() === mhr_differences() && true === end( MHR_Centre::$finished )['match'], implode( ' | ', mhr_differences() ) );
check( 'the one who had unsubscribed is unsubscribed there; the one deleted is marked as gone', 'unsubscribed' === MHR_Centre::$copy[ $unsubscribed ]['status'] && true === MHR_Centre::$copy[ $deleted ]['deleted'] );
check( 'nothing on this site was changed by any of it: not a subscriber, a tag, or who holds which', mhr_fingerprint() === $fingerprint );
check( 'the site notes that four were sent again', 4 === (int) Mailhouse_Central_Identity::get( 'last_reconcile' )['sent'] && '' === Mailhouse_Central_Identity::get( 'needs_reconcile' ) );

// ---------------------------------------------------------------------------
echo "\nComparing, when things get in the way\n";

MHR_Centre::$requests = array();
Mailhouse_Central_Identity::update( array( 'needs_reconcile' => 'requested' ) );
Mailhouse_Central_Outbox::record( 'tag.saved', 'tag', $tag_ids[0], array( 'local_id' => $tag_ids[0], 'name' => 'MHR Weekly', 'slug' => 'mhr-weekly' ) );

check( 'with a change still waiting to be sent, the comparison waits for it', false === Mailhouse_Central_Reconcile::work( time() + 5 ) && array() === MHR_Centre::took( '/sync/reconcile' ) );

Mailhouse_Central_Sync::push();
MHR_Centre::$busy  = 2;
MHR_Centre::$pages = array();
Mailhouse_Central_Reconcile::work( time() + 5 );
Mailhouse_Central_Reconcile::work( time() + 5 );
check( 'while the centre is still applying what it was sent, it waits, and that is no failure', array() === MHR_Centre::$pages && 2 === count( MHR_Centre::took( '/sync/reconcile' ) ) && 0 === (int) Mailhouse_Central_Identity::get( 'push_failures' ) );

Mailhouse_Central_Reconcile::work( time() + 5 );
$cursor           = (int) Mailhouse_Central_Identity::get( 'reconcile' )['cursor'];
MHR_Centre::$mode = '500';
Mailhouse_Central_Reconcile::work( time() + 5 );
check( 'a centre that is failing: the run stays where it was, and the next try is put off', $cursor === (int) Mailhouse_Central_Identity::get( 'reconcile' )['cursor'] && (int) Mailhouse_Central_Identity::get( 'push_next_at' ) > time() && 1 === count( MHR_Centre::$pages ) );
check( 'until then it does not ask again', false === Mailhouse_Central_Reconcile::work( time() + 5 ) && 1 === count( MHR_Centre::took( '/sync/reconcile' ) ) - 1 );

MHR_Centre::$mode = 'ok';
mhr_settle();
check( 'when the centre is well it carries on from the same place and finishes', array() === (array) Mailhouse_Central_Identity::get( 'reconcile' ) && 0 === MHR_Centre::$pages[1]['after'] - $cursor && true === end( MHR_Centre::$finished )['match'] );

// A centre that asks for more than it was shown.
$outside              = mhr_id( 2500 );
MHR_Centre::$ask_for  = array( $outside, 999999999 );
MHR_Centre::$requests = array();
$events_before        = count( MHR_Centre::$events );
Mailhouse_Central_Identity::update( array( 'needs_reconcile' => 'requested' ) );
Mailhouse_Central_Reconcile::work( time() + 5 );
Mailhouse_Central_Sync::push();
MHR_Centre::$ask_for = array();
$sent                = array_slice( MHR_Centre::$events, $events_before );
check( 'asked for a subscriber the page did not contain, the site does not send them', array() === $sent );
mhr_settle();

MHR_Centre::$mode = 'key_revoked';
Mailhouse_Central_Identity::update( array( 'needs_reconcile' => 'requested' ) );
Mailhouse_Central_Reconcile::work( time() + 5 );
check( 'refused by the centre, the site notes that it is suspended and stops', 'suspended' === Mailhouse_Central_Identity::status() && false === Mailhouse_Central_Reconcile::work( time() + 5 ) );
MHR_Centre::$mode = 'ok';
mhr_beat();
mhr_settle();
check( 'let back in, it finishes what it was asked', 'connected' === Mailhouse_Central_Identity::status() && array() === (array) Mailhouse_Central_Identity::get( 'reconcile' ) && array() === mhr_differences() );

// ---------------------------------------------------------------------------
echo "\nThe database is put back to an earlier time\n";

// phpcs:disable WordPress.DB
$tables = array( 'subscribers', 'subscriber_tags', 'tags', 'outbox' );

// ---- the backup is taken ----
foreach ( $tables as $name ) {
	$table = Mailhouse_Install::table( $name );
	$wpdb->query( "CREATE TABLE {$table}_mhr_backup LIKE {$table}" );
	$wpdb->query( "INSERT INTO {$table}_mhr_backup SELECT * FROM {$table}" );
}

$backup_option      = get_option( 'mailhouse_central' );
$backup_next_id     = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Mailhouse_Subscribers::table() ) + 1;
$backup_next_outbox = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Mailhouse_Install::table( 'outbox' ) ) + 1;
// phpcs:enable

// ---- an hour passes ----
$leaver   = Mailhouse_Subscribers::get( mhr_id( 304 ) );
$bouncer  = Mailhouse_Subscribers::get( mhr_id( 305 ) );
$retitled = Mailhouse_Subscribers::get( mhr_id( 306 ) );
$removed  = Mailhouse_Subscribers::get( mhr_id( 309 ) );

Mailhouse_Subscribers::unsubscribe( $leaver, 'link' );
Mailhouse_Subscribers::mark_bounced( $bouncer, '550 no such user' );
Mailhouse_Subscribers::update( (int) $retitled->id, array( 'first_name' => 'Since the backup' ) );
Mailhouse_Subscribers::delete( array( (int) $removed->id ) );
$late = Mailhouse_Subscribers::add(
	array(
		'email'       => 'after-the-backup@' . MHR_DOMAIN,
		'status'      => 'subscribed',
		'send_emails' => false,
	)
);
mhr_settle();

check( 'an hour of changes after a backup: all at the centre', array() === mhr_differences() && 'unsubscribed' === MHR_Centre::$copy[ (int) $leaver->id ]['status'] && 'bounced' === MHR_Centre::$copy[ (int) $bouncer->id ]['status'] );

$epoch_before = MHR_Centre::$epoch;

// ---- the backup is restored ----
// phpcs:disable WordPress.DB
foreach ( $tables as $name ) {
	$table = Mailhouse_Install::table( $name );
	$wpdb->query( "DELETE FROM {$table}" );
	$wpdb->query( "INSERT INTO {$table} SELECT * FROM {$table}_mhr_backup" );
}
// A restored table counts on from where the backup left off.
$wpdb->query( 'ALTER TABLE ' . Mailhouse_Subscribers::table() . " AUTO_INCREMENT = {$backup_next_id}" );
$wpdb->query( 'ALTER TABLE ' . Mailhouse_Install::table( 'outbox' ) . " AUTO_INCREMENT = {$backup_next_outbox}" );
// phpcs:enable
update_option( 'mailhouse_central', $backup_option );
Mailhouse_Central_Identity::flush();
Mailhouse_Central_Outbox::reset();

$restored = mhr_fingerprint();

check( 'restored, this site has forgotten the hour: they are subscribed here again, the deleted one is back', 'subscribed' === Mailhouse_Subscribers::get( (int) $leaver->id )->status && 'subscribed' === Mailhouse_Subscribers::get( (int) $bouncer->id )->status && null !== Mailhouse_Subscribers::get( (int) $removed->id ) && null === Mailhouse_Subscribers::get( (int) $late['id'] ) );
check( 'and the two no longer agree', count( mhr_differences() ) >= 5 );

MHR_Centre::$pages     = array();
MHR_Centre::$forgotten = array();
mhr_beat();

check( 'the centre notices at the next heartbeat, and starts the sequence again', $epoch_before + 1 === MHR_Centre::$epoch && MHR_Centre::$epoch === (int) Mailhouse_Central_Identity::get( 'epoch' ) );
check( 'the site drops what it had waiting, counts from one, and knows a comparison is due', 0 === Mailhouse_Central_Outbox::waiting()['depth'] && 0 === (int) Mailhouse_Central_Identity::get( 'outbox_acked' ) + (int) Mailhouse_Central_Identity::get( 'seq_offset' ) && ( '' !== Mailhouse_Central_Identity::get( 'needs_reconcile' ) || Mailhouse_Central_Identity::get( 'want_reconcile' ) ) );

mhr_settle();
$outcome = end( MHR_Centre::$finished );

check( 'the comparison that follows is taken as the truth', true === $outcome['forced'] && true === (bool) Mailhouse_Central_Identity::get( 'last_reconcile' )['forced'] );
check( 'afterwards the centre’s copy is the restored list, even where that is older', array() === mhr_differences() && true === $outcome['match'], implode( ' | ', array_slice( mhr_differences(), 0, 5 ) ) );
check( 'the centre knows which opt-outs this site has forgotten, to issue them again', array( (int) $leaver->id, (int) $bouncer->id ) === MHR_Centre::$forgotten );
check( 'the subscriber who joined after the backup is reported gone, not re-created here', true === MHR_Centre::$copy[ (int) $late['id'] ]['deleted'] && null === Mailhouse_Subscribers::get( (int) $late['id'] ) );
check( 'and nothing on this site was changed by the centre finding out', mhr_fingerprint() === $restored );

$next = Mailhouse_Subscribers::add(
	array(
		'email'       => 'after-the-restore@' . MHR_DOMAIN,
		'status'      => 'subscribed',
		'send_emails' => false,
	)
);
mhr_settle();
check( 'life goes on: the next sign-up, on an id that was used before, is at the centre as who they are now', (int) $next['id'] === (int) $late['id'] && false === MHR_Centre::$copy[ (int) $next['id'] ]['deleted'] && 'after-the-restore@' . MHR_DOMAIN === MHR_Centre::$copy[ (int) $next['id'] ]['email'] && array() === mhr_differences() );

// A restore noticed another way: by the comparison itself.
Mailhouse_Subscribers::update( mhr_id( 400 ), array( 'first_name' => 'Twice' ) );
mhr_settle();
$epoch_before = MHR_Centre::$epoch;
// phpcs:ignore WordPress.DB
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Mailhouse_Subscribers::table() . ' SET revision = revision - 1, first_name = %s WHERE id = %d', 'First 400', mhr_id( 400 ) ) );
Mailhouse_Central_Identity::update( array( 'needs_reconcile' => 'requested' ) );
mhr_settle();
check( 'a subscriber at an older revision than the centre was told of: a new epoch, and the site’s word taken', $epoch_before + 1 === MHR_Centre::$epoch && MHR_Centre::$epoch === (int) Mailhouse_Central_Identity::get( 'epoch' ) && true === end( MHR_Centre::$finished )['forced'] && array() === mhr_differences() );

// ---------------------------------------------------------------------------
echo "\nThe settings tab, and leaving\n";

require_once ABSPATH . 'wp-admin/includes/template.php';

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( $admins ? (int) $admins[0] : 0 );

$screen = new Mailhouse_Central_Screen();
ob_start();
$screen->render();
$html = (string) ob_get_clean();

check( 'the tab says when the list was first copied and last compared', false !== strpos( $html, 'First copy of the list' ) && false !== strpos( $html, 'Last compared' ) && false !== strpos( $html, 'checked' ) );
check( 'and offers to compare now', false !== strpos( $html, 'mailhouse_central_compare' ) && false !== strpos( $html, 'Compare with the centre' ) && false !== has_action( 'admin_post_mailhouse_central_compare' ) );

Mailhouse_Central_Identity::update(
	array(
		'backfill' => array(
			'run_id' => wp_generate_uuid4(),
			'phase'  => 'subscribers',
			'cursor' => 1234,
		),
	)
);
ob_start();
$screen->render();
$html = (string) ob_get_clean();
check( 'while the first copy is being made it says how far it has got', false !== strpos( $html, '1,234' ) );

wp_set_current_user( 0 );
Mailhouse_Central_Identity::disconnect();
check( 'disconnecting forgets both jobs', array() === (array) Mailhouse_Central_Identity::get( 'backfill' ) && array() === (array) Mailhouse_Central_Identity::get( 'reconcile' ) && false === Mailhouse_Central_Reconcile::work( time() + 5 ) );

$connected = Mailhouse_Central_Identity::register( MHR_CENTRAL, MHR_Centre::issue_token() );
check( 'connecting again starts a new copy, which finds the centre already has it all', true === $connected && 'tags' === Mailhouse_Central_Identity::get( 'backfill' )['phase'] && mhr_settle() <= 40 && array() === mhr_differences() && true === end( MHR_Centre::$finished )['match'] );

Mailhouse_Central_Identity::disconnect();

mailhouse_test_results();
