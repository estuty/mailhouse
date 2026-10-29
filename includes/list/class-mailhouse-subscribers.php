<?php
/**
 * The mailing list.
 *
 * Self-hosted and, by default, double opt-in: an address sits at `pending`
 * until somebody clicks the link in a confirmation email. That is not a
 * formality. It is what stops a bot -- or a person typing a stranger's address
 * -- from putting anybody on the list, and it is the difference between a list
 * that lands in inboxes and one that lands in spam folders.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores, confirms and removes subscribers.
 */
class Mailhouse_Subscribers {

	/**
	 * Register the hooks that collect subscribers from elsewhere on the site.
	 */
	public function __construct() {
		add_action( 'comment_form_after_fields', array( $this, 'comment_checkbox' ) );
		add_action( 'comment_form_logged_in_after', array( $this, 'comment_checkbox' ) );
		add_action( 'comment_post', array( $this, 'subscribe_commenter' ), 10, 3 );
		add_action( 'mailhouse_send_confirmation', array( __CLASS__, 'send_confirmation_by_id' ) );

		add_action( 'register_form', array( $this, 'register_checkbox' ) );
		add_action( 'user_register', array( $this, 'subscribe_registrant' ) );

		// WordPress' own privacy tooling, so an address can be exported or
		// erased through Tools -> Export/Erase Personal Data.
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * The subscribers table.
	 *
	 * @return string
	 */
	public static function table() {
		return Mailhouse_Install::table( 'subscribers' );
	}

	/**
	 * Every status a subscriber can hold.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'subscribed'   => __( 'Subscribed', 'mailhouse' ),
			'pending'      => __( 'Pending', 'mailhouse' ),
			'unsubscribed' => __( 'Unsubscribed', 'mailhouse' ),
			'bounced'      => __( 'Bounced', 'mailhouse' ),
		);
	}

	/**
	 * Add someone to the list.
	 *
	 * Re-subscribing an address that is already on the list never creates a
	 * second row and never silently reactivates someone who opted out -- they
	 * go back through confirmation.
	 *
	 * Two flags decide how much a call may change an existing row. Both are
	 * off by default, because the default caller is a public form where the
	 * person typing is not necessarily the owner of the address:
	 *
	 * - `overwrite_names`: replace a stored name and custom fields. Off, a
	 *   name is only filled in where none is stored, so a stranger cannot
	 *   rewrite what `{{first_name}}` says in somebody else's inbox.
	 * - `allow_resubscribe`: move an unsubscribed or bounced row back onto
	 *   the list. Off, the row keeps its status; a public signup sends a
	 *   confirmation link instead, and only that click brings them back.
	 *
	 * @param array $args email, first_name, last_name, source, ip, tags, fields,
	 *                    status, double_optin, send_emails, user_id,
	 *                    overwrite_names, allow_resubscribe, async_emails.
	 * @return array|WP_Error id, status, action, is_new.
	 */
	public static function add( $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'email'        => '',
				'first_name'   => '',
				'last_name'    => '',
				'source'       => '',
				'ip'           => '',
				'tags'         => array(),
				'fields'       => array(),
				'status'       => '',
				'double_optin' => null,
				'send_emails'       => true,
				'user_id'           => 0,
				'overwrite_names'   => false,
				'allow_resubscribe' => false,
				'async_emails'      => false,
			)
		);

		$email = mailhouse_clean_email( $args['email'] );

		if ( ! $email ) {
			return new WP_Error( 'mailhouse_invalid_email', __( 'That does not look like an email address.', 'mailhouse' ) );
		}

		$double_optin = null === $args['double_optin'] ? (bool) mailhouse_option( 'double_optin' ) : (bool) $args['double_optin'];
		$status       = $args['status'] ? $args['status'] : ( $double_optin ? 'pending' : 'subscribed' );
		$tag_ids      = Mailhouse_Tags::resolve( $args['tags'] );
		$default_tags = Mailhouse_Tags::resolve( mailhouse_option( 'default_tags', array() ), false );
		$tag_ids      = array_unique( array_merge( $tag_ids, $default_tags ) );

		$existing = self::get_by_email( $email );

