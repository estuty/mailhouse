<?php
/**
 * The connector to Mailhouse Central: what became of a message after this
 * site's transport took it.
 *
 * A transport's "yes" is not an arrival. Where this site sends through a
 * provider that calls back (`Mailhouse_Webhooks`), the provider says
 * afterwards that a message was delivered, that it bounced, or that its
 * reader marked it as spam, and names the message by the id it gave when
 * it took it. That id is kept on the row of the queue the message was sent
 * from. So a report can be matched to its message, and for a newsletter
 * sent for the centre the centre can be told which one.
 *
 * Nothing here changes what this site does with a report. A bounce still
 * retires the address and a complaint still unsubscribes, exactly as
 * before and whether or not the site is connected: that is
 * `Mailhouse_Webhooks`. This only writes down, beside the message, what
 * was said of it.
 *
 * Only messages of the centre's campaigns are written to. A message of one
 * of this site's own is left as it was.
 *
 * Matched by the provider's id for the message and by nothing else. A
 * report with no id, or with one this site does not hold, is about no
 * message as far as this goes; and one that names an address other than
 * the one the message went to is not about that message.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * What a provider says of a message for the centre, after taking it.
 */
class Mailhouse_Central_Delivery {

	/**
	 * What this site announces, and what the centre must say it takes
	 * before it is told any of this.
	 */
	const CAPABILITY = 'delivery.events.v1';

	/**
	 * The option that remembers when each provider last called.
	 */
	const HEARD_OPTION = 'mailhouse_central_heard';

	/**
	 * A provider that has not called for this long is not one that calls:
	 * thirty days, in seconds.
	 */
	const HEARD_WITHIN = 2592000;

	/**
	 * When a provider last called is not written down more often than
	 * this, in seconds. A provider calls once for every message.
	 */
	const HEARD_EVERY = 300;

	/**
	 * Register the hooks. Called for a connected site only.
	 *
	 * @return void
	 */
	public static function listen() {
		add_action( 'mailhouse_webhook_received', array( __CLASS__, 'heard' ), 10, 1 );
		add_action( 'mailhouse_webhook_event', array( __CLASS__, 'record' ), 10, 1 );
	}

	/**
	 * Whether the centre has said it takes what a provider says of a
	 * message. An older centre has no word for it, and would refuse a
	 * whole report that used one.
	 *
	 * @return bool
	 */
	public static function accepted() {
		return in_array( self::CAPABILITY, (array) Mailhouse_Central_Identity::get( 'centre_accepts' ), true );
	}

	// ---- whether a provider calls at all ---------------------------------------------------

	/**
	 * When each provider last called, by its id.
	 *
	 * @return array<string,int> Unix times.
	 */
	public static function heard_from() {
		$heard = get_option( self::HEARD_OPTION, array() );
		$known = Mailhouse_Webhooks::providers();
		$clean = array();

		foreach ( is_array( $heard ) ? $heard : array() as $provider => $when ) {
			if ( is_string( $provider ) && isset( $known[ $provider ] ) && (int) $when > 0 ) {
				$clean[ $provider ] = (int) $when;
			}
		}

		return $clean;
	}

	/**
	 * The provider whose call is being read at this moment, or nothing.
	 *
	 * @var string
	 */
	private static $speaking = '';

	/**
	 * Note that a provider has called with something that could be read.
	 *
	 * @param string $provider Provider id.
	 * @return void
	 */
	public static function heard( $provider ) {
		$provider = sanitize_key( (string) $provider );

		if ( ! array_key_exists( $provider, Mailhouse_Webhooks::providers() ) ) {
			self::$speaking = '';
			return;
		}

		// Whose call this is, for what it goes on to say of a message.
		self::$speaking = $provider;

		$heard = self::heard_from();
		$now   = time();

		if ( isset( $heard[ $provider ] ) && $now - $heard[ $provider ] < self::HEARD_EVERY && $heard[ $provider ] <= $now ) {
			return;
		}

		$heard[ $provider ] = $now;

		update_option( self::HEARD_OPTION, $heard, false );
	}

