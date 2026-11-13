<?php
/**
 * Commands: what the centre asks this site to do.
 *
 * A command is a request, not an order. Each one names something this
 * plugin already does for itself -- unsubscribe somebody, rename a tag --
 * and is carried out by the same function an administrator's click would
 * reach, under the same rules. The connector adds no second way of
 * changing a subscriber.
 *
 * **Nothing here can make anybody subscribed.** There is no command for
 * it. Somebody who has unsubscribed or bounced comes back only by their own
 * act, on this site.
 *
 * Before anything is done, a command must be: signed by a platform key
 * this site pinned when it registered; addressed to this website; later in
 * the centre's sequence than everything already dealt with; and not past
 * its expiry. One that is not signed, or not for this site, is not
 * answered at all -- there is nobody trustworthy to answer.
 *
 * Each command is dealt with at most once. It is written down before it is
 * carried out, so one that arrives twice is answered from the record, and
 * one that was interrupted half-way is reported as failed rather than
 * tried again.
 *
 * `define( 'MAILHOUSE_CENTRAL_READ_ONLY', true );` in wp-config.php makes
 * this site refuse every command: it goes on reporting, and nothing here
 * can be changed from the centre.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collecting commands, checking them, carrying them out, answering.
 */
class Mailhouse_Central_Commands {

	/**
	 * What this site announces when it will take commands.
	 */
	const CAPABILITY = 'subscribers.commands.v1';

	/**
	 * Commands asked for at a time.
	 */
	const BATCH = 20;

	/**
	 * Seconds one scheduled run may spend on commands. It shares the run
	 * with this site's own mail queue, which comes after it.
	 */
	const TIME_BOX = 10;

	/**
	 * The longest first or last name the subscribers table holds.
	 */
	const NAME_LENGTH = 100;

	/**
	 * Seconds after which a command begun and not finished is taken to
	 * have been interrupted. Far longer than carrying one out takes.
	 */
	const INTERRUPTED_AFTER = 300;

	/**
	 * How long an answered command is remembered, so that a late copy of
	 * it is recognised. Longer than any command stays valid.
	 */
	const KEEP = 400 * DAY_IN_SECONDS;

	/**
	 * Every type this site will carry out. The list is fixed: carry_out()
	 * names the one function for each, and nothing a command says chooses
	 * what is called.
	 *
	 * @var string[]
	 */
	const TYPES = array(
		'subscriber.create',
		'subscriber.update_profile',
		'subscriber.set_tags',
		'subscriber.unsubscribe',
		'subscriber.mark_bounced',
		'subscriber.reconfirm',
		'subscriber.delete',
		'tag.create',
		'tag.rename',
		'tag.delete',
		'site.reconcile',
	);

	/**
	 * The inbox table.
	 *
	 * @return string
	 */
	public static function table() {
		return Mailhouse_Install::table( 'inbox' );
	}

	/**
	 * Whether this site takes commands at all.
	 *
	 * @return bool
	 */
	public static function accepting() {
		if ( defined( 'MAILHOUSE_CENTRAL_READ_ONLY' ) && MAILHOUSE_CENTRAL_READ_ONLY ) {
			return false;
		}

		/**
		 * Filter whether this site carries out what the centre asks of it.
		 *
		 * @param bool $accepting Whether commands are accepted.
		 */
		return (bool) apply_filters( 'mailhouse_central_accept_commands', Mailhouse_Central_Protocol::available() );
	}

