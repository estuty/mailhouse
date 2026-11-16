<?php
/**
 * Campaigns from the centre: a newsletter written there, sent by this site.
 *
 * No centre is needed, and no mail leaves. The stand-in centre below signs
 * `campaign.prepare` the way the real one does, serves a campaign's
 * recipients a few at a time and keeps what the site says about its
 * progress. The transport is a provider's API, answered here: it can take a
 * message, refuse it for good, refuse it for now, or leave nobody knowing.
 *
 * What is checked is what must never go wrong: that a campaign taken in
 * twice is one campaign; that nothing is sent unless the recipients are the
 * ones the centre fixed; that a message which may have gone is never sent
 * again; that nothing is sent after the deadline, to somebody who has
 * unsubscribed, by a copy of the site, or through the backup transport;
 * and that none of it changes how this site's own campaigns behave.
 *
 * Usage: php tests/test-central-campaigns.php
 *
 * @package Mailhouse
 */

require __DIR__ . '/bootstrap.php';

$saved_settings = get_option( 'mailhouse_settings' );
$saved_central  = get_option( 'mailhouse_central', null );

const MHC_CENTRAL = 'https://central.example.test';
const MHC_WEBSITE = '0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
const MHC_DOMAIN  = 'mhc.example.test';

/**
 * Remove everything this file added.
 */
function mhc_clean() {
	global $wpdb;

	$subscribers = Mailhouse_Install::table( 'subscribers' );
	$pivot       = Mailhouse_Install::table( 'subscriber_tags' );
	$queue       = Mailhouse_Install::table( 'queue' );
	$remote      = Mailhouse_Install::table( 'remote_campaigns' );
	$log         = Mailhouse_Install::table( 'log' );

	// phpcs:disable WordPress.DB
	$posts = array_map(
		'intval',
		(array) $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_mailhouse_central_execution', '_mhc_test')" )
	);

	foreach ( $posts as $post_id ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$queue} WHERE campaign_id = %d", $post_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE campaign_id = %d", $post_id ) );
		wp_delete_post( $post_id, true );
	}

	$wpdb->query( "DELETE FROM {$queue} WHERE remote_job_id IS NOT NULL" );
	$wpdb->query( "DELETE FROM {$remote}" );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE to_email LIKE %s", '%@' . MHC_DOMAIN ) );
	$wpdb->query( $wpdb->prepare( "DELETE p FROM {$pivot} p JOIN {$subscribers} s ON s.id = p.subscriber_id WHERE s.email LIKE %s", '%@' . MHC_DOMAIN ) );
	$wpdb->query( $wpdb->prepare( "DELETE q FROM {$queue} q JOIN {$subscribers} s ON s.id = q.subscriber_id WHERE s.email LIKE %s", '%@' . MHC_DOMAIN ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$subscribers} WHERE email LIKE %s", '%@' . MHC_DOMAIN ) );
	$wpdb->query( 'DELETE FROM ' . Mailhouse_Install::table( 'outbox' ) );
	$wpdb->query( 'DELETE FROM ' . Mailhouse_Install::table( 'inbox' ) );
	// phpcs:enable

	delete_transient( 'mailhouse_queue_lock' );
	delete_transient( 'mailhouse_central_inbox_pruned' );
}

register_shutdown_function(
	static function () use ( $saved_settings, $saved_central ) {
		mhc_clean();
		update_option( 'mailhouse_settings', $saved_settings );

		if ( null === $saved_central ) {
			delete_option( 'mailhouse_central' );
		} else {
			update_option( 'mailhouse_central', $saved_central );
		}
	}
);

/**
 * The stand-in centre.
 */
final class MHC_Centre {

	/** @var string The platform key pair. */
	public static $keypair = '';

	/** @var array[] Every command issued, by id. */
	public static $commands = array();

	/** @var int */
	public static $seq = 0;

	/** @var array[]|null Hand out exactly these on the next collection. */
	public static $serve = null;

	/** @var string ok, down, paused (the website is, at the centre), or lost (something in between answers 404). */
	public static $mode = 'ok';

	/** @var array[] Every request seen. */
	public static $requests = array();

	/** @var string */
	public static $site_key = '';

	/** @var string */
	public static $token = '';

	/** @var bool Whether every signed request verified, query string included. */
	public static $all_signed = true;

	/**
	 * Every execution, by id: `list` (local id => job id), `status`,
	 * `events` (what the site said), `fetches`, `page` (recipients a
	 * page), `garble` (serve a page that cannot be read).
	 *
	 * @var array[]
	 */
	public static $executions = array();

	/**
	 * A centre that has never heard from this website.
	 */
	public static function reset() {
		self::$keypair    = sodium_crypto_sign_keypair();
		self::$commands   = array();
		self::$seq        = 0;
		self::$serve      = null;
		self::$mode       = 'ok';
		self::$requests   = array();
		self::$all_signed = true;
		self::$executions = array();
	}

	/**
	 * A fresh enrolment token.
	 */
	public static function issue_token() {
		self::$token = 'mhe_' . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore

		return self::$token;
	}

	/**
	 * The hash of a campaign's content, worked out here and not by the
	 * plugin: one string, hashed whole.
	 */
	public static function content_hash( $subject, $preheader, $html, $text ) {
		$whole = "MH1-CONTENT\n";

		foreach ( array( $subject, $preheader, $html, $text ) as $part ) {
			$whole .= strlen( $part ) . ':' . $part . "\n";
		}

		return hash( 'sha256', $whole );
	}

	/**
	 * The hash of a list of recipients, likewise.
	 */
	public static function list_hash( array $list ) {
		ksort( $list, SORT_NUMERIC );

		$whole = "MH1-RECIPIENTS\n";

		foreach ( $list as $local => $job ) {
			$whole .= $local . ':' . $job . "\n";
		}

		return hash( 'sha256', $whole );
	}

	/**
	 * Fix a campaign for some of this site's subscribers, and return the
	 * payload of the command that hands it over.
	 *
	 * @param int[] $locals The site's ids of its recipients.
	 * @param array $how    subject, preheader, body_html, body_text, start, deadline, fields, payload (overrides).
	 * @return array
	 */
	public static function campaign( array $locals, array $how = array() ) {
		$list = array();

		foreach ( $locals as $local ) {
			$list[ (int) $local ] = wp_generate_uuid4();
		}

		ksort( $list, SORT_NUMERIC );

		$subject   = isset( $how['subject'] ) ? $how['subject'] : 'MHC news for {{first_name|you}}';
		$preheader = isset( $how['preheader'] ) ? $how['preheader'] : 'What happened this month';
		$html      = isset( $how['body_html'] ) ? $how['body_html'] : '<h1>Hello {{first_name|there}}</h1><p>The news.</p>';
		$text      = isset( $how['body_text'] ) ? $how['body_text'] : "HELLO {{first_name|there}}\n\nThe news.";
		$execution = wp_generate_uuid4();

		self::$executions[ $execution ] = array(
			'list'    => $list,
			'status'  => 'awaiting_site',
			'events'  => array(),
			'fetches' => 0,
			'page'    => 3,
			'garble'  => false,
		);

		return array_merge(
			array(
				'execution_id'    => $execution,
				'campaign_id'     => wp_generate_uuid4(),
				'name'            => 'MHC November',
				'subject'         => $subject,
				'preheader'       => $preheader,
				'body_html'       => $html,
				'body_text'       => $text,
				'content_hash'    => self::content_hash( $subject, $preheader, $html, $text ),
				'recipient_count' => count( $list ),
				'recipients_hash' => self::list_hash( $list ),
				'start_at'        => gmdate( 'Y-m-d\TH:i:s\Z', isset( $how['start'] ) ? (int) $how['start'] : time() - 60 ),
				'deadline'        => gmdate( 'Y-m-d\TH:i:s\Z', isset( $how['deadline'] ) ? (int) $how['deadline'] : time() + 3 * DAY_IN_SECONDS ),
				'fields'          => isset( $how['fields'] ) ? $how['fields'] : array(),
				'track_opens'     => false,
				'track_clicks'    => false,
			),
			isset( $how['payload'] ) ? $how['payload'] : array()
		);
	}