		if ( $existing ) {
			$updates = array( 'updated_at' => mailhouse_now() );

			foreach ( array( 'first_name', 'last_name' ) as $key ) {
				$value = sanitize_text_field( (string) $args[ $key ] );

				if ( '' === $value ) {
					continue;
				}

				if ( $args['overwrite_names'] || '' === (string) $existing->$key ) {
					$updates[ $key ] = $value;
				}
			}

			if ( $args['fields'] ) {
				$stored = self::fields( $existing );
				$fields = self::clean_fields( (array) $args['fields'] );

				// Without overwrite, only keys that are not stored yet are added.
				$updates['fields'] = wp_json_encode(
					$args['overwrite_names'] ? array_merge( $stored, $fields ) : array_merge( $fields, $stored )
				);
			}

			$action      = 'already_subscribed';
			$send_link   = false;
			$was_removed = in_array( $existing->status, array( 'unsubscribed', 'bounced' ), true );

			if ( 'subscribed' !== $existing->status ) {
				if ( $was_removed && ! $args['allow_resubscribe'] ) {
					// An opt-out is only undone by the owner of the address.
					// The row keeps its status; a public signup gets a
					// confirmation link, and confirm() brings them back only
					// when it is clicked. Bounced addresses are not mailed.
					$action = 'kept_' . $existing->status;

					if ( 'unsubscribed' === $existing->status && '' === (string) $args['status'] && $args['send_emails'] ) {
						$updates['confirm_key'] = $existing->confirm_key ? $existing->confirm_key : mailhouse_token( 16 );
						$action                 = 'reconfirming';
						$send_link              = true;
					}
				} else {
					// Somebody who unsubscribed and signed up again is treated as
					// a fresh opt-in, not as a switch to flip back on.
					//
					// An existing confirmation key is kept rather than replaced.
					// Replacing it would kill the link in the email they were sent
					// minutes ago -- and the throttle below may well decline to
					// send a replacement, leaving them with nothing that works.
					$updates['status']      = $status;
					$updates['confirm_key'] = 'pending' === $status
						? ( $existing->confirm_key ? $existing->confirm_key : mailhouse_token( 16 ) )
						: null;
					$action                 = 'pending' === $status ? 'reconfirming' : 'resubscribed';
					$send_link              = 'pending' === $status;

					if ( 'subscribed' === $status ) {
						$updates['confirmed_at'] = mailhouse_now();
					}
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( self::table(), $updates, array( 'id' => (int) $existing->id ) );

			if ( $tag_ids && 0 !== strpos( $action, 'kept_' ) ) {
				Mailhouse_Tags::attach( (int) $existing->id, $tag_ids );
			}

			$subscriber = self::get( (int) $existing->id );

			if ( $args['send_emails'] ) {
				if ( $send_link ) {
					self::dispatch_confirmation( $subscriber, $args['async_emails'] );
				} elseif ( 'resubscribed' === $action ) {
					do_action( 'mailhouse_subscriber_confirmed', $subscriber );
				}
			}

			return array(
				'id'     => (int) $existing->id,
				'status' => $subscriber->status,
				'action' => $action,
				'is_new' => false,
			);
		}

		$now = mailhouse_now();

		$row = array(
			'email'        => $email,
			'first_name'   => sanitize_text_field( $args['first_name'] ),
			'last_name'    => sanitize_text_field( $args['last_name'] ),
			'status'       => $status,
			'source'       => substr( sanitize_key( $args['source'] ), 0, 60 ),
			'ip'           => $args['ip'],
			'user_id'      => (int) $args['user_id'],
			'confirm_key'  => 'pending' === $status ? mailhouse_token( 16 ) : null,
			'manage_key'   => mailhouse_token( 16 ),
			'fields'       => $args['fields'] ? wp_json_encode( self::clean_fields( (array) $args['fields'] ) ) : null,
			'created_at'   => $now,
			'updated_at'   => $now,
			'confirmed_at' => 'subscribed' === $status ? $now : null,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert( self::table(), $row );

		if ( ! $inserted ) {
			return new WP_Error( 'mailhouse_insert_failed', __( 'The subscriber could not be saved.', 'mailhouse' ) );
		}

		$id = (int) $wpdb->insert_id;

		if ( $tag_ids ) {
			Mailhouse_Tags::attach( $id, $tag_ids );
		}

		$subscriber = self::get( $id );

		/**
		 * Fires after a new subscriber is stored, confirmed or not.
		 *
		 * @param object $subscriber Subscriber row.
		 */
		do_action( 'mailhouse_subscriber_added', $subscriber );

		if ( $args['send_emails'] ) {
			if ( 'pending' === $status ) {
				self::dispatch_confirmation( $subscriber, $args['async_emails'] );
			} else {
				do_action( 'mailhouse_subscriber_confirmed', $subscriber );
			}
		}

		return array(
			'id'     => $id,
			'status' => $status,
			'action' => 'created',
			'is_new' => true,
		);
	}

	/**
	 * One subscriber by id.
	 *
	 * @param int $id Subscriber id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * One subscriber by address.
	 *
	 * @param string $email Address.
	 * @return object|null
	 */
	public static function get_by_email( $email ) {
		global $wpdb;

		$email = mailhouse_clean_email( $email );

		if ( ! $email ) {
			return null;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ) );
	}

	/**
	 * One subscriber by confirmation or management key.
	 *
	 * @param string $column confirm_key or manage_key.
	 * @param string $key    Key value.
	 * @return object|null
	 */
	public static function get_by_key( $column, $key ) {
		global $wpdb;

		if ( ! in_array( $column, array( 'confirm_key', 'manage_key' ), true ) ) {
			return null;
		}

		$key = preg_replace( '/[^a-f0-9]/', '', (string) $key );

		if ( strlen( $key ) < 16 ) {
			return null;
		}

		$table = self::table();

		// The column is whitelisted immediately above and the value is always
		// prepared.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s", $key ) );
	}

	/**
	 * Update columns on a subscriber.
	 *
	 * @param int   $id     Subscriber id.
	 * @param array $fields Column/value pairs.
	 * @return bool
	 */
	public static function update( $id, $fields ) {
		global $wpdb;

		$fields['updated_at'] = mailhouse_now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( self::table(), $fields, array( 'id' => (int) $id ) );
	}

	/**
	 * Delete subscribers outright, tags and queued sends included.
	 *
	 * @param int[] $ids Subscriber ids.
	 * @return int Rows deleted.
	 */
	public static function delete( $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', (array) $ids ) );

		if ( ! $ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = self::table();
		$pivot        = Mailhouse_Tags::pivot();
		$queue        = Mailhouse_Install::table( 'queue' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$pivot} WHERE subscriber_id IN ({$placeholders})", $ids ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$queue} WHERE subscriber_id IN ({$placeholders}) AND status = 'pending'", $ids ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );

		do_action( 'mailhouse_subscribers_deleted', $ids );

		return $deleted;
	}

	/**
	 * Mark a pending subscriber as confirmed.
	 *
	 * @param object $subscriber Subscriber row.
	 * @return bool True when this call is what confirmed them.
	 */
	public static function confirm( $subscriber ) {
		if ( ! $subscriber || 'subscribed' === $subscriber->status ) {
			return false;
		}

		self::update(
			(int) $subscriber->id,
			array(
				'status'       => 'subscribed',
				'confirmed_at' => mailhouse_now(),
				'confirm_key'  => null,
			)
		);

		$subscriber = self::get( (int) $subscriber->id );

		/**
		 * Fires when somebody joins the list for real.
		 *
		 * @param object $subscriber Subscriber row.
		 */
		do_action( 'mailhouse_subscriber_confirmed', $subscriber );

		return true;
	}

	/**
	 * Take someone off the list.
	 *
	 * The row stays, with its status changed. Deleting it would let the same
	 * address be re-added by the next import, which is precisely what an
	 * unsubscribe is supposed to prevent.
	 *
	 * @param object $subscriber Subscriber row.
	 * @param string $source     How they left: a link, a provider callback, a
	 *                           spam complaint, or the admin.
	 * @return bool
	 */
	public static function unsubscribe( $subscriber, $source = '' ) {
		if ( ! $subscriber || 'unsubscribed' === $subscriber->status ) {
			return false;
		}

		self::update(
			(int) $subscriber->id,
			array(
				'status'          => 'unsubscribed',
				'unsubscribed_at' => mailhouse_now(),
			)
		);

		self::cancel_queued( (int) $subscriber->id );

		/**
		 * Fires when somebody leaves the list.
		 *
		 * @param object $subscriber Subscriber row.
		 * @param string $source     What removed them.
		 */
		do_action( 'mailhouse_subscriber_unsubscribed', self::get( (int) $subscriber->id ), $source );

		return true;
	}

	/**
	 * Stop sending to an address the provider says does not work.
	 *
	 * A separate status from unsubscribed, because the two mean different
	 * things: one is a decision the person made, the other is a fact about
	 * their mailbox. Keeping them apart is what lets you see at a glance
	 * whether a list is shrinking because people are leaving or because it has
	 * gone stale.
	 *
	 * The counter is left to the caller, which knows whether this bounce has
	 * already been counted.
	 *
	 * @param object $subscriber Subscriber row.
	 * @param string $reason     What the provider said.
	 * @return bool Whether this call is what changed the status.
	 */
	public static function mark_bounced( $subscriber, $reason = '' ) {
		if ( ! $subscriber || 'bounced' === $subscriber->status ) {
			return false;
		}

		self::update( (int) $subscriber->id, array( 'status' => 'bounced' ) );
		self::cancel_queued( (int) $subscriber->id );

		/**
		 * Fires when an address is retired as undeliverable.
		 *
		 * @param object $subscriber Subscriber row.
		 * @param string $reason     What the provider said.
		 */
		do_action( 'mailhouse_subscriber_bounced', self::get( (int) $subscriber->id ), $reason );

		return true;
	}

	/**
	 * Drop anything still queued for a subscriber.
	 *
	 * @param int $subscriber_id Subscriber id.
	 * @return void
	 */
	public static function cancel_queued( $subscriber_id ) {
		global $wpdb;

		$queue = Mailhouse_Install::table( 'queue' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$queue} WHERE subscriber_id = %d AND status = 'pending'", (int) $subscriber_id )
		);
	}

