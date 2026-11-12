<?php
/**
 * The connector: this site's link to a Mailhouse Central installation.
 *
 * Optional, and inert until a site is registered. A site that has never
 * connected runs exactly the code it ran before this module existed: the
 * module adds a tab to the settings screen and nothing else -- no scheduled
 * work, no requests, no writes.
 *
 * A connected site is still its own master. It reports in once a minute and
 * the centre can see it; if the centre is slow, down or gone, the site
 * carries on sending its own mail and collecting its own subscribers, and
 * simply tries again later.
 *
 * `define( 'MAILHOUSE_CENTRAL_DISABLED', true );` in wp-config.php stops the
 * connector entirely, whatever the database says.
 * `define( 'MAILHOUSE_CENTRAL_READ_ONLY', true );` leaves it reporting and
 * makes it refuse everything the centre asks it to change.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * The module: the scheduled tick, the heartbeat, the kill switch.
 */
class Mailhouse_Central {

	/**
	 * Seconds to wait for the centre during a scheduled tick. Short on
	 * purpose: the tick shares a cron run with the send queue, and a slow
	 * centre must not hold up this site's own mail.
	 */
	const TICK_TIMEOUT = 8;

	/**
	 * A heartbeat is not sent more often than this, however often cron runs.
	 */
	const MIN_INTERVAL = 45;

	/**
	 * Why changes are being made right now: `local`, or `command:<id>`
	 * while something the centre asked for is being done.
	 *
	 * @var string
	 */
	protected static $origin = 'local';

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		if ( is_admin() ) {
			new Mailhouse_Central_Screen();
		}

		// An unconnected site, or one with the connector switched off, gets
		// no scheduled work at all.
		if ( self::disabled() || '' === Mailhouse_Central_Identity::status() ) {
			return;
		}

		// From here on, what changes on this site is written down for the centre.
		Mailhouse_Central_Outbox::listen();

		// Before the queue (priority 10), so that what the centre says is
		// known before anything is sent.
		add_action( 'mailhouse_process_queue', array( __CLASS__, 'tick' ), 5 );