	/**
	 * Make a command: numbered, signed, and waiting.
	 */
	public static function issue( $type, array $payload ) {
		$envelope = array(
			'id'           => wp_generate_uuid4(),
			'website_id'   => MHC_WEBSITE,
			'seq'          => ++self::$seq,
			'type'         => $type,
			'issued_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'expires_at'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3 * DAY_IN_SECONDS ),
			'payload_json' => $payload ? wp_json_encode( $payload ) : '{}',
			'key_id'       => 'p-test',
		);

		$envelope['signature'] = base64_encode( sodium_crypto_sign_detached( Mailhouse_Central_Protocol::command_message( $envelope ), sodium_crypto_sign_secretkey( self::$keypair ) ) ); // phpcs:ignore

		self::$commands[ $envelope['id'] ] = array(
			'envelope' => $envelope,
			'answer'   => null,
		);

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
	 * What the site last said about an execution, or null.
	 */
	public static function last_said( $execution ) {
		$events = self::$executions[ $execution ]['events'];

		return $events ? $events[ count( $events ) - 1 ] : null;
	}

	/**
	 * Every state the site has reported for an execution, in order.
	 */
	public static function said( $execution ) {
		return wp_list_pluck( self::$executions[ $execution ]['events'], 'state' );
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

		return MHC_WEBSITE === $headers['X-MH-Website']
			&& sodium_crypto_sign_verify_detached( base64_decode( $headers['X-MH-Signature'], true ), $message, base64_decode( self::$site_key, true ) ); // phpcs:ignore
	}

	/**
	 * Answer a request.
	 */
	public static function handle( $args, $url ) {
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

		// A proxy, or an older centre, that knows no such address.
		if ( 'lost' === self::$mode ) {
			return array(
				'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
				'body'     => '<html><body>404 Not Found</body></html>',
				'response' => array(
					'code'    => 404,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			);
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
					'website_id'            => MHC_WEBSITE,
					'platform_keys'         => array(
						array(
							'key_id'     => 'p-test',
							'public_key' => base64_encode( sodium_crypto_sign_publickey( self::$keypair ) ), // phpcs:ignore
							'status'     => 'active',
						),
					),
					'protocol'              => 1,
					'endpoints'             => array( 'base' => MHC_CENTRAL . '/api/v1/connector' ),
					'poll_interval_seconds' => 60,
					'sync'                  => array(
						'epoch'             => 1,
						'last_seq_received' => 0,
					),
				)
			);
		}

		if ( ! self::signed( $args['method'], $path, $query, $args['headers'], $body ) ) {
			self::$all_signed = false;

			return self::answer( 401, array( 'code' => 'unauthenticated' ) );
		}

		if ( preg_match( '#^/api/v1/connector/executions/([0-9a-f-]{36})/(recipients|events)$#', $path, $found ) ) {
			if ( ! isset( self::$executions[ $found[1] ] ) ) {
				return self::answer( 404, array( 'code' => 'not_found' ) );
			}

			$execution = &self::$executions[ $found[1] ];

			if ( 'recipients' === $found[2] ) {
				++$execution['fetches'];

				// The website is paused at the centre: wait, do not stop.
				if ( 'paused' === self::$mode ) {
					return self::answer(
						409,
						array(
							'code'   => 'conflict',
							'reason' => 'paused',
							'status' => $execution['status'],
						)
					);
				}

				if ( in_array( $execution['status'], array( 'cancelled', 'blocked', 'failed' ), true ) ) {
					return self::answer(
						409,
						array(
							'code'   => 'conflict',
							'reason' => 'stopped',
							'status' => $execution['status'],
						)
					);
				}

				$after = isset( $asked['after'] ) ? (int) $asked['after'] : 0;
				$limit = min( isset( $asked['limit'] ) ? (int) $asked['limit'] : 1000, (int) $execution['page'] );
				$data  = array();
				$more  = false;

				foreach ( $execution['list'] as $local => $job ) {
					if ( $local <= $after ) {
						continue;
					}

					if ( count( $data ) === $limit ) {
						$more = true;
						break;
					}

					$data[] = array(
						'job_id'   => $job,
						'local_id' => $local,
					);
				}

				if ( $execution['garble'] && $data ) {
					$data[0]['local_id'] = 'seven';
				}

				return self::answer(
					200,
					array(
						'data'       => $data,
						'next_after' => $more ? $data[ count( $data ) - 1 ]['local_id'] : null,
						'total'      => count( $execution['list'] ),
						'hash'       => self::list_hash( $execution['list'] ),
					)
				);
			}

			$progress              = $json['progress'];
			$execution['events'][] = $progress;

			// As the real centre takes it: ready only with the right count
			// and the right hash, and nothing once it is stopped.
			$began = in_array( $progress['state'], array( 'sending', 'completed' ), true );

			if ( 'awaiting_site' === $execution['status'] && ( 'ready' === $progress['state'] || $began ) ) {
				$agrees = isset( $progress['prepared'] )
					&& count( $execution['list'] ) === $progress['prepared']['recipients']
					&& self::list_hash( $execution['list'] ) === $progress['prepared']['hash'];

				$execution['status'] = ! $agrees ? 'blocked' : ( $began ? 'sending' : 'ready' );
			} elseif ( in_array( $execution['status'], array( 'awaiting_site', 'ready' ), true ) && 'failed' === $progress['state'] ) {
				$execution['status'] = 'blocked';
			} elseif ( 'ready' === $execution['status'] && $began ) {
				$execution['status'] = 'sending';
			}

			return self::answer( 200, array( 'status' => $execution['status'] ) );
		}

		if ( '/api/v1/connector/sync/events' === $path ) {
			$through = 0;

			foreach ( $json['events'] as $event ) {
				$through = max( $through, (int) $event['seq'] );
			}

			return self::answer(
				200,
				array(
					'accepted_through_seq' => $through,
					'duplicates'           => 0,
				)
			);
		}

		if ( '/api/v1/connector/disconnect' === $path ) {
			return self::answer( 200, array( 'disconnected' => true ) );
		}

		if ( '/api/v1/connector/commands' === $path ) {
			if ( null !== self::$serve ) {
				$serve       = self::$serve;
				self::$serve = null;

				return self::answer(
					200,
					array(
						'commands' => $serve,
						'more'     => false,
					)
				);
			}

			$after = isset( $asked['after_seq'] ) ? (int) $asked['after_seq'] : 0;
			$due   = array();

			foreach ( self::$commands as $command ) {
				if ( null === $command['answer'] && $command['envelope']['seq'] > $after ) {
					$due[] = $command['envelope'];
				}
			}

			return self::answer(
				200,
				array(
					'commands' => $due,
					'more'     => false,
				)
			);
		}

		if ( '/api/v1/connector/commands/ack' === $path ) {
			$through = 0;

			foreach ( $json['results'] as $result ) {
				$through = max( $through, (int) $result['seq'] );

				if ( isset( self::$commands[ $result['id'] ] ) && null === self::$commands[ $result['id'] ]['answer'] ) {
					self::$commands[ $result['id'] ]['answer'] = $result;
				}
			}

			return self::answer( 200, array( 'acked_through_seq' => $through ) );
		}

		// A heartbeat, or anything else.
		return self::answer(
			200,
			array(
				'status'           => 'connected',
				'commands_pending' => self::waiting() + ( null === self::$serve ? 0 : count( self::$serve ) ),
				'ack_through_seq'  => 0,
				'want'             => array(),
				'epoch'            => 1,
				'accepted'         => true,
				'removed'          => 0,
			)
		);
	}

	/**
	 * How many requests were made to paths ending in something, since the
	 * last call.
	 */
	public static function took( $ending ) {
		$found = 0;

		foreach ( self::$requests as $request ) {
			if ( substr( $request['path'], -strlen( $ending ) ) === $ending ) {
				++$found;
			}
		}

		self::$requests = array();

		return $found;
	}
}

/**
 * The transport: what a provider's API answers, and everything it was
 * handed. `mhc_transport` takes the address and returns `accept`,
 * `refuse` (for good), `later` (try again), `unclear`, or `unreachable`.
 */
$GLOBALS['mhc_transport'] = null;
$GLOBALS['mhc_handed']    = array();
$GLOBALS['mhc_attempts']  = array();

/**
 * A canned HTTP response.
 */
function mhc_reply( $code, $body = '', $headers = array() ) {
	return array(
		'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
		'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		// Something earlier has already answered: a fault put there by a test.
		if ( false !== $pre ) {
			return $pre;
		}

		if ( 0 === strpos( $url, 'https://central.' ) ) {
			return MHC_Centre::handle( $args, $url );
		}

		$via = false !== strpos( $url, 'sendgrid' ) ? 'sendgrid' : ( false !== strpos( $url, 'postmark' ) ? 'postmark' : '' );

		if ( '' === $via ) {
			return $pre;
		}

		$json = json_decode( (string) $args['body'], true );

		if ( 'sendgrid' === $via ) {
			$message = array(
				'via'     => $via,
				'to'      => $json['personalizations'][0]['to'][0]['email'],
				'subject' => $json['subject'],
				'html'    => '',
				'text'    => '',
				'headers' => isset( $json['headers'] ) ? $json['headers'] : array(),
			);

			foreach ( $json['content'] as $part ) {
				$message[ 'text/html' === $part['type'] ? 'html' : 'text' ] = $part['value'];
			}
		} else {
			$message = array(
				'via'     => $via,
				'to'      => $json['To'],
				'subject' => $json['Subject'],
				'html'    => isset( $json['HtmlBody'] ) ? $json['HtmlBody'] : '',
				'text'    => isset( $json['TextBody'] ) ? $json['TextBody'] : '',
				'headers' => array(),
			);
		}

		$how = is_callable( $GLOBALS['mhc_transport'] ) ? call_user_func( $GLOBALS['mhc_transport'], $message['to'], $via ) : 'accept';

		$message['how']          = $how;
		$GLOBALS['mhc_handed'][] = $message;

		// Never reset: how often each address was handed to a transport at all.
		$GLOBALS['mhc_attempts'][ $message['to'] ] = ( isset( $GLOBALS['mhc_attempts'][ $message['to'] ] ) ? $GLOBALS['mhc_attempts'][ $message['to'] ] : 0 ) + 1;

		switch ( $how ) {
			case 'refuse':
				return mhc_reply( 400, array( 'errors' => array( array( 'message' => 'does not exist' ) ) ) );
			case 'later':
				return mhc_reply( 503, 'unavailable' );
			case 'unclear':
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received' );
			case 'unreachable':
				return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to api.sendgrid.com port 443' );
		}

		return 'sendgrid' === $via
			? mhc_reply( 202, '', array( 'x-message-id' => 'sg-' . md5( $message['to'] ) ) )
			: mhc_reply(
				200,
				array(
					'ErrorCode' => 0,
					'Message'   => 'OK',
					'MessageID' => 'pm-' . md5( $message['to'] ),
				)
			);
	},
	10,
	3
);

add_filter(
	'home_url',
	static function ( $url, $path ) {
		return ( isset( $GLOBALS['mhc_home'] ) ? $GLOBALS['mhc_home'] : 'https://shop.example.test' ) . ( $path ? '/' . ltrim( $path, '/' ) : '' );
	},
	10,
	2
);

add_shortcode(
	'mhc_code',
	static function () {
		return 'SHORTCODE-RAN';
	}
);

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
	Mailhouse_Central_Outbox::reset();
}

/**
 * Connect to the stand-in, with the first copy of the list already made.
 */
function mhc_connect() {
	mhc_forget();

	$result = Mailhouse_Central_Identity::register( MHC_CENTRAL, MHC_Centre::issue_token() );

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
	MHC_Centre::$requests = array();

	return $result;
}

/**
 * Report in and deal with whatever the centre is holding.
 */
function mhc_tick() {
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
 * Hand a campaign over: issue the command, let the site collect it.
 *
 * @return array The payload, and the site's answer to the command.
 */
function mhc_hand_over( array $locals, array $how = array() ) {
	$payload  = MHC_Centre::campaign( $locals, $how );
	$envelope = MHC_Centre::issue( 'campaign.prepare', $payload );

	mhc_tick();

	return array( $payload, MHC_Centre::answer_for( $envelope ), $envelope );
}

/**
 * What the site keeps of an execution.
 */
function mhc_row( $execution ) {
	global $wpdb;

	Mailhouse_Central_Campaigns::flush();

	// phpcs:ignore WordPress.DB
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Mailhouse_Install::table( 'remote_campaigns' ) . ' WHERE execution_id = %s', $execution ) );
}

/**
 * Change what the site keeps of an execution, as time passing would.
 */
function mhc_set( $execution, array $fields ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB
	$wpdb->update( Mailhouse_Install::table( 'remote_campaigns' ), $fields, array( 'execution_id' => $execution ) );
	Mailhouse_Central_Campaigns::flush();
}

/**
 * A campaign's queue rows, by subscriber id.
 */
function mhc_queue( $post_id ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT q.subscriber_id AS by_subscriber, q.* FROM ' . Mailhouse_Install::table( 'queue' ) . ' q WHERE q.campaign_id = %d ORDER BY q.subscriber_id', (int) $post_id ), OBJECT_K );
}

/**
 * How many of a campaign's rows are in each status: `sent:5 skipped:1`.
 */
function mhc_tally( $post_id ) {
	$tally = array();

	foreach ( mhc_queue( $post_id ) as $row ) {
		$tally[ $row->status ] = isset( $tally[ $row->status ] ) ? $tally[ $row->status ] + 1 : 1;
	}

	ksort( $tally );

	$parts = array();

	foreach ( $tally as $status => $count ) {
		$parts[] = "$status:$count";
	}

	return implode( ' ', $parts );
}