	/**
	 * The providers that have called lately.
	 *
	 * @return string[] Provider ids, in order.
	 */
	public static function callers() {
		$callers = array();

		foreach ( self::heard_from() as $provider => $when ) {
			if ( time() - $when <= self::HEARD_WITHIN ) {
				$callers[] = $provider;
			}
		}

		sort( $callers, SORT_STRING );

		return $callers;
	}

	/**
	 * The transport this site's newsletters leave by.
	 *
	 * @return string Provider id.
	 */
	public static function transport() {
		$campaign = (string) mailhouse_option( 'campaign_mailer', '' );

		return '' !== $campaign ? $campaign : (string) mailhouse_option( 'mailer', 'php' );
	}

	/**
	 * Whether this site can expect to hear what becomes of a newsletter's
	 * messages: its newsletters leave through a provider that calls back,
	 * and that provider has called lately.
	 *
	 * That a provider can call is not that it does. Somebody has to give
	 * it this site's address, and nothing here can see whether they have,
	 * except that it then calls.
	 *
	 * @return bool
	 */
	public static function reports() {
		return in_array( self::transport(), self::callers(), true );
	}

	// ---- writing down what was said of a message -------------------------------------------

	/**
	 * Write down, beside the message it is about, what a provider has
	 * said: delivered, bounced (and how), or marked as spam.
	 *
	 * The first delivery stands, and the first complaint. A bounce that
	 * may pass stands until one that will not; the address being given up
	 * on stands.
	 *
	 * @param array $event A normalised event: email, type, reason,
	 *                     message_id, event_id, occurred_at.
	 * @return bool Whether anything was written.
	 */
	public static function record( $event ) {
		global $wpdb;

		if ( ! is_array( $event ) || empty( $event['type'] ) || empty( $event['message_id'] ) || ! is_string( $event['message_id'] ) ) {
			return false;
		}

		$type = (string) $event['type'];

		if ( ! in_array( $type, array( 'delivered', 'hard', 'soft', 'complaint' ), true ) ) {
			return false;
		}

		// Only the provider the newsletters leave by can say what became of
		// one. The door a provider calls at takes any provider's name, and
		// some of them sign nothing: a call made in another's name, to
		// avoid the signature this site's own provider's calls must carry,
		// says nothing about a message of the centre's.
		if ( '' === self::$speaking || self::$speaking !== self::transport() ) {
			return false;
		}

		$queue = Mailhouse_Queue::table();

		// The newest, should a provider ever give one id twice. Only a
		// message this site holds as sent: one it is still to send has
		// nothing to be said of it.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, email FROM {$queue} WHERE provider_message_id = %s AND remote_job_id IS NOT NULL AND status = 'sent' ORDER BY id DESC LIMIT 1", substr( $event['message_id'], 0, 191 ) ) );

		if ( ! $row ) {
			return false;
		}

		// A report that names somebody else is not about this message.
		// One whose reader has since been forgotten has no address left to
		// compare, and is matched by its id alone.
		$named = isset( $event['email'] ) ? mailhouse_clean_email( $event['email'] ) : '';

		if ( '' !== (string) $row->email && '' !== $named && strtolower( (string) $row->email ) !== $named ) {
			return false;
		}

		// When it happened, by the provider; never later than now.
		$when = isset( $event['occurred_at'] ) ? (int) $event['occurred_at'] : 0;
		$when = gmdate( 'Y-m-d H:i:s', $when > 0 && $when <= time() ? $when : time() );

		// Owed to the centre only if the centre can be told. What is noted
		// while it cannot is made owed when it can: catch_up().
		$owe = self::accepted() ? 1 : 0;
		$id  = (int) $row->id;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		switch ( $type ) {
			case 'delivered':
				$done = $wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET reported = IF(%d = 1 AND reported = 2, 3, reported), delivered_at = %s WHERE id = %d AND delivered_at IS NULL", $owe, $when, $id ) );
				break;

			case 'complaint':
				$done = $wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET reported = IF(%d = 1 AND reported = 2, 3, reported), complained_at = %s WHERE id = %d AND complained_at IS NULL", $owe, $when, $id ) );
				break;

			default:
				$reason = Mailhouse_Central_Campaigns::said( isset( $event['reason'] ) ? (string) $event['reason'] : '' );
				// A bounce that will not pass replaces one that might; nothing replaces it.
				$unless = 'hard' === $type ? "( bounce_class IS NULL OR bounce_class <> 'hard' )" : 'bounced_at IS NULL';

				$done = $wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET reported = IF(%d = 1 AND reported = 2, 3, reported), bounced_at = %s, bounce_class = %s, bounce_reason = NULLIF(%s, '') WHERE id = %d AND {$unless}", $owe, $when, $type, $reason, $id ) );
				break;
		}
		// phpcs:enable

		return (bool) $done;
	}

