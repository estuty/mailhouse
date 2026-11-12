<?php
/**
 * Sending the outbox to the centre.
 *
 * On the scheduled tick, after this site's own mail has gone: read the
 * oldest waiting rows, send them in order, and remove the ones the centre
 * says it has stored. Everything else here is what to do when that does
 * not go to plan, and the rule throughout is the same -- nothing the site
 * does for itself ever waits on this, and nothing is removed until the
 * centre has said it is safe.
 *
 * A row's sequence number is its id plus an offset fixed when the site
 * registered, so that a website connected for a second time carries on
 * from the number the centre last saw.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * The push.
 */
class Mailhouse_Central_Sync {

	/**
	 * The most events in one request. The centre's limit.
	 */
	const BATCH = 500;

	/**
	 * The most bytes of events in one request. The centre accepts ten
	 * megabytes; this leaves room, and keeps one request's memory modest.
	 */
	const BATCH_BYTES = 4194304;

	/**
	 * Seconds one tick may spend pushing.
	 */
	const TIME_BOX = 15;

	/**
	 * The event that takes the place of one the centre will not accept.
	 */
	const UNSENDABLE = 'site.event_unsendable';

	/**
	 * The repairs made during the current push, each allowed once.
	 *
	 * @var array<string,bool>
	 */
	protected static $repaired = array();

	/**
	 * Send what is waiting, as far as time allows.
	 *
	 * @param int $time_box Seconds to spend.
	 * @param int $timeout  Seconds to wait for each answer.
	 * @return array{sent:int,error:string}
	 */
	public static function push( $time_box = self::TIME_BOX, $timeout = Mailhouse_Central::TICK_TIMEOUT ) {
		$result = array(
			'sent'  => 0,
			'error' => '',
		);

		if ( Mailhouse_Central::disabled() || 'connected' !== Mailhouse_Central_Identity::status() ) {
			// Suspended, a copy, or switched off: keep recording, send nothing.
			return $result;
		}

		if ( ! Mailhouse_Central_Identity::at_registered_address() ) {
			return $result;
		}

		$now = time();

		if ( $now < (int) Mailhouse_Central_Identity::get( 'push_next_at' ) ) {
			return $result;
		}

		self::bound();

		self::$repaired = array();
		$deadline       = $now + max( 1, (int) $time_box );
		$size           = self::BATCH;

		do {
			$rows = self::next_rows( $size );

			if ( ! $rows ) {
				break;
			}

			$outcome = self::send( $rows, $timeout );

			if ( 'ok' === $outcome ) {
				$result['sent'] += count( $rows );
				// Past whatever was in the way: back towards full batches.
				$size = min( self::BATCH, $size * 2 );
				continue;
			}

			if ( 'smaller' === $outcome ) {
				// The centre would not take the batch whole. Halve it until
				// the one event it objects to is alone, and can be dealt with.
				$size = max( 1, (int) floor( count( $rows ) / 2 ) );
				continue;
			}

			if ( 'again' === $outcome ) {
				// Something was put right; the same rows are worth another try.
				continue;
			}

			$result['error'] = (string) Mailhouse_Central_Identity::get( 'push_error' );
			break;
		} while ( time() < $deadline );

		return $result;
	}