	/**
	 * The part of a scheduled run that deals with commands: answer what is
	 * owed, collect what is waiting, carry it out, answer that.
	 *
	 * @param int|null $deadline When to stop, as a Unix time.
	 * @param int      $timeout  Seconds to wait for each answer.
	 * @return array{applied:int,rejected:int,failed:int,error:string}
	 */
	public static function work( $deadline = null, $timeout = Mailhouse_Central::TICK_TIMEOUT ) {
		$done = array(
			'applied'  => 0,
			'rejected' => 0,
			'failed'   => 0,
			'error'    => '',
		);

		if ( Mailhouse_Central::disabled() || ! self::accepting() || 'connected' !== Mailhouse_Central_Identity::status() ) {
			return $done;
		}

		if ( 'paused' === Mailhouse_Central_Identity::get( 'centre_status' ) || ! Mailhouse_Central_Identity::at_registered_address() ) {
			return $done;
		}

		$deadline = null === $deadline ? time() + self::TIME_BOX : (int) $deadline;

		self::recover();

		// Answers the centre has not had yet, from a run that was cut short.
		if ( ! self::acknowledge( $timeout ) ) {
			$done['error'] = (string) Mailhouse_Central_Identity::get( 'command_error' );

			return $done;
		}

		while ( (int) Mailhouse_Central_Identity::get( 'commands_pending' ) > 0 && time() < $deadline ) {
			$answer = Mailhouse_Central_Client::signed(
				'GET',
				'/api/v1/connector/commands',
				null,
				$timeout,
				array(
					array( 'after_seq', (string) (int) Mailhouse_Central_Identity::get( 'inbox_seq' ) ),
					array( 'limit', (string) self::BATCH ),
				)
			);

			if ( is_wp_error( $answer ) || 200 !== $answer['status'] || ! is_array( $answer['body'] ) || ! isset( $answer['body']['commands'] ) || ! is_array( $answer['body']['commands'] ) ) {
				// The next report in will say whether anything is still waiting.
				$done['error'] = is_wp_error( $answer ) ? $answer->get_error_message() : 'status ' . (int) $answer['status'];
				break;
			}

			$stopped = false;

			foreach ( $answer['body']['commands'] as $envelope ) {
				$outcome = self::receive( $envelope );

				if ( null === $outcome ) {
					// Not something this site can trust. Nothing after it is
					// looked at: order matters, and so does who is asking.
					$stopped       = true;
					$done['error'] = (string) Mailhouse_Central_Identity::get( 'command_error' );
					break;
				}

				if ( isset( $done[ $outcome ] ) ) {
					++$done[ $outcome ];
				}

				if ( time() >= $deadline ) {
					break;
				}
			}

			$acknowledged = self::acknowledge( $timeout );

			if ( $stopped || ! $acknowledged || empty( $answer['body']['more'] ) ) {
				if ( ! $stopped && $acknowledged && empty( $answer['body']['more'] ) && count( $answer['body']['commands'] ) < self::BATCH ) {
					Mailhouse_Central_Identity::update( array( 'commands_pending' => 0 ) );
				}

				break;
			}
		}

		if ( '' === $done['error'] && '' !== (string) Mailhouse_Central_Identity::get( 'command_error' ) ) {
			Mailhouse_Central_Identity::update( array( 'command_error' => '' ) );
		}

		self::prune();

		return $done;
	}

