<?php
/**
 * The outbox: what has changed on this site, waiting to be told to the centre.
 *
 * Every change to a subscriber or a tag goes through a handful of functions,
 * and those functions fire hooks. This class listens to the hooks and writes
 * one row for each change. Nothing here talks to the network: a row is one
 * local insert, so a visitor signing up never waits on the centre, and the
 * list carries on exactly as before whether the centre is up, slow or gone.
 * Sending the rows is Mailhouse_Central_Sync's job, on the scheduled tick.
 *
 * Each subscriber event carries the subscriber's whole state after the
 * change, never a difference. A lost, repeated or late event can therefore
 * delay the centre's copy but cannot corrupt it.
 *
 * The row is not written in the same transaction as the change -- WordPress
 * has none to join. A crash between the two loses an event, and that is
 * what the periodic comparison of state is for; events are the fast path.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Capturing changes into the outbox table.
 */
class Mailhouse_Central_Outbox {

	/**
	 * The most rows kept waiting. A site cut off for long enough to pass
	 * this is brought back by comparing state, not by replaying history.
	 */
	const MAX_ROWS = 100000;

	/**
	 * The oldest a waiting row may be.
	 */
	const MAX_AGE = 30 * DAY_IN_SECONDS;

	/**
	 * Whether the listeners are attached.
	 *
	 * @var bool
	 */
	protected static $listening = false;

	/**
	 * The status event written for each subscriber during this request,
	 * so that the hook which knows why can add that to it.
	 *
	 * @var array<int,array{row:int,type:string,data:array,state:array,default:string}>
	 */
	protected static $status_events = array();

	/**
	 * The table.
	 *
	 * @return string
	 */
	public static function table() {
		return Mailhouse_Install::table( 'outbox' );
	}

	/**
	 * Attach the listeners. Safe to call more than once.
	 *
	 * Late, at priority 20, so that anything else listening to the same
	 * hook has finished changing the row before its state is read.
	 *
	 * @return void
	 */
	public static function listen() {
		if ( self::$listening ) {
			return;
		}

		self::$listening = true;

		add_action( 'mailhouse_subscriber_added', array( __CLASS__, 'on_added' ), 20, 1 );
		add_action( 'mailhouse_subscriber_updated', array( __CLASS__, 'on_updated' ), 20, 3 );
		add_action( 'mailhouse_subscriber_confirmed', array( __CLASS__, 'on_confirmed' ), 20, 1 );
		add_action( 'mailhouse_subscriber_unsubscribed', array( __CLASS__, 'on_unsubscribed' ), 20, 2 );
		add_action( 'mailhouse_subscriber_bounced', array( __CLASS__, 'on_bounced' ), 20, 3 );
		add_action( 'mailhouse_subscriber_resubscribed', array( __CLASS__, 'on_resubscribed' ), 20, 2 );
		add_action( 'mailhouse_subscribers_deleting', array( __CLASS__, 'on_deleting' ), 20, 2 );
		add_action( 'mailhouse_subscriber_tags_changed', array( __CLASS__, 'on_tags_changed' ), 20, 3 );
		add_action( 'mailhouse_tag_saved', array( __CLASS__, 'on_tag_saved' ), 20, 1 );
		add_action( 'mailhouse_tag_deleted', array( __CLASS__, 'on_tag_deleted' ), 20, 2 );
	}

	/**
	 * Whether changes are being recorded.
	 *
	 * Only while this site is registered: connected, or suspended and
	 * waiting to be let back in. A site that has never connected, a copy of
	 * a connected site, and a site whose website has been removed at the
	 * centre all record nothing.
	 *
	 * @return bool
	 */
	public static function capturing() {
		if ( Mailhouse_Central::disabled() ) {
			return false;
		}

		return in_array( Mailhouse_Central_Identity::status(), array( 'connected', 'suspended' ), true );
	}

	// -------------------------------------------------------------------------
	// Subscribers