/**
 * Let the queue send a batch, whatever the pace.
 */
function mhc_send() {
	delete_transient( 'mailhouse_queue_lock' );

	return Mailhouse_Queue::process();
}

/**
 * Make every retry due now.
 */
function mhc_make_due( $post_id ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . Mailhouse_Install::table( 'queue' ) . " SET scheduled_at = %s WHERE campaign_id = %d AND status = 'pending'", gmdate( 'Y-m-d H:i:s', time() - 60 ), (int) $post_id ) );
}

/**
 * Subscribers made here, by the site itself.
 *
 * @return int[] Their ids, in order.
 */
function mhc_people( $count, array $more = array() ) {
	static $made = 0;

	$ids = array();

	for ( $i = 0; $i < $count; $i++ ) {
		++$made;

		$added = Mailhouse_Subscribers::add(
			array_merge(
				array(
					'email'       => "reader$made@" . MHC_DOMAIN,
					'first_name'  => "Reader$made",
					'last_name'   => 'Test',
					'source'      => 'form',
					'status'      => 'subscribed',
					'send_emails' => false,
				),
				$more
			)
		);

		$ids[] = (int) $added['id'];
	}

	return $ids;
}

/**
 * The messages handed to a transport for an address.
 */
function mhc_handed_to( $email, $via = '' ) {
	return array_values(
		array_filter(
			$GLOBALS['mhc_handed'],
			static function ( $message ) use ( $email, $via ) {
				return $message['to'] === $email && ( '' === $via || $via === $message['via'] );
			}
		)
	);
}

/**
 * Whether an answer to a command is what was expected.
 */
function mhc_is( $answer, $status, array $result = array() ) {
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

/**
 * Take a campaign in and prepare it, all the way to ready or beyond.
 *
 * @return array The payload, the site's row, the post id.
 */
function mhc_prepared( array $locals, array $how = array() ) {
	list( $payload ) = mhc_hand_over( $locals, $how );

	Mailhouse_Central_Campaigns::network();

	$row = mhc_row( $payload['execution_id'] );

	return array( $payload, $row, $row ? (int) $row->post_id : 0 );
}

mhc_clean();
mhc_forget();
MHC_Centre::reset();

Mailhouse_Install::install( true );

Mailhouse_Settings::set(
	array(
		'mailer'              => 'sendgrid',
		'campaign_mailer'     => '',
		'fallback_mailer'     => '',
		'from_email'          => 'sender@example.com',
		'campaign_from_email' => 'news@example.com',
		'sendgrid_key'        => 'SG.test',
		'postmark_token'      => 'pm-test',
		'batch_size'          => 50,
		'track_opens'         => false,
		'track_clicks'        => false,
	)
);
Mailhouse_Settings::flush();

global $wpdb;

$queue_table  = Mailhouse_Install::table( 'queue' );
$remote_table = Mailhouse_Install::table( 'remote_campaigns' );

// ---------------------------------------------------------------------------
echo "\nWhere a campaign from the centre is kept\n";

$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$remote_table}" ); // phpcs:ignore WordPress.DB
check( 'the table exists', in_array( 'execution_id', $columns, true ) && in_array( 'recipients_hash', $columns, true ) && in_array( 'deadline_at', $columns, true ), implode( ',', $columns ) );
check( 'and holds nowhere an address could be', ! array_intersect( array( 'email', 'to_email', 'recipients', 'first_name' ), $columns ) );
check( 'an execution can be written down only once', (bool) $wpdb->get_var( "SHOW INDEX FROM {$remote_table} WHERE Key_name = 'execution_id' AND Non_unique = 0" ) ); // phpcs:ignore WordPress.DB
check( 'the queue records when a message was handed to the transport', in_array( 'attempt_started_at', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$queue_table}" ), true ) ); // phpcs:ignore WordPress.DB
check( 'a job id can be in the queue only once', (bool) $wpdb->get_var( "SHOW INDEX FROM {$queue_table} WHERE Key_name = 'remote_job' AND Non_unique = 0" ) ); // phpcs:ignore WordPress.DB
check( 'the schema version is 1.5.0', '1.5.0' === Mailhouse_Install::DB_VERSION && '1.5.0' === get_option( 'mailhouse_db_version' ) );
check( 'uninstalling removes the table with the others', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-mailhouse-install.php' ), "array( 'remote_campaigns', 'inbox', 'outbox', 'log', 'queue', 'subscriber_tags', 'tags', 'subscribers' )" ) ); // phpcs:ignore

// ---------------------------------------------------------------------------
echo "\nThe two hashes, against the worked examples\n";

$vectors = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/protocol-v1.json' ), true ); // phpcs:ignore
check( 'the worked examples include campaigns', is_array( $vectors ) && ! empty( $vectors['campaigns']['content'] ) && ! empty( $vectors['campaigns']['recipients'] ) );

$wrong = array();

foreach ( $vectors['campaigns']['content'] as $case ) {
	if ( Mailhouse_Central_Campaigns::content_hash( $case['subject'], $case['preheader'], $case['body_html'], $case['body_text'] ) !== $case['hash'] ) {
		$wrong[] = $case['name'];
	}

	if ( MHC_Centre::content_hash( $case['subject'], $case['preheader'], $case['body_html'], $case['body_text'] ) !== $case['hash'] ) {
		$wrong[] = 'stand-in: ' . $case['name'];
	}
}

check( count( $vectors['campaigns']['content'] ) . ' contents: the same hash as the centre', ! $wrong, implode( ' | ', $wrong ) );

$wrong = array();

foreach ( $vectors['campaigns']['recipients'] as $case ) {
	$pairs = array();
	$list  = array();

	foreach ( $case['recipients'] as $recipient ) {
		$pairs[]                        = array( $recipient['local_id'], strtoupper( $recipient['job_id'] ) );
		$list[ $recipient['local_id'] ] = $recipient['job_id'];
	}

	if ( Mailhouse_Central_Campaigns::recipients_hash( $pairs ) !== $case['hash'] ) {
		$wrong[] = $case['name'];
	}

	if ( MHC_Centre::list_hash( $list ) !== $case['hash'] ) {
		$wrong[] = 'stand-in: ' . $case['name'];
	}
}

check( count( $vectors['campaigns']['recipients'] ) . ' lists of recipients: the same hash as the centre', ! $wrong, implode( ' | ', $wrong ) );

$handed = null;

foreach ( $vectors['commands'] as $command ) {
	if ( 'campaign.prepare' === $command['type'] ) {
		$handed = json_decode( $command['payload_json'], true );
	}
}

check( 'the command in the examples names its own content', is_array( $handed ) && Mailhouse_Central_Campaigns::content_hash( $handed['subject'], $handed['preheader'], $handed['body_html'], $handed['body_text'] ) === $handed['content_hash'] );

// ---------------------------------------------------------------------------
echo "\nWho is offered a campaign\n";

check( 'a site that takes commands says it will take a campaign', in_array( 'campaigns.local.v1', Mailhouse_Central_Status::capabilities(), true ) );

add_filter( 'mailhouse_central_accept_commands', '__return_false' );
check( 'a site that refuses commands does not', ! in_array( 'campaigns.local.v1', Mailhouse_Central_Status::capabilities(), true ) );
remove_filter( 'mailhouse_central_accept_commands', '__return_false' );

mhc_connect();
Mailhouse_Central_Campaigns::listen();

check( 'a connected site starts and stops campaigns before the queue runs, and reports after it', 6 === has_action( 'mailhouse_process_queue', array( 'Mailhouse_Central_Campaigns', 'local' ) ) && 21 === has_action( 'mailhouse_process_queue', array( 'Mailhouse_Central_Campaigns', 'network' ) ) );

// ---------------------------------------------------------------------------
echo "\nTaking a campaign in\n";

$people = mhc_people( 7 );
$acme   = mhc_people( 1, array( 'fields' => array( 'company' => 'Acme & Sons' ) ) );
$people = array_merge( $people, $acme );

$body = '<h1>Hello {{first_name|there}}</h1>'
	. '<p style="color:#ff0000;position:fixed" onclick="steal()" class="lead">From {{company|your company}}. [mhc_code] {{mystery}}</p>'
	. '<script>alert(1)</script><iframe src="https://example.com/"></iframe>'
	. '<p><a href="https://example.com/offer" class="mh-button">Go</a> <a href="javascript:alert(1)">no</a></p>'
	. '<table width="100%" cellpadding="0"><tr><td align="center" bgcolor="#eeeeee">Cell</td></tr></table>';

list( $payload, $answer ) = mhc_hand_over(
	$people,
	array(
		'body_html' => $body,
		'start'     => time() + HOUR_IN_SECONDS,
		'fields'    => array( 'company' ),
	)
);

$execution = $payload['execution_id'];
$row       = mhc_row( $execution );
$post_id   = $row ? (int) $row->post_id : 0;
$post      = $post_id ? get_post( $post_id ) : null;

check( 'the command is answered as carried out, with the site’s own name for the campaign', mhc_is( $answer, 'applied', array( 'local_campaign_id' => $post_id ) ) && $post_id > 0, wp_json_encode( $answer ) );
check( 'and not as one that did nothing, though it changed nobody on the list', is_array( $answer ) && ! isset( $answer['result']['noop'] ) && 0 === $answer['result']['events'], wp_json_encode( $answer ) );
check( 'the campaign is kept, being prepared', $row && 'preparing' === $row->state && $payload['campaign_id'] === $row->campaign_id && 8 === (int) $row->recipient_count && $payload['recipients_hash'] === $row->recipients_hash, wp_json_encode( $row ) );
check( 'with its times, to the second', $row && gmdate( 'Y-m-d H:i:s', strtotime( $payload['start_at'] ) ) === $row->start_at && gmdate( 'Y-m-d H:i:s', strtotime( $payload['deadline'] ) ) === $row->deadline_at );
check( 'the body is cleaned again here: no script, no frame, no handler', $row && false === stripos( $row->body_html, '<script' ) && false === stripos( $row->body_html, '<iframe' ) && false === stripos( $row->body_html, 'onclick' ) && false === stripos( $row->body_html, 'javascript:' ), $row ? $row->body_html : '' );
check( 'a style it may have is kept, one it may not is gone', $row && false !== strpos( $row->body_html, 'color:#ff0000' ) && false === strpos( $row->body_html, 'position' ), $row ? $row->body_html : '' );
check( 'this site’s button keeps its class, and no other class is kept', $row && false !== strpos( $row->body_html, 'class="mh-button"' ) && false === strpos( $row->body_html, 'class="lead"' ) );
check( 'a table for layout survives', $row && false !== strpos( $row->body_html, '<td align="center" bgcolor="#eeeeee">' ) && false !== strpos( $row->body_html, 'cellpadding="0"' ), $row ? $row->body_html : '' );
check( 'there is a record of it among this site’s emails', $post && Mailhouse_Emails::POST_TYPE === $post->post_type && 'MHC November' === $post->post_title && $execution === get_post_meta( $post_id, '_mailhouse_central_execution', true ) );
check( 'which does not hold the body', $post && false === strpos( $post->post_content, 'Hello' ) && false !== strpos( $post->post_content, 'Mailhouse Central' ) );
check( 'and is labelled in the list', $post && array( 'mailhouse_central' => 'From Mailhouse Central' ) === Mailhouse_Central_Campaigns::label( array(), $post ) );
check( 'nothing is queued by taking it in', 0 === count( mhc_queue( $post_id ) ) );

wp_update_post(
	array(
		'ID'           => $post_id,
		'post_title'   => 'Changed here',
		'post_content' => '<p>Something else entirely</p>',
	)
);
clean_post_cache( $post_id );
$post = get_post( $post_id );
check( 'saving the record changes neither its title nor its text', 'MHC November' === $post->post_title && false === strpos( $post->post_content, 'Something else' ) );

ob_start();
Mailhouse_Central_Campaigns::notice( $post );
$notice = (string) ob_get_clean();
check( 'its screen says what it is', false !== strpos( $notice, 'Sent for Mailhouse Central' ) );

ob_start();
mailhouse()->module( 'emails' )->send_box( $post );
$panel = (string) ob_get_clean();
check( 'its Sending panel offers nobody to choose, no time to set and no Send', false === strpos( $panel, 'mailhouse_tag' ) && false === strpos( $panel, 'mailhouse_send_at' ) && false === strpos( $panel, 'mailhouse_send_campaign' ) && false === strpos( $panel, 'mailhouse-send-test' ), $panel );
check( 'it says where the campaign has got to, and can be called off', false !== strpos( $panel, 'Fetching its recipients' ) && false !== strpos( $panel, 'mailhouse_cancel_campaign' ) && false !== strpos( $panel, 'Nothing is sent after' ) );

// An administrator saves the record's screen, with the form changed by hand.
$administrators = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
wp_set_current_user( $administrators ? $administrators[0]->ID : 0 );
$_POST = array(
	'mailhouse_email_nonce' => wp_create_nonce( 'mailhouse_save_email' ),
	'mailhouse_type'        => 'welcome',
	'mailhouse_subject'     => 'Changed by hand',
	'mailhouse_tag'         => '0',
	'mailhouse_send_at'     => '2030-01-01T09:00',
);
mailhouse()->module( 'emails' )->save( $post_id, $post );
$_POST = array();
check( 'saving it cannot make it one of this site’s automated emails, or change its subject', $administrators && 'newsletter' === get_post_meta( $post_id, '_mailhouse_type', true ) && $payload['subject'] === get_post_meta( $post_id, '_mailhouse_subject', true ) && gmdate( 'Y-m-d H:i:s', strtotime( $payload['start_at'] ) ) === get_post_meta( $post_id, '_mailhouse_send_at', true ) );

echo "\nThe same campaign, delivered twice\n";

// The same command again, as a centre that did not hear the answer sends it.
$again              = MHC_Centre::$commands;
$first              = reset( $again );
MHC_Centre::$serve  = array( $first['envelope'] );
mhc_tick();
check( 'the same command collected again makes nothing new', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$remote_table}" ) && 1 === count( get_posts( array( 'post_type' => Mailhouse_Emails::POST_TYPE, 'meta_key' => '_mailhouse_central_execution', 'post_status' => 'any', 'numberposts' => -1 ) ) ) ); // phpcs:ignore

// A new command for the same execution, as a centre that restarted half-way sends it.
$envelope = MHC_Centre::issue( 'campaign.prepare', $payload );
mhc_tick();
$answer = MHC_Centre::answer_for( $envelope );
check( 'a second command for the same execution is answered as a repeat', mhc_is( $answer, 'applied', array( 'repeat' => true, 'local_campaign_id' => $post_id ) ), wp_json_encode( $answer ) );
check( 'and there is still one campaign, and one record', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$remote_table}" ) && 1 === count( get_posts( array( 'post_type' => Mailhouse_Emails::POST_TYPE, 'meta_key' => '_mailhouse_central_execution', 'post_status' => 'any', 'numberposts' => -1 ) ) ) ); // phpcs:ignore

echo "\nWhat is not taken in\n";

$refusals = array(
	'no execution id'                     => array( array( 'execution_id' => 'not-an-id' ), 'malformed' ),
	'an empty subject'                    => array( array( 'subject' => '  ' ), 'malformed' ),
	'a subject of two lines'              => array( array( 'subject' => "One\r\nBcc: someone@example.com" ), 'malformed' ),
	'nobody to send to'                   => array( array( 'recipient_count' => 0 ), 'malformed' ),
	'a count that is not a number'        => array( array( 'recipient_count' => '8' ), 'malformed' ),
	'a hash that is not one'              => array( array( 'recipients_hash' => 'abc' ), 'malformed' ),
	'a start that is not a time'          => array( array( 'start_at' => 'tomorrow' ), 'malformed' ),
	'a deadline before the start'         => array( array( 'deadline' => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ) ), 'malformed' ),
	'a body that is not what was hashed'  => array( array( 'body_html' => '<p>Something the centre never fixed</p>' ), 'content_mismatch' ),
	'a subject that is not what was hashed' => array( array( 'subject' => 'A different subject' ), 'content_mismatch' ),
);

foreach ( $refusals as $label => $case ) {
	$before         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$remote_table}" ); // phpcs:ignore WordPress.DB
	list( , $answer ) = mhc_hand_over( $people, array( 'payload' => $case[0] ) );

	check( "$label: refused, and nothing kept", mhc_is( $answer, 'rejected', array( 'reason' => $case[1] ) ) && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$remote_table}" ) === $before, wp_json_encode( $answer ) ); // phpcs:ignore WordPress.DB
}

