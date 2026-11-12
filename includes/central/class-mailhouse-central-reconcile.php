<?php
/**
 * Copying this site's list to the centre, and checking the copy.
 *
 * Two jobs, done a little at a time on the scheduled run, after the site's
 * own mail and after anything waiting in the outbox has gone:
 *
 *   the backfill    When a site connects, the centre knows nothing of the
 *                   subscribers it already has. Every tag, then every
 *                   subscriber in id order, is written to the outbox as a
 *                   snapshot of how it stands.
 *
 *   the comparison  Afterwards -- once a day, when the centre asks, when
 *                   this site knows events were lost, or when somebody
 *                   presses the button -- the site sends each subscriber's
 *                   id, revision and hash, and the centre says which it
 *                   holds differently. Those are sent again as snapshots;
 *                   anybody the centre holds and this site does not is
 *                   reported as gone.
 *
 * Everything either job sends travels through the outbox, in the same
 * order as every other change, so a snapshot can never overtake the event
 * that follows it.
 *
 * Neither job ever changes a subscriber on this site. This site is the
 * authority on its own list; what is being corrected is the centre's
 * picture of it.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * The backfill and the comparison.
 */
class Mailhouse_Central_Reconcile {

	/**
	 * Subscribers written to the outbox at a time during a backfill.
	 */
	const CHUNK = 500;

	/**
	 * Nothing more is written to the outbox while this many rows wait in
	 * it: a backfill feeds the push, it does not run ahead of it.
	 */
	const HIGH_WATER = 2000;

	/**
	 * Subscribers in one page of a comparison. The centre's limit.
	 */
	const PAGE = 5000;

	/**
	 * Do one piece of whichever job is due.
	 *
	 * @param int $deadline Unix time to stop by.
	 * @param int $timeout  Seconds to wait for the centre.
	 * @return bool Whether anything was written to the outbox, so that it is worth pushing again.
	 */
	public static function work( $deadline, $timeout = Mailhouse_Central::TICK_TIMEOUT ) {
		if ( Mailhouse_Central::disabled() || 'connected' !== Mailhouse_Central_Identity::status() ) {
			return false;
		}

		if ( ! Mailhouse_Central_Identity::at_registered_address() ) {
			return false;
		}

		$backfill = (array) Mailhouse_Central_Identity::get( 'backfill' );

		if ( $backfill && 'done' !== $backfill['phase'] ) {
			return self::backfill( $backfill, $deadline );
		}

		$state = Mailhouse_Central_Identity::state();

		if ( $state['reconcile'] || '' !== $state['needs_reconcile'] || $state['want_reconcile'] ) {
			return self::compare( $timeout );
		}

		return false;
	}

	/**
	 * A backfill that has not started: what registration stores.
	 *
	 * @return array
	 */
	public static function fresh_backfill() {
		return array(
			'run_id'     => wp_generate_uuid4(),
			'phase'      => 'tags',
			'cursor'     => 0,
			'started_at' => time(),
		);
	}

	// -------------------------------------------------------------------------
	// The backfill

	/**
	 * Write the next part of the list to the outbox.
	 *
	 * @param array $backfill Where the backfill has got to.
	 * @param int   $deadline Unix time to stop by.
	 * @return bool Whether anything was written.
	 */
	protected static function backfill( array $backfill, $deadline ) {
		global $wpdb;

		$wrote = false;

		if ( 'tags' === $backfill['phase'] ) {
			$events = array();

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Mailhouse_Tags::table() . ' ORDER BY id ASC' ) as $tag ) {
				$events[] = Mailhouse_Central_Outbox::tag_event( $tag );
			}

			Mailhouse_Central_Outbox::record_many( $events );

			$backfill['phase'] = 'subscribers';
			$wrote             = (bool) $events;