	/**
	 * A new subscriber.
	 *
	 * @param object $subscriber Subscriber row.
	 * @return void
	 */
	public static function on_added( $subscriber ) {
		if ( ! self::capturing() || ! is_object( $subscriber ) ) {
			return;
		}

		self::record( 'subscriber.created', 'subscriber', (int) $subscriber->id, array(), self::state_of( $subscriber ) );
	}

	/**
	 * Something about a subscriber changed.
	 *
	 * One write can be more than one thing -- an edit that changes a name
	 * and unsubscribes. Each is recorded, in a fixed order, and each
	 * carries the same final state.
	 *
	 * @param object   $after   The row as it is now.
	 * @param object   $before  The row as it was.
	 * @param string[] $changed The columns that differ.
	 * @return void
	 */
	public static function on_updated( $after, $before, $changed ) {
		if ( ! self::capturing() || ! is_object( $after ) || ! is_object( $before ) ) {
			return;
		}

		$changed = (array) $changed;
		$id      = (int) $after->id;
		$state   = self::state_of( $after );

		if ( in_array( 'email', $changed, true ) ) {
			self::record(
				'subscriber.email_changed',
				'subscriber',
				$id,
				array( 'previous_email' => Mailhouse_Central_Protocol::text( (string) $before->email, 320 ) ),
				$state
			);
		}

		$status_changed = in_array( 'status', $changed, true );
		$profile        = array_values( array_intersect( $changed, array( 'first_name', 'last_name', 'fields' ) ) );
		// Dates and the source, when they move by themselves: a correction,
		// and still a change to the state.
		$other = $status_changed ? array() : array_values( array_intersect( $changed, array( 'source', 'created_at', 'confirmed_at', 'unsubscribed_at' ) ) );

		if ( $profile || $other ) {
			self::record( 'subscriber.profile_updated', 'subscriber', $id, array( 'changed' => array_merge( $profile, $other ) ), $state );
		}

		if ( ! $status_changed ) {
			return;
		}

		$actor = self::actor();

		switch ( $after->status ) {
			case 'unsubscribed':
				$type = 'subscriber.unsubscribed';
				$data = array( 'source' => $actor );
				break;

			case 'bounced':
				$type = 'subscriber.bounced';
				$data = array(
					'kind'   => 'hard',
					'reason' => '',
				);
				break;

			default:
				// Back onto the list, or onto its doorstep. From pending it
				// is a confirmation; from anywhere else it is a return.
				$type = 'pending' === $before->status && 'subscribed' === $after->status ? 'subscriber.confirmed' : 'subscriber.resubscribed';
				$data = array( 'by' => $actor );
		}

		$row = self::record( $type, 'subscriber', $id, $data, $state );

		if ( $row ) {
			self::$status_events[ $id ] = array(
				'row'     => $row,
				'type'    => $type,
				'data'    => $data,
				'state'   => $state,
				'default' => $actor,
			);
		}
	}

	/**
	 * Somebody confirmed. If this request has just recorded the change of
	 * status, and nobody with authority over the list made it, it was the
	 * owner of the address.
	 *
	 * The hook also fires when a welcome email is due and nothing changed,
	 * which is why it only ever adds to an event and never makes one.
	 *
	 * @param object $subscriber Subscriber row.
	 * @return void
	 */
	public static function on_confirmed( $subscriber ) {
		$id = is_object( $subscriber ) ? (int) $subscriber->id : 0;

		if ( ! isset( self::$status_events[ $id ] ) ) {
			return;
		}

		$event = self::$status_events[ $id ];

		if ( in_array( $event['type'], array( 'subscriber.confirmed', 'subscriber.resubscribed' ), true ) && 'system' === $event['default'] ) {
			self::amend( $id, array( 'by' => 'owner' ) );
		}
	}

	/**
	 * Somebody left the list: add how.
	 *
	 * @param object $subscriber Subscriber row.
	 * @param string $source     What removed them.
	 * @return void
	 */
	public static function on_unsubscribed( $subscriber, $source = '' ) {
		$id     = is_object( $subscriber ) ? (int) $subscriber->id : 0;
		$source = sanitize_key( (string) $source );

		if ( isset( self::$status_events[ $id ] ) && 'subscriber.unsubscribed' === self::$status_events[ $id ]['type'] && '' !== $source ) {
			self::amend( $id, array( 'source' => substr( $source, 0, 60 ) ) );
		}
	}