list( , $answer ) = mhc_hand_over(
	$people,
	array(
		'start'    => time() - 4 * DAY_IN_SECONDS,
		'deadline' => time() - DAY_IN_SECONDS,
	)
);
check( 'a campaign whose deadline has passed is refused as too late', mhc_is( $answer, 'rejected', array( 'reason' => 'too_late' ) ), wp_json_encode( $answer ) );

list( , $answer ) = mhc_hand_over( $people, array( 'body_html' => '<iframe src="https://example.com/"></iframe><script></script><object data="x"></object>' ) );
check( 'one with nothing left after cleaning is refused as unusable', mhc_is( $answer, 'rejected', array( 'reason' => 'unusable' ) ), wp_json_encode( $answer ) );

// ---------------------------------------------------------------------------
echo "\nFetching its recipients\n";

MHC_Centre::$requests = array();
MHC_Centre::$mode     = 'down';
Mailhouse_Central_Campaigns::network();
$row = mhc_row( $execution );
check( 'with the centre away nothing is fetched, and it is tried again later', 'preparing' === $row->state && 0 === (int) $row->fetch_after && (int) $row->next_at > time() && 0 === count( mhc_queue( $post_id ) ) );

MHC_Centre::$mode = 'ok';
Mailhouse_Central_Campaigns::network();
check( 'not before the wait is over', 0 === MHC_Centre::$executions[ $execution ]['fetches'] );

// A run whose time is already up does nothing, and loses nothing.
mhc_set( $execution, array( 'next_at' => 0 ) );
MHC_Centre::$requests = array();
Mailhouse_Central_Campaigns::network( time() );
check( 'a run with no time left asks for nothing', 0 === MHC_Centre::$executions[ $execution ]['fetches'] && 'preparing' === mhc_row( $execution )->state );

// The centre goes away after the first page.
$pages = 0;
$flaky = static function ( $pre, $args, $url ) use ( &$pages ) {
	if ( false !== strpos( $url, '/recipients' ) && ++$pages > 1 ) {
		return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
	}

	return $pre;
};
add_filter( 'pre_http_request', $flaky, 5, 3 );
Mailhouse_Central_Campaigns::network();
remove_filter( 'pre_http_request', $flaky, 5 );

$row = mhc_row( $execution );
check( 'a page that arrived is kept when the next does not', 'preparing' === $row->state && 3 === count( mhc_queue( $post_id ) ) && (int) $row->fetch_after === (int) array_keys( MHC_Centre::$executions[ $execution ]['list'] )[2] && (int) $row->next_at > time(), wp_json_encode( array( $row->state, $row->fetch_after, count( mhc_queue( $post_id ) ) ) ) );

// The site forgets how far it had got, all but the first: the next page
// it is given is two people it holds already and one it does not.
$first_id = (int) array_keys( MHC_Centre::$executions[ $execution ]['list'] )[0];
mhc_set(
	$execution,
	array(
		'next_at'     => 0,
		'fetch_after' => $first_id,
	)
);
Mailhouse_Central_Campaigns::network();

$row      = mhc_row( $execution );
$rows     = mhc_queue( $post_id );
$fetches  = array_values(
	array_filter(
		MHC_Centre::$requests,
		static function ( $request ) {
			return '/recipients' === substr( $request['path'], -11 );
		}
	)
);
$expected = MHC_Centre::$executions[ $execution ]['list'];

check( 'the list arrives a page at a time, each asked for from where the last ended', 4 === count( $fetches ) && array( '0', '1000' ) === array( $fetches[0]['query']['after'], $fetches[0]['query']['limit'] ) && (string) $first_id === $fetches[1]['query']['after'] && (string) array_keys( $expected )[3] === $fetches[2]['query']['after'] && (string) array_keys( $expected )[6] === $fetches[3]['query']['after'], wp_json_encode( wp_list_pluck( $fetches, 'query' ) ) );
check( 'somebody fetched twice is stored once, and nobody on an overlapping page is lost', 8 === count( $rows ) );
check( 'every request was signed, query string and all', MHC_Centre::$all_signed );
check( 'every recipient is a row of the queue, waiting', 8 === count( $rows ) && 'waiting:8' === mhc_tally( $post_id ), mhc_tally( $post_id ) );

$right = true;

foreach ( $expected as $local => $job ) {
	$held  = Mailhouse_Subscribers::get( $local );
	$right = $right && isset( $rows[ $local ] ) && $rows[ $local ]->remote_job_id === $job && $rows[ $local ]->email === $held->email;
}

check( 'each with the centre’s name for its message, and the address this site holds', $right );
check( 'counted and hashed, the campaign is ready', 'ready' === $row->state && 1 === (int) $row->fetched && 0 === (int) $row->unknown_count, wp_json_encode( array( $row->state, $row->error ) ) );

$said = MHC_Centre::last_said( $execution );
check( 'the centre is told so, with the count and the hash the site arrived at', $said && 'ready' === $said['state'] && 8 === $said['prepared']['recipients'] && $payload['recipients_hash'] === $said['prepared']['hash'] && array() === $said['prepared']['unknown_local_ids'] && $post_id === $said['local_campaign_id'], wp_json_encode( $said ) );
check( 'and takes it as ready', 'ready' === MHC_Centre::$executions[ $execution ]['status'] );
check( 'the site remembers having said it', 'ready' === $row->reported_state && (int) $row->reported_at > 0 );