			Mailhouse_Central_Identity::update( array( 'backfill' => $backfill ) );
		}

		$table = Mailhouse_Subscribers::table();

		while ( 'subscribers' === $backfill['phase'] && time() < $deadline ) {
			if ( Mailhouse_Central_Outbox::waiting()['depth'] >= self::HIGH_WATER ) {
				break;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", (int) $backfill['cursor'], self::CHUNK ) );
			$events = array();

			foreach ( self::states( $rows ) as $id => $state ) {
				$events[] = self::snapshot( $id, $state, $backfill['run_id'], 'backfill' );
			}

			if ( $rows ) {
				$backfill['cursor'] = (int) $rows[ count( $rows ) - 1 ]->id;
			}

			if ( count( $rows ) < self::CHUNK ) {
				// The end of the list. What the site holds, by status, as
				// this is written: the centre checks its copy against it.
				$events[]                 = self::completed( 'site.backfill_completed', $backfill['run_id'] );
				$backfill['phase']        = 'done';
				$backfill['completed_at'] = time();
			}

			Mailhouse_Central_Outbox::record_many( $events );
			// After the rows are safely written, not before: a crash
			// between the two sends a chunk twice, which is harmless, and
			// never skips one.
			Mailhouse_Central_Identity::update( array( 'backfill' => $backfill ) );

			$wrote = true;
		}

		return $wrote;
	}

	// -------------------------------------------------------------------------
	// The comparison

	/**
	 * Send one page of a comparison and act on the answer.
	 *
	 * @param int $timeout Seconds to wait for the centre.
	 * @return bool Whether anything was written to the outbox.
	 */
	protected static function compare( $timeout ) {
		global $wpdb;

		if ( time() < (int) Mailhouse_Central_Identity::get( 'push_next_at' ) ) {
			return false;
		}

		// Whatever has changed must reach the centre first. Comparing
		// while changes wait would only find those changes.
		if ( Mailhouse_Central_Outbox::waiting()['depth'] > 0 ) {
			return false;
		}

		$run = (array) Mailhouse_Central_Identity::get( 'reconcile' );

		if ( ! $run ) {
			$run = array(
				'run_id'     => wp_generate_uuid4(),
				'cursor'     => 0,
				'checked'    => 0,
				'sent'       => 0,
				'started_at' => time(),
			);

			// What asked for this run is answered by it. Anything that
			// asks again while it is under way gets a run of its own.
			Mailhouse_Central_Identity::update(
				array(
					'reconcile'       => $run,
					'needs_reconcile' => '',
					'want_reconcile'  => false,
				)
			);
		}

		/**
		 * Filter how many subscribers one page of a comparison covers.
		 *
		 * @param int $size Page size; the centre accepts at most 5,000.
		 */
		$size  = max( 1, min( self::PAGE, (int) apply_filters( 'mailhouse_central_reconcile_page', self::PAGE ) ) );
		$table = Mailhouse_Subscribers::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", (int) $run['cursor'], $size ) );
		$states = self::states( $rows );
		$final  = count( $rows ) < $size;
		$digest = array();

		foreach ( $states as $id => $state ) {
			$digest[] = array( $id, $state['revision'], $state['hash'] );
		}

		$answer = Mailhouse_Central_Client::signed(
			'POST',
			'/api/v1/connector/sync/reconcile',
			array(
				'run_id'         => $run['run_id'],
				'after_local_id' => (int) $run['cursor'],
				'final'          => $final,
				'digest'         => $digest,
			),
			$timeout
		);

		if ( is_wp_error( $answer ) ) {
			Mailhouse_Central_Sync::failed( $answer->get_error_message() );

			return false;
		}

		$reply = is_array( $answer['body'] ) ? $answer['body'] : array();
		$code  = isset( $reply['code'] ) && is_string( $reply['code'] ) ? $reply['code'] : '';

		if ( 409 === $answer['status'] && 'sync_backlog' === $code ) {
			// The centre is still applying what it was sent. Next time.
			return false;
		}

		if ( 409 === $answer['status'] && 'epoch_mismatch' === $code && isset( $reply['epoch'] ) ) {
			// The centre has found this site holding older revisions than
			// it once reported: the database has been restored from a
			// backup. The sequence starts again, and the comparison with it.
			Mailhouse_Central_Sync::new_epoch( (int) $reply['epoch'] );

			return false;
		}

		if ( 423 === $answer['status'] ) {
			Mailhouse_Central_Identity::update(
				array(
					'status'           => 'suspended',
					'suspended_reason' => isset( $reply['reason'] ) && is_string( $reply['reason'] ) ? sanitize_key( $reply['reason'] ) : 'suspended',
				)
			);

			return false;
		}

		if ( 200 !== $answer['status'] || ! isset( $reply['send_state_for'], $reply['confirm_absent'] ) || ! is_array( $reply['send_state_for'] ) || ! is_array( $reply['confirm_absent'] ) ) {
			/* translators: %d: HTTP status. */
			Mailhouse_Central_Sync::failed( sprintf( __( 'The centre would not compare this site’s list (status %d).', 'mailhouse' ), (int) $answer['status'] ) );

			return false;
		}

		$events = array();

		foreach ( array_map( 'intval', $reply['send_state_for'] ) as $id ) {
			// Only what this page told the centre about. An id it did not
			// ask this site about is not one this site answers for.
			if ( isset( $states[ $id ] ) ) {
				$events[] = self::snapshot( $id, $states[ $id ], $run['run_id'], 'reconcile' );
			}
		}

		$missing = array_slice( array_values( array_unique( array_filter( array_map( 'intval', $reply['confirm_absent'] ) ) ) ), 0, self::PAGE );

		if ( $missing ) {
			// Somebody the centre holds and this page did not mention.
			// Usually gone; just possibly added a moment ago.
			$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery
			$found = self::states( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", $missing ) ) );

			foreach ( $missing as $id ) {
				$events[] = isset( $found[ $id ] )
					? self::snapshot( $id, $found[ $id ], $run['run_id'], 'reconcile' )
					: array(
						'type'         => 'site.subscriber_absent',
						'subject_type' => 'site',
						'subject_id'   => 0,
						'data'         => array(
							'local_id' => $id,
							'run_id'   => $run['run_id'],
						),
						'state'        => null,
					);
			}
		}

		if ( $rows ) {
			$run['cursor'] = (int) $rows[ count( $rows ) - 1 ]->id;
		}

		$run['checked'] = (int) $run['checked'] + count( $rows );
		$run['sent']    = (int) $run['sent'] + count( $events );

		if ( $final ) {
			$events[] = self::completed( 'site.reconcile_completed', $run['run_id'] );
		}

		Mailhouse_Central_Outbox::record_many( $events );

		Mailhouse_Central_Identity::update(
			$final
				? array(
					'reconcile'         => array(),
					'last_reconcile_at' => time(),
					// How it went, for the settings screen.
					'last_reconcile'    => array(
						'checked' => $run['checked'],
						'sent'    => $run['sent'],
						'forced'  => ! empty( $reply['forced'] ),
					),
				)
				: array( 'reconcile' => $run )
		);

		return (bool) $events;
	}

	// -------------------------------------------------------------------------
	// Shared

	/**
	 * The state of each of some subscriber rows, keyed by id, in the order given.
	 *
	 * @param object[] $rows Subscriber rows.
	 * @return array<int,array>
	 */
	protected static function states( array $rows ) {
		$ids = array();

		foreach ( $rows as $row ) {
			$ids[] = (int) $row->id;
		}

		$tags   = Mailhouse_Central_Outbox::tags_of( $ids );
		$states = array();

		foreach ( $rows as $row ) {
			$id            = (int) $row->id;
			$states[ $id ] = Mailhouse_Central_Protocol::subscriber_state( $row, isset( $tags[ $id ] ) ? $tags[ $id ] : array() );
		}

		return $states;
	}

	/**
	 * A subscriber's state, sent for its own sake.
	 *
	 * @param int    $id     Subscriber id.
	 * @param array  $state  Their state.
	 * @param string $run_id The run it belongs to.
	 * @param string $reason `backfill` or `reconcile`.
	 * @return array An event for Mailhouse_Central_Outbox::record_many().
	 */
	protected static function snapshot( $id, array $state, $run_id, $reason ) {
		return array(
			'type'         => 'subscriber.snapshot',
			'subject_type' => 'subscriber',
			'subject_id'   => (int) $id,
			'data'         => array(
				'run_id' => $run_id,
				'reason' => $reason,
			),
			'state'        => $state,
		);
	}

	/**
	 * The event that ends a run: how many subscribers this site holds, by
	 * status, at the moment it is written.
	 *
	 * @param string $type   Event type.
	 * @param string $run_id The run.
	 * @return array
	 */
	protected static function completed( $type, $run_id ) {
		$counts = Mailhouse_Subscribers::counts();

		return array(
			'type'         => $type,
			'subject_type' => 'site',
			'subject_id'   => 0,
			'data'         => array(
				'run_id' => $run_id,
				'counts' => array(
					'subscribed'   => (int) $counts['subscribed'],
					'pending'      => (int) $counts['pending'],
					'unsubscribed' => (int) $counts['unsubscribed'],
					'bounced'      => (int) $counts['bounced'],
				),
			),
			'state'        => null,
		);
	}

	/**
	 * What the heartbeat says about the two jobs.
	 *
	 * @return array
	 */
	public static function report() {
		global $wpdb;

		$state    = Mailhouse_Central_Identity::state();
		$backfill = (array) $state['backfill'];
		$report   = array(
			'backfill'  => ! $backfill ? 'done' : ( 'done' === $backfill['phase'] ? 'done' : ( $backfill['cursor'] > 0 ? 'running' : 'waiting' ) ),
			'reconcile' => $state['reconcile'] ? 'running' : 'idle',
		);

		if ( 'done' !== $report['backfill'] ) {
			$report['backfill_through'] = (int) $backfill['cursor'];
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$report['backfill_of'] = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Mailhouse_Subscribers::table() );
		}

		return $report;
	}
}