	/**
	 * An address was retired: add what the provider said.
	 *
	 * @param object $subscriber Subscriber row.
	 * @param string $reason     What the provider said.
	 * @param string $kind       `hard`, or `soft_limit` for a mailbox that was full once too often.
	 * @return void
	 */
	public static function on_bounced( $subscriber, $reason = '', $kind = 'hard' ) {
		$id = is_object( $subscriber ) ? (int) $subscriber->id : 0;

		if ( isset( self::$status_events[ $id ] ) && 'subscriber.bounced' === self::$status_events[ $id ]['type'] ) {
			self::amend(
				$id,
				array(
					'kind'   => 'soft_limit' === $kind ? 'soft_limit' : 'hard',
					'reason' => Mailhouse_Central_Protocol::text( (string) $reason, 500 ),
				)
			);
		}
	}

	/**
	 * Somebody came back: add who asked.
	 *
	 * @param object $subscriber Subscriber row.
	 * @param string $by         Who asked.
	 * @return void
	 */
	public static function on_resubscribed( $subscriber, $by = 'owner' ) {
		$id = is_object( $subscriber ) ? (int) $subscriber->id : 0;
		$by = sanitize_key( (string) $by );

		if ( isset( self::$status_events[ $id ] ) && 'subscriber.resubscribed' === self::$status_events[ $id ]['type'] && '' !== $by ) {
			self::amend( $id, array( 'by' => substr( $by, 0, 60 ) ) );
		}
	}