	/**
	 * Send one batch and act on the answer.
	 *
	 * @param object[] $rows    Outbox rows, in id order.
	 * @param int      $timeout Seconds.
	 * @return string `ok`, `smaller`, `again` or `stop`.
	 */
	protected static function send( array $rows, $timeout ) {
		$offset = (int) Mailhouse_Central_Identity::get( 'seq_offset' );
		$events = array();

		foreach ( $rows as $row ) {
			$events[] = self::event_json( $row, $offset );
		}

		$body   = '{"epoch":' . max( 1, (int) Mailhouse_Central_Identity::get( 'epoch' ) ) . ',"events":[' . implode( ',', $events ) . ']}';
		$answer = Mailhouse_Central_Client::signed( 'POST', '/api/v1/connector/sync/events', $body, $timeout );
		$last   = $rows[ count( $rows ) - 1 ];

		if ( is_wp_error( $answer ) ) {
			return self::failed( $answer->get_error_message() );
		}

		$reply = is_array( $answer['body'] ) ? $answer['body'] : array();
		$code  = isset( $reply['code'] ) && is_string( $reply['code'] ) ? $reply['code'] : '';

		switch ( $answer['status'] ) {
			case 200:
				$accepted = isset( $reply['accepted_through_seq'] ) ? (int) $reply['accepted_through_seq'] - $offset : 0;

				// Only what this request sent is removed on the strength of
				// it. The centre may hold more -- an earlier acknowledgement
				// that never arrived -- and the next request finds that out.
				$accepted = min( $accepted, (int) $last->id );

				if ( $accepted < (int) $rows[0]->id ) {
					return self::failed( __( 'The centre’s acknowledgement could not be understood.', 'mailhouse' ) );
				}

				Mailhouse_Central_Identity::update(
					array(
						'outbox_acked'  => $accepted,
						'last_push_at'  => time(),
						'push_failures' => 0,
						'push_next_at'  => 0,
						'push_error'    => '',
					)
				);
				Mailhouse_Central_Outbox::forget( $accepted );

				return 'ok';

			case 409:
				return self::conflict( $code, $reply, $rows );

			case 413:
			case 422:
				if ( count( $rows ) > 1 ) {
					return 'smaller';
				}

				return self::set_aside( $rows[0], $answer['status'] );

			case 423:
				// Suspended. The heartbeat is what finds out when that ends.
				Mailhouse_Central_Identity::update(
					array(
						'status'           => 'suspended',
						'suspended_reason' => isset( $reply['reason'] ) && is_string( $reply['reason'] ) ? sanitize_key( $reply['reason'] ) : 'suspended',
					)
				);

				return 'stop';

			case 410:
				Mailhouse_Central_Identity::update( array( 'status' => 'gone' ) );

				return 'stop';

			case 401:
				return self::failed( __( 'The centre did not accept this site’s signature.', 'mailhouse' ) );

			case 429:
				return self::failed( __( 'The centre asked this site to slow down.', 'mailhouse' ) );
		}

		/* translators: %d: HTTP status. */
		return self::failed( sprintf( __( 'The centre answered with status %d.', 'mailhouse' ), (int) $answer['status'] ) );
	}

	/**
	 * The centre and this site disagree about where the sequence stands.
	 *
	 * @param string   $code  The centre's reason.
	 * @param array    $reply The centre's answer.
	 * @param object[] $rows  The rows that were sent.
	 * @return string
	 */
	protected static function conflict( $code, array $reply, array $rows ) {
		switch ( $code ) {
			case 'sequence_gap':
			case 'sequence_regression':
				// The centre expects a number this site cannot give it: it
				// has lost events this site already removed, or this site
				// has been restored from a backup and is counting again
				// from an older number. Either way the events in between
				// cannot be produced. Number the waiting rows from where
				// the centre is, and ask for the two copies to be compared.
				if ( ! isset( $reply['expected_seq'] ) || (int) $reply['expected_seq'] < 1 ) {
					return self::failed( __( 'The centre refused the sequence without saying where to resume.', 'mailhouse' ) );
				}

				Mailhouse_Central_Identity::update(
					array(
						'seq_offset'      => (int) $reply['expected_seq'] - (int) $rows[0]->id,
						'needs_reconcile' => $code,
					)
				);

				return self::once( $code );

			case 'epoch_mismatch':
				if ( ! isset( $reply['epoch'] ) || (int) $reply['epoch'] < 1 ) {
					return self::failed( __( 'The centre is on another epoch and did not say which.', 'mailhouse' ) );
				}

				// The centre has started this site's sequence again: it has
				// found the site counting from an older number, as a site
				// restored from a backup does. What is waiting belongs to
				// the old count and is dropped; the comparison that follows
				// tells the centre how things stand.
				self::new_epoch( (int) $reply['epoch'] );

				return self::once( $code );

			case 'conflict':
				// An event id the centre already holds under another number,
				// which happens only after the rows were renumbered. They
				// are the same changes; they need new names.
				self::rename( (int) $rows[0]->id );

				return self::once( $code );
		}

		/* translators: %s: reason code. */
		return self::failed( sprintf( __( 'The centre refused the events (%s).', 'mailhouse' ), '' !== $code ? $code : '409' ) );
	}

	/**
	 * Allow a repair one immediate retry, and no more: a repair that did
	 * not work must not become a loop inside one tick.
	 *
	 * @param string $code What was repaired.
	 * @return string `again` the first time, `stop` after that.
	 */
	protected static function once( $code ) {
		if ( isset( self::$repaired[ $code ] ) ) {
			/* translators: %s: reason code. */
			return self::failed( sprintf( __( 'The centre keeps refusing the events (%s).', 'mailhouse' ), $code ) );
		}

		self::$repaired[ $code ] = true;

		return 'again';
	}