$reports = count( MHC_Centre::$executions[ $execution ]['events'] );
Mailhouse_Central_Campaigns::network();
Mailhouse_Central_Campaigns::network();
check( 'and does not say it again, or fetch again', count( MHC_Centre::$executions[ $execution ]['events'] ) === $reports && 4 === MHC_Centre::$executions[ $execution ]['fetches'], (string) MHC_Centre::$executions[ $execution ]['fetches'] );

// ---------------------------------------------------------------------------
echo "\nNot before its time\n";

$GLOBALS['mhc_handed'] = array();
Mailhouse_Central_Campaigns::local();
check( 'before the start time nothing is released', 'ready' === mhc_row( $execution )->state && 'waiting:8' === mhc_tally( $post_id ) );
check( 'the queue does not take a waiting row', 0 === mhc_send() && array() === Mailhouse_Queue::claim( 50 ) && 0 === count( $GLOBALS['mhc_handed'] ) );

// What an administrator's Resume does to a campaign: paused rows go back.
// phpcs:ignore WordPress.DB
$wpdb->update(
	$queue_table,
	array(
		'status'       => 'pending',
		'scheduled_at' => mailhouse_now(),
	),
	array(
		'campaign_id' => $post_id,
		'status'      => 'paused',
	)
);
check( 'resuming it from this site releases nothing early', 'waiting:8' === mhc_tally( $post_id ) && 0 === mhc_send() );
// Somebody subscribed here whom the centre never listed for it.
$bystander = mhc_people( 1 );
check( 'it cannot be queued from here as one of this site’s own: nobody the centre did not list is added', 0 === Mailhouse_Queue::enqueue_campaign( $post_id ) && 8 === count( mhc_queue( $post_id ) ) && ! isset( mhc_queue( $post_id )[ $bystander[0] ] ) );
check( 'and trying changes nothing about it', 'scheduled' === get_post_meta( $post_id, '_mailhouse_status', true ) && 'waiting:8' === mhc_tally( $post_id ) );
Mailhouse_Subscribers::delete( $bystander );

// ---------------------------------------------------------------------------
echo "\nAt its time, by this site’s own clock, with the centre away\n";

// Somebody unsubscribes after the list was fixed.
$leaver = $people[2];
Mailhouse_Subscribers::unsubscribe( Mailhouse_Subscribers::get( $leaver ), 'link' );
check( 'somebody who unsubscribes while it waits is taken out, and the row kept', 'skipped' === mhc_queue( $post_id )[ $leaver ]->status && 1 === (int) mhc_queue( $post_id )[ $leaver ]->reported, mhc_tally( $post_id ) );