	/**
	 * Subscribers are about to be deleted. Their last state is recorded
	 * while there is still a row, and tags, to read it from.
	 *
	 * @param array<int,object> $rows   The rows about to go, keyed by id.
	 * @param string            $reason `admin`, or `erasure` for a request to be forgotten.
	 * @return void
	 */
	public static function on_deleting( $rows, $reason = 'admin' ) {
		if ( ! self::capturing() || ! is_array( $rows ) || ! $rows ) {
			return;
		}

		$reason = 'erasure' === $reason ? 'erasure' : ( 'local' === Mailhouse_Central::origin() ? 'admin' : 'command' );
		$tags   = self::tags_of( array_keys( $rows ) );

		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) ) {
				continue;
			}

			$id = (int) $row->id;

			self::record(
				'subscriber.deleted',
				'subscriber',
				$id,
				array(
					'last_status' => (string) $row->status,
					'reason'      => $reason,
				),
				Mailhouse_Central_Protocol::subscriber_state( $row, isset( $tags[ $id ] ) ? $tags[ $id ] : array() )
			);

			unset( self::$status_events[ $id ] );
		}
	}

	/**
	 * The tags on a subscriber changed.
	 *
	 * @param int   $subscriber_id Subscriber id.
	 * @param int[] $added         Tag ids attached.
	 * @param int[] $removed       Tag ids detached.
	 * @return void
	 */
	public static function on_tags_changed( $subscriber_id, $added, $removed ) {
		if ( ! self::capturing() ) {
			return;
		}

		$subscriber = Mailhouse_Subscribers::get( (int) $subscriber_id );

		if ( ! $subscriber ) {
			return;
		}

		self::record(
			'subscriber.tags_changed',
			'subscriber',
			(int) $subscriber_id,
			array(
				'added'   => array_values( array_map( 'intval', (array) $added ) ),
				'removed' => array_values( array_map( 'intval', (array) $removed ) ),
			),
			self::state_of( $subscriber )
		);
	}

	// -------------------------------------------------------------------------
	// Tags

	/**
	 * A tag was created or renamed.
	 *
	 * @param object $tag Tag row.
	 * @return void
	 */
	public static function on_tag_saved( $tag ) {
		if ( ! self::capturing() || ! is_object( $tag ) ) {
			return;
		}

		$event = self::tag_event( $tag );

		self::record( $event['type'], $event['subject_type'], $event['subject_id'], $event['data'] );
	}

	/**
	 * A tag as the centre is told about one.
	 *
	 * @param object $tag Tag row.
	 * @return array An event for record_many().
	 */
	public static function tag_event( $tag ) {
		$id   = (int) $tag->id;
		$name = Mailhouse_Central_Protocol::text( (string) $tag->name, 191 );
		$slug = Mailhouse_Central_Protocol::text( (string) $tag->slug, 191 );

		return array(
			'type'         => 'tag.saved',
			'subject_type' => 'tag',
			'subject_id'   => $id,
			'data'         => array(
				'local_id' => $id,
				// Neither may be empty at the centre; a tag with no name is
				// still a tag, and is known by its number.
				'name'     => '' !== $name ? $name : 'Tag ' . $id,
				'slug'     => '' !== $slug ? $slug : 'tag-' . $id,
			),
			'state'        => null,
		);
	}

	/**
	 * A tag was deleted. One event for the tag, not one for each holder:
	 * the centre takes it off everyone who held it, and moves their
	 * revisions on by one exactly as this site did.
	 *
	 * @param object $tag     The tag as it was.
	 * @param int[]  $holders Ids of the subscribers who had it.
	 * @return void
	 */
	public static function on_tag_deleted( $tag, $holders = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! self::capturing() || ! is_object( $tag ) ) {
			return;
		}

		self::record( 'tag.deleted', 'tag', (int) $tag->id, array( 'local_id' => (int) $tag->id ) );
	}

	// -------------------------------------------------------------------------
	// Storage

	/**
	 * Write one event.
	 *
	 * @param string     $type         Event type.
	 * @param string     $subject_type `subscriber`, `tag` or `site`.
	 * @param int        $subject_id   The subject's id on this site.
	 * @param array      $data         What the event says beyond the state.
	 * @param array|null $state        A subscriber's state, for a subscriber event.
	 * @return int The row's id, or zero when it could not be written.
	 */
	public static function record( $type, $subject_type, $subject_id, array $data = array(), $state = null ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'event_id'     => wp_generate_uuid4(),
				'type'         => $type,
				'subject_type' => $subject_type,
				'subject_id'   => (int) $subject_id,
				'origin'       => Mailhouse_Central::origin(),
				'payload'      => self::payload( $data, $state ),
				'occurred_at'  => mailhouse_now(),
			)
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Write many events, in the order given, a hundred to a statement.
	 *
	 * For the backfill and the comparison, which write thousands. They are
	 * the site's own doing, whatever else is going on in the request.
	 *
	 * @param array[] $events Each: type, subject_type, subject_id, data, state.
	 * @return void
	 */
	public static function record_many( array $events ) {
		global $wpdb;

		$table = self::table();
		$now   = mailhouse_now();

		foreach ( array_chunk( $events, 100 ) as $chunk ) {
			$values = array();

			foreach ( $chunk as $event ) {
				$values[] = $wpdb->prepare(
					'(%s, %s, %s, %d, %s, %s, %s)',
					wp_generate_uuid4(),
					$event['type'],
					$event['subject_type'],
					(int) $event['subject_id'],
					'local',
					self::payload( (array) $event['data'], $event['state'] ),
					$now
				);
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "INSERT INTO {$table} (event_id, type, subject_type, subject_id, origin, payload, occurred_at) VALUES " . implode( ',', $values ) );
		}
	}

	/**
	 * An event's data and state as stored: JSON, exactly as it will be sent.
	 *
	 * @param array      $data  What the event says beyond the state.
	 * @param array|null $state A subscriber's state.
	 * @return string
	 */
	protected static function payload( array $data, $state ) {
		$payload = array( 'data' => $data ? $data : new stdClass() );

		if ( null !== $state ) {
			$payload['state'] = $state;
		}

		// Unescaped: a third of the size for anything not in English, and
		// readable by whoever has to look in the table.
		return (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Add to the status event this request recorded for a subscriber.
	 *
	 * @param int   $subscriber_id Subscriber id.
	 * @param array $more          Values to set in the event's data.
	 * @return void
	 */
	protected static function amend( $subscriber_id, array $more ) {
		global $wpdb;

		$event         = self::$status_events[ $subscriber_id ];
		$event['data'] = array_merge( $event['data'], $more );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array( 'payload' => self::payload( $event['data'], $event['state'] ) ),
			array( 'id' => $event['row'] )
		);

		// Said once. A second hook for the same change has nothing to add.
		unset( self::$status_events[ $subscriber_id ] );
	}

	/**
	 * Who, as far as this request can tell, is making a change.
	 *
	 * `central` when the centre asked for it, `admin` when somebody who
	 * manages the list is signed in, and `system` otherwise -- which the
	 * hooks that know better then replace with `owner`, `link`,
	 * `complaint` and the rest.
	 *
	 * @return string
	 */
	protected static function actor() {
		if ( 'local' !== Mailhouse_Central::origin() ) {
			return 'central';
		}

		return function_exists( 'is_user_logged_in' ) && is_user_logged_in() && mailhouse_can_manage() ? 'admin' : 'system';
	}

	/**
	 * A subscriber's state, tags included.
	 *
	 * @param object $subscriber Subscriber row.
	 * @return array
	 */
	public static function state_of( $subscriber ) {
		$id   = (int) $subscriber->id;
		$tags = self::tags_of( array( $id ) );

		return Mailhouse_Central_Protocol::subscriber_state( $subscriber, isset( $tags[ $id ] ) ? $tags[ $id ] : array() );
	}

	/**
	 * The tag ids held by each of some subscribers.
	 *
	 * @param int[] $subscriber_ids Subscriber ids.
	 * @return array<int,int[]>
	 */
	public static function tags_of( array $subscriber_ids ) {
		global $wpdb;

		$held  = array();
		$pivot = Mailhouse_Tags::pivot();

		foreach ( array_chunk( array_map( 'intval', $subscriber_ids ), 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT subscriber_id, tag_id FROM {$pivot} WHERE subscriber_id IN ({$placeholders})", $chunk ) );

			foreach ( $rows as $row ) {
				$held[ (int) $row->subscriber_id ][] = (int) $row->tag_id;
			}
		}

		return $held;
	}

	// -------------------------------------------------------------------------
	// What is waiting

	/**
	 * How much is waiting to be sent.
	 *
	 * @return array{depth:int,oldest:?string,highest:int}
	 */
	public static function waiting() {
		global $wpdb;

		$table = self::table();
		$acked = (int) Mailhouse_Central_Identity::get( 'outbox_acked' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS depth, MIN(occurred_at) AS oldest, MAX(id) AS highest FROM {$table} WHERE id > %d", $acked ) );

		return array(
			'depth'   => $row ? (int) $row->depth : 0,
			'oldest'  => $row && $row->oldest ? (string) $row->oldest : null,
			'highest' => $row && $row->highest ? (int) $row->highest : $acked,
		);
	}

	/**
	 * The highest id the table has ever given out and still remembers.
	 *
	 * @return int
	 */
	public static function highest() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
	}

	/**
	 * Remove rows the centre has confirmed it holds.
	 *
	 * The newest row is always left, sent or not. An empty table can have
	 * its counter reset when the database restarts, and a sequence number
	 * used twice is the one thing the centre will not accept; one row is
	 * enough to stop that.
	 *
	 * It is left for its number, and not for what it said. Nothing reads a
	 * row the centre already has, and the last thing a site says about
	 * somebody who asked to be forgotten is who they were: on a quiet site
	 * that would sit here until the next sign-up.
	 *
	 * @param int $through Remove rows up to and including this id.
	 * @return void
	 */
	public static function forget( $through ) {
		global $wpdb;

		$table   = self::table();
		$highest = self::highest();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d AND id < %d", (int) $through, $highest ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET payload = '' WHERE id <= %d AND payload <> ''", (int) $through ) );
	}

	/**
	 * Forget what this request has recorded about status changes. For tests,
	 * and for a long-running process that handles many requests' worth of work.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$status_events = array();
	}
}