	/**
	 * Replace one event the centre will not accept with a note that there
	 * was one.
	 *
	 * The sequence may not have a hole in it, and one row the centre cannot
	 * read must not hold up every row behind it. So the row keeps its place
	 * and its number, and says only what kind of event stood there and for
	 * whom. The comparison of state is what then brings that subscriber's
	 * copy up to date.
	 *
	 * @param object $row    The outbox row.
	 * @param int    $status The status the centre refused it with.
	 * @return string
	 */
	protected static function set_aside( $row, $status ) {
		global $wpdb;

		if ( self::UNSENDABLE === $row->type ) {
			// Even the note was refused. Nothing more can be done from here.
			/* translators: %d: HTTP status. */
			return self::failed( sprintf( __( 'The centre refused an event it should always accept (status %d).', 'mailhouse' ), (int) $status ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Mailhouse_Central_Outbox::table(),
			array(
				'event_id'     => wp_generate_uuid4(),
				'type'         => self::UNSENDABLE,
				'subject_type' => 'site',
				'subject_id'   => 0,
				'payload'      => (string) wp_json_encode(
					array(
						'data' => array(
							'type'         => (string) $row->type,
							'subject_type' => (string) $row->subject_type,
							'local_id'     => (int) $row->subject_id,
							'status'       => (int) $status,
						),
					)
				),
			),
			array( 'id' => (int) $row->id )
		);

		Mailhouse_Central_Identity::update( array( 'needs_reconcile' => 'event_unsendable' ) );

		return 'again';
	}

	/**
	 * Give every waiting row from an id onwards a new event id.
	 *
	 * @param int $from First row id.
	 * @return void
	 */
	protected static function rename( $from ) {
		global $wpdb;

		$table = Mailhouse_Central_Outbox::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE id >= %d ORDER BY id ASC LIMIT %d", $from, self::BATCH ) );

		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, array( 'event_id' => wp_generate_uuid4() ), array( 'id' => (int) $id ) );
		}
	}

	/**
	 * Note a failed push and decide when to try again: one minute, then
	 * two, four, eight, and every fifteen from then on, each with up to a
	 * fifth added at random so that fifty sites cut off together do not
	 * all come back in the same second.
	 *
	 * @param string $message What went wrong.
	 * @return string Always `stop`.
	 */
	public static function failed( $message ) {
		$failures = (int) Mailhouse_Central_Identity::get( 'push_failures' ) + 1;
		$wait     = (int) min( 15 * MINUTE_IN_SECONDS, MINUTE_IN_SECONDS * pow( 2, min( $failures, 5 ) - 1 ) );
		$wait    += wp_rand( 0, (int) ( $wait / 5 ) );

		Mailhouse_Central_Identity::update(
			array(
				'push_failures' => $failures,
				'push_next_at'  => time() + $wait,
				'push_error'    => substr( (string) $message, 0, 500 ),
			)
		);

		return 'stop';
	}

	/**
	 * The oldest rows that have not been acknowledged.
	 *
	 * @param int $limit Most rows.
	 * @return object[]
	 */
	protected static function next_rows( $limit ) {
		global $wpdb;

		$table = Mailhouse_Central_Outbox::table();
		$acked = (int) Mailhouse_Central_Identity::get( 'outbox_acked' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $acked, max( 1, min( self::BATCH, (int) $limit ) ) ) );

		// Never so many that the request is too large to send. The first
		// row always goes, whatever its size.
		$bytes = 0;
		$kept  = array();

		foreach ( $rows as $row ) {
			$bytes += strlen( (string) $row->payload ) + 300;

			if ( $kept && $bytes > self::BATCH_BYTES ) {
				break;
			}

			$kept[] = $row;
		}