	/**
	 * Query subscribers.
	 *
	 * @param array $args status, search, tag, per_page, page, orderby, order, ids_only.
	 * @return object[]|int[]
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'search'   => '',
				'tag'      => 0,
				'per_page' => 20,
				'page'     => 1,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'ids_only' => false,
			)
		);

		$where   = self::where( $args );
		$table   = self::table();
		$pivot   = Mailhouse_Tags::pivot();
		$join    = $args['tag'] ? "INNER JOIN {$pivot} p ON p.subscriber_id = s.id" : '';
		$columns = $args['ids_only'] ? 's.id' : 's.*';

		$orderby = in_array( $args['orderby'], array( 'created_at', 'email', 'status', 'id', 'first_name' ), true ) ? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$sql    = "SELECT {$columns} FROM {$table} s {$join} WHERE {$where['sql']} ORDER BY s.{$orderby} {$order}";
		$params = $where['params'];

		if ( $args['per_page'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $args['per_page'];
			$params[] = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );
		}

		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $params );
		}

		if ( $args['ids_only'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $sql );
	}

	/**
	 * Count subscribers matching a query.
	 *
	 * @param array $args Same shape as query().
	 * @return int
	 */
	public static function count( $args = array() ) {
		global $wpdb;

		$args  = wp_parse_args( $args, array( 'status' => '', 'search' => '', 'tag' => 0 ) );
		$where = self::where( $args );
		$table = self::table();
		$pivot = Mailhouse_Tags::pivot();
		$join  = $args['tag'] ? "INNER JOIN {$pivot} p ON p.subscriber_id = s.id" : '';

		$sql = "SELECT COUNT(DISTINCT s.id) FROM {$table} s {$join} WHERE " . $where['sql'];

		if ( $where['params'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $where['params'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * How many subscribers hold each status.
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" );

		$counts = array_fill_keys( array_keys( self::statuses() ), 0 );

		foreach ( $rows as $row ) {
			$counts[ $row->status ] = (int) $row->total;
		}

		$counts['all'] = array_sum( $counts );

		return $counts;
	}

	/**
	 * Build the WHERE clause shared by query() and count().
	 *
	 * @param array $args Query arguments.
	 * @return array{sql:string,params:array}
	 */
	protected static function where( $args ) {
		global $wpdb;

		$sql    = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$sql[]    = 's.status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['tag'] ) ) {
			$sql[]    = 'p.tag_id = %d';
			$params[] = (int) $args['tag'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$sql[]    = '(s.email LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		return array(
			'sql'    => implode( ' AND ', $sql ),
			'params' => $params,
		);
	}

	/**
	 * The custom fields stored against a subscriber.
	 *
	 * @param object $subscriber Subscriber row.
	 * @return array
	 */
	public static function fields( $subscriber ) {
		if ( ! $subscriber || empty( $subscriber->fields ) ) {
			return array();
		}

		$fields = json_decode( $subscriber->fields, true );

		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * A subscriber's display name, falling back to the part before the @.
	 *
	 * @param object $subscriber Subscriber row.
	 * @return string
	 */
	public static function name( $subscriber ) {
		$name = trim( $subscriber->first_name . ' ' . $subscriber->last_name );

		if ( '' !== $name ) {
			return $name;
		}

		return ucfirst( strtok( (string) $subscriber->email, '@' ) );
	}

	/**
	 * Send the confirmation email, at most once every few minutes per address.
	 *
	 * The throttle matters: without it, a form submitted repeatedly turns the
	 * site into a way of mailbombing whichever address the attacker types.
	 *
	 * @param object $subscriber Subscriber row.
	 * @return bool
	 */
	public static function send_confirmation( $subscriber ) {
		// Pending rows always hold a key; an unsubscribed row holds one only
		// while it is waiting for its owner to confirm a new signup.
		if ( ! $subscriber || empty( $subscriber->confirm_key ) || in_array( $subscriber->status, array( 'subscribed', 'bounced' ), true ) ) {
			return false;
		}

		$key = 'mailhouse_confirm_' . md5( $subscriber->email );

		if ( get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, 5 * MINUTE_IN_SECONDS );

		return Mailhouse_Automations::send_confirmation( $subscriber );
	}

	/**
	 * Send the confirmation email now, or straight after this request.
	 *
	 * The public form sends it asynchronously. Sent in the request, a new
	 * address takes a second (the provider round trip) and a known one
	 * returns at once, which would tell anybody timing the form who is
	 * already on the list.
	 *
	 * @param object $subscriber Subscriber row.
	 * @param bool   $async      Whether to defer it.
	 * @return void
	 */
	public static function dispatch_confirmation( $subscriber, $async = false ) {
		if ( ! $subscriber ) {
			return;
		}

		if ( ! $async ) {
			self::send_confirmation( $subscriber );
			return;
		}

		wp_schedule_single_event( time(), 'mailhouse_send_confirmation', array( (int) $subscriber->id ) );

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Cron callback for a deferred confirmation.
	 *
	 * @param int $id Subscriber id.
	 * @return void
	 */
	public static function send_confirmation_by_id( $id ) {
		self::send_confirmation( self::get( (int) $id ) );
	}

	/**
	 * Custom fields reduced to plain text: scalar values, sane keys.
	 *
	 * They end up in emails through merge tags, so nothing that is not
	 * plain text is kept.
	 *
	 * @param array $fields Raw fields.
	 * @return array<string,string>
	 */
	public static function clean_fields( $fields ) {
		$out = array();

		foreach ( (array) $fields as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || ! is_scalar( $value ) ) {
				continue;
			}

			$out[ substr( $key, 0, 60 ) ] = sanitize_text_field( (string) $value );
		}

		return $out;
	}

	/**
	 * The opt-in checkbox under the comment form.
	 *
	 * @return void
	 */
	public function comment_checkbox() {
		if ( ! mailhouse_option( 'subscribe_comment' ) ) {
			return;
		}

		$label = (string) mailhouse_option( 'comment_label' );
		$label = '' !== $label ? $label : __( 'Email me the newsletter', 'mailhouse' );

		printf(
			'<p class="comment-form-mailhouse"><label for="mailhouse-subscribe"><input type="checkbox" name="mailhouse_subscribe" id="mailhouse-subscribe" value="1" /> %s</label></p>',
			esc_html( $label )
		);
	}

	/**
	 * Subscribe a commenter who ticked the box.
	 *
	 * @param int        $comment_id Comment id.
	 * @param int|string $approved   Approval state.
	 * @param array      $data       Comment data.
	 * @return void
	 */
	public function subscribe_commenter( $comment_id, $approved, $data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! mailhouse_option( 'subscribe_comment' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the comment form carries its own protection.
		if ( empty( $_POST['mailhouse_subscribe'] ) || empty( $data['comment_author_email'] ) ) {
			return;
		}

		// A comment the site already judged to be spam does not get to add
		// anybody to the list, and the comment form shares the signup limit.
		if ( 'spam' === $approved || 'trash' === $approved || ! Mailhouse_Forms::within_rate_limit() ) {
			return;
		}

		self::add(
			array(
				'email'      => $data['comment_author_email'],
				'first_name' => isset( $data['comment_author'] ) ? $data['comment_author'] : '',
				'source'       => 'comment',
				'ip'           => mailhouse_ip(),
				'async_emails' => true,
			)
		);
	}

	/**
	 * The opt-in checkbox on the registration form.
	 *
	 * @return void
	 */
	public function register_checkbox() {
		if ( ! mailhouse_option( 'subscribe_register' ) ) {
			return;
		}

		$label = (string) mailhouse_option( 'comment_label' );
		$label = '' !== $label ? $label : __( 'Email me the newsletter', 'mailhouse' );

		printf(
			'<p><label for="mailhouse-subscribe"><input type="checkbox" name="mailhouse_subscribe" id="mailhouse-subscribe" value="1" /> %s</label></p>',
			esc_html( $label )
		);
	}

	/**
	 * Subscribe a new user who ticked the box.
	 *
	 * @param int $user_id New user id.
	 * @return void
	 */
	public function subscribe_registrant( $user_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the registration form carries its own protection.
		if ( ! mailhouse_option( 'subscribe_register' ) || empty( $_POST['mailhouse_subscribe'] ) ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		self::add(
			array(
				'email'      => $user->user_email,
				'first_name' => $user->first_name,
				'last_name'  => $user->last_name,
				'source'     => 'registration',
				'ip'         => mailhouse_ip(),
				'user_id'    => $user_id,
			)
		);
	}

	/**
	 * Add the list to WordPress' personal data exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['mailhouse'] = array(
			'exporter_friendly_name' => __( 'Newsletter subscription', 'mailhouse' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Export everything held about one address.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public static function export_personal_data( $email, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$subscriber = self::get_by_email( $email );

		if ( ! $subscriber ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$tags = wp_list_pluck( Mailhouse_Tags::for_subscriber( (int) $subscriber->id ), 'name' );

		$data = array(
			array( 'name' => __( 'Email address', 'mailhouse' ), 'value' => $subscriber->email ),
			array( 'name' => __( 'Name', 'mailhouse' ), 'value' => trim( $subscriber->first_name . ' ' . $subscriber->last_name ) ),
			array( 'name' => __( 'Status', 'mailhouse' ), 'value' => $subscriber->status ),
			array( 'name' => __( 'Signed up', 'mailhouse' ), 'value' => $subscriber->created_at ),
			array( 'name' => __( 'Confirmed', 'mailhouse' ), 'value' => $subscriber->confirmed_at ),
			array( 'name' => __( 'Signup source', 'mailhouse' ), 'value' => $subscriber->source ),
			array( 'name' => __( 'IP address at signup', 'mailhouse' ), 'value' => $subscriber->ip ),
			array( 'name' => __( 'Tags', 'mailhouse' ), 'value' => implode( ', ', $tags ) ),
		);

		return array(
			'data' => array(
				array(
					'group_id'    => 'mailhouse',
					'group_label' => __( 'Newsletter subscription', 'mailhouse' ),
					'item_id'     => 'mailhouse-' . $subscriber->id,
					'data'        => array_values( array_filter( $data, static function ( $item ) {
						return '' !== (string) $item['value'] && null !== $item['value'];
					} ) ),
				),
			),
			'done' => true,
		);
	}

	/**
	 * Add the list to WordPress' personal data eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['mailhouse'] = array(
			'eraser_friendly_name' => __( 'Newsletter subscription', 'mailhouse' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Remove everything held about one address.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public static function erase_personal_data( $email, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$subscriber = self::get_by_email( $email );

		if ( ! $subscriber ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		self::delete( array( (int) $subscriber->id ) );

		return array(
			'items_removed'  => true,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