mhc_set( $execution, array( 'start_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ) );
MHC_Centre::$mode      = 'down';
$GLOBALS['mhc_handed'] = array();

Mailhouse_Central_Campaigns::local();
$row = mhc_row( $execution );
check( 'the campaign starts', 'sending' === $row->state && null !== $row->started_at && 'pending:7 skipped:1' === mhc_tally( $post_id ), mhc_tally( $post_id ) );
check( 'this site shows it as being sent', 'sending' === get_post_meta( $post_id, '_mailhouse_status', true ) );

// Somebody else unsubscribes after it has started, by a route that does not
// touch the queue: the check at the moment of sending is what stops it.
$late = $people[4];
// phpcs:ignore WordPress.DB
$wpdb->update( Mailhouse_Install::table( 'subscribers' ), array( 'status' => 'unsubscribed' ), array( 'id' => $late ) );

$sent = mhc_send();
$rows = mhc_queue( $post_id );
check( 'six messages go, one to each remaining recipient', 6 === $sent && 6 === count( $GLOBALS['mhc_handed'] ) && 'sent:6 skipped:2' === mhc_tally( $post_id ), mhc_tally( $post_id ) );

$once = true;

foreach ( $people as $id ) {
	$held  = Mailhouse_Subscribers::get( $id );
	$count = count( mhc_handed_to( $held->email ) );
	$once  = $once && ( in_array( $id, array( $leaver, $late ), true ) ? 0 === $count : 1 === $count );
}

check( 'exactly once each, and nothing to the two who left', $once );
check( 'the one who left at the last moment is skipped, not sent', 'skipped' === $rows[ $late ]->status && 1 === (int) $rows[ $late ]->reported && '' !== (string) $rows[ $late ]->error );

$reader  = Mailhouse_Subscribers::get( $people[0] );
$message = mhc_handed_to( $reader->email )[0];
check( 'the subject is the centre’s, with this subscriber’s name in it', 'MHC news for ' . $reader->first_name === $message['subject'], $message['subject'] );
check( 'the body is the centre’s, with this subscriber’s name in it', false !== strpos( $message['html'], '<h1>Hello ' . $reader->first_name . '</h1>' ) );
check( 'in this site’s own template', false !== stripos( $message['html'], '<html' ) && false !== strpos( $message['html'], 'What happened this month' ) && strlen( $message['html'] ) > strlen( $row->body_html ) + 200 );
check( 'with this subscriber’s own unsubscribe link', false !== strpos( $message['html'], esc_url( Mailhouse_Optin::unsubscribe_url( $reader ) ) ) || false !== strpos( $message['html'], Mailhouse_Optin::unsubscribe_url( $reader ) ) );
check( 'and the header that puts the button in the mail program', isset( $message['headers']['List-Unsubscribe'] ) && false !== strpos( $message['headers']['List-Unsubscribe'], Mailhouse_Optin::unsubscribe_url( $reader ) ) );
check( 'it carries the centre’s name for the message', isset( $message['headers']['X-MH-Job'] ) && $expected[ $people[0] ] === $message['headers']['X-MH-Job'], wp_json_encode( $message['headers'] ) );
check( 'a shortcode arrives as it was written, and is not run', false !== strpos( $message['html'], '[mhc_code]' ) && false === strpos( $message['html'], 'SHORTCODE-RAN' ) );
check( 'a custom field somebody does not have takes its fallback', false !== strpos( $message['html'], 'From your company.' ), substr( $message['html'], (int) strpos( $message['html'], 'From ' ), 60 ) );

$acme_message = mhc_handed_to( Mailhouse_Subscribers::get( $acme[0] )->email )[0];
check( 'one they do have is used, escaped', false !== strpos( $acme_message['html'], 'From Acme &amp; Sons.' ), substr( $acme_message['html'], (int) strpos( $acme_message['html'], 'From ' ), 60 ) );
check( 'a tag nobody named is left as written, as in any email', false !== strpos( $message['html'], '{{mystery}}' ) );
check( 'there is a text part, with the reader’s name', '' !== $message['text'] && false !== strpos( $message['text'], 'Hello ' . $reader->first_name ) );
check( 'and in it the unsubscribe link, and every other link, written out', false !== strpos( $message['text'], '<' . Mailhouse_Optin::unsubscribe_url( $reader ) . '>' ) && false !== strpos( $message['text'], 'Go <https://example.com/offer>' ), substr( $message['text'], -300 ) );
check( 'with nothing left of a tag, or of the place a link was kept', false === strpos( $message['text'], '%%mailhouse-link' ) && false === strpos( $message['text'], '<p' ) && false === strpos( $message['text'], '</' ) );
check( 'what the transport called each message is kept', 'sg-' . md5( $reader->email ) === $rows[ $people[0] ]->provider_message_id && null !== $rows[ $people[0] ]->sent_at );
check( 'what became of every message is owed to the centre', 8 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue_table} WHERE campaign_id = %d AND reported = 1", $post_id ) ) ); // phpcs:ignore WordPress.DB
check( 'the centre heard nothing of any of this: it was away', array( 'ready' ) === MHC_Centre::said( $execution ) );

Mailhouse_Central_Campaigns::local();
check( 'with nothing left the campaign is finished', 'completed' === mhc_row( $execution )->state && 'sent' === get_post_meta( $post_id, '_mailhouse_status', true ) );

$GLOBALS['mhc_handed'] = array();
mhc_send();
mhc_send();
check( 'and nothing is ever sent twice', 0 === count( $GLOBALS['mhc_handed'] ) );

MHC_Centre::$mode = 'ok';
mhc_set( $execution, array( 'next_at' => 0 ) );
Mailhouse_Central_Campaigns::network();
$said = MHC_Centre::last_said( $execution );
check( 'when the centre is back it is told the campaign is finished', $said && 'completed' === $said['state'] && 0 === $said['pending'] && 8 === $said['stored'], wp_json_encode( $said ) );
check( 'with the count and the hash again, for a centre that never heard it had begun', isset( $said['prepared'] ) && 8 === $said['prepared']['recipients'] && $payload['recipients_hash'] === $said['prepared']['hash'] && 'sending' === MHC_Centre::$executions[ $execution ]['status'], wp_json_encode( $said ) );

// A centre that was away from before the site was ready until after it had finished.
$three                  = mhc_people( 3 );
list( $payload_away )   = mhc_hand_over( $three );
MHC_Centre::$mode       = 'paused';
Mailhouse_Central_Campaigns::network();
MHC_Centre::$mode       = 'ok';
$flaky_reports          = static function ( $pre, $args, $url ) {
	return false !== strpos( $url, '/events' ) ? new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) : $pre;
};
add_filter( 'pre_http_request', $flaky_reports, 5, 3 );
mhc_set( $payload_away['execution_id'], array( 'next_at' => 0 ) );
Mailhouse_Central_Campaigns::network();
mhc_send();
Mailhouse_Central_Campaigns::local();
remove_filter( 'pre_http_request', $flaky_reports, 5 );
check( 'a site whose reports never arrived sends all the same', 'completed' === mhc_row( $payload_away['execution_id'] )->state && array() === MHC_Centre::said( $payload_away['execution_id'] ) && 'sent:3' === mhc_tally( (int) mhc_row( $payload_away['execution_id'] )->post_id ) );
mhc_set( $payload_away['execution_id'], array( 'next_at' => 0 ) );
Mailhouse_Central_Campaigns::network();
check( 'and the first the centre hears is that it has finished, which it takes as having begun', array( 'completed' ) === MHC_Centre::said( $payload_away['execution_id'] ) && 'sending' === MHC_Centre::$executions[ $payload_away['execution_id'] ]['status'], wp_json_encode( MHC_Centre::said( $payload_away['execution_id'] ) ) );

// ---------------------------------------------------------------------------
echo "\nA campaign due already starts in the run that makes it ready\n";

$four                           = mhc_people( 4 );
list( $payload, $row, $post_4 ) = mhc_prepared( $four );
check( 'ready and sending in one run', $row && 'sending' === $row->state && 'pending:4' === mhc_tally( $post_4 ), $row ? $row->state . ' ' . mhc_tally( $post_4 ) : 'no row' );
check( 'the centre heard that it was ready', array( 'ready' ) === MHC_Centre::said( $payload['execution_id'] ), wp_json_encode( MHC_Centre::said( $payload['execution_id'] ) ) );

Mailhouse_Central_Campaigns::network();
check( 'and hears next that it has begun', array( 'ready', 'sending' ) === MHC_Centre::said( $payload['execution_id'] ) && 'sending' === MHC_Centre::$executions[ $payload['execution_id'] ]['status'], wp_json_encode( MHC_Centre::said( $payload['execution_id'] ) ) );

mhc_send();
check( 'and it goes', 'sent:4' === mhc_tally( $post_4 ) );

// ---------------------------------------------------------------------------
echo "\nRecipients this site does not hold\n";

$three   = mhc_people( 3 );
$missing = array( 990000001, 990000002 );

list( $payload, $row, $post_u ) = mhc_prepared( array_merge( $three, $missing ), array( 'start' => time() + HOUR_IN_SECONDS ) );
$said                           = MHC_Centre::last_said( $payload['execution_id'] );

check( 'they are not guessed at: three rows for five recipients', 3 === count( mhc_queue( $post_u ) ) && 2 === (int) $row->unknown_count );
check( 'the list still agrees, so the campaign is ready', 'ready' === $row->state, (string) $row->error );
check( 'and the centre is told who they are', $said && $missing === $said['prepared']['unknown_local_ids'] && 2 === $said['prepared']['unknown'] && 5 === $said['prepared']['recipients'] && 3 === $said['stored'], wp_json_encode( $said ) );

// Somebody deleted between two pages is one more of the same.
$deleted                        = mhc_people( 5 );
list( $payload )                = mhc_hand_over( $deleted, array( 'start' => time() + HOUR_IN_SECONDS ) );
MHC_Centre::$executions[ $payload['execution_id'] ]['page'] = 5;
Mailhouse_Subscribers::delete( array( $deleted[1] ) );
Mailhouse_Central_Campaigns::network();
$row = mhc_row( $payload['execution_id'] );
check( 'somebody deleted before the list arrived is counted as not held', 'ready' === $row->state && 1 === (int) $row->unknown_count && 4 === count( mhc_queue( (int) $row->post_id ) ) );

Mailhouse_Subscribers::delete( array( $deleted[3] ) );
$rows = mhc_queue( (int) $row->post_id );
check( 'somebody deleted afterwards keeps their row, as not sent', isset( $rows[ $deleted[3] ] ) && 'skipped' === $rows[ $deleted[3] ]->status && 1 === (int) $rows[ $deleted[3] ]->reported, mhc_tally( (int) $row->post_id ) );

// ---------------------------------------------------------------------------
echo "\nRecipients that are not the ones the centre fixed\n";

$five            = mhc_people( 5 );
list( $payload ) = mhc_hand_over( $five );

// The list served is one short of the list that was hashed.
$served = MHC_Centre::$executions[ $payload['execution_id'] ]['list'];
unset( $served[ $five[4] ] );
MHC_Centre::$executions[ $payload['execution_id'] ]['list'] = $served;

$GLOBALS['mhc_handed'] = array();
Mailhouse_Central_Campaigns::network();
$row = mhc_row( $payload['execution_id'] );
check( 'a list one short: the campaign is not ready', 'failed' === $row->state && '' !== (string) $row->error, $row->state );
check( 'what was fetched is called off, and kept', 'cancelled:4' === mhc_tally( (int) $row->post_id ), mhc_tally( (int) $row->post_id ) );
check( 'the centre is told, and why', 'failed' === MHC_Centre::last_said( $payload['execution_id'] )['state'] && '' !== MHC_Centre::last_said( $payload['execution_id'] )['error'] && 'blocked' === MHC_Centre::$executions[ $payload['execution_id'] ]['status'] );
mhc_send();
Mailhouse_Central_Campaigns::local();
mhc_send();
check( 'and nothing is sent, then or later', 0 === count( $GLOBALS['mhc_handed'] ) && 'failed' === mhc_row( $payload['execution_id'] )->state );

// The right people, with one job id changed.
$five            = mhc_people( 5 );
list( $payload ) = mhc_hand_over( $five );
MHC_Centre::$executions[ $payload['execution_id'] ]['list'][ $five[1] ] = wp_generate_uuid4();
Mailhouse_Central_Campaigns::network();
$row = mhc_row( $payload['execution_id'] );
check( 'the right people with one message renamed: not ready either', 'failed' === $row->state && 'cancelled:5' === mhc_tally( (int) $row->post_id ) );

// One more than was hashed.
$five            = mhc_people( 5 );
$extra           = mhc_people( 1 );
list( $payload ) = mhc_hand_over( $five );
MHC_Centre::$executions[ $payload['execution_id'] ]['list'][ $extra[0] ] = wp_generate_uuid4();
Mailhouse_Central_Campaigns::network();
$row = mhc_row( $payload['execution_id'] );
check( 'one more person than was fixed: not ready, and nobody is mailed', 'failed' === $row->state && 'cancelled:6' === mhc_tally( (int) $row->post_id ) && 0 === mhc_send() );

// A page that cannot be read.
$five            = mhc_people( 5 );
list( $payload ) = mhc_hand_over( $five );
MHC_Centre::$executions[ $payload['execution_id'] ]['garble'] = true;
Mailhouse_Central_Campaigns::network();
$row = mhc_row( $payload['execution_id'] );
check( 'a page that cannot be read: not ready', 'failed' === $row->state && 0 === count( mhc_queue( (int) $row->post_id ) ) );

// So many strangers that the two ends plainly disagree.
$strangers = range( 980000001, 980000000 + Mailhouse_Central_Campaigns::UNKNOWN_KEPT + 5 );
list( $payload ) = mhc_hand_over( $strangers );
MHC_Centre::$executions[ $payload['execution_id'] ]['page'] = 1000;
Mailhouse_Central_Campaigns::network();
$row = mhc_row( $payload['execution_id'] );
check( 'more strangers than can be named: not ready', 'failed' === $row->state && Mailhouse_Central_Campaigns::UNKNOWN_KEPT + 5 === (int) $row->unknown_count, $row->state . ' ' . $row->unknown_count );

// ---------------------------------------------------------------------------
echo "\nThe centre calls it off before it has begun\n";

$five                           = mhc_people( 5 );
list( $payload, $row, $post_c ) = mhc_prepared( $five, array( 'start' => time() + HOUR_IN_SECONDS ) );
check( 'a campaign is ready and waiting', 'ready' === $row->state && 'waiting:5' === mhc_tally( $post_c ) );

// The site says something, and the answer says what the centre holds.
MHC_Centre::$executions[ $payload['execution_id'] ]['status'] = 'cancelled';
mhc_set( $payload['execution_id'], array( 'reported_state' => '' ) );
Mailhouse_Central_Campaigns::network();
$row = mhc_row( $payload['execution_id'] );
check( 'told so in the answer to its report, the site stops', 'cancelled' === $row->state && 'cancelled:5' === mhc_tally( $post_c ), $row->state . ' ' . mhc_tally( $post_c ) );

mhc_set( $payload['execution_id'], array( 'start_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ) );
Mailhouse_Central_Campaigns::local();
check( 'and nothing starts when its time comes', 0 === mhc_send() && 'cancelled:5' === mhc_tally( $post_c ) );

$five            = mhc_people( 5 );
list( $payload ) = mhc_hand_over( $five );
MHC_Centre::$executions[ $payload['execution_id'] ]['status'] = 'cancelled';
Mailhouse_Central_Campaigns::network();
$row = mhc_row( $payload['execution_id'] );
check( 'told so when it asks for its recipients, it fetches none', 'cancelled' === $row->state && 0 === count( mhc_queue( (int) $row->post_id ) ) );

$five            = mhc_people( 5 );
list( $payload ) = mhc_hand_over( $five );
unset( MHC_Centre::$executions[ $payload['execution_id'] ] );
Mailhouse_Central_Campaigns::network();
$row = mhc_row( $payload['execution_id'] );
check( 'a campaign the centre no longer knows is dropped the same way', 'cancelled' === $row->state && '' !== (string) $row->error );

// Told to wait is not told to stop.
$five            = mhc_people( 5 );
list( $payload ) = mhc_hand_over( $five, array( 'start' => time() + HOUR_IN_SECONDS ) );

foreach ( array( 'paused', 'lost' ) as $mode ) {
	MHC_Centre::$mode = $mode;
	mhc_set( $payload['execution_id'], array( 'next_at' => 0 ) );
	Mailhouse_Central_Campaigns::network();
	$row = mhc_row( $payload['execution_id'] );
	check( "$mode: the campaign is kept, and asked for again later", 'preparing' === $row->state && (int) $row->next_at > time() && '' === (string) $row->error, $row->state . ' ' . $row->error );
}

MHC_Centre::$mode = 'ok';
mhc_set( $payload['execution_id'], array( 'next_at' => 0 ) );
Mailhouse_Central_Campaigns::network();
check( 'and is ready once the centre answers', 'ready' === mhc_row( $payload['execution_id'] )->state );

// A report answered by something that is not the centre is said again.
$five                           = mhc_people( 5 );
list( $payload )                = mhc_hand_over( $five, array( 'start' => time() + HOUR_IN_SECONDS ) );
$lost_reports                   = static function ( $pre, $args, $url ) {
	return false !== strpos( $url, '/events' )
		? array(
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
			'body'     => 'Not Found',
			'response' => array(
				'code'    => 404,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		)
		: $pre;
};
add_filter( 'pre_http_request', $lost_reports, 5, 3 );
Mailhouse_Central_Campaigns::network();
remove_filter( 'pre_http_request', $lost_reports, 5 );
$row = mhc_row( $payload['execution_id'] );
check( 'a report that did not reach the centre is not taken as heard, nor as a reason to stop', 'ready' === $row->state && '' === $row->reported_state && 'waiting:5' === mhc_tally( (int) $row->post_id ), $row->state . ' / ' . $row->reported_state );
mhc_set( $payload['execution_id'], array( 'next_at' => 0 ) );
Mailhouse_Central_Campaigns::network();
check( 'and is said again', 'ready' === mhc_row( $payload['execution_id'] )->reported_state && array( 'ready' ) === MHC_Centre::said( $payload['execution_id'] ) );

// Once it has begun it is this site's to finish.
$five                           = mhc_people( 5 );
list( $payload, $row, $post_g ) = mhc_prepared( $five );
MHC_Centre::$executions[ $payload['execution_id'] ]['status'] = 'cancelled';
mhc_set( $payload['execution_id'], array( 'reported_state' => '' ) );
Mailhouse_Central_Campaigns::network();
check( 'one that has begun is not stopped by an answer', 'sending' === mhc_row( $payload['execution_id'] )->state && 'pending:5' === mhc_tally( $post_g ) );

// A centre that no longer knows a campaign changes nothing about one that
// is under way, or over.
$forgotten = static function () use ( $payload ) {
	unset( MHC_Centre::$executions[ $payload['execution_id'] ] );
	mhc_set(
		$payload['execution_id'],
		array(
			'reported_state' => '',
			'reported_at'    => 0,
			'next_at'        => 0,
		)
	);
	Mailhouse_Central_Campaigns::network();

	return mhc_row( $payload['execution_id'] );
};

$row = $forgotten();
check( 'a campaign under way when the centre forgets it carries on', 'sending' === $row->state && '' === (string) $row->error && 'pending:5' === mhc_tally( $post_g ), $row->state . ' ' . $row->error . ' ' . mhc_tally( $post_g ) );
mhc_send();
Mailhouse_Central_Campaigns::local();
$row = $forgotten();
check( 'and one that is over stays over, and is not relabelled', 'completed' === $row->state && '' === (string) $row->error && 'sent:5' === mhc_tally( $post_g ), $row->state . ' ' . $row->error . ' ' . mhc_tally( $post_g ) );

// ---------------------------------------------------------------------------
echo "\nWhen the transport does not say\n";

Mailhouse_Settings::set( array( 'fallback_mailer' => 'postmark' ) );
Mailhouse_Settings::flush();

$six                            = mhc_people( 6 );
list( $payload, $row, $post_t ) = mhc_prepared( $six );
$emails                         = array();

foreach ( $six as $id ) {
	$emails[ $id ] = Mailhouse_Subscribers::get( $id )->email;
}

$GLOBALS['mhc_at_handover'] = array();
$GLOBALS['mhc_transport']   = static function ( $to, $via ) use ( $emails, $six, $queue_table ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB
	$GLOBALS['mhc_at_handover'][ $to ] = $wpdb->get_row( $wpdb->prepare( "SELECT status, attempt_started_at, claim_token FROM {$queue_table} WHERE email = %s AND remote_job_id IS NOT NULL ORDER BY id DESC LIMIT 1", $to ) );

	if ( 'postmark' === $via ) {
		return 'accept';
	}

	$map = array(
		$emails[ $six[0] ] => 'accept',
		$emails[ $six[1] ] => 'unclear',
		$emails[ $six[2] ] => 'refuse',
		$emails[ $six[3] ] => 'later',
		$emails[ $six[4] ] => 'unreachable',
		$emails[ $six[5] ] => 'accept',
	);

	return $map[ $to ];
};
$GLOBALS['mhc_handed']    = array();

mhc_send();
$rows = mhc_queue( $post_t );

$written = true;

foreach ( $emails as $address ) {
	$seen    = isset( $GLOBALS['mhc_at_handover'][ $address ] ) ? $GLOBALS['mhc_at_handover'][ $address ] : null;
	$written = $written && $seen && 'sending' === $seen->status && null !== $seen->attempt_started_at && null !== $seen->claim_token;
}

check( 'before each is handed to the transport, its row says so', $written, wp_json_encode( $GLOBALS['mhc_at_handover'] ) );
check( 'a message taken is sent', 'sent' === $rows[ $six[0] ]->status && 'sent' === $rows[ $six[5] ]->status );
check( 'one that may or may not have gone is held as uncertain', 'uncertain' === $rows[ $six[1] ]->status && 1 === (int) $rows[ $six[1] ]->attempts && '' !== (string) $rows[ $six[1] ]->error && 1 === (int) $rows[ $six[1] ]->reported, wp_json_encode( $rows[ $six[1] ] ) );
check( 'one refused for good has failed, at once', 'failed' === $rows[ $six[2] ]->status && 1 === (int) $rows[ $six[2] ]->attempts && 1 === (int) $rows[ $six[2] ]->reported );
check( 'one refused for now waits to be tried again', 'pending' === $rows[ $six[3] ]->status && 1 === (int) $rows[ $six[3] ]->attempts && strtotime( $rows[ $six[3] ]->scheduled_at . ' UTC' ) > time() + 60 && 0 === (int) $rows[ $six[3] ]->reported );
check( 'so does one for which the transport could not be reached', 'pending' === $rows[ $six[4] ]->status && 1 === (int) $rows[ $six[4] ]->attempts );
check( 'the backup transport was not used for any of them', 0 === count( array_filter( $GLOBALS['mhc_handed'], static function ( $m ) { return 'postmark' === $m['via']; } ) ) && 6 === count( $GLOBALS['mhc_handed'] ), wp_json_encode( wp_list_pluck( $GLOBALS['mhc_handed'], 'via' ) ) ); // phpcs:ignore
check( 'no row is left marked as being sent', null === $rows[ $six[1] ]->claim_token && null === $rows[ $six[1] ]->attempt_started_at && null === $rows[ $six[3] ]->attempt_started_at );

Mailhouse_Central_Campaigns::local();
check( 'the campaign is not finished while something is to be tried again', 'sending' === mhc_row( $payload['execution_id'] )->state );

// Twice more, each when due.
$GLOBALS['mhc_handed'] = array();
mhc_make_due( $post_t );
mhc_send();
$rows = mhc_queue( $post_t );
check( 'tried again when due, and only what was refused for now', 2 === count( $GLOBALS['mhc_handed'] ) && 2 === (int) $rows[ $six[3] ]->attempts && 'pending' === $rows[ $six[3] ]->status, wp_json_encode( wp_list_pluck( $GLOBALS['mhc_handed'], 'to' ) ) );

mhc_make_due( $post_t );
mhc_send();
$rows = mhc_queue( $post_t );
check( 'after three refusals it has failed', 'failed' === $rows[ $six[3] ]->status && 3 === (int) $rows[ $six[3] ]->attempts && 'failed' === $rows[ $six[4] ]->status && 1 === (int) $rows[ $six[4] ]->reported );
check( 'the uncertain one was handed to the transport once, and never again', 1 === $GLOBALS['mhc_attempts'][ $emails[ $six[1] ] ] && 'uncertain' === $rows[ $six[1] ]->status );
check( 'the one refused for good once; the two refused for now, three times each', 1 === $GLOBALS['mhc_attempts'][ $emails[ $six[2] ] ] && 3 === $GLOBALS['mhc_attempts'][ $emails[ $six[3] ] ] && 3 === $GLOBALS['mhc_attempts'][ $emails[ $six[4] ] ], wp_json_encode( $GLOBALS['mhc_attempts'] ) );

$GLOBALS['mhc_handed'] = array();
mhc_make_due( $post_t );
mhc_send();
mhc_send();
check( 'nothing more is attempted', 0 === count( $GLOBALS['mhc_handed'] ) && 'failed:3 sent:2 uncertain:1' === mhc_tally( $post_t ), mhc_tally( $post_t ) );

Mailhouse_Central_Campaigns::local();
check( 'and the campaign is finished, with the uncertain one still held', 'completed' === mhc_row( $payload['execution_id'] )->state );

// This site's own campaign, in the same circumstances, is as it always was.
$own_people = mhc_people( 2 );
$own_post   = wp_insert_post(
	array(
		'post_type'    => Mailhouse_Emails::POST_TYPE,
		'post_status'  => 'publish',
		'post_title'   => 'MHC own campaign',
		'post_content' => '<p>Own body [mhc_code] for {{first_name}}</p>',
	)
);
update_post_meta( $own_post, '_mhc_test', 1 );
update_post_meta( $own_post, '_mailhouse_type', 'newsletter' );
update_post_meta( $own_post, '_mailhouse_subject', 'MHC own subject' );

Mailhouse_Queue::enqueue_one( $own_post, $own_people[0], Mailhouse_Subscribers::get( $own_people[0] )->email );
Mailhouse_Queue::enqueue_one( $own_post, $own_people[1], Mailhouse_Subscribers::get( $own_people[1] )->email );

$own_email                = Mailhouse_Subscribers::get( $own_people[0] )->email;
$GLOBALS['mhc_transport'] = static function ( $to, $via ) use ( $own_email ) {
	return 'sendgrid' === $via && $to === $own_email ? 'unclear' : 'accept';
};
$GLOBALS['mhc_handed']    = array();

mhc_send();
$rows = mhc_queue( $own_post );
check( 'this site’s own campaign still falls back to the backup transport', 'sent' === $rows[ $own_people[0] ]->status && 1 === count( mhc_handed_to( $own_email, 'postmark' ) ), mhc_tally( $own_post ) );
check( 'its messages carry no job of the centre’s', ! isset( mhc_handed_to( Mailhouse_Subscribers::get( $own_people[1] )->email )[0]['headers']['X-MH-Job'] ) && 0 === (int) $rows[ $own_people[1] ]->reported );
check( 'and its shortcodes are run', false !== strpos( mhc_handed_to( Mailhouse_Subscribers::get( $own_people[1] )->email )[0]['html'], 'SHORTCODE-RAN' ) );
$own_message = mhc_handed_to( Mailhouse_Subscribers::get( $own_people[1] )->email )[0];
check( 'and its text part has its unsubscribe link written out too', false !== strpos( $own_message['text'], '<' . Mailhouse_Optin::unsubscribe_url( Mailhouse_Subscribers::get( $own_people[1] ) ) . '>' ), substr( $own_message['text'], -200 ) );

Mailhouse_Settings::set( array( 'fallback_mailer' => '' ) );
Mailhouse_Settings::flush();

// Without a backup, an unclear result for this site's own mail is retried,
// as it was before any of this existed.
$own_people = mhc_people( 1 );
Mailhouse_Queue::enqueue_one( $own_post, $own_people[0], Mailhouse_Subscribers::get( $own_people[0] )->email );
$GLOBALS['mhc_transport'] = static function () {
	return 'unclear';
};
mhc_send();
$rows = mhc_queue( $own_post );
check( 'this site’s own mail is still retried after an unclear result', 'pending' === $rows[ $own_people[0] ]->status && 1 === (int) $rows[ $own_people[0] ]->attempts );
// phpcs:ignore WordPress.DB
$wpdb->delete( $queue_table, array( 'campaign_id' => $own_post, 'status' => 'pending' ) );

$GLOBALS['mhc_transport'] = null;

// ---------------------------------------------------------------------------
echo "\nA process that dies part-way\n";

$five                           = mhc_people( 5 );
list( $payload, $row, $post_d ) = mhc_prepared( $five );
$long_ago                       = gmdate( 'Y-m-d H:i:s', time() - Mailhouse_Queue::STALE_AFTER - 60 );

// Claimed by a run that died: two after handing the message to the
// transport, one before it got that far.
$claimed = Mailhouse_Queue::claim( 3 );
$begun   = array( (int) $claimed[0]->id, (int) $claimed[1]->id );
$never   = (int) $claimed[2]->id;

// phpcs:disable WordPress.DB
$wpdb->query( "UPDATE {$queue_table} SET attempt_started_at = '" . mailhouse_now() . "' WHERE id IN (" . implode( ',', $begun ) . ')' );

check( 'handing back a batch does not hand back what was begun', 1 === Mailhouse_Queue::release( $claimed[0]->claim_token ) && 'pending:3 sending:2' === mhc_tally( $post_d ), mhc_tally( $post_d ) );

Mailhouse_Queue::reap_stale();
check( 'a row begun a moment ago is left alone: its run may be alive', 'pending:3 sending:2' === mhc_tally( $post_d ) );

$wpdb->query( "UPDATE {$queue_table} SET claimed_at = '{$long_ago}' WHERE id IN (" . implode( ',', $begun ) . ')' );
// One claimed long ago and never begun.
$wpdb->query( "UPDATE {$queue_table} SET status = 'sending', claim_token = 'dead', claimed_at = '{$long_ago}' WHERE id = {$never}" );
// phpcs:enable

$GLOBALS['mhc_handed'] = array();
Mailhouse_Queue::reap_stale();
$rows = array();

foreach ( mhc_queue( $post_d ) as $queued ) {
	$rows[ (int) $queued->id ] = $queued;
}

check( 'a message that was being handed over when its run died is held as uncertain', 'uncertain' === $rows[ $begun[0] ]->status && 'uncertain' === $rows[ $begun[1] ]->status && 1 === (int) $rows[ $begun[0] ]->reported && '' !== (string) $rows[ $begun[0] ]->error && null === $rows[ $begun[0] ]->claim_token );
check( 'one that was claimed and never begun goes back to be sent', 'pending' === $rows[ $never ]->status && null === $rows[ $never ]->claim_token );

mhc_send();
check( 'the uncertain ones are not sent again; the other three go', 3 === count( $GLOBALS['mhc_handed'] ) && 'sent:3 uncertain:2' === mhc_tally( $post_d ), mhc_tally( $post_d ) );

// This site's own campaign: a stale row goes back, as it always has.
$own_people = mhc_people( 1 );
Mailhouse_Queue::enqueue_one( $own_post, $own_people[0], Mailhouse_Subscribers::get( $own_people[0] )->email );
// phpcs:ignore WordPress.DB
$wpdb->query( $wpdb->prepare( "UPDATE {$queue_table} SET status = 'sending', claim_token = 'dead', claimed_at = %s, attempt_started_at = %s WHERE campaign_id = %d AND subscriber_id = %d", $long_ago, $long_ago, $own_post, $own_people[0] ) );
Mailhouse_Queue::reap_stale();
check( 'a stale row of this site’s own campaign goes back to be sent, as before', 'pending' === mhc_queue( $own_post )[ $own_people[0] ]->status );
// phpcs:ignore WordPress.DB
$wpdb->delete( $queue_table, array( 'campaign_id' => $own_post, 'status' => 'pending' ) );

// ---------------------------------------------------------------------------
echo "\nThe deadline\n";

$five                           = mhc_people( 5 );
list( $payload, $row, $post_x ) = mhc_prepared( $five );
Mailhouse_Settings::set( array( 'batch_size' => 2 ) );
Mailhouse_Settings::flush();
mhc_send();
check( 'a campaign is part-way through', 'pending:3 sent:2' === mhc_tally( $post_x ), mhc_tally( $post_x ) );

// The deadline passes between a batch being claimed and its being sent.
$claimed = Mailhouse_Queue::claim( 1 );
mhc_set( $payload['execution_id'], array( 'deadline_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ) );
// phpcs:ignore WordPress.DB
$wpdb->update( $queue_table, array( 'status' => 'pending', 'claim_token' => null, 'claimed_at' => null ), array( 'id' => (int) $claimed[0]->id ) );

$GLOBALS['mhc_handed'] = array();
mhc_send();
check( 'a message whose turn comes after the deadline is not sent', 0 === count( $GLOBALS['mhc_handed'] ) && 'cancelled:2 pending:1 sent:2' === mhc_tally( $post_x ), mhc_tally( $post_x ) );

Mailhouse_Central_Campaigns::local();
$row = mhc_row( $payload['execution_id'] );
check( 'at the deadline what is left is called off, and kept', 'cancelled:3 sent:2' === mhc_tally( $post_x ) && 'completed' === $row->state, mhc_tally( $post_x ) . ' ' . $row->state );
check( 'and the campaign says how many were not sent', false !== strpos( (string) $row->error, 'with 3 messages not sent' ), (string) $row->error );
check( 'each is owed to the centre as not sent', 3 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue_table} WHERE campaign_id = %d AND status = 'cancelled' AND reported = 1", $post_x ) ) ); // phpcs:ignore WordPress.DB

mhc_send();
check( 'nothing is sent afterwards', 0 === count( $GLOBALS['mhc_handed'] ) );

Mailhouse_Settings::set( array( 'batch_size' => 50 ) );
Mailhouse_Settings::flush();

// A site that was away: ready, never started, and back after the deadline.
$five                           = mhc_people( 5 );
list( $payload, $row, $post_y ) = mhc_prepared( $five, array( 'start' => time() + HOUR_IN_SECONDS ) );
mhc_set(
	$payload['execution_id'],
	array(
		'start_at'    => gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS ),
		'deadline_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
	)
);
$GLOBALS['mhc_handed'] = array();
Mailhouse_Central_Campaigns::local();
mhc_send();
$row = mhc_row( $payload['execution_id'] );
check( 'a site back after the deadline sends none of it', 0 === count( $GLOBALS['mhc_handed'] ) && 'cancelled' === $row->state && 'cancelled:5' === mhc_tally( $post_y ), $row->state . ' ' . mhc_tally( $post_y ) );

// A site that was away while still fetching.
$five            = mhc_people( 5 );
list( $payload ) = mhc_hand_over( $five );
mhc_set( $payload['execution_id'], array( 'deadline_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ) );
Mailhouse_Central_Campaigns::local();
$fetches = MHC_Centre::$executions[ $payload['execution_id'] ]['fetches'];
Mailhouse_Central_Campaigns::network();
$row = mhc_row( $payload['execution_id'] );
check( 'one still being prepared at its deadline is dropped, and fetches nothing more', 'cancelled' === $row->state && MHC_Centre::$executions[ $payload['execution_id'] ]['fetches'] === $fetches );
check( 'and the centre is told', 'cancelled' === MHC_Centre::last_said( $payload['execution_id'] )['state'] );

// ---------------------------------------------------------------------------
echo "\nThis site keeps the last word\n";

$five                           = mhc_people( 5 );
list( $payload, $row, $post_a ) = mhc_prepared( $five );
Mailhouse_Settings::set( array( 'batch_size' => 2 ) );
Mailhouse_Settings::flush();
mhc_send();
Mailhouse_Settings::set( array( 'batch_size' => 50 ) );
Mailhouse_Settings::flush();

Mailhouse_Central_Campaigns::cancel_locally( $post_a );
$row = mhc_row( $payload['execution_id'] );
check( 'called off here, what has not gone is kept as not sent', 'cancelled' === $row->state && 'cancelled:3 sent:2' === mhc_tally( $post_a ), mhc_tally( $post_a ) );

$GLOBALS['mhc_handed'] = array();
mhc_send();
check( 'and nothing more goes', 0 === count( $GLOBALS['mhc_handed'] ) );

Mailhouse_Central_Campaigns::network();
check( 'the centre is told it was called off here', 'cancelled' === MHC_Centre::last_said( $payload['execution_id'] )['state'] && false !== strpos( MHC_Centre::last_said( $payload['execution_id'] )['error'], 'administrator' ) );

// ---------------------------------------------------------------------------
echo "\nA copy of the site, and a site switched off\n";

$five                           = mhc_people( 5 );
list( $payload, $row, $post_k ) = mhc_prepared( $five, array( 'start' => time() + HOUR_IN_SECONDS ) );
mhc_set( $payload['execution_id'], array( 'start_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ) );

// The same database, answering at another address.
$GLOBALS['mhc_home'] = 'https://staging.example.test';
Mailhouse_Central_Identity::flush();

$GLOBALS['mhc_handed'] = array();
Mailhouse_Central_Campaigns::local();
mhc_send();
check( 'a copy of the site at another address starts nothing', 'ready' === mhc_row( $payload['execution_id'] )->state && 'waiting:5' === mhc_tally( $post_k ) && 0 === count( $GLOBALS['mhc_handed'] ), mhc_tally( $post_k ) );

// One that was already being sent when the copy was made.
// phpcs:ignore WordPress.DB
$wpdb->update( $queue_table, array( 'status' => 'pending', 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'campaign_id' => $post_k ) );
mhc_send();
$rows = mhc_queue( $post_k );
check( 'and sends nothing of one already begun: the rows wait', 0 === count( $GLOBALS['mhc_handed'] ) && 'pending:5' === mhc_tally( $post_k ) && strtotime( reset( $rows )->scheduled_at . ' UTC' ) > time() + 60 && 0 === (int) reset( $rows )->attempts, mhc_tally( $post_k ) );

unset( $GLOBALS['mhc_home'] );
Mailhouse_Central_Identity::flush();
Mailhouse_Central_Identity::at_registered_address();
mhc_make_due( $post_k );
// phpcs:ignore WordPress.DB
$wpdb->update( $queue_table, array( 'status' => 'waiting' ), array( 'campaign_id' => $post_k ) );
Mailhouse_Central_Campaigns::local();
mhc_send();
check( 'the real site, at its own address, sends it', 'sent:5' === mhc_tally( $post_k ) && 5 === count( $GLOBALS['mhc_handed'] ), mhc_tally( $post_k ) . ' ' . Mailhouse_Central_Identity::status() );

// ---------------------------------------------------------------------------
echo "\nDisconnecting\n";

$five                           = mhc_people( 5 );
list( $payload, $row, $post_z ) = mhc_prepared( $five, array( 'start' => time() + HOUR_IN_SECONDS ) );
$five_b                         = mhc_people( 5 );
list( $payload_b, , $post_w )   = mhc_prepared( $five_b );
Mailhouse_Settings::set( array( 'batch_size' => 2 ) );
Mailhouse_Settings::flush();
mhc_send();
Mailhouse_Settings::set( array( 'batch_size' => 50 ) );
Mailhouse_Settings::flush();

Mailhouse_Central_Identity::disconnect();
Mailhouse_Central_Identity::flush();

$GLOBALS['mhc_handed'] = array();
mhc_send();
Mailhouse_Central_Campaigns::local();
mhc_send();

check( 'a campaign that was waiting is called off', 'cancelled' === mhc_row( $payload['execution_id'] )->state && 'cancelled:5' === mhc_tally( $post_z ), mhc_tally( $post_z ) );
check( 'one part-way through stops where it was', 'cancelled' === mhc_row( $payload_b['execution_id'] )->state && 'cancelled:3 sent:2' === mhc_tally( $post_w ), mhc_tally( $post_w ) );
check( 'and a disconnected site sends nothing for the centre', 0 === count( $GLOBALS['mhc_handed'] ) );
check( 'the records stay among this site’s emails', null !== get_post( $post_z ) && null !== get_post( $post_w ) );

mailhouse_test_results();