		return $kept;
	}

	/**
	 * One outbox row as the JSON of an event.
	 *
	 * The data and the state were encoded when the change happened and are
	 * sent as they were stored, not decoded and encoded again.
	 *
	 * @param object $row    Outbox row.
	 * @param int    $offset Added to the row's id to give its sequence number.
	 * @return string
	 */
	protected static function event_json( $row, $offset ) {
		$head = (string) wp_json_encode(
			array(
				'seq'         => (int) $row->id + $offset,
				'event_id'    => (string) $row->event_id,
				'type'        => (string) $row->type,
				'occurred_at' => Mailhouse_Central_Protocol::timestamp( (string) $row->occurred_at, true ),
				'origin'      => (string) $row->origin,
				'subject'     => array(
					'type'     => (string) $row->subject_type,
					'local_id' => (int) $row->subject_id,
				),
			)
		);

		$payload = (string) $row->payload;

		// Two JSON objects joined into one: the head without its closing
		// brace, the stored payload without its opening one.
		if ( strlen( $payload ) < 2 || '{' !== $payload[0] || '}' !== substr( $payload, -1 ) ) {
			$payload = '{"data":{}}';
		}

		return substr( $head, 0, -1 ) . ',' . substr( $payload, 1 );
	}

	/**
	 * Keep the outbox within its bounds.
	 *
	 * A site cut off for a month, or holding a hundred thousand unsent
	 * changes, is not brought back by replaying them: the waiting rows are
	 * dropped, and the site asks for its state to be compared instead.
	 * State wins over history. No subscriber is touched by this -- the
	 * rows are a record of changes already made and kept in the list.
	 *
	 * @return bool Whether the outbox was emptied.
	 */
	public static function bound() {
		$waiting = Mailhouse_Central_Outbox::waiting();

		if ( 0 === $waiting['depth'] ) {
			return false;
		}

		$too_many = $waiting['depth'] > Mailhouse_Central_Outbox::MAX_ROWS;
		$too_old  = null !== $waiting['oldest'] && strtotime( $waiting['oldest'] . ' UTC' ) < time() - Mailhouse_Central_Outbox::MAX_AGE;

		if ( ! $too_many && ! $too_old ) {
			return false;
		}

		Mailhouse_Central_Identity::update(
			array(
				'outbox_acked'    => $waiting['highest'],
				'needs_reconcile' => 'outbox_overflow',
			)
		);
		Mailhouse_Central_Outbox::forget( $waiting['highest'] );

		return true;
	}

	/**
	 * Start the sequence for a new registration.
	 *
	 * Whatever was waiting belongs to an earlier connection and is dropped;
	 * the next change this site records is numbered one more than the last
	 * the centre received.
	 *
	 * A marker row is written first and never sent. The table's counter is
	 * then known for certain to stand just past it -- which it would not be
	 * for a table that is empty but has been used before -- and the
	 * numbering is worked out from there.
	 *
	 * @param int $last_seq_received The centre's count for this website.
	 * @param int $epoch             The centre's epoch for this website.
	 * @return array State values to store with the registration.
	 */
	public static function begin( $last_seq_received, $epoch ) {
		return array_merge(
			array( 'epoch' => max( 1, (int) $epoch ) ),
			self::renumber( $last_seq_received )
		);
	}

	/**
	 * Drop whatever is waiting and count from a given number.
	 *
	 * @param int $last_seq The number the centre last received; the next row is one more.
	 * @return array State values: the offset, and the row the count starts after.
	 */
	protected static function renumber( $last_seq ) {
		$marker  = Mailhouse_Central_Outbox::record( 'site.connected', 'site', 0 );
		$highest = max( $marker, Mailhouse_Central_Outbox::highest() );

		Mailhouse_Central_Outbox::forget( $highest );
		Mailhouse_Central_Outbox::reset();

		return array(
			'seq_offset'   => max( 0, (int) $last_seq ) - $highest,
			'outbox_acked' => $highest,
		);
	}

	/**
	 * The centre has moved this website to a new epoch.
	 *
	 * It does that when this site's history has gone backwards -- a
	 * database restored from a backup. The sequence starts again from one.
	 * Whatever was waiting to be sent is dropped: it was numbered under the
	 * old count, and some of it may describe a past that is no longer this
	 * site's. A comparison follows, in which the centre takes this site's
	 * list as it is. A backfill that had not finished starts again.
	 *
	 * No subscriber on this site is touched.
	 *
	 * @param int $epoch The epoch the centre is now on.
	 * @return bool Whether the epoch changed.
	 */
	public static function new_epoch( $epoch ) {
		$epoch = (int) $epoch;

		if ( $epoch < 1 || $epoch === (int) Mailhouse_Central_Identity::get( 'epoch' ) ) {
			return false;
		}

		$backfill = (array) Mailhouse_Central_Identity::get( 'backfill' );

		Mailhouse_Central_Identity::update(
			array_merge(
				self::renumber( 0 ),
				array(
					'epoch'           => $epoch,
					'reconcile'       => array(),
					'needs_reconcile' => 'new_epoch',
					'backfill'        => $backfill && 'done' !== $backfill['phase'] ? Mailhouse_Central_Reconcile::fresh_backfill() : $backfill,
					'push_failures'   => 0,
					'push_next_at'    => 0,
					'push_error'      => '',
				)
			)
		);

		return true;
	}

	/**
	 * What the heartbeat says about the outbox.
	 *
	 * @return array
	 */
	public static function report() {
		$waiting = Mailhouse_Central_Outbox::waiting();
		$state   = Mailhouse_Central_Identity::state();

		return array(
			'depth'           => $waiting['depth'],
			'oldest'          => null === $waiting['oldest'] ? null : Mailhouse_Central_Protocol::timestamp( $waiting['oldest'] ),
			'last_acked_seq'  => max( 0, (int) $state['outbox_acked'] + (int) $state['seq_offset'] ),
			'needs_reconcile' => '' !== (string) $state['needs_reconcile'],
		);
	}
}