	/**
	 * Make owed to the centre whatever was noted while it could not be
	 * told. Run once, when the centre first says that it can.
	 *
	 * @return int Messages made owed.
	 */
	public static function catch_up() {
		global $wpdb;

		$queue = Mailhouse_Queue::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( "UPDATE {$queue} SET reported = 3 WHERE reported = 2 AND remote_job_id IS NOT NULL AND ( delivered_at IS NOT NULL OR bounced_at IS NOT NULL OR complained_at IS NOT NULL )" );
	}

	/**
	 * How many of a campaign's messages a provider has said something of.
	 *
	 * `soft` is those turned away for now and not since delivered.
	 *
	 * @param int $post_id A campaign's post.
	 * @return array{delivered:int,bounced:int,soft:int,complained:int}
	 */
	public static function counts( $post_id ) {
		global $wpdb;

		$queue = Mailhouse_Queue::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(delivered_at IS NOT NULL AND ( bounce_class IS NULL OR bounce_class <> 'hard' )) AS delivered, SUM(bounce_class = 'hard') AS bounced, SUM(bounce_class = 'soft' AND delivered_at IS NULL) AS soft, SUM(complained_at IS NOT NULL) AS complained FROM {$queue} WHERE campaign_id = %d", (int) $post_id ) );

		return array(
			'delivered'  => $row ? (int) $row->delivered : 0,
			'bounced'    => $row ? (int) $row->bounced : 0,
			'soft'       => $row ? (int) $row->soft : 0,
			'complained' => $row ? (int) $row->complained : 0,
		);
	}

	/**
	 * In a sentence, for this site's administrator: whether a provider
	 * calls, and what follows from it.
	 *
	 * @return string
	 */
	public static function summary() {
		$names   = Mailhouse_Webhooks::providers();
		$heard   = self::heard_from();
		$via     = self::transport();
		$label   = isset( $names[ $via ] ) ? $names[ $via ] : '';
		$callers = self::callers();

		if ( '' === $label ) {
			return __( 'newsletters here do not leave through a provider that says what became of a message: the centre is told what the transport said, and nothing after', 'mailhouse' );
		}

		if ( ! in_array( $via, $callers, true ) ) {
			return sprintf(
				/* translators: %s: a provider's name. */
				__( 'nothing heard from %s in the last 30 days: give it this site’s address (Settings, Bounces and complaints) and the centre is told what became of each message', 'mailhouse' ),
				$label
			);
		}

		return sprintf(
			/* translators: 1: a provider's name, 2: a length of time, e.g. "2 minutes". */
			__( '%1$s last called %2$s ago: the centre is told what became of each message', 'mailhouse' ),
			$label,
			human_time_diff( (int) $heard[ $via ], time() )
		);
	}
}