		// And after it, so that this site's own mail never waits on telling
		// the centre what has changed.
		add_action( 'mailhouse_process_queue', array( __CLASS__, 'push' ), 20 );
	}

	/**
	 * Why changes are being made right now.
	 *
	 * @return string `local`, or `command:<id>`.
	 */
	public static function origin() {
		return self::$origin;
	}

	/**
	 * Do something because the centre asked for it.
	 *
	 * Every change recorded while the callback runs is marked as the
	 * command's doing, which is how the centre tells its own instruction
	 * coming back from something a person did on the site.
	 *
	 * @param string   $command_id The command's id.
	 * @param callable $callback   What to do.
	 * @return mixed Whatever the callback returns.
	 */
	public static function with_origin( $command_id, $callback ) {
		$before       = self::$origin;
		$command_id   = strtolower( (string) $command_id );
		self::$origin = preg_match( '/^[0-9a-f-]{36}$/', $command_id ) ? 'command:' . $command_id : 'local';

		try {
			return call_user_func( $callback );
		} finally {
			self::$origin = $before;
		}
	}

	/**
	 * The part of a scheduled run that sends the outbox.
	 *
	 * @return void
	 */
	public static function push() {
		if ( self::disabled() ) {
			return;
		}

		// Send what is waiting; then do a piece of the backfill or the
		// comparison, which writes more; and so on until there is nothing
		// to do or the time is up. One time box for all of it.
		$deadline = time() + Mailhouse_Central_Sync::TIME_BOX;

		do {
			Mailhouse_Central_Sync::push( max( 1, $deadline - time() ) );
		} while ( time() < $deadline && Mailhouse_Central_Reconcile::work( $deadline ) );
	}

	/**
	 * Whether the connector is switched off in wp-config.php.
	 *
	 * @return bool
	 */
	public static function disabled() {
		return defined( 'MAILHOUSE_CENTRAL_DISABLED' ) && MAILHOUSE_CENTRAL_DISABLED;
	}

	/**
	 * One scheduled run.
	 *
	 * @return void
	 */
	public static function tick() {
		if ( self::disabled() ) {
			return;
		}

		$status = Mailhouse_Central_Identity::status();

		if ( '' === $status || 'gone' === $status ) {
			return;
		}

		// A copy of a connected site stops here, before sending anything.
		if ( ! Mailhouse_Central_Identity::at_registered_address() ) {
			return;
		}

		$now   = time();
		$state = Mailhouse_Central_Identity::state();

		if ( $now < (int) $state['next_attempt_at'] || $now - (int) $state['last_heartbeat_at'] < self::MIN_INTERVAL ) {
			Mailhouse_Central_Identity::update( array( 'last_tick_at' => $now ) );
			return;
		}

		Mailhouse_Central_Identity::update( array( 'last_tick_at' => $now ) );

		// What the centre has asked for is done here, before the queue runs:
		// somebody the centre has just unsubscribed is not sent the next message.
		if ( true === self::heartbeat( self::TICK_TIMEOUT ) ) {
			Mailhouse_Central_Commands::work();
		}
	}

	/**
	 * Report in, and take note of the answer.
	 *
	 * @param int $timeout Seconds to wait.
	 * @return true|WP_Error
	 */
	public static function heartbeat( $timeout = Mailhouse_Central_Client::TIMEOUT ) {
		// Switched off means switched off: not even a failure is recorded.
		if ( self::disabled() ) {
			return new WP_Error( 'mailhouse_central_disabled', __( 'The connector is switched off in wp-config.php (MAILHOUSE_CENTRAL_DISABLED).', 'mailhouse' ) );
		}

		$answer = Mailhouse_Central_Client::signed( 'POST', '/api/v1/connector/heartbeat', Mailhouse_Central_Status::report(), $timeout );
		$now    = time();

		if ( is_wp_error( $answer ) ) {
			return self::failed( $answer->get_error_message() );
		}

		$body = is_array( $answer['body'] ) ? $answer['body'] : array();

		switch ( $answer['status'] ) {
			case 200:
				Mailhouse_Central_Identity::update(
					array(
						'status'            => 'connected',
						'centre_status'     => isset( $body['status'] ) && 'paused' === $body['status'] ? 'paused' : 'connected',
						'suspended_reason'  => '',
						'last_heartbeat_at' => $now,
						// The centre says when it would like the two copies compared.
						'want_reconcile'    => (bool) Mailhouse_Central_Identity::get( 'want_reconcile' ) || ( isset( $body['want'] ) && is_array( $body['want'] ) && in_array( 'reconcile', $body['want'], true ) ),
						// And how many commands it is holding for this site.
						'commands_pending'  => isset( $body['commands_pending'] ) ? max( 0, (int) $body['commands_pending'] ) : 0,
						'clock_offset'      => Mailhouse_Central_Client::offset_from( $body ),
						'last_error'        => '',
						'failures'          => 0,
						'next_attempt_at'   => 0,
					)
				);

				// The centre moves a website to a new epoch when its
				// sequence has to start again.
				if ( isset( $body['epoch'] ) ) {
					Mailhouse_Central_Sync::new_epoch( (int) $body['epoch'] );
				}

				return true;

			case 423:
				// Refused for now. Keep asking, slowly: the centre is the
				// one that can lift this, and a heartbeat is how the site
				// finds out that it has.
				$reason = isset( $body['reason'] ) && is_string( $body['reason'] ) ? sanitize_key( $body['reason'] ) : 'suspended';

				Mailhouse_Central_Identity::update(
					array(
						'status'           => 'suspended',
						'suspended_reason' => $reason,
						'last_error'       => '',
						'failures'         => 0,
						'next_attempt_at'  => $now + 15 * MINUTE_IN_SECONDS,
					)
				);

				return new WP_Error( 'mailhouse_central_suspended', self::suspension( $reason ) );

			case 410:
				Mailhouse_Central_Identity::update(
					array(
						'status'     => 'gone',
						'last_error' => '',
					)
				);

				return new WP_Error( 'mailhouse_central_gone', __( 'The centre says this website has been removed there.', 'mailhouse' ) );

			case 401:
				return self::failed( __( 'The centre did not accept this site’s signature.', 'mailhouse' ) );

			case 426:
				return self::failed( __( 'The centre no longer speaks this version of the protocol. Update the plugin.', 'mailhouse' ) );

			case 429:
				return self::failed( __( 'The centre asked this site to slow down.', 'mailhouse' ) );
		}

		/* translators: %d: HTTP status. */
		return self::failed( sprintf( __( 'The centre answered with status %d.', 'mailhouse' ), (int) $answer['status'] ) );
	}

	/**
	 * Note a failed attempt and decide when to try again.
	 *
	 * One minute, then two, four, eight, and every fifteen from then on: a
	 * centre that is down costs this site one short request at widening
	 * intervals, and nothing else.
	 *
	 * @param string $message What went wrong.
	 * @return WP_Error
	 */
	protected static function failed( $message ) {
		$failures = (int) Mailhouse_Central_Identity::get( 'failures' ) + 1;
		$wait     = min( 15 * MINUTE_IN_SECONDS, MINUTE_IN_SECONDS * pow( 2, min( $failures, 5 ) - 1 ) );

		Mailhouse_Central_Identity::update(
			array(
				'last_error'      => substr( (string) $message, 0, 500 ),
				'failures'        => $failures,
				'next_attempt_at' => time() + (int) $wait,
			)
		);

		return new WP_Error( 'mailhouse_central_failed', $message );
	}

	/**
	 * Why the centre is refusing this site, in words.
	 *
	 * @param string $reason Reason code from the centre.
	 * @return string
	 */
	public static function suspension( $reason ) {
		switch ( $reason ) {
			case 'key_revoked':
				return __( 'This site’s key has been revoked at the centre. Disconnect here, then connect again with a new token.', 'mailhouse' );
			case 'disconnected':
				return __( 'This website has been disconnected at the centre. Disconnect here too; to reconnect, use a new token.', 'mailhouse' );
			case 'site_url_mismatch':
				return __( 'The centre has this website registered at a different address from the one this site is at.', 'mailhouse' );
		}

		return __( 'The centre has suspended this website.', 'mailhouse' );
	}
}
