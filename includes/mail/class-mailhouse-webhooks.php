<?php
/**
 * Bounce and complaint webhooks.
 *
 * Sending to an address that no longer exists is the single fastest way to
 * ruin a domain's reputation, and the provider is the only party that knows it
 * happened -- the bounce arrives at them, hours or days after the send. So they
 * have to be able to tell us, and we have to act on it without anybody
 * reading a mailbox.
 *
 * What arrives here is untrusted input from the public internet. Two things
 * guard it: a secret in the URL, which every provider can carry, and a
 * signature over the body, which only some can. Where a signature is
 * available, configure it -- without one, anybody who learns the URL can mark
 * your subscribers as bounced, which is a quiet way of cutting people off the
 * list.
 *
 * Deliberately a query-string endpoint rather than a pretty URL: it needs no
 * rewrite rules, so it cannot break on a site with plain permalinks or one
 * whose rules were never flushed.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receives provider event callbacks and acts on them.
 */
class Mailhouse_Webhooks {

	/**
	 * Option holding the URL secret.
	 */
	const KEY_OPTION = 'mailhouse_webhook_key';

	/**
	 * Providers that can report events.
	 *
	 * @return array<string,string>
	 */
	public static function providers() {
		return array(
			'sendgrid' => 'SendGrid',
			'mailgun'  => 'Mailgun',
			'brevo'    => __( 'Brevo (formerly Sendinblue)', 'mailhouse' ),
			'postmark' => 'Postmark',
			'resend'   => 'Resend',
		);
	}