	/**
	 * Take one command: check it, and if it passes, carry it out once.
	 *
	 * @param mixed $envelope The command as it arrived.
	 * @return string|null `applied`, `rejected`, `failed`, `repeat` -- or
	 *                     null for something that must not be answered.
	 */
	public static function receive( $envelope ) {
		global $wpdb;

		$state = Mailhouse_Central_Identity::state();

		// 1. Who is asking. Before anything else, and before anything is
		// written: an envelope that fails here leaves no trace but the error.
		if ( ! Mailhouse_Central_Protocol::verify_command( $envelope, (array) $state['platform_keys'] ) ) {
			return self::distrust( __( 'A command arrived that was not signed by the centre this site is registered with. Nothing was done.', 'mailhouse' ) );
		}

		// 2. Whether it is for this website. A command made for another
		// site is genuine, and is not this site's to carry out.
		if ( '' === (string) $state['website_id'] || ! hash_equals( (string) $state['website_id'], (string) $envelope['website_id'] ) ) {
			return self::distrust( __( 'A command arrived that was made for a different website. Nothing was done.', 'mailhouse' ) );
		}

		$id   = strtolower( (string) $envelope['id'] );
		$seq  = is_int( $envelope['seq'] ) ? $envelope['seq'] : 0;
		$type = (string) $envelope['type'];

		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id ) || $seq < 1 ) {
			return self::distrust( __( 'A command arrived without a usable id or number. Nothing was done.', 'mailhouse' ) );
		}

		$table = self::table();

		// 3. Whether it has been here before. The record answers; nothing is done twice.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$seen = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE command_id = %s", $id ) );

		if ( $seen ) {
			if ( 'applying' === $seen->status ) {
				// Begun and not finished. Either another run is carrying it
				// out at this moment, or one died half-way; recover() tells
				// the two apart by how long ago it was. Not begun again.
				self::recover();
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, array( 'acked' => 0 ), array( 'id' => (int) $seen->id ) );
			}

			return 'repeat';
		}

		// From here the command is the centre's, and for this site; whatever
		// becomes of it is written down and answered.
		$row = array(
			'command_id'  => $id,
			'seq'         => $seq,
			'type'        => substr( $type, 0, 40 ),
			'status'      => 'applying',
			'result'      => '{}',
			'received_at' => mailhouse_now(),
			'acked'       => 0,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->insert( $table, $row ) ) {
			// Without a record there is no "once". Left for the next run.
			return self::distrust( __( 'A command could not be written down, so it was not carried out.', 'mailhouse' ) );
		}

		$inbox_id = (int) $wpdb->insert_id;

		// 4. Its place in the order. Earlier than something already dealt
		// with, and not that thing itself: out of order, or a copy of one
		// long forgotten.
		if ( $seq <= (int) $state['inbox_seq'] ) {
			return self::conclude( $inbox_id, 'rejected', array( 'reason' => 'out_of_order' ) );
		}

		Mailhouse_Central_Identity::update( array( 'inbox_seq' => $seq ) );

		// 5. Whether it is still wanted.
		$expires = strtotime( (string) $envelope['expires_at'] );

		if ( false === $expires || $expires <= time() + (int) $state['clock_offset'] ) {
			return self::conclude( $inbox_id, 'rejected', array( 'reason' => 'expired' ) );
		}

		// 6. Whether it is something this site does.
		if ( ! in_array( $type, self::types(), true ) ) {
			return self::conclude( $inbox_id, 'rejected', array( 'reason' => 'unsupported' ) );
		}

		$payload = json_decode( (string) $envelope['payload_json'], true );

		if ( ! is_array( $payload ) ) {
			return self::conclude( $inbox_id, 'rejected', array( 'reason' => 'malformed' ) );
		}

		$before = Mailhouse_Central_Outbox::highest();

		try {
			$outcome = Mailhouse_Central::with_origin(
				$id,
				static function () use ( $type, $payload ) {
					return self::carry_out( $type, $payload );
				}
			);
		} catch ( Throwable $error ) {
			$outcome = array( 'failed', array( 'reason' => 'error' ) );
		}

		if ( ! is_array( $outcome ) || ! isset( $outcome[0], $outcome[1] ) || ! in_array( $outcome[0], array( 'applied', 'rejected', 'failed' ), true ) ) {
			$outcome = array( 'failed', array( 'reason' => 'error' ) );
		}

		// How many events carrying it out recorded. None, and the centre
		// has nothing further to wait for.
		$outcome[1]['events'] = max( 0, Mailhouse_Central_Outbox::highest() - $before );

		if ( 'applied' === $outcome[0] && 0 === $outcome[1]['events'] && ! isset( $outcome[1]['noop'] ) && Mailhouse_Central_Outbox::capturing() && self::changes( $type ) ) {
			$outcome[1]['noop'] = true;
		}

		return self::conclude( $inbox_id, $outcome[0], $outcome[1] );
	}

	/**
	 * Give up on commands that were begun and never finished.
	 *
	 * A command is written down before it is carried out. One still marked
	 * as being carried out minutes later belongs to a process that died
	 * half-way. How far it got is not known, so it is reported as failed,
	 * and is not tried again: the centre can see what this site holds and
	 * ask again if it still wants to.
	 *
	 * @return void
	 */
	protected static function recover() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed', result = %s, acked = 0 WHERE status = 'applying' AND received_at < %s",
				wp_json_encode( array( 'reason' => 'interrupted' ) ),
				gmdate( 'Y-m-d H:i:s', time() - self::INTERRUPTED_AFTER )
			)
		);
	}

	/**
	 * Whether carrying out a type of command changes anything the centre
	 * is told about, so that "nothing was recorded" means "nothing changed".
	 *
	 * @param string $type Command type.
	 * @return bool
	 */
	protected static function changes( $type ) {
		return ! in_array( $type, array( 'subscriber.reconfirm', 'site.reconcile' ), true );
	}

	/**
	 * The types this site will carry out.
	 *
	 * @return string[]
	 */
	public static function types() {
		/**
		 * Filter the kinds of command this site carries out. Removing one
		 * makes this site refuse it; nothing can be added that this class
		 * has no method for.
		 *
		 * @param string[] $types Command types.
		 */
		$allowed = (array) apply_filters( 'mailhouse_central_command_types', self::TYPES );

		return array_values( array_intersect( self::TYPES, array_filter( $allowed, 'is_string' ) ) );
	}

	/**
	 * Carry out one command. Each type is spelled out with the one function
	 * that does it; a type that is not here is not done.
	 *
	 * @param string $type    Command type, already checked against types().
	 * @param array  $payload Decoded payload.
	 * @return array array( status, result ).
	 */
	protected static function carry_out( $type, array $payload ) {
		switch ( $type ) {
			case 'subscriber.create':
				return self::create( $payload );
			case 'subscriber.update_profile':
				return self::update_profile( $payload );
			case 'subscriber.set_tags':
				return self::set_tags( $payload );
			case 'subscriber.unsubscribe':
				return self::unsubscribe( $payload );
			case 'subscriber.mark_bounced':
				return self::mark_bounced( $payload );
			case 'subscriber.reconfirm':
				return self::reconfirm( $payload );
			case 'subscriber.delete':
				return self::delete( $payload );
			case 'tag.create':
				return self::tag_create( $payload );
			case 'tag.rename':
				return self::tag_rename( $payload );
			case 'tag.delete':
				return self::tag_delete( $payload );
			case 'site.reconcile':
				return self::reconcile();
		}

		return self::refuse( 'unsupported' );
	}

	/**
	 * Note that something arrived which cannot be trusted or cannot be
	 * recorded, and stop.
	 *
	 * @param string $message What was wrong.
	 * @return null
	 */
	protected static function distrust( $message ) {
		Mailhouse_Central_Identity::update( array( 'command_error' => substr( (string) $message, 0, 500 ) ) );

		return null;
	}

	/**
	 * Write down what became of a command.
	 *
	 * @param int    $inbox_id The inbox row.
	 * @param string $status   `applied`, `rejected` or `failed`.
	 * @param array  $result   A few facts about the outcome. Never a subscriber.
	 * @return string The status.
	 */
	protected static function conclude( $inbox_id, $status, array $result ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array(
				'status' => $status,
				'result' => wp_json_encode( $result ),
				'acked'  => 0,
			),
			array( 'id' => (int) $inbox_id )
		);

		return $status;
	}

	/**
	 * Tell the centre what became of the commands it has not been told about.
	 *
	 * @param int $timeout Seconds.
	 * @return bool False when the centre could not be told.
	 */
	public static function acknowledge( $timeout = Mailhouse_Central::TICK_TIMEOUT ) {
		global $wpdb;

		$table = self::table();

		for ( $round = 0; $round < 10; $round++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = (array) $wpdb->get_results( "SELECT id, command_id, seq, status, result FROM {$table} WHERE acked = 0 AND status <> 'applying' ORDER BY seq ASC, id ASC LIMIT 100" );

			if ( ! $rows ) {
				return true;
			}

			$results = array();

			foreach ( $rows as $row ) {
				$result    = json_decode( (string) $row->result, true );
				$results[] = array(
					'id'     => (string) $row->command_id,
					'seq'    => (int) $row->seq,
					'status' => (string) $row->status,
					// An object, even when there is nothing in it.
					'result' => is_array( $result ) && $result ? $result : new stdClass(),
				);
			}

			$answer = Mailhouse_Central_Client::signed( 'POST', '/api/v1/connector/commands/ack', array( 'results' => $results ), $timeout );

			if ( is_wp_error( $answer ) || 200 !== $answer['status'] ) {
				Mailhouse_Central_Identity::update(
					array(
						'command_error' => is_wp_error( $answer )
							? substr( $answer->get_error_message(), 0, 500 )
							/* translators: %d: HTTP status. */
							: sprintf( __( 'The centre answered with status %d when told what became of its commands.', 'mailhouse' ), (int) $answer['status'] ),
					)
				);

				return false;
			}

			$ids = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "UPDATE {$table} SET acked = 1 WHERE id IN ({$ids})" );
		}

		return true;
	}

	/**
	 * Forget answered commands too old to arrive again. At most daily.
	 *
	 * @return void
	 */
	protected static function prune() {
		global $wpdb;

		if ( get_transient( 'mailhouse_central_inbox_pruned' ) ) {
			return;
		}

		set_transient( 'mailhouse_central_inbox_pruned', 1, DAY_IN_SECONDS );

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE acked = 1 AND received_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP ) ) );
	}

	/**
	 * What the heartbeat says about commands.
	 *
	 * @return array
	 */
	public static function report() {
		return array( 'last_applied_seq' => (int) Mailhouse_Central_Identity::get( 'inbox_seq' ) );
	}

	/**
	 * How many commands have been dealt with, by outcome, and how many
	 * answers are still owed.
	 *
	 * @return array{applied:int,rejected:int,failed:int,unanswered:int}
	 */
	public static function counts() {
		global $wpdb;

		$table  = self::table();
		$counts = array(
			'applied'    => 0,
			'rejected'   => 0,
			'failed'     => 0,
			'unanswered' => 0,
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total, SUM(acked = 0) AS owed FROM {$table} GROUP BY status" ) as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->total;
			}

			$counts['unanswered'] += (int) $row->owed;
		}

		return $counts;
	}

	// ---------------------------------------------------------------------
	// What each command does. Every one returns array( status, result ).
	// ---------------------------------------------------------------------

	/**
	 * A whole number from a payload, or zero.
	 *
	 * @param array  $payload Payload.
	 * @param string $key     Key.
	 * @return int
	 */
	protected static function id_from( array $payload, $key = 'local_id' ) {
		return isset( $payload[ $key ] ) && is_int( $payload[ $key ] ) && $payload[ $key ] > 0 ? $payload[ $key ] : 0;
	}

	/**
	 * Text from a payload, or an empty string.
	 *
	 * @param array  $payload Payload.
	 * @param string $key     Key.
	 * @return string
	 */
	protected static function text_from( array $payload, $key ) {
		return isset( $payload[ $key ] ) && is_string( $payload[ $key ] ) ? $payload[ $key ] : '';
	}

	/**
	 * Whether a name is one this site can store whole. A name that does
	 * not fit is refused, not cut short: half a name is a different name.
	 *
	 * @param string $name A name, already cleaned.
	 * @return bool
	 */
	protected static function fits( $name ) {
		return ( function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name ) ) <= self::NAME_LENGTH;
	}

	/**
	 * The subscriber a command is about, or null.
	 *
	 * @param array $payload Payload.
	 * @return object|null
	 */
	protected static function subscriber( array $payload ) {
		$id = self::id_from( $payload );

		return $id ? Mailhouse_Subscribers::get( $id ) : null;
	}

	/**
	 * A refusal.
	 *
	 * @param string $reason Why, as one word.
	 * @param array  $more   Anything else worth saying.
	 * @return array
	 */
	protected static function refuse( $reason, array $more = array() ) {
		return array( 'rejected', array_merge( array( 'reason' => $reason ), $more ) );
	}

	/**
	 * Add somebody to the list.
	 *
	 * Somebody already here is left exactly as they are. In particular an
	 * address that has unsubscribed or bounced is refused: it is not put
	 * back, and it is not sent a message inviting it back either.
	 *
	 * @param array $payload email, first_name, last_name, fields, tags, mode.
	 * @return array
	 */
	protected static function create( array $payload ) {
		$email = mailhouse_clean_email( self::text_from( $payload, 'email' ) );
		$mode  = self::text_from( $payload, 'mode' );

		if ( ! $email ) {
			return self::refuse( 'invalid_email' );
		}

		if ( 'opt_in' !== $mode && 'import' !== $mode ) {
			return self::refuse( 'malformed' );
		}

		$first = sanitize_text_field( self::text_from( $payload, 'first_name' ) );
		$last  = sanitize_text_field( self::text_from( $payload, 'last_name' ) );

		if ( strlen( $email ) > 191 || ! self::fits( $first ) || ! self::fits( $last ) ) {
			return self::refuse( 'too_long' );
		}

		$existing = Mailhouse_Subscribers::get_by_email( $email );

		if ( $existing ) {
			if ( in_array( $existing->status, array( 'unsubscribed', 'bounced' ), true ) ) {
				return self::refuse( 'kept_' . $existing->status, array( 'local_id' => (int) $existing->id ) );
			}

			return array(
				'applied',
				array(
					'noop'     => true,
					'reason'   => 'exists',
					'local_id' => (int) $existing->id,
				)
			);
		}

		$tags = array();

		foreach ( isset( $payload['tags'] ) && is_array( $payload['tags'] ) ? $payload['tags'] : array() as $tag_id ) {
			if ( is_int( $tag_id ) && $tag_id > 0 && Mailhouse_Tags::get( $tag_id ) ) {
				$tags[] = $tag_id;
			}
		}

		$args = array(
			'email'             => $email,
			'first_name'        => $first,
			'last_name'         => $last,
			'fields'            => isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array(),
			'tags'              => $tags,
			'source'            => 'central',
			'allow_resubscribe' => false,
			'overwrite_names'   => false,
		);

		if ( 'import' === $mode ) {
			// Added as subscribed, on the basis the centre recorded. No
			// message: they did not just sign up.
			$args['status']      = 'subscribed';
			$args['send_emails'] = false;
		} else {
			// They are asked. Whatever this site's own setting, somebody
			// added from elsewhere confirms before they are subscribed.
			$args['double_optin'] = true;
		}

		$added = Mailhouse_Subscribers::add( $args );

		if ( is_wp_error( $added ) ) {
			return array( 'failed', array( 'reason' => 'not_saved' ) );
		}

		// Somebody else added the same address in the same moment.
		if ( empty( $added['is_new'] ) ) {
			return 0 === strpos( (string) $added['action'], 'kept_' )
				? self::refuse( (string) $added['action'], array( 'local_id' => (int) $added['id'] ) )
				: array(
					'applied',
					array(
						'noop'     => true,
						'reason'   => 'exists',
						'local_id' => (int) $added['id'],
					)
				);
		}

		return array(
			'applied',
			array(
				'local_id' => (int) $added['id'],
				'status'   => (string) $added['status'],
			)
		);
	}

	/**
	 * Change a name or the custom fields.
	 *
	 * Refused when the subscriber has changed here since the person asking
	 * last saw them: nothing is written over something they had not seen.
	 *
	 * @param array $payload local_id, first_name?, last_name?, fields?, expected_revision.
	 * @return array
	 */
	protected static function update_profile( array $payload ) {
		$subscriber = self::subscriber( $payload );

		if ( ! $subscriber ) {
			return self::refuse( 'not_found' );
		}

		if ( ! isset( $payload['expected_revision'] ) || ! is_int( $payload['expected_revision'] ) ) {
			return self::refuse( 'malformed' );
		}

		if ( (int) $subscriber->revision !== $payload['expected_revision'] ) {
			return self::refuse( 'stale', array( 'revision' => (int) $subscriber->revision ) );
		}

		$changes = array();

		foreach ( array( 'first_name', 'last_name' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_string( $payload[ $key ] ) ) {
				$changes[ $key ] = sanitize_text_field( $payload[ $key ] );

				if ( ! self::fits( $changes[ $key ] ) ) {
					return self::refuse( 'too_long' );
				}
			}
		}

		if ( isset( $payload['fields'] ) && is_array( $payload['fields'] ) ) {
			$fields            = Mailhouse_Subscribers::clean_fields( $payload['fields'] );
			$changes['fields'] = $fields ? wp_json_encode( $fields ) : null;
		}

		// Only these three. A status or an address in the payload is not
		// looked at, whatever it says.
		if ( ! $changes ) {
			return self::refuse( 'malformed' );
		}

		if ( ! Mailhouse_Subscribers::update( (int) $subscriber->id, $changes ) ) {
			return array( 'failed', array( 'reason' => 'not_saved' ) );
		}

		$after = Mailhouse_Subscribers::get( (int) $subscriber->id );

		return array( 'applied', array( 'revision' => $after ? (int) $after->revision : 0 ) );
	}

	/**
	 * Attach and detach tags.
	 *
	 * @param array $payload local_id, add, remove.
	 * @return array
	 */
	protected static function set_tags( array $payload ) {
		$subscriber = self::subscriber( $payload );

		if ( ! $subscriber ) {
			return self::refuse( 'not_found' );
		}

		$lists = array();

		foreach ( array( 'add', 'remove' ) as $key ) {
			$lists[ $key ] = array();

			foreach ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ? $payload[ $key ] : array() as $tag_id ) {
				if ( ! is_int( $tag_id ) || $tag_id < 1 ) {
					return self::refuse( 'malformed' );
				}

				$lists[ $key ][] = $tag_id;
			}
		}

		foreach ( $lists['add'] as $tag_id ) {
			if ( ! Mailhouse_Tags::get( $tag_id ) ) {
				return self::refuse( 'unknown_tag', array( 'tag' => $tag_id ) );
			}
		}

		if ( $lists['add'] ) {
			Mailhouse_Tags::attach( (int) $subscriber->id, $lists['add'] );
		}

		// Never with an empty list: detaching nothing in particular means
		// detaching everything.
		if ( $lists['remove'] ) {
			Mailhouse_Tags::detach( (int) $subscriber->id, $lists['remove'] );
		}

		$after = Mailhouse_Subscribers::get( (int) $subscriber->id );

		return array( 'applied', array( 'revision' => $after ? (int) $after->revision : 0 ) );
	}

	/**
	 * Take somebody off the list. Never refused for a subscriber who is here.
	 *
	 * @param array $payload local_id, source, reason?.
	 * @return array
	 */
	protected static function unsubscribe( array $payload ) {
		$subscriber = self::subscriber( $payload );

		if ( ! $subscriber ) {
			return self::refuse( 'not_found' );
		}

		// Already off the list, one way or the other.
		if ( in_array( $subscriber->status, array( 'unsubscribed', 'bounced' ), true ) ) {
			return array( 'applied', array( 'noop' => true ) );
		}

		$source = self::text_from( $payload, 'source' );

		Mailhouse_Subscribers::unsubscribe( $subscriber, in_array( $source, array( 'central', 'central_link', 'global_suppression' ), true ) ? $source : 'central' );

		$after = Mailhouse_Subscribers::get( (int) $subscriber->id );

		return array( 'applied', array( 'revision' => $after ? (int) $after->revision : 0 ) );
	}

	/**
	 * Retire an address that does not work. Never refused for a subscriber who is here.
	 *
	 * @param array $payload local_id, reason.
	 * @return array
	 */
	protected static function mark_bounced( array $payload ) {
		$subscriber = self::subscriber( $payload );

		if ( ! $subscriber ) {
			return self::refuse( 'not_found' );
		}

		if ( 'bounced' === $subscriber->status ) {
			return array( 'applied', array( 'noop' => true ) );
		}

		Mailhouse_Subscribers::mark_bounced( $subscriber, sanitize_text_field( self::text_from( $payload, 'reason' ) ), 'hard' );

		$after = Mailhouse_Subscribers::get( (int) $subscriber->id );

		return array( 'applied', array( 'revision' => $after ? (int) $after->revision : 0 ) );
	}

	/**
	 * Send this site's confirmation message again to somebody who has not
	 * confirmed. Whether they then subscribe is theirs to decide.
	 *
	 * Only for somebody pending. Somebody who unsubscribed is not written
	 * to because a third party asked.
	 *
	 * @param array $payload local_id.
	 * @return array
	 */
	protected static function reconfirm( array $payload ) {
		$subscriber = self::subscriber( $payload );

		if ( ! $subscriber ) {
			return self::refuse( 'not_found' );
		}

		if ( 'pending' !== $subscriber->status ) {
			return self::refuse( 'not_pending', array( 'status' => (string) $subscriber->status ) );
		}

		if ( empty( $subscriber->confirm_key ) ) {
			Mailhouse_Subscribers::update( (int) $subscriber->id, array( 'confirm_key' => mailhouse_token( 16 ) ) );

			$subscriber = Mailhouse_Subscribers::get( (int) $subscriber->id );
		}

		// The same limit a form submitted twice meets: one message every
		// few minutes to any one address, whoever is asking.
		if ( ! Mailhouse_Subscribers::send_confirmation( $subscriber ) ) {
			return self::refuse( 'throttled' );
		}

		return array( 'applied', array( 'sent' => true ) );
	}

	/**
	 * Remove a subscriber from this site.
	 *
	 * @param array $payload local_id, mode: remove|erase.
	 * @return array
	 */
	protected static function delete( array $payload ) {
		$mode = self::text_from( $payload, 'mode' );

		if ( 'remove' !== $mode && 'erase' !== $mode ) {
			return self::refuse( 'malformed' );
		}

		$subscriber = self::subscriber( $payload );

		if ( $subscriber ) {
			Mailhouse_Subscribers::delete( array( (int) $subscriber->id ), 'erase' === $mode ? 'erasure' : 'central' );
		}

		if ( 'erase' !== $mode ) {
			return array( 'applied', $subscriber ? array() : array( 'noop' => true ) );
		}

		// To be forgotten is more than to be taken off the list. The list
		// may have lost them already, and the records of what they were
		// sent still say who they were.
		$cleared = self::forget( self::id_from( $payload ), $subscriber ? (string) $subscriber->email : '' );

		if ( ! $subscriber && ! array_sum( $cleared ) ) {
			return array( 'applied', array( 'noop' => true ) );
		}

		return array( 'applied', $cleared );
	}

	/**
	 * Take a person out of what this site recorded about the mail it sent
	 * them: a request to be forgotten, carried out from the centre.
	 *
	 * The send records stay, because a campaign's counts are made of them,
	 * but no longer say whose they were. A message still waiting to go, or
	 * half-way out, is dropped. The mail log's entries for them go
	 * altogether: a log entry is the address, the subject and the message.
	 *
	 * Found by the subscriber's number, so it works for somebody the list
	 * has already lost, and by the address where that is known.
	 *
	 * @param int    $id    The subscriber's id on this site.
	 * @param string $email Their address, or '' if the row has gone.
	 * @return array{queue:int,log:int} How many records of each kind were dealt with.
	 */
	public static function forget( $id, $email = '' ) {
		global $wpdb;

		$id    = (int) $id;
		$email = (string) $email;
		$done  = array(
			'queue' => 0,
			'log'   => 0,
		);

		if ( $id <= 0 ) {
			return $done;
		}

		$queue = Mailhouse_Install::table( 'queue' );
		$log   = Mailhouse_Install::table( 'log' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$done['queue'] += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$queue} WHERE subscriber_id = %d AND status IN ('pending', 'sending')", $id ) );
		$done['queue'] += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET email = '', error = NULL WHERE subscriber_id = %d AND ( email <> '' OR error IS NOT NULL )", $id ) );

		if ( '' !== $email ) {
			// The address alone as well: a confirmation message is logged
			// before there is a subscriber to log it against.
			$done['log'] += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE subscriber_id = %d OR to_email = %s", $id, $email ) );
		} else {
			$done['log'] += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE subscriber_id = %d", $id ) );
		}
		// phpcs:enable

		return $done;
	}

	/**
	 * Make a tag. One with the same name already here is the answer.
	 *
	 * @param array $payload name.
	 * @return array
	 */
	protected static function tag_create( array $payload ) {
		$id = Mailhouse_Tags::create( self::text_from( $payload, 'name' ) );

		if ( ! $id ) {
			return self::refuse( 'invalid_name' );
		}

		return array( 'applied', array( 'local_id' => (int) $id ) );
	}

	/**
	 * Rename a tag.
	 *
	 * @param array $payload local_id, name.
	 * @return array
	 */
	protected static function tag_rename( array $payload ) {
		$tag  = Mailhouse_Tags::get( self::id_from( $payload ) );
		$name = trim( wp_strip_all_tags( self::text_from( $payload, 'name' ) ) );

		if ( ! $tag ) {
			return self::refuse( 'not_found' );
		}

		if ( '' === $name ) {
			return self::refuse( 'invalid_name' );
		}

		if ( $name === (string) $tag->name ) {
			return array( 'applied', array( 'noop' => true ) );
		}

		$clash = Mailhouse_Tags::get_by_slug( sanitize_title( $name ) );

		if ( $clash && (int) $clash->id !== (int) $tag->id ) {
			return self::refuse( 'conflict', array( 'local_id' => (int) $clash->id ) );
		}

		if ( ! Mailhouse_Tags::update( (int) $tag->id, $name, (string) $tag->description ) ) {
			return array( 'failed', array( 'reason' => 'not_saved' ) );
		}

		return array( 'applied', array() );
	}

	/**
	 * Delete a tag. Whoever held it stays, without it.
	 *
	 * @param array $payload local_id.
	 * @return array
	 */
	protected static function tag_delete( array $payload ) {
		$tag = Mailhouse_Tags::get( self::id_from( $payload ) );

		if ( ! $tag ) {
			return array( 'applied', array( 'noop' => true ) );
		}

		Mailhouse_Tags::delete( (int) $tag->id );

		return array( 'applied', array() );
	}

	/**
	 * Compare this site's list with the centre's copy at the next opportunity.
	 *
	 * @return array
	 */
	protected static function reconcile() {
		Mailhouse_Central_Identity::update( array( 'want_reconcile' => true ) );

		return array( 'applied', array() );
	}
}