	/**
	 * The providers actually in use, across both mail streams.
	 *
	 * Site mail and campaigns can go out through different transports, and a
	 * bounce reported to one provider says nothing about the other. Both
	 * endpoints therefore need configuring -- and the newsletter stream is the
	 * one bounces matter most on.
	 *
	 * @return array<string,string> Provider id to label, in use and able to report.
	 */
	public static function active_providers() {
		$providers = self::providers();

		$ids = array( (string) mailhouse_option( 'mailer', 'php' ) );

		// Empty, and absent entirely, on a site that sends everything through
		// one transport.
		$campaign = (string) mailhouse_option( 'campaign_mailer', '' );

		if ( '' !== $campaign ) {
			$ids[] = $campaign;
		}

		$active = array();

		foreach ( array_unique( $ids ) as $id ) {
			if ( isset( $providers[ $id ] ) ) {
				$active[ $id ] = $providers[ $id ];
			}
		}

		return $active;
	}

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'handle' ), 1 );
	}

	/**
	 * The URL secret, created on first use.
	 *
	 * Generated lazily rather than at activation so a site upgrading from a
	 * version without webhooks gets one without needing a schema bump.
	 *
	 * @return string
	 */
	public static function key() {
		$key = get_option( self::KEY_OPTION );

		if ( ! $key ) {
			$key = mailhouse_token( 16 );
			update_option( self::KEY_OPTION, $key, false );
		}

		return $key;
	}

	/**
	 * Replace the secret, invalidating every URL already configured.
	 *
	 * @return string The new secret.
	 */
	public static function rotate_key() {
		$key = mailhouse_token( 16 );

		update_option( self::KEY_OPTION, $key, false );

		return $key;
	}

	/**
	 * The endpoint to paste into a provider's dashboard.
	 *
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function url( $provider ) {
		return add_query_arg(
			array(
				'mailhouse_webhook' => $provider,
				'k'                 => self::key(),
			),
			home_url( '/' )
		);
	}

	/**
	 * Answer an incoming callback.
	 *
	 * @return void
	 */
	public function handle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a provider callback authenticated by secret and signature, not a form.
		if ( empty( $_GET['mailhouse_webhook'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$provider = sanitize_key( wp_unslash( $_GET['mailhouse_webhook'] ) );

		if ( ! array_key_exists( $provider, self::providers() ) ) {
			$this->respond( 404, 'unknown provider' );
		}

		// The secret may travel in the query string or in a header. A header
		// keeps it out of access logs, which is worth having where the
		// provider allows one.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$supplied = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';

		if ( '' === $supplied && isset( $_SERVER['HTTP_X_MAILHOUSE_KEY'] ) ) {
			$supplied = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MAILHOUSE_KEY'] ) );
		}

		if ( ! self::authenticates( $supplied ) ) {
			$this->respond( 403, 'bad key' );
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		// A GET with the right secret is how somebody checks the URL works
		// before saving it in a dashboard.
		if ( 'POST' !== $method ) {
			$this->respond( 200, 'mailhouse webhook ready' );
		}

		$body = (string) file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// A body far larger than any provider sends is not a batch of events.
		if ( strlen( $body ) > 2 * MB_IN_BYTES ) {
			$this->respond( 413, 'body too large' );
		}

		$result = self::process( $provider, $body );

		if ( is_wp_error( $result ) ) {
			$this->respond( 'mailhouse_signature' === $result->get_error_code() ? 403 : 400, $result->get_error_message() );
		}

		// 200 whatever we made of it. A provider that gets an error retries,
		// and retrying will not make an event we do not recognise
		// recognisable -- it only risks the endpoint being switched off for
		// failing too often.
		$this->respond(
			200,
			sprintf( 'received %d, applied %d', $result['received'], $result['applied'] )
		);
	}

	/**
	 * Whether a supplied secret matches.
	 *
	 * @param string $supplied Secret from the request.
	 * @return bool
	 */
	public static function authenticates( $supplied ) {
		return '' !== (string) $supplied && hash_equals( self::key(), (string) $supplied );
	}

	/**
	 * Verify, parse and act on a callback body.
	 *
	 * Separate from the request plumbing so it can be driven directly -- by a
	 * test, or by anything else that already has the payload in hand.
	 *
	 * @param string $provider Provider id.
	 * @param string $body     Raw JSON body.
	 * @return array{received:int,applied:int}|WP_Error
	 */
	public static function process( $provider, $body ) {
		if ( ! self::verify( $provider, $body ) ) {
			return new WP_Error( 'mailhouse_signature', 'signature rejected' );
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'mailhouse_json', 'body is not JSON' );
		}

		$events  = self::events( $provider, $payload );
		$applied = 0;

		foreach ( $events as $event ) {
			if ( self::apply( $event ) ) {
				$applied++;
			}
		}

		return array(
			'received' => count( $events ),
			'applied'  => $applied,
		);
	}

	/**
	 * Check the signature, where the provider offers one and it is configured.
	 *
	 * Returns true when there is nothing to check: the URL secret has already
	 * been verified by then, and refusing events from a provider that cannot
	 * sign them would mean no bounce handling at all for Brevo and Postmark.
	 *
	 * @param string $provider Provider id.
	 * @param string $body     Raw request body.
	 * @return bool
	 */
	protected static function verify( $provider, $body ) {
		switch ( $provider ) {
			case 'mailgun':
				return self::verify_mailgun( $body );

			case 'resend':
				return self::verify_svix( $body );

			case 'sendgrid':
				return self::verify_sendgrid( $body );
		}

		return true;
	}

	/**
	 * Mailgun signs a timestamp and token with the webhook signing key.
	 *
	 * @param string $body Raw body.
	 * @return bool
	 */
	protected static function verify_mailgun( $body ) {
		$secret = (string) mailhouse_option( 'webhook_mailgun_key', '' );

		if ( '' === $secret ) {
			return true;
		}

		$payload   = json_decode( $body, true );
		$signature = isset( $payload['signature'] ) && is_array( $payload['signature'] ) ? $payload['signature'] : array();

		foreach ( array( 'timestamp', 'token', 'signature' ) as $field ) {
			if ( empty( $signature[ $field ] ) || ! is_scalar( $signature[ $field ] ) ) {
				return false;
			}
		}

		if ( ! self::timestamp_is_recent( (int) $signature['timestamp'] ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $signature['timestamp'] . $signature['token'], $secret );

		return hash_equals( $expected, (string) $signature['signature'] );
	}

	/**
	 * Resend signs with Svix: id, timestamp and body, keyed by a whsec secret.
	 *
	 * @param string $body Raw body.
	 * @return bool
	 */
	protected static function verify_svix( $body ) {
		$secret = (string) mailhouse_option( 'webhook_resend_secret', '' );

		if ( '' === $secret ) {
			return true;
		}

		$id        = isset( $_SERVER['HTTP_SVIX_ID'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SVIX_ID'] ) ) : '';
		$timestamp = isset( $_SERVER['HTTP_SVIX_TIMESTAMP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SVIX_TIMESTAMP'] ) ) : '';
		$header    = isset( $_SERVER['HTTP_SVIX_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SVIX_SIGNATURE'] ) ) : '';

		if ( '' === $id || '' === $timestamp || '' === $header ) {
			return false;
		}

		if ( ! self::timestamp_is_recent( (int) $timestamp ) ) {
			return false;
		}

		// The secret is base64 behind a whsec_ prefix.
		$raw = base64_decode( preg_replace( '/^whsec_/', '', $secret ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw ) {
			return false;
		}

		$expected = base64_encode( hash_hmac( 'sha256', $id . '.' . $timestamp . '.' . $body, $raw, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		// The header carries one or more space-separated version,signature
		// pairs, because a secret being rotated means two are valid at once.
		foreach ( explode( ' ', $header ) as $candidate ) {
			$parts = explode( ',', $candidate, 2 );

			if ( 2 === count( $parts ) && 'v1' === $parts[0] && hash_equals( $expected, $parts[1] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * SendGrid signs with ECDSA over the timestamp and body.
	 *
	 * @param string $body Raw body.
	 * @return bool
	 */
	protected static function verify_sendgrid( $body ) {
		$public_key = (string) mailhouse_option( 'webhook_sendgrid_key', '' );

		if ( '' === $public_key ) {
			return true;
		}

		if ( ! function_exists( 'openssl_verify' ) ) {
			// Configured but uncheckable. Refusing is the safe answer, and the
			// settings screen says so.
			return false;
		}

		$signature = isset( $_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE'] ) )
			: '';

		$timestamp = isset( $_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP'] ) )
			: '';

		if ( '' === $signature || '' === $timestamp ) {
			return false;
		}

		if ( ! self::timestamp_is_recent( (int) $timestamp ) ) {
			return false;
		}

		$decoded = base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $decoded ) {
			return false;
		}

		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( $public_key, 64, "\n" ) . "-----END PUBLIC KEY-----\n";

		return 1 === openssl_verify( $timestamp . $body, $decoded, $pem, OPENSSL_ALGO_SHA256 );
	}

	/**
	 * Whether a signed timestamp is close enough to now.
	 *
	 * Without this check a signature captured once could be replayed for ever.
	 *
	 * @param int $timestamp Unix timestamp from the provider.
	 * @return bool
	 */
	protected static function timestamp_is_recent( $timestamp ) {
		/**
		 * Filter how much clock skew a signed webhook may carry.
		 *
		 * @param int $tolerance Seconds.
		 */
		$tolerance = (int) apply_filters( 'mailhouse_webhook_tolerance', 15 * MINUTE_IN_SECONDS );

		return $timestamp > 0 && abs( time() - $timestamp ) <= $tolerance;
	}

	/**
	 * Turn a provider's payload into events this plugin understands.
	 *
	 * Normalised to: email, type (hard, soft, complaint, unsubscribe,
	 * delivered) and a human-readable reason. Anything else -- opens, clicks,
	 * deferrals, processed notices -- is dropped here, because acting on it
	 * either duplicates our own tracking or punishes an address for a
	 * temporary condition the provider is still retrying.
	 *
	 * @param string $provider Provider id.
	 * @param array  $payload  Decoded body.
	 * @return array<int,array{email:string,type:string,reason:string}>
	 */
	protected static function events( $provider, $payload ) {
		$events = array();

		switch ( $provider ) {
			case 'sendgrid':
				// SendGrid posts a batch.
				foreach ( $payload as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}

					$event  = isset( $item['event'] ) ? strtolower( (string) $item['event'] ) : '';
					$reason = isset( $item['reason'] ) ? (string) $item['reason'] : '';
					$type   = '';

					switch ( $event ) {
						case 'bounce':
							// SendGrid calls a soft bounce a "blocked" bounce.
							$type = ( isset( $item['type'] ) && 'blocked' === $item['type'] ) ? 'soft' : 'hard';
							break;

						case 'blocked':
							$type = 'soft';
							break;

						case 'dropped':
							// Dropped means SendGrid refused to even try,
							// almost always because the address is already
							// suppressed on their side.
							$type = 'hard';
							break;

						case 'spamreport':
							$type = 'complaint';
							break;

						case 'unsubscribe':
						case 'group_unsubscribe':
							$type = 'unsubscribe';
							break;

						case 'delivered':
							$type = 'delivered';
							break;
					}

					if ( $type && ! empty( $item['email'] ) ) {
						$events[] = array(
							'email'  => (string) $item['email'],
							'type'   => $type,
							'reason' => $reason,
						);
					}
				}
				break;

			case 'mailgun':
				$data = isset( $payload['event-data'] ) && is_array( $payload['event-data'] ) ? $payload['event-data'] : array();

				$event  = isset( $data['event'] ) ? strtolower( (string) $data['event'] ) : '';
				$status = isset( $data['delivery-status'] ) && is_array( $data['delivery-status'] ) ? $data['delivery-status'] : array();
				$reason = '';

				foreach ( array( 'message', 'description', 'code' ) as $field ) {
					if ( ! empty( $status[ $field ] ) && is_scalar( $status[ $field ] ) ) {
						$reason = (string) $status[ $field ];
						break;
					}
				}

				if ( '' === $reason && ! empty( $data['reason'] ) && is_scalar( $data['reason'] ) ) {
					$reason = (string) $data['reason'];
				}

				$type = '';

				switch ( $event ) {
					case 'failed':
						$type = ( isset( $data['severity'] ) && 'temporary' === $data['severity'] ) ? 'soft' : 'hard';
						break;

					case 'rejected':
						$type = 'hard';
						break;

					case 'complained':
						$type = 'complaint';
						break;

					case 'unsubscribed':
						$type = 'unsubscribe';
						break;

					case 'delivered':
						$type = 'delivered';
						break;
				}

				if ( $type && ! empty( $data['recipient'] ) ) {
					$events[] = array(
						'email'  => (string) $data['recipient'],
						'type'   => $type,
						'reason' => $reason,
					);
				}
				break;

			case 'brevo':
				$event = isset( $payload['event'] ) ? strtolower( (string) $payload['event'] ) : '';
				$type  = '';

				switch ( $event ) {
					case 'hard_bounce':
					case 'invalid_email':
					case 'blocked':
						$type = 'hard';
						break;

					case 'soft_bounce':
					case 'error':
						$type = 'soft';
						break;

					case 'spam':
						$type = 'complaint';
						break;

					case 'unsubscribed':
						$type = 'unsubscribe';
						break;

					case 'delivered':
						$type = 'delivered';
						break;
				}

				if ( $type && ! empty( $payload['email'] ) ) {
					$events[] = array(
						'email'  => (string) $payload['email'],
						'type'   => $type,
						'reason' => isset( $payload['reason'] ) ? (string) $payload['reason'] : '',
					);
				}
				break;

			case 'postmark':
				$record = isset( $payload['RecordType'] ) ? (string) $payload['RecordType'] : '';
				$type   = '';
				$reason = '';

				foreach ( array( 'Description', 'Details' ) as $field ) {
					if ( ! empty( $payload[ $field ] ) && is_scalar( $payload[ $field ] ) ) {
						$reason = (string) $payload[ $field ];
						break;
					}
				}

				if ( 'Bounce' === $record ) {
					$soft = array( 'softbounce', 'transient', 'dnserror', 'smtpapierror', 'dmarcpolicy' );
					$kind = isset( $payload['Type'] ) ? strtolower( (string) $payload['Type'] ) : '';

					// Postmark deactivating the address is the strongest
					// signal it has; it overrides the bounce classification.
					$type = ( ! empty( $payload['Inactive'] ) || ! in_array( $kind, $soft, true ) ) ? 'hard' : 'soft';
				} elseif ( 'SpamComplaint' === $record ) {
					$type = 'complaint';
				} elseif ( 'SubscriptionChange' === $record ) {
					$type = ! empty( $payload['SuppressSending'] ) ? 'unsubscribe' : '';
				} elseif ( 'Delivery' === $record ) {
					$type = 'delivered';
				}

				if ( $type && ! empty( $payload['Email'] ) ) {
					$events[] = array(
						'email'  => (string) $payload['Email'],
						'type'   => $type,
						'reason' => $reason,
					);
				}
				break;

			case 'resend':
				$event = isset( $payload['type'] ) ? strtolower( (string) $payload['type'] ) : '';
				$data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
				$type  = '';
				$reason = '';

				if ( 'email.bounced' === $event ) {
					$bounce = isset( $data['bounce'] ) && is_array( $data['bounce'] ) ? $data['bounce'] : array();
					$kind   = isset( $bounce['type'] ) ? strtolower( (string) $bounce['type'] ) : '';
					$type   = 'transient' === $kind ? 'soft' : 'hard';
					$reason = isset( $bounce['message'] ) ? (string) $bounce['message'] : '';
				} elseif ( 'email.complained' === $event ) {
					$type = 'complaint';
				} elseif ( 'email.delivered' === $event ) {
					$type = 'delivered';
				}

				// Resend reports the whole recipient list on one event.
				$recipients = isset( $data['to'] ) ? (array) $data['to'] : array();

				foreach ( $recipients as $recipient ) {
					if ( $type && is_string( $recipient ) ) {
						$events[] = array(
							'email'  => $recipient,
							'type'   => $type,
							'reason' => $reason,
						);
					}
				}
				break;
		}

		/**
		 * Filter the normalised events parsed from a provider callback.
		 *
		 * @param array  $events   Normalised events.
		 * @param string $provider Provider id.
		 * @param array  $payload  Decoded body.
		 */
		return apply_filters( 'mailhouse_webhook_events', $events, $provider, $payload );
	}

	/**
	 * Act on one event.
	 *
	 * @param array $event email, type, reason.
	 * @return bool Whether it changed anything.
	 */
	protected static function apply( $event ) {
		$email      = mailhouse_clean_email( $event['email'] );
		$subscriber = $email ? Mailhouse_Subscribers::get_by_email( $email ) : null;

		/**
		 * Fires for every event, whether or not the address is on the list.
		 *
		 * @param array       $event      Normalised event.
		 * @param object|null $subscriber Matching subscriber, if any.
		 */
		do_action( 'mailhouse_webhook_event', $event, $subscriber );

		// Transactional mail goes to people who were never subscribers. A
		// bounce for one of them is still worth logging, but there is no list
		// membership to change.
		if ( ! $subscriber ) {
			self::log( $event, 0 );

			return false;
		}

		$id = (int) $subscriber->id;

		// Providers retry a callback they could not deliver, and some send the
		// same event more than once regardless. Every branch below therefore
		// stops when there is nothing left to change: otherwise a retry
		// fortnight keeps bumping the bounce counter and filling the log with
		// copies of one bounce.
		switch ( $event['type'] ) {
			case 'hard':
				if ( 'bounced' === $subscriber->status ) {
					return false;
				}

				Mailhouse_Subscribers::update( $id, array( 'bounces' => (int) $subscriber->bounces + 1 ) );
				Mailhouse_Subscribers::mark_bounced( Mailhouse_Subscribers::get( $id ), $event['reason'] );
				break;

			case 'complaint':
				// Somebody pressing "this is spam" must come off the list
				// immediately, from any status. Continuing to send after that
				// is what gets a domain blocked outright.
				if ( ! Mailhouse_Subscribers::unsubscribe( $subscriber, 'complaint' ) ) {
					return false;
				}
				break;

			case 'unsubscribe':
				if ( ! Mailhouse_Subscribers::unsubscribe( $subscriber, 'provider' ) ) {
					return false;
				}
				break;

			case 'soft':
				// Already retired: counting further soft bounces against the
				// address would achieve nothing.
				if ( 'bounced' === $subscriber->status ) {
					return false;
				}

				$limit   = (int) mailhouse_option( 'soft_bounce_limit', 3 );
				$bounces = (int) $subscriber->bounces + 1;

				Mailhouse_Subscribers::update( $id, array( 'bounces' => $bounces ) );

				// A full mailbox is temporary; a mailbox that is always full
				// is not. The count is cleared again the next time the
				// provider confirms a delivery.
				if ( $limit > 0 && $bounces >= $limit ) {
					Mailhouse_Subscribers::mark_bounced( Mailhouse_Subscribers::get( $id ), $event['reason'] );
				}
				break;

			case 'delivered':
				if ( (int) $subscriber->bounces > 0 ) {
					Mailhouse_Subscribers::update( $id, array( 'bounces' => 0 ) );
					break;
				}

				// Nothing to record: a delivery that confirms what we already
				// assumed is not worth a log row per message.
				return false;
		}

		self::log( $event, $id );

		return true;
	}

	/**
	 * Record the event in the email log.
	 *
	 * The log already answers "what happened to mail sent to this address",
	 * and a bounce arriving three hours later is part of that answer.
	 *
	 * @param array $event         Normalised event.
	 * @param int   $subscriber_id Subscriber id, or 0.
	 * @return void
	 */
	protected static function log( $event, $subscriber_id ) {
		if ( 'delivered' === $event['type'] ) {
			return;
		}

		$status = 'complaint' === $event['type'] ? 'complaint' : ( 'unsubscribe' === $event['type'] ? 'unsubscribed' : 'bounced' );

		$subjects = array(
			'hard'        => __( 'Hard bounce reported by the provider', 'mailhouse' ),
			'soft'        => __( 'Soft bounce reported by the provider', 'mailhouse' ),
			'complaint'   => __( 'Marked as spam by the recipient', 'mailhouse' ),
			'unsubscribe' => __( 'Unsubscribed at the provider', 'mailhouse' ),
		);

		Mailhouse_Log::set_context( array( 'subscriber_id' => $subscriber_id ) );

		Mailhouse_Log::record(
			$status,
			array(
				'to'      => array( $event['email'] ),
				'subject' => isset( $subjects[ $event['type'] ] ) ? $subjects[ $event['type'] ] : '',
			),
			$event['reason']
		);

		Mailhouse_Log::set_context( array() );
	}

	/**
	 * Answer the provider and stop.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $message Short message, for a human reading the provider's
	 *                        delivery log.
	 * @return void
	 */
	protected function respond( $code, $message ) {
		nocache_headers();
		status_header( $code );
		header( 'Content-Type: application/json; charset=utf-8' );

		echo wp_json_encode(
			array(
				'ok'      => $code >= 200 && $code < 300,
				'message' => $message,
			)
		);

		exit;
	}
}
