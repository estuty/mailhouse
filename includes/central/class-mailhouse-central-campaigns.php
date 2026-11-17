<?php
/**
 * The connector to Mailhouse Central: a campaign written at the centre,
 * sent by this site.
 *
 * The centre decides what is sent and to whom. This site sends it: in its
 * own template, through its own transport, at its own pace, and only to
 * people it holds as subscribed at the moment each message goes. What
 * arrives is a signed request to take a campaign in, and this is what
 * becomes of it:
 *
 * 1. The content is checked against the hash that names it, cleaned once
 *    more, and kept in a table of its own. A record of it is made among
 *    this site's emails, which cannot be edited or sent from here.
 * 2. Its recipients are fetched a page at a time: this site's own ids, and
 *    a job id for each. Each becomes a row in the queue, waiting.
 * 3. When they are all here they are counted and hashed, and the hash is
 *    compared with the one the request named. Only if both agree is the
 *    campaign ready; otherwise nothing is sent, and the centre is told.
 * 4. At the start time, by this site's own clock, the waiting rows are
 *    released and the queue sends them as it sends anything else. The
 *    centre need not be reachable at that moment.
 * 5. After the deadline, by this site's own clock, nothing more of it is
 *    sent. A site that was away for a week does not come back and send
 *    last week's newsletter.
 *
 * 6. What became of each message is told to the centre as it happens: the
 *    transport took it, refused it, or did not say; it was not sent. So is
 *    a first open and a first click, where this site records them.
 *
 * Taking the same request in twice makes one campaign: the execution's id
 * is unique here, and a repeat carries on where the first stopped.
 *
 * Either end can hold a campaign and either can call it off. Whoever holds
 * it lets it go: the centre cannot lift a hold this site's administrator
 * put on, nor the other way about. This site keeps the last word on what
 * it sends, and that word can only ever be "less".
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Campaigns handed to this site by the centre.
 */
class Mailhouse_Central_Campaigns {

	/**
	 * What this site announces when it will take a campaign in.
	 */
	const CAPABILITY = 'campaigns.local.v1';

	/**
	 * The post meta that marks an email as the record of an execution.
	 */
	const META = '_mailhouse_central_execution';

	/**
	 * The status of a queue row that is here and not yet released.
	 *
	 * Not `paused`: an administrator's Resume releases paused rows, and
	 * these must stay where they are until the campaign is ready and its
	 * time has come.
	 */
	const WAITING = 'waiting';

	/**
	 * Recipients fetched in one request.
	 */
	const PAGE = 1000;

	/**
	 * Seconds one scheduled run may spend fetching and reporting.
	 */
	const TIME_BOX = 10;

	/**
	 * How many recipients this site does not hold are remembered by id.
	 * With more than that the two ends disagree about who is on the list,
	 * and the campaign is not sent.
	 */
	const UNKNOWN_KEPT = 500;

	/**
	 * Seconds to wait after the centre could not be reached.
	 */
	const RETRY_AFTER = 60;

	/**
	 * Seconds between reports while a campaign is being sent.
	 */
	const REPORT_EVERY = 60;

	/**
	 * Rows read at a time when the recipients are hashed.
	 */
	const HASH_PAGE = 5000;

	/**
	 * Outcomes told to the centre in one request.
	 */
	const REPORT_PAGE = 1000;

	/**
	 * A queue row's `reported`: nothing is owed to the centre.
	 */
	const REPORTED_NONE = 0;

	/**
	 * What became of the message is owed.
	 */
	const REPORTED_OWED = 1;

	/**
	 * The centre has been told.
	 */
	const REPORTED_DONE = 2;

	/**
	 * The centre has been told what became of it, and is owed a first open
	 * or click that happened since.
	 */
	const REPORTED_ENGAGED = 3;

	/**
	 * The centre no longer knows the campaign: there is nobody to tell.
	 */
	const REPORTED_NOBODY = 9;

	/**
	 * The most addresses a test goes to.
	 */
	const TEST_ADDRESSES = 5;

	/**
	 * The states in which there is still something to do.
	 *
	 * @var string[]
	 */
	const ACTIVE = array( 'preparing', 'ready', 'sending' );

	/**
	 * Executions looked up while a batch is sent, by post id.
	 *
	 * @var array<int,object|false>
	 */
	protected static $cache = array();

	/**
	 * The table of campaigns from the centre.
	 *
	 * @return string
	 */
	public static function table() {
		return Mailhouse_Install::table( 'remote_campaigns' );
	}

	/**
	 * Register the hooks. Called for a connected site only.
	 *
	 * @return void
	 */
	public static function listen() {
		// What needs no centre: starting on time, stopping at the deadline,
		// noticing that a campaign has finished. After the centre has been
		// heard (5) and before the queue sends (10).
		add_action( 'mailhouse_process_queue', array( __CLASS__, 'local' ), 6 );

		// What needs the centre: fetching recipients, saying how far this
		// site has got. After this site's own mail and its own news (20).
		add_action( 'mailhouse_process_queue', array( __CLASS__, 'network' ), 21 );

		// The record among this site's emails is the centre's, not an
		// email to be edited here.
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'keep_record' ), 10, 2 );
		add_action( 'edit_form_top', array( __CLASS__, 'notice' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'label' ), 10, 2 );
	}

	// ---- taking a campaign in ---------------------------------------------------

	/**
	 * Carry out `campaign.prepare`.
	 *
	 * By the time this is called the request has been checked: it was
	 * signed by the centre this site is registered with, made for this
	 * website, has not expired, and has not been seen before.
	 *
	 * @param array $payload Decoded payload.
	 * @return array array( status, result ).
	 */
	public static function prepare( array $payload ) {
		global $wpdb;

		$text = static function ( $key ) use ( $payload ) {
			return isset( $payload[ $key ] ) && is_string( $payload[ $key ] ) ? $payload[ $key ] : null;
		};

		$execution = strtolower( (string) $text( 'execution_id' ) );
		$campaign  = strtolower( (string) $text( 'campaign_id' ) );
		$subject   = $text( 'subject' );
		$preheader = $text( 'preheader' );
		$html      = $text( 'body_html' );
		$plain     = $text( 'body_text' );
		$start     = self::moment( $text( 'start_at' ) );
		$deadline  = self::moment( $text( 'deadline' ) );
		$count     = isset( $payload['recipient_count'] ) && is_int( $payload['recipient_count'] ) ? $payload['recipient_count'] : 0;
		$uuid      = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
		$sha       = '/^[0-9a-f]{64}$/';

		if (
			! preg_match( $uuid, $execution ) || ! preg_match( $uuid, $campaign )
			|| null === $subject || '' === trim( $subject ) || null === $preheader
			|| null === $html || '' === $html || null === $plain
			|| ! preg_match( $sha, (string) $text( 'content_hash' ) )
			|| ! preg_match( $sha, (string) $text( 'recipients_hash' ) )
			|| $count < 1 || false === $start || false === $deadline || $deadline <= $start
		) {
			return array( 'rejected', array( 'reason' => 'malformed' ) );
		}

		// A subject is one line. A second one would be a second header.
		if ( preg_match( '/[\x00-\x1f\x7f]/', $subject ) || preg_match( '/[\x00-\x1f\x7f]/', $preheader ) ) {
			return array( 'rejected', array( 'reason' => 'malformed' ) );
		}

		// 1. That what arrived is what the centre fixed.
		if ( ! hash_equals( (string) $text( 'content_hash' ), self::content_hash( $subject, $preheader, $html, $plain ) ) ) {
			return array( 'rejected', array( 'reason' => 'content_mismatch' ) );
		}

		$table = self::table();

		// 2. Whether it is here already. If it is, this is a repeat, and
		// the first one carries on where it was.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$seen = $wpdb->get_row( $wpdb->prepare( "SELECT id, post_id, state FROM {$table} WHERE execution_id = %s", $execution ) );

		if ( $seen ) {
			return array(
				'applied',
				array(
					'repeat'            => true,
					'local_campaign_id' => (int) $seen->post_id,
					'state'             => (string) $seen->state,
				),
			);
		}

		if ( $deadline <= self::now() ) {
			return array( 'rejected', array( 'reason' => 'too_late' ) );
		}

		// 3. Cleaned here as well as there: the body ends up in front of
		// every subscriber of a site that did not write it.
		$body = self::sanitise( $html );

		if ( '' === trim( wp_strip_all_tags( $body ) ) && false === stripos( $body, '<img' ) ) {
			return array( 'rejected', array( 'reason' => 'unusable' ) );
		}

		$fields = array();

		foreach ( isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array() as $name ) {
			if ( is_string( $name ) && preg_match( '/^[a-z0-9_]{1,60}$/', strtolower( $name ) ) ) {
				$fields[] = strtolower( $name );
			}
		}

		$name = isset( $payload['name'] ) && is_string( $payload['name'] ) ? sanitize_text_field( $payload['name'] ) : '';
		$now  = mailhouse_now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'execution_id'    => $execution,
				'campaign_id'     => $campaign,
				'name'            => substr( $name, 0, 191 ),
				'subject'         => $subject,
				'preheader'       => $preheader,
				'body_html'       => $body,
				'body_text'       => $plain,
				'content_hash'    => (string) $text( 'content_hash' ),
				'recipient_count' => $count,
				'recipients_hash' => (string) $text( 'recipients_hash' ),
				'fields'          => wp_json_encode( array_values( array_unique( $fields ) ) ),
				'start_at'        => gmdate( 'Y-m-d H:i:s', $start ),
				'deadline_at'     => gmdate( 'Y-m-d H:i:s', $deadline ),
				'state'           => 'preparing',
				'unknown'         => '{}',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		if ( ! $inserted ) {
			// Two runs arrived together and the other one has it.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$seen = $wpdb->get_row( $wpdb->prepare( "SELECT post_id FROM {$table} WHERE execution_id = %s", $execution ) );

			return $seen
				? array( 'applied', array( 'repeat' => true, 'local_campaign_id' => (int) $seen->post_id ) )
				: array( 'failed', array( 'reason' => 'not_stored' ) );
		}

		$row_id = (int) $wpdb->insert_id;

		// 4. This site's own record of it: an email like any other in the
		// list, with its statistics, which says where it came from.
		$post_id = wp_insert_post(
			array(
				'post_type'    => Mailhouse_Emails::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => '' !== $name ? $name : $subject,
				'post_content' => self::record_text(),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			self::fail( $row_id, __( 'This site could not make its record of the campaign.', 'mailhouse' ) );

			return array( 'failed', array( 'reason' => 'no_record' ) );
		}

		update_post_meta( $post_id, self::META, $execution );
		update_post_meta( $post_id, '_mailhouse_type', 'newsletter' );
		update_post_meta( $post_id, '_mailhouse_subject', $subject );
		update_post_meta( $post_id, '_mailhouse_preview', $preheader );
		update_post_meta( $post_id, '_mailhouse_status', 'scheduled' );
		update_post_meta( $post_id, '_mailhouse_send_at', gmdate( 'Y-m-d H:i:s', $start ) );

		self::set( $row_id, array( 'post_id' => (int) $post_id ) );

		return array( 'applied', array( 'local_campaign_id' => (int) $post_id ) );
	}

	/**
	 * A moment written `2026-11-17T09:00:00Z`, as a Unix time.
	 *
	 * @param string|null $text The moment.
	 * @return int|false
	 */
	protected static function moment( $text ) {
		if ( ! is_string( $text ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $text ) ) {
			return false;
		}

		return strtotime( $text );
	}

	/**
	 * The time, as the centre would give it: this site's clock, corrected
	 * by however far the centre last found it to be out.
	 *
	 * @return int
	 */
	protected static function now() {
		return time() + (int) Mailhouse_Central_Identity::get( 'clock_offset' );
	}

	/**
	 * What the record among this site's emails says in place of a body.
	 *
	 * @return string
	 */
	protected static function record_text() {
		return __( 'This newsletter was written in Mailhouse Central and is sent by this site on its behalf. Its content is kept by the connector and cannot be edited here.', 'mailhouse' );
	}

	// ---- the two hashes -----------------------------------------------------------

	/**
	 * The hash that names a campaign's content.
	 *
	 * SHA-256 over the line `MH1-CONTENT`, then for each of the subject,
	 * the preheader, the HTML and the text, in that order: its length in
	 * bytes as a decimal number, a colon, the bytes, and a line feed.
	 *
	 * @param string $subject   Subject.
	 * @param string $preheader Preview text.
	 * @param string $html      Body, as HTML.
	 * @param string $plain     Body, as text.
	 * @return string Lower-case hex.
	 */
	public static function content_hash( $subject, $preheader, $html, $plain ) {
		$context = hash_init( 'sha256' );

		hash_update( $context, "MH1-CONTENT\n" );

		foreach ( array( $subject, $preheader, $html, $plain ) as $part ) {
			$part = (string) $part;

			hash_update( $context, strlen( $part ) . ':' );
			hash_update( $context, $part );
			hash_update( $context, "\n" );
		}

		return hash_final( $context );
	}

	/**
	 * The hash that names a list of recipients.
	 *
	 * SHA-256 over the line `MH1-RECIPIENTS`, then for each recipient, by
	 * this site's own id ascending: the id in decimal, a colon, the job's
	 * id in lower case, and a line feed.
	 *
	 * @param iterable $pairs Each array( local id, job id ), already in order.
	 * @return string Lower-case hex.
	 */
	public static function recipients_hash( $pairs ) {
		$context = hash_init( 'sha256' );

		hash_update( $context, "MH1-RECIPIENTS\n" );

		foreach ( $pairs as $pair ) {
			hash_update( $context, (int) $pair[0] . ':' . strtolower( (string) $pair[1] ) . "\n" );
		}

		return hash_final( $context );
	}

	// ---- cleaning the body ----------------------------------------------------------

	/**
	 * Reduce a body to what an email may contain.
	 *
	 * The centre has done this already, by a list of its own. This is the
	 * second filter, written independently and run by WordPress's own
	 * code: an element, an attribute or a style property is kept because
	 * it is named here. Nothing is run as a shortcode or a block,
	 * anywhere.
	 *
	 * @param string $html The body as it arrived.
	 * @return string
	 */
	public static function sanitise( $html ) {
		$every = array(
			'style' => true,
			'align' => true,
			'title' => true,
			'dir'   => true,
			'lang'  => true,
		);
		$cell  = $every + array(
			'width'   => true,
			'height'  => true,
			'valign'  => true,
			'bgcolor' => true,
			'colspan' => true,
			'rowspan' => true,
			'scope'   => true,
		);

		$allowed = array_fill_keys(
			array( 'b', 'strong', 'i', 'em', 'u', 's', 'sub', 'sup', 'small', 'code', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'thead', 'tbody', 'tfoot', 'caption', 'colgroup', 'div', 'span', 'hr', 'br', 'blockquote' ),
			$every
		);

		$allowed['a']     = $every + array(
			'href'  => true,
			'class' => true,
		);
		$allowed['img']   = $every + array(
			'src'    => true,
			'alt'    => true,
			'width'  => true,
			'height' => true,
			'border' => true,
		);
		$allowed['table'] = $every + array(
			'width'       => true,
			'height'      => true,
			'cellpadding' => true,
			'cellspacing' => true,
			'border'      => true,
			'bgcolor'     => true,
			'role'        => true,
		);
		$allowed['td']    = $cell;
		$allowed['th']    = $cell;
		$allowed['tr']    = $every + array(
			'valign'  => true,
			'bgcolor' => true,
		);
		$allowed['col']   = $every + array(
			'width' => true,
			'span'  => true,
		);
		$allowed['ol']    = $every + array(
			'start' => true,
			'type'  => true,
		);
		$allowed['ul']    = $every + array( 'type' => true );
		$allowed['li']    = $every + array( 'value' => true );

		// A picture from the centre is served by the centre, which on a
		// development machine is plain http. Nowhere else is.
		$protocols = array( 'https', 'mailto' );
		$central   = (string) Mailhouse_Central_Identity::get( 'central_url' );

		if ( 0 === strpos( $central, 'http://' ) ) {
			$protocols[] = 'http';
		}

		$styles = static function () {
			return self::style_properties();
		};

		// What a script or a style sheet says is not text. Taking the tags
		// off would leave it standing in the message as if it were.
		$html = (string) preg_replace( '@<(script|style)\b[^>]*>.*?</\1\s*>@is', '', (string) $html );

		add_filter( 'safe_style_css', $styles, 99 );
		$clean = wp_kses( (string) $html, $allowed, $protocols );
		remove_filter( 'safe_style_css', $styles, 99 );

		// One class, on a link: this site's own button. Any other goes.
		$clean = (string) preg_replace_callback(
			'/\sclass=("|\')(.*?)\1/i',
			static function ( $found ) {
				return 'mh-button' === trim( $found[2] ) ? ' class="mh-button"' : '';
			},
			$clean
		);

		return $clean;
	}

	/**
	 * The style properties a body may use.
	 *
	 * @return string[]
	 */
	public static function style_properties() {
		$sides = array( '', '-top', '-right', '-bottom', '-left' );
		$list  = array( 'color', 'background-color', 'font-size', 'font-weight', 'font-style', 'font-family', 'text-align', 'text-decoration', 'text-transform', 'line-height', 'letter-spacing', 'width', 'max-width', 'min-width', 'height', 'max-height', 'display', 'vertical-align', 'white-space', 'word-break', 'list-style-type', 'text-indent', 'border-collapse', 'border-spacing', 'border-radius', 'border-color', 'border-width', 'border-style' );

		foreach ( array( 'margin', 'padding', 'border' ) as $property ) {
			foreach ( $sides as $side ) {
				$list[] = $property . $side;
			}
		}

		return $list;
	}

	// ---- what a scheduled run does ----------------------------------------------------

	/**
	 * Every campaign that still has something to be done about it.
	 *
	 * @return object[]
	 */
	protected static function active() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE state IN ('preparing', 'ready', 'sending') ORDER BY id ASC" );
	}

	/**
	 * The part that needs no centre: stop what is past its deadline, start
	 * what is ready and due, notice what has finished.
	 *
	 * @return void
	 */
	public static function local() {
		$now  = self::now();
		$send = self::may_send();

		foreach ( self::active() as $row ) {
			// Stopping is always allowed. Starting is not.
			if ( strtotime( $row->deadline_at . ' UTC' ) <= $now ) {
				self::expire( $row );
				continue;
			}

			if ( ! $send ) {
				continue;
			}

			// Held: its time may come and go. It starts when it is let go.
			if ( 'ready' === $row->state && '' === (string) $row->hold && strtotime( $row->start_at . ' UTC' ) <= $now ) {
				self::start( $row );
				continue;
			}

			if ( 'sending' === $row->state && 0 === self::unsent( (int) $row->post_id ) ) {
				self::set( (int) $row->id, array( 'state' => 'completed' ) );
			}
		}
	}

	/**
	 * Whether this site may send for the centre at this moment.
	 *
	 * Not when the connector is switched off; not when the centre has said
	 * this website is gone; and not when this is a copy of the site living
	 * at another address, which holds a copy of the campaign and of every
	 * subscriber and must not write to any of them.
	 *
	 * @return bool
	 */
	public static function may_send() {
		return ! Mailhouse_Central::disabled()
			&& in_array( Mailhouse_Central_Identity::status(), array( 'connected', 'suspended' ), true )
			&& Mailhouse_Central_Identity::at_registered_address();
	}

	/**
	 * This site has been disconnected from the centre. Whatever of the
	 * centre's has not been sent is called off, and kept as the record.
	 *
	 * @return int How many campaigns were stopped.
	 */
	public static function disconnected() {
		$reason  = __( 'This site was disconnected from the centre.', 'mailhouse' );
		$stopped = 0;

		foreach ( self::active() as $row ) {
			self::call_off( (int) $row->post_id, $reason );
			self::set(
				(int) $row->id,
				array(
					'state' => 'cancelled',
					'error' => $reason,
				)
			);
			update_post_meta( (int) $row->post_id, '_mailhouse_status', 'sending' === $row->state ? 'sent' : '' );
			++$stopped;
		}

		return $stopped;
	}

	/**
	 * The part that needs the centre: fetch recipients, and say how far
	 * each campaign has got.
	 *
	 * @param int|null $deadline When to stop, as a Unix time.
	 * @return void
	 */
	public static function network( $deadline = null ) {
		if ( Mailhouse_Central::disabled() || 'connected' !== Mailhouse_Central_Identity::status() || ! Mailhouse_Central_Identity::at_registered_address() ) {
			return;
		}

		global $wpdb;

		$deadline = is_int( $deadline ) ? $deadline : time() + self::TIME_BOX;
		$table    = self::table();

		// Those with recipients still to fetch.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE state = 'preparing' ORDER BY id ASC LIMIT 20" );

		foreach ( $rows as $row ) {
			if ( time() >= $deadline ) {
				return;
			}

			if ( (int) $row->next_at <= time() ) {
				self::fetch( $row, $deadline );
			}
		}

		// What became of each message since the centre was last told. Each
		// such report says how far its campaign has got as well.
		self::outcomes( $deadline );

		// Those the centre has not heard the latest of, and those under way.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE state <> reported_state OR state = 'sending' ORDER BY id ASC LIMIT 20" );

		foreach ( $rows as $row ) {
			if ( time() >= $deadline ) {
				return;
			}

			if ( (int) $row->next_at > time() ) {
				continue;
			}

			if ( $row->state !== $row->reported_state || ( 'sending' === $row->state && time() - (int) $row->reported_at >= self::REPORT_EVERY ) ) {
				self::report( $row, max( 1, min( Mailhouse_Central::TICK_TIMEOUT, $deadline - time() ) ) );
			}
		}

		// A campaign that became ready in this run and is already due
		// starts now, not a minute from now.
		self::local();
	}

	/**
	 * Fetch a campaign's recipients, as far as the time allows; and when
	 * they are all here, count them and hash them.
	 *
	 * @param object $row      The campaign.
	 * @param int    $deadline When to stop.
	 * @return object|null The campaign as it now is, or null if the centre could not be asked.
	 */
	protected static function fetch( $row, $deadline ) {
		global $wpdb;

		$queue       = Mailhouse_Queue::table();
		$subscribers = Mailhouse_Subscribers::table();
		$after       = (int) $row->fetch_after;
		$unknown     = json_decode( (string) $row->unknown, true );
		$unknown     = is_array( $unknown ) ? $unknown : array();
		$missing     = (int) $row->unknown_count;
		$done        = (bool) $row->fetched;

		while ( ! $done && time() < $deadline ) {
			$answer = Mailhouse_Central_Client::signed(
				'GET',
				'/api/v1/connector/executions/' . $row->execution_id . '/recipients',
				null,
				max( 1, min( Mailhouse_Central::TICK_TIMEOUT, $deadline - time() ) ),
				array(
					array( 'after', (string) $after ),
					array( 'limit', (string) self::PAGE ),
				)
			);

			if ( is_wp_error( $answer ) ) {
				self::set( (int) $row->id, array( 'next_at' => time() + self::RETRY_AFTER ) );

				return null;
			}

			$body = is_array( $answer['body'] ) ? $answer['body'] : array();

			// The centre says there is nothing to prepare: it was called
			// off, or held, or the centre no longer knows of it. Only when
			// it says so in as many words: a 404 from something in between,
			// or a website that is merely paused there, is a reason to ask
			// again later, not to give a campaign up.
			$gone    = 404 === (int) $answer['status'] && isset( $body['code'] ) && 'not_found' === $body['code'];
			$stopped = 409 === (int) $answer['status'] && isset( $body['reason'] ) && 'stopped' === $body['reason'];

			if ( $gone || $stopped ) {
				self::stop( $row, $gone ? __( 'The centre no longer knows this campaign.', 'mailhouse' ) : __( 'The centre called this campaign off.', 'mailhouse' ) );

				return self::get( (int) $row->id );
			}

			if ( 200 !== (int) $answer['status'] || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
				self::set( (int) $row->id, array( 'next_at' => time() + self::RETRY_AFTER ) );

				return null;
			}

			$pairs = array();

			foreach ( $body['data'] as $item ) {
				if ( is_array( $item ) && isset( $item['local_id'], $item['job_id'] ) && is_int( $item['local_id'] ) && $item['local_id'] > 0 && is_string( $item['job_id'] ) && preg_match( '/^[0-9a-f-]{36}$/', strtolower( $item['job_id'] ) ) ) {
					$pairs[ $item['local_id'] ] = strtolower( $item['job_id'] );
				}
			}

			if ( count( $pairs ) !== count( $body['data'] ) ) {
				self::fail( (int) $row->id, __( 'The centre’s list of recipients could not be read.', 'mailhouse' ) );

				return self::get( (int) $row->id );
			}

			if ( $pairs ) {
				$ids = array_map( 'intval', array_keys( $pairs ) );

				// The addresses are this site's own. The centre sent none.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$held = $wpdb->get_results( "SELECT id, email FROM {$subscribers} WHERE id IN (" . implode( ',', $ids ) . ')', OBJECT_K );

				$values = array();
				$params = array();

				foreach ( $pairs as $local => $job ) {
					if ( ! isset( $held[ $local ] ) ) {
						// Not guessed at: counted, and named back to the centre.
						$missing += isset( $unknown[ $local ] ) ? 0 : 1;

						if ( count( $unknown ) < self::UNKNOWN_KEPT ) {
							$unknown[ $local ] = $job;
						}

						continue;
					}

					$values[] = '(%d, %d, %s, %s, %s, %s)';
					$params[] = (int) $row->post_id;
					$params[] = (int) $local;
					$params[] = $held[ $local ]->email;
					$params[] = self::WAITING;
					$params[] = $row->start_at;
					$params[] = $job;
				}

				if ( $values ) {
					// INSERT IGNORE: a page fetched twice is a page stored once.
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
					$wpdb->query(
						$wpdb->prepare(
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							"INSERT IGNORE INTO {$queue} (campaign_id, subscriber_id, email, status, scheduled_at, remote_job_id) VALUES " . implode( ', ', $values ),
							$params
						)
					);
				}

				$after = max( $ids );
			}

			$done = ! isset( $body['next_after'] ) || null === $body['next_after'];

			self::set(
				(int) $row->id,
				array(
					'fetch_after'   => $after,
					'fetched'       => $done ? 1 : 0,
					'unknown'       => wp_json_encode( (object) $unknown ),
					'unknown_count' => $missing,
				)
			);
		}

		if ( ! $done ) {
			return self::get( (int) $row->id );
		}

		// All here. Counted and hashed from what this site actually holds,
		// not from what it remembers being told.
		$row = self::get( (int) $row->id );

		if ( $missing > count( $unknown ) ) {
			self::fail( (int) $row->id, sprintf( /* translators: %d: a number of subscribers. */ __( 'The centre listed %d subscribers this site does not hold. The two lists must be compared before a campaign can be sent.', 'mailhouse' ), $missing ) );

			return self::get( (int) $row->id );
		}

		$stored = self::stored( (int) $row->post_id );

		if ( $stored + $missing !== (int) $row->recipient_count || ! hash_equals( (string) $row->recipients_hash, self::recipients_hash( self::pairs( (int) $row->post_id, $unknown ) ) ) ) {
			self::fail( (int) $row->id, __( 'The recipients this site fetched are not the ones the centre fixed. Nothing was sent.', 'mailhouse' ) );

			return self::get( (int) $row->id );
		}

		self::set( (int) $row->id, array( 'state' => 'ready' ) );

		return self::get( (int) $row->id );
	}

	/**
	 * How many recipients of a campaign are stored here.
	 *
	 * @param int $post_id The campaign's post.
	 * @return int
	 */
	protected static function stored( $post_id ) {
		global $wpdb;

		$queue = Mailhouse_Queue::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE campaign_id = %d AND remote_job_id IS NOT NULL", $post_id ) );
	}

	/**
	 * How many of a campaign's messages have not been attempted.
	 *
	 * @param int $post_id The campaign's post.
	 * @return int
	 */
	public static function unsent( $post_id ) {
		global $wpdb;

		$queue = Mailhouse_Queue::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE campaign_id = %d AND status IN ('waiting', 'pending', 'sending', 'paused')", $post_id ) );
	}

	/**
	 * Every recipient of a campaign, as ( local id, job id ), in order of
	 * local id: those stored in the queue, and those this site was given
	 * and does not hold.
	 *
	 * @param int   $post_id The campaign's post.
	 * @param array $unknown Local id => job id, for those not held.
	 * @return Generator
	 */
	protected static function pairs( $post_id, array $unknown ) {
		global $wpdb;

		$queue = Mailhouse_Queue::table();
		$after = 0;
		$next  = 0;
		$gone  = array();

		ksort( $unknown, SORT_NUMERIC );

		foreach ( $unknown as $local => $job ) {
			$gone[] = array( (int) $local, (string) $job );
		}

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$page = (array) $wpdb->get_results( $wpdb->prepare( "SELECT subscriber_id, remote_job_id FROM {$queue} WHERE campaign_id = %d AND remote_job_id IS NOT NULL AND subscriber_id > %d ORDER BY subscriber_id ASC LIMIT %d", $post_id, $after, self::HASH_PAGE ) );

			foreach ( $page as $stored ) {
				$local = (int) $stored->subscriber_id;

				// Those not held that come before this one.
				while ( isset( $gone[ $next ] ) && $gone[ $next ][0] < $local ) {
					yield $gone[ $next ];
					++$next;
				}

				yield array( $local, $stored->remote_job_id );
				$after = $local;
			}
		} while ( count( $page ) === self::HASH_PAGE );

		while ( isset( $gone[ $next ] ) ) {
			yield $gone[ $next ];
			++$next;
		}
	}

	/**
	 * Release a campaign's waiting rows: its time has come.
	 *
	 * @param object $row The campaign.
	 * @return void
	 */
	protected static function start( $row ) {
		global $wpdb;

		$queue = Mailhouse_Queue::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET status = 'pending', scheduled_at = %s WHERE campaign_id = %d AND status = %s", mailhouse_now(), (int) $row->post_id, self::WAITING ) );

		self::set(
			(int) $row->id,
			array(
				'state'      => 'sending',
				'started_at' => mailhouse_now(),
			)
		);
		update_post_meta( (int) $row->post_id, '_mailhouse_status', 'sending' );
		unset( self::$cache[ (int) $row->post_id ] );
	}

	/**
	 * Stop a campaign whose time has passed. What has gone has gone; what
	 * has not is marked as called off, and kept.
	 *
	 * @param object $row The campaign.
	 * @return void
	 */
	protected static function expire( $row ) {
		global $wpdb;

		self::call_off( (int) $row->post_id, __( 'Its time had passed.', 'mailhouse' ) );

		// Every message of it that was not sent because of the time: these,
		// and any the queue turned away itself a moment earlier.
		$queue = Mailhouse_Queue::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE campaign_id = %d AND status = 'cancelled'", (int) $row->post_id ) );

		self::set(
			(int) $row->id,
			array(
				'hold'  => '',
				'state' => 'sending' === $row->state ? 'completed' : 'cancelled',
				'error' => $left ? sprintf( /* translators: %d: a number of messages. */ __( 'The deadline passed with %d messages not sent. They were not sent.', 'mailhouse' ), $left ) : (string) $row->error,
			)
		);
		update_post_meta( (int) $row->post_id, '_mailhouse_status', 'sending' === $row->state ? 'sent' : '' );
	}

	/**
	 * Stop a campaign the centre no longer wants prepared.
	 *
	 * @param object $row    The campaign.
	 * @param string $reason Why, in words.
	 * @return void
	 */
	protected static function stop( $row, $reason ) {
		// One that is already being sent is this site's to finish, and one
		// that is over is over: only what has not begun can be stopped.
		if ( ! in_array( $row->state, array( 'preparing', 'ready' ), true ) ) {
			return;
		}

		self::call_off( (int) $row->post_id, $reason );
		self::set(
			(int) $row->id,
			array(
				'state'          => 'cancelled',
				'reported_state' => 'cancelled',
				'error'          => $reason,
			)
		);
		update_post_meta( (int) $row->post_id, '_mailhouse_status', '' );
	}

	/**
	 * Mark every message of a campaign that has not been attempted as
	 * called off. The rows stay, as the record.
	 *
	 * @param int    $post_id The campaign's post.
	 * @param string $reason  Why, in words.
	 * @return int How many.
	 */
	public static function call_off( $post_id, $reason ) {
		global $wpdb;

		$queue = Mailhouse_Queue::table();

		unset( self::$cache[ (int) $post_id ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET status = 'cancelled', error = %s, reported = 1, claim_token = NULL, claimed_at = NULL WHERE campaign_id = %d AND status IN ('waiting', 'pending', 'paused')", $reason, (int) $post_id ) );
	}

	/**
	 * An administrator of this site called a campaign off. This site keeps
	 * the last word.
	 *
	 * @param int $post_id The campaign's post.
	 * @return void
	 */
	public static function cancel_locally( $post_id ) {
		$row = self::for_post( $post_id );

		if ( ! $row ) {
			return;
		}

		self::cancel( $row, __( 'Called off by an administrator of this site.', 'mailhouse' ) );
	}

	/**
	 * Call a campaign off, whoever asked: what has not been attempted is
	 * not sent, and is kept. What has gone has gone.
	 *
	 * @param object $row    The campaign.
	 * @param string $reason Why, in words.
	 * @return int How many messages were called off, or -1 if it was already over.
	 */
	protected static function cancel( $row, $reason ) {
		if ( ! in_array( $row->state, self::ACTIVE, true ) ) {
			return -1;
		}

		$left = self::call_off( (int) $row->post_id, $reason );

		self::set(
			(int) $row->id,
			array(
				'state' => 'cancelled',
				'hold'  => '',
				'error' => $reason,
			)
		);

		$stats = Mailhouse_Queue::campaign_stats( (int) $row->post_id );

		update_post_meta( (int) $row->post_id, '_mailhouse_status', $stats['sent'] ? 'sent' : '' );

		return $left;
	}

	// ---- holding, and letting go ------------------------------------------------------

	/**
	 * Who is holding a campaign.
	 *
	 * @param object $row The campaign.
	 * @return string[] Any of `site` and `centre`.
	 */
	protected static function holders( $row ) {
		switch ( (string) $row->hold ) {
			case 'both':
				return array( 'site', 'centre' );
			case 'site':
			case 'centre':
				return array( (string) $row->hold );
		}

		return array();
	}

	/**
	 * Hold a campaign: nothing more of it is attempted until everybody who
	 * holds it has let it go. One that has not begun does not begin.
	 *
	 * @param object $row The campaign.
	 * @param string $by  `site` or `centre`.
	 * @return bool Whether it is one that can be held.
	 */
	protected static function hold( $row, $by ) {
		global $wpdb;

		if ( ! in_array( $row->state, self::ACTIVE, true ) ) {
			return false;
		}

		$holders = array_unique( array_merge( self::holders( $row ), array( $by ) ) );

		if ( 'sending' === $row->state ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				Mailhouse_Queue::table(),
				array( 'status' => 'paused' ),
				array(
					'campaign_id' => (int) $row->post_id,
					'status'      => 'pending',
				)
			);
			update_post_meta( (int) $row->post_id, '_mailhouse_status', 'paused' );
		}

		self::set(
			(int) $row->id,
			array(
				'hold'           => count( $holders ) > 1 ? 'both' : $by,
				// The centre is told at the next opportunity.
				'reported_state' => '',
			)
		);

		return true;
	}

	/**
	 * Let a campaign go, for one of those who hold it.
	 *
	 * @param object $row The campaign.
	 * @param string $by  `site` or `centre`.
	 * @return bool Whether nobody holds it now.
	 */
	protected static function release( $row, $by ) {
		global $wpdb;

		$before  = self::holders( $row );
		$holders = array_values( array_diff( $before, array( $by ) ) );

		if ( count( $before ) === count( $holders ) ) {
			return ! $holders;
		}

		if ( ! $holders && 'sending' === $row->state ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				Mailhouse_Queue::table(),
				array(
					'status'       => 'pending',
					'scheduled_at' => mailhouse_now(),
				),
				array(
					'campaign_id' => (int) $row->post_id,
					'status'      => 'paused',
				)
			);
			update_post_meta( (int) $row->post_id, '_mailhouse_status', 'sending' );
		}

		self::set(
			(int) $row->id,
			array(
				'hold'           => $holders ? $holders[0] : '',
				'reported_state' => '',
			)
		);

		return ! $holders;
	}

	/**
	 * Whether a campaign is held, for the queue to ask before each message.
	 *
	 * @param int $post_id The campaign's post.
	 * @return bool
	 */
	public static function held( $post_id ) {
		$row = self::for_post( $post_id );

		return $row && '' !== (string) $row->hold;
	}

	/**
	 * An administrator of this site holds a campaign.
	 *
	 * @param int $post_id The campaign's post.
	 * @return void
	 */
	public static function hold_locally( $post_id ) {
		$row = self::for_post( $post_id );

		if ( $row ) {
			self::hold( $row, 'site' );
		}
	}

	/**
	 * An administrator of this site lets a campaign go. A hold the centre
	 * put on is the centre's to lift.
	 *
	 * @param int $post_id The campaign's post.
	 * @return bool Whether nobody holds it now.
	 */
	public static function release_locally( $post_id ) {
		$row = self::for_post( $post_id );

		return $row ? self::release( $row, 'site' ) : false;
	}

	// ---- what the centre asks about a campaign this site holds -------------------------

	/**
	 * The campaign an execution id names.
	 *
	 * @param array $payload Decoded payload.
	 * @return object|null
	 */
	protected static function named( array $payload ) {
		global $wpdb;

		$execution = isset( $payload['execution_id'] ) && is_string( $payload['execution_id'] ) ? strtolower( $payload['execution_id'] ) : '';

		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $execution ) ) {
			return null;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE execution_id = %s", $execution ) );
	}

	/**
	 * Carry out `campaign.pause`, `campaign.resume` or `campaign.cancel`.
	 *
	 * Asked twice, each does nothing the second time. Asked of a campaign
	 * that is over, each does nothing and says so.
	 *
	 * @param string $verb    `pause`, `resume` or `cancel`.
	 * @param array  $payload Decoded payload.
	 * @return array array( status, result ).
	 */
	public static function control( $verb, array $payload ) {
		$row = self::named( $payload );

		if ( ! $row ) {
			return array( 'rejected', array( 'reason' => 'not_found' ) );
		}

		if ( ! in_array( $row->state, self::ACTIVE, true ) ) {
			return array(
				'applied',
				array(
					'noop'  => true,
					'state' => (string) $row->state,
				)
			);
		}

		switch ( $verb ) {
			case 'pause':
				$already = in_array( 'centre', self::holders( $row ), true );

				self::hold( $row, 'centre' );

				return array( 'applied', $already ? array( 'noop' => true ) : array( 'held' => true ) );

			case 'resume':
				if ( ! in_array( 'centre', self::holders( $row ), true ) ) {
					// Not held by the centre. If this site's administrator
					// holds it, that stands, and the centre is told so.
					return array(
						'applied',
						array(
							'noop'    => true,
							'held_by' => (string) $row->hold,
						)
					);
				}

				$free = self::release( $row, 'centre' );

				if ( $free ) {
					Mailhouse_Queue::kick();
				}

				return array( 'applied', array( 'held_by' => $free ? '' : 'site' ) );

			case 'cancel':
				$left  = self::cancel( $row, __( 'Called off at the centre.', 'mailhouse' ) );
				$stats = Mailhouse_Queue::campaign_stats( (int) $row->post_id );

				return array(
					'applied',
					array(
						// How many had gone before this arrived: they cannot be recalled.
						'sent'      => (int) $stats['sent'],
						'cancelled' => max( 0, $left ),
					)
				);
		}

		return array( 'rejected', array( 'reason' => 'unsupported' ) );
	}

	/**
	 * Carry out `campaign.resolve`: what an operator at the centre decided
	 * about messages that may or may not have gone.
	 *
	 * `resend` is the only way such a message is ever tried again, and only
	 * while the campaign may still be sent here. The other two send
	 * nothing: they bring this site's record into line with the centre's.
	 *
	 * @param array $payload Decoded payload.
	 * @return array array( status, result ).
	 */
	public static function resolve( array $payload ) {
		global $wpdb;

		$row        = self::named( $payload );
		$resolution = isset( $payload['resolution'] ) && is_string( $payload['resolution'] ) ? $payload['resolution'] : '';
		$jobs       = array();

		foreach ( isset( $payload['job_ids'] ) && is_array( $payload['job_ids'] ) ? $payload['job_ids'] : array() as $job ) {
			if ( is_string( $job ) && preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower( $job ) ) ) {
				$jobs[] = strtolower( $job );
			}
		}

		if ( ! $jobs || count( $jobs ) > 200 || ! in_array( $resolution, array( 'resend', 'mark_sent', 'abandon' ), true ) ) {
			return array( 'rejected', array( 'reason' => 'malformed' ) );
		}

		if ( ! $row ) {
			return array( 'rejected', array( 'reason' => 'not_found' ) );
		}

		$queue = Mailhouse_Queue::table();
		$post  = (int) $row->post_id;
		$in    = implode( ',', array_fill( 0, count( $jobs ), '%s' ) );

		if ( 'resend' === $resolution ) {
			// Nothing of a campaign is sent once it is called off, or after
			// its deadline: not even on the centre's word.
			if ( in_array( $row->state, array( 'cancelled', 'failed' ), true ) || strtotime( $row->deadline_at . ' UTC' ) <= self::now() ) {
				return array( 'rejected', array( 'reason' => 'too_late' ) );
			}

			// Whatever the centre thinks is unclear and this site holds as
			// settled is told to the centre again, as it is.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					"UPDATE {$queue} SET reported = 1 WHERE campaign_id = %d AND status IN ('sent', 'failed', 'skipped', 'cancelled') AND remote_job_id IN ({$in})",
					array_merge( array( $post ), $jobs )
				)
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$changed = (int) $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					"UPDATE {$queue} SET status = %s, scheduled_at = %s, error = '', error_class = NULL, reported = 0, claim_token = NULL, claimed_at = NULL, attempt_started_at = NULL WHERE campaign_id = %d AND status = 'uncertain' AND remote_job_id IN ({$in})",
					array_merge( array( '' !== (string) $row->hold ? 'paused' : 'pending', mailhouse_now(), $post ), $jobs )
				)
			);

			if ( $changed && 'completed' === $row->state ) {
				// It has something to send again.
				self::set( (int) $row->id, array( 'state' => 'sending' ) );
				update_post_meta( $post, '_mailhouse_status', '' !== (string) $row->hold ? 'paused' : 'sending' );
			}

			if ( $changed ) {
				Mailhouse_Queue::kick();
			}

			return array( 'applied', $changed ? array( 'changed' => $changed ) : array( 'noop' => true ) );
		}

		$sent = 'mark_sent' === $resolution;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$changed = (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				"UPDATE {$queue} SET status = %s, sent_at = IF(%d = 1, COALESCE(sent_at, %s), sent_at), error = %s, reported = 2 WHERE campaign_id = %d AND status = 'uncertain' AND remote_job_id IN ({$in})",
				array_merge(
					array(
						$sent ? 'sent' : 'failed',
						$sent ? 1 : 0,
						mailhouse_now(),
						$sent ? __( 'Counted as sent at the centre, on an operator’s judgement.', 'mailhouse' ) : __( 'Given up at the centre.', 'mailhouse' ),
						$post,
					),
					$jobs
				)
			)
		);

		return array( 'applied', $changed ? array( 'changed' => $changed ) : array( 'noop' => true ) );
	}

	/**
	 * Carry out `campaign.test`: send a draft, now, to a few addresses an
	 * operator named, in this site's template and through its transport.
	 *
	 * Nothing is created: no campaign, no record, no row in the queue, and
	 * nobody's statistics change. It is addressed to no subscriber, so a
	 * merge tag is filled in with nobody's details and the footer carries
	 * no unsubscribe link of anybody's.
	 *
	 * @param array $payload Decoded payload.
	 * @return array array( status, result ).
	 */
	public static function test( array $payload ) {
		$text = static function ( $key ) use ( $payload ) {
			return isset( $payload[ $key ] ) && is_string( $payload[ $key ] ) ? $payload[ $key ] : null;
		};

		$subject   = $text( 'subject' );
		$preheader = $text( 'preheader' );
		$html      = $text( 'body_html' );
		$to        = array();

		foreach ( isset( $payload['to'] ) && is_array( $payload['to'] ) ? $payload['to'] : array() as $address ) {
			$address = is_string( $address ) ? mailhouse_clean_email( $address ) : '';

			if ( '' === $address ) {
				return array( 'rejected', array( 'reason' => 'malformed' ) );
			}

			$to[ $address ] = true;
		}

		if (
			null === $subject || '' === trim( $subject ) || null === $preheader || null === $html || '' === $html
			|| ! $to || count( $to ) > self::TEST_ADDRESSES
			|| preg_match( '/[\x00-\x1f\x7f]/', $subject ) || preg_match( '/[\x00-\x1f\x7f]/', $preheader )
		) {
			return array( 'rejected', array( 'reason' => 'malformed' ) );
		}

		// Cleaned here, as the real thing is.
		$body = self::sanitise( $html );

		if ( '' === trim( wp_strip_all_tags( $body ) ) && false === stripos( $body, '<img' ) ) {
			return array( 'rejected', array( 'reason' => 'unusable' ) );
		}

		$fields = array();

		foreach ( isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array() as $name ) {
			if ( is_string( $name ) && preg_match( '/^[a-z0-9_]{1,60}$/', strtolower( $name ) ) ) {
				$fields[] = strtolower( $name );
			}
		}

		$context = array(
			'subscriber' => null,
			'campaign'   => null,
			'fields'     => $fields,
		);

		// Never run as blocks or shortcodes, exactly as the real thing is not.
		$line    = Mailhouse_Merge_Tags::render( $subject, $context );
		$content = Mailhouse_Merge_Tags::render( $body, $context, 'html' );
		$wrapped = Mailhouse_Template::wrap(
			$content,
			array(
				'subject'         => $line,
				'preview'         => Mailhouse_Merge_Tags::render( $preheader, $context ),
				'subscriber'      => null,
				'unsubscribe_url' => '',
				'preferences_url' => '',
			)
		);
		/* translators: %s: the subject of a newsletter. */
		$line = sprintf( __( '[Test] %s', 'mailhouse' ), wp_specialchars_decode( $line, ENT_QUOTES ) );
		$sent = 0;

		foreach ( array_keys( $to ) as $address ) {
			$took = Mailhouse_Mailer::send(
				$address,
				$line,
				$wrapped,
				array(
					'text'        => mailhouse_html_to_text( $wrapped ),
					'headers'     => array( 'X-MH-Test: 1' ),
					// By the route a campaign would take, or not at all.
					'no_fallback' => true,
					'from'        => mailhouse_clean_email( mailhouse_option( 'campaign_from_email' ) ),
					'from_name'   => (string) mailhouse_option( 'campaign_from_name' ),
					'reply_to'    => mailhouse_clean_email( mailhouse_option( 'campaign_reply_to' ) ),
				)
			);

			$sent += $took ? 1 : 0;
		}

		if ( ! $sent ) {
			return array( 'failed', array( 'reason' => 'transport' ) );
		}

		return array(
			'applied',
			array(
				'sent' => $sent,
				'of'   => count( $to ),
			)
		);
	}

	// ---- telling the centre what became of each message --------------------------------

	/**
	 * Tell the centre what became of each message since it was last told,
	 * a thousand at a time, for as long as the time allows.
	 *
	 * @param int $deadline When to stop, as a Unix time.
	 * @return void
	 */
	protected static function outcomes( $deadline ) {
		$tried = array();

		for ( $round = 0; $round < 50 && time() < $deadline; $round++ ) {
			$owed = self::owed( $tried );

			if ( ! $owed ) {
				return;
			}

			$by_campaign = array();

			foreach ( $owed as $item ) {
				$by_campaign[ (int) $item->campaign_id ][] = $item;
			}

			foreach ( $by_campaign as $post_id => $items ) {
				if ( time() >= $deadline ) {
					return;
				}

				$row = self::for_post( $post_id );

				if ( ! $row ) {
					// A message with no campaign: there is nobody to tell.
					self::mark( $items, self::REPORTED_NOBODY );
					continue;
				}

				if ( (int) $row->next_at > time() || ! self::report( $row, max( 1, min( Mailhouse_Central::TICK_TIMEOUT, $deadline - time() ) ), $items ) ) {
					// The centre could not be told. Not again in this run.
					$tried[] = (int) $post_id;
				}
			}

			if ( count( $owed ) < self::REPORT_PAGE ) {
				return;
			}
		}
	}

	/**
	 * The messages whose outcome, or whose first open or click, the centre
	 * has not been told: the oldest first, at most a page.
	 *
	 * @param int[] $except Campaigns to leave out.
	 * @return object[]
	 */
	protected static function owed( array $except = array() ) {
		global $wpdb;

		$queue = Mailhouse_Queue::table();
		$not   = $except ? ' AND campaign_id NOT IN (' . implode( ',', array_map( 'intval', $except ) ) . ')' : '';
		$found = array();

		foreach ( array( self::REPORTED_OWED, self::REPORTED_ENGAGED ) as $kind ) {
			$room = self::REPORT_PAGE - count( $found );

			if ( $room <= 0 ) {
				break;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$page = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, campaign_id, remote_job_id, status, attempts, error, error_class, sent_at, opened_at, clicked_at, provider_message_id, reported FROM {$queue} WHERE reported = %d AND remote_job_id IS NOT NULL{$not} ORDER BY id ASC LIMIT %d", $kind, $room ) );

			$found = array_merge( $found, $page );
		}

		return $found;
	}

	/**
	 * A moment of this site's, as the centre reads one.
	 *
	 * @param string|null $datetime A `Y-m-d H:i:s` in UTC, or nothing for now.
	 * @return string
	 */
	protected static function at( $datetime = null ) {
		$time = $datetime ? strtotime( $datetime . ' UTC' ) : false;

		return gmdate( 'Y-m-d\TH:i:s\Z', false === $time ? time() : $time );
	}

	/**
	 * What a transport said, for the centre: cut to length, and with any
	 * address taken out. A refusal often quotes the address it refused, and
	 * the centre is not sent addresses.
	 *
	 * @param string $error What it said.
	 * @return string
	 */
	public static function said( $error ) {
		$error = preg_replace( '/[^\s<>()\[\],;:"\']+@[^\s<>()\[\],;:"\']+/', '[address]', (string) $error );

		return Mailhouse_Central_Protocol::text( (string) $error, 300 );
	}

	/**
	 * What to tell the centre about one message.
	 *
	 * @param object $item A row of the queue.
	 * @return array[] Events.
	 */
	protected static function events_of( $item ) {
		$events = array();
		$job    = (string) $item->remote_job_id;
		$tries  = max( 1, (int) $item->attempts );
		$error  = '' !== (string) $item->error ? self::said( (string) $item->error ) : '';

		if ( self::REPORTED_OWED === (int) $item->reported ) {
			switch ( (string) $item->status ) {
				case 'sent':
					$event = array(
						'job_id'  => $job,
						'type'    => 'accepted',
						'at'      => self::at( $item->sent_at ),
						'attempt' => $tries,
					);

					if ( '' !== (string) $item->provider_message_id ) {
						$event['provider_message_id'] = substr( (string) $item->provider_message_id, 0, 191 );
					}

					$events[] = $event;
					break;

				case 'failed':
					$event = array(
						'job_id'  => $job,
						'type'    => 'failed',
						'at'      => self::at(),
						'attempt' => $tries,
						'class'   => 'transient' === (string) $item->error_class ? 'transient' : 'permanent',
					);

					if ( '' !== $error ) {
						$event['error'] = $error;
					}

					$events[] = $event;
					break;

				case 'uncertain':
					$event = array(
						'job_id'  => $job,
						'type'    => 'uncertain',
						'at'      => self::at(),
						'attempt' => $tries,
					);

					if ( '' !== $error ) {
						$event['error'] = $error;
					}

					$events[] = $event;
					break;

				case 'skipped':
					$events[] = array(
						'job_id' => $job,
						'type'   => 'skipped',
						'at'     => self::at(),
						'reason' => 'not_subscribed',
					);
					break;

				case 'cancelled':
					$event = array(
						'job_id' => $job,
						'type'   => 'cancelled',
						'at'     => self::at(),
					);

					if ( '' !== $error ) {
						$event['error'] = $error;
					}

					$events[] = $event;
					break;
			}
		}

		if ( $item->opened_at ) {
			$events[] = array(
				'job_id' => $job,
				'type'   => 'opened',
				'at'     => self::at( $item->opened_at ),
			);
		}

		if ( $item->clicked_at ) {
			$events[] = array(
				'job_id' => $job,
				'type'   => 'clicked',
				'at'     => self::at( $item->clicked_at ),
			);
		}

		return $events;
	}

	/**
	 * Mark messages as told to the centre: each only if it is still as it
	 * was when it was read. One that has been opened in the meantime stays
	 * owed, and is told again with the open.
	 *
	 * @param object[] $items Rows of the queue, as they were read.
	 * @param int      $as    What to mark them.
	 * @return void
	 */
	protected static function mark( array $items, $as = self::REPORTED_DONE ) {
		global $wpdb;

		$queue  = Mailhouse_Queue::table();
		$groups = array();

		foreach ( $items as $item ) {
			$groups[ ( $item->opened_at ? 'o' : '-' ) . ( $item->clicked_at ? 'c' : '-' ) ][] = (int) $item->id;
		}

		foreach ( $groups as $signature => $ids ) {
			$same  = 'o' === $signature[0] ? 'opened_at IS NOT NULL' : 'opened_at IS NULL';
			$same .= 'c' === $signature[1] ? ' AND clicked_at IS NOT NULL' : ' AND clicked_at IS NULL';

			foreach ( array_chunk( $ids, 500 ) as $chunk ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET reported = %d WHERE reported IN (1, 3) AND {$same} AND id IN (" . implode( ',', $chunk ) . ')', (int) $as ) );
			}
		}
	}

	/**
	 * How many outcomes the centre has yet to be told, for one campaign or
	 * for all of them.
	 *
	 * @param int $post_id A campaign's post, or 0 for every campaign.
	 * @return int
	 */
	public static function unreported( $post_id = 0 ) {
		global $wpdb;

		$queue = Mailhouse_Queue::table();

		if ( $post_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE reported = 1 AND campaign_id = %d", (int) $post_id ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE reported = 1" );
	}

	/**
	 * What this site says a campaign's state is. One that is held is
	 * `paused`, whatever it was doing when it was held.
	 *
	 * @param object $row The campaign.
	 * @return string
	 */
	protected static function says( $row ) {
		return '' !== (string) $row->hold && in_array( $row->state, array( 'ready', 'sending' ), true ) ? 'paused' : (string) $row->state;
	}

	/**
	 * Give up on a campaign that cannot be prepared, saying why. Nothing
	 * of it is sent.
	 *
	 * @param int    $row_id The campaign's row.
	 * @param string $reason Why, in words.
	 * @return void
	 */
	protected static function fail( $row_id, $reason ) {
		$row = self::get( $row_id );

		if ( $row && (int) $row->post_id ) {
			self::call_off( (int) $row->post_id, $reason );
			update_post_meta( (int) $row->post_id, '_mailhouse_status', '' );
		}

		self::set(
			$row_id,
			array(
				'state' => 'failed',
				'error' => substr( $reason, 0, 300 ),
			)
		);
	}

	/**
	 * Tell the centre how far a campaign has got, and what became of some
	 * of its messages.
	 *
	 * @param object   $row     The campaign.
	 * @param int      $timeout Seconds to wait.
	 * @param object[] $items   Rows of the queue whose outcome is owed.
	 * @return bool Whether the centre was told.
	 */
	protected static function report( $row, $timeout, array $items = array() ) {
		$says     = self::says( $row );
		$progress = array(
			'state'             => $says,
			'local_campaign_id' => max( 1, (int) $row->post_id ),
			'stored'            => self::stored( (int) $row->post_id ),
			'pending'           => self::unsent( (int) $row->post_id ),
			// Whether this site records opens and clicks at all. Where it
			// does not, none is not zero.
			'tracking'          => array(
				'opens'  => (bool) mailhouse_option( 'track_opens' ),
				'clicks' => (bool) mailhouse_option( 'track_clicks' ),
			),
		);

		if ( '' !== (string) $row->hold && 'paused' === $says ) {
			$progress['held_by'] = (string) $row->hold;
		}

		// What this site arrived at, with every report from ready onwards:
		// the centre may not have heard that it was ready before it hears
		// that it is under way, or over.
		$events  = array();
		$unknown = array();
		$arrived = in_array( $row->state, array( 'ready', 'sending', 'completed' ), true );

		if ( $arrived ) {
			$unknown = json_decode( (string) $row->unknown, true );
			$unknown = is_array( $unknown ) ? $unknown : array();

			$progress['prepared'] = array(
				'recipients'        => (int) $row->recipient_count,
				'hash'              => (string) $row->recipients_hash,
				'unknown_local_ids' => array_slice( array_map( 'intval', array_keys( $unknown ) ), 0, 100 ),
				'unknown'           => (int) $row->unknown_count,
			);
		}

		// Those the centre listed and this site does not hold: said once,
		// each as a message that was not sent.
		$naming = $arrived && $unknown && ! (int) $row->unknown_reported && count( $items ) + count( $unknown ) <= self::REPORT_PAGE;

		if ( $naming ) {
			foreach ( $unknown as $job ) {
				if ( is_string( $job ) ) {
					$events[] = array(
						'job_id' => $job,
						'type'   => 'skipped',
						'at'     => self::at(),
						'reason' => 'not_held',
					);
				}
			}
		}

		$carried = 0;

		foreach ( $items as $item ) {
			$of = self::events_of( $item );

			if ( count( $events ) + count( $of ) > self::REPORT_PAGE ) {
				break;
			}

			$events = array_merge( $events, $of );
			++$carried;
		}

		$items = array_slice( $items, 0, $carried );

		// How many outcomes will still be owed once this has been heard:
		// what the centre goes by when a campaign says it has finished.
		$owed = 0;

		foreach ( $items as $item ) {
			$owed += self::REPORTED_OWED === (int) $item->reported ? 1 : 0;
		}

		$progress['unreported'] = max( 0, self::unreported( (int) $row->post_id ) - $owed ) + ( $arrived && $unknown && ! (int) $row->unknown_reported && ! $naming ? count( $unknown ) : 0 );

		if ( '' !== (string) $row->error ) {
			$progress['error'] = self::said( (string) $row->error );
		}

		$body = array( 'progress' => $progress );

		if ( $events ) {
			$body['events'] = $events;
		}

		$answer = Mailhouse_Central_Client::signed( 'POST', '/api/v1/connector/executions/' . $row->execution_id . '/events', $body, $timeout );

		$body = ! is_wp_error( $answer ) && is_array( $answer['body'] ) ? $answer['body'] : array();
		$gone = ! is_wp_error( $answer ) && 404 === (int) $answer['status'] && isset( $body['code'] ) && 'not_found' === $body['code'];

		// Heard, or told in as many words that there is no such campaign.
		// Anything else is tried again.
		if ( is_wp_error( $answer ) || ( 200 !== (int) $answer['status'] && ! $gone ) ) {
			self::set( (int) $row->id, array( 'next_at' => time() + self::RETRY_AFTER ) );

			return false;
		}

		$status = isset( $body['status'] ) && is_string( $body['status'] ) ? $body['status'] : '';

		// Told. Each message only if it is as it was when it was read.
		self::mark( $items, $gone ? self::REPORTED_NOBODY : self::REPORTED_DONE );

		$fields = array(
			'reported_state' => (string) $row->state,
			'reported_at'    => time(),
			'next_at'        => 0,
		);

		if ( $naming && ! $gone ) {
			$fields['unknown_reported'] = 1;
		}

		self::set( (int) $row->id, $fields );

		// What the centre holds it to be. Called off there, nothing more of
		// it goes from here, whether or not the request to stop has
		// arrived. Held or failed there and not begun here, it does not
		// begin.
		if ( $gone ) {
			self::stop( $row, __( 'The centre no longer knows this campaign.', 'mailhouse' ) );
		} elseif ( in_array( $status, array( 'cancelled', 'blocked', 'failed' ), true ) && in_array( $row->state, array( 'preparing', 'ready' ), true ) ) {
			self::stop( $row, __( 'The centre called this campaign off.', 'mailhouse' ) );
		} elseif ( 'cancelled' === $status && 'sending' === $row->state ) {
			self::cancel( $row, __( 'Called off at the centre.', 'mailhouse' ) );
		}

		return true;
	}

	// ---- reading and writing a row ------------------------------------------------------

	/**
	 * One campaign, by its row.
	 *
	 * @param int $row_id Row id.
	 * @return object|null
	 */
	protected static function get( $row_id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $row_id ) );
	}

	/**
	 * Change a campaign's row.
	 *
	 * @param int   $row_id Row id.
	 * @param array $fields Column/value pairs.
	 * @return void
	 */
	protected static function set( $row_id, array $fields ) {
		global $wpdb;

		$fields['updated_at'] = mailhouse_now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table(), $fields, array( 'id' => (int) $row_id ) );

		self::$cache = array();
	}

	/**
	 * Forget what has been looked up, so that it is read again.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = array();
	}

	/**
	 * The campaign a post is the record of, if it is one.
	 *
	 * @param int $post_id Post id.
	 * @return object|null
	 */
	public static function for_post( $post_id ) {
		global $wpdb;

		$post_id = (int) $post_id;

		if ( ! isset( self::$cache[ $post_id ] ) ) {
			$table = self::table();

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$row = $post_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ) ) : null;

			self::$cache[ $post_id ] = $row ? $row : false;
		}

		return self::$cache[ $post_id ] ? self::$cache[ $post_id ] : null;
	}

	/**
	 * Whether a post is the record of a campaign from the centre.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function is_central( $post_id ) {
		return '' !== (string) get_post_meta( (int) $post_id, self::META, true );
	}

	/**
	 * Whether a campaign's deadline has passed, for the queue to ask
	 * before each message.
	 *
	 * @param int $post_id The campaign's post.
	 * @return bool
	 */
	public static function past_deadline( $post_id ) {
		$row = self::for_post( $post_id );

		return $row && strtotime( $row->deadline_at . ' UTC' ) <= self::now();
	}

	/**
	 * What a campaign says, for the renderer.
	 *
	 * @param int $post_id The campaign's post.
	 * @return array{subject:string,preheader:string,body:string,fields:string[]}|null
	 */
	public static function content( $post_id ) {
		$row = self::for_post( $post_id );

		if ( ! $row ) {
			return null;
		}

		$fields = json_decode( (string) $row->fields, true );

		return array(
			'subject'   => (string) $row->subject,
			'preheader' => (string) $row->preheader,
			'body'      => (string) $row->body_html,
			'fields'    => is_array( $fields ) ? array_values( array_filter( $fields, 'is_string' ) ) : array(),
		);
	}

	/**
	 * How many campaigns from the centre this site holds, by state.
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		global $wpdb;

		$table  = self::table();
		$counts = array();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_results( "SELECT state, COUNT(*) AS n FROM {$table} GROUP BY state" ) as $row ) {
			$counts[ (string) $row->state ] = (int) $row->n;
		}

		return $counts;
	}

	// ---- the record among this site's emails ----------------------------------------------

	/**
	 * Keep the record as it was made: its title and its text are the
	 * centre's, and saving the post changes neither.
	 *
	 * @param array $data    What is about to be saved.
	 * @param array $postarr What was asked for.
	 * @return array
	 */
	public static function keep_record( $data, $postarr ) {
		$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;

		if ( ! $id || ! isset( $data['post_type'] ) || Mailhouse_Emails::POST_TYPE !== $data['post_type'] || ! self::is_central( $id ) ) {
			return $data;
		}

		$stored = get_post( $id );

		if ( $stored ) {
			$data['post_title']   = $stored->post_title;
			$data['post_content'] = $stored->post_content;
		}

		return $data;
	}

	/**
	 * Say, at the top of the record's screen, what it is.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return void
	 */
	public static function notice( $post ) {
		if ( ! $post || Mailhouse_Emails::POST_TYPE !== $post->post_type || ! self::is_central( $post->ID ) ) {
			return;
		}

		$row = self::for_post( $post->ID );

		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Sent for Mailhouse Central.', 'mailhouse' ) . '</strong> ';
		echo esc_html__( 'This newsletter was written there and is sent by this site, in this site’s template, to this site’s own subscribers. It cannot be edited here. You can pause it or call it off.', 'mailhouse' );

		if ( $row && '' !== (string) $row->error ) {
			echo ' ' . esc_html( (string) $row->error );
		}

		echo '</p></div>';
	}

	/**
	 * The Sending panel of a record: what the centre asked for and how far
	 * it has got, in place of the controls an email of this site's has.
	 * There is nobody to choose and no time to set. It can be paused, and
	 * it can be called off.
	 *
	 * @param WP_Post $post The record.
	 * @return void
	 */
	public static function panel( $post ) {
		$row    = self::for_post( $post->ID );
		$stats = Mailhouse_Queue::campaign_stats( $post->ID );

		if ( ! $row ) {
			echo '<p>' . esc_html__( 'The connector no longer holds this campaign.', 'mailhouse' ) . '</p>';

			return;
		}

		$labels = array(
			'preparing' => __( 'Fetching its recipients from the centre.', 'mailhouse' ),
			'ready'     => __( 'Ready. It starts at the time below.', 'mailhouse' ),
			'sending'   => __( 'Being sent.', 'mailhouse' ),
			'completed' => __( 'Finished.', 'mailhouse' ),
			'cancelled' => __( 'Called off.', 'mailhouse' ),
			'failed'    => __( 'Not sent: it could not be prepared.', 'mailhouse' ),
		);

		$holders = in_array( $row->state, self::ACTIVE, true ) ? self::holders( $row ) : array();
		$label   = isset( $labels[ $row->state ] ) ? $labels[ $row->state ] : $row->state;

		if ( in_array( 'centre', $holders, true ) ) {
			$label = in_array( 'site', $holders, true ) ? __( 'Held, here and at the centre. Both must let it go.', 'mailhouse' ) : __( 'Held at the centre, which is where it is let go.', 'mailhouse' );
		} elseif ( $holders ) {
			$label = __( 'Held here.', 'mailhouse' );
		}

		echo '<p><strong>' . esc_html( $label ) . '</strong></p>';

		printf(
			'<p>%s</p>',
			sprintf(
				/* translators: 1: number sent, 2: number of recipients. */
				esc_html__( 'Sent %1$s of %2$s.', 'mailhouse' ),
				'<strong>' . esc_html( number_format_i18n( $stats['sent'] ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( (int) $row->recipient_count ) ) . '</strong>'
			)
		);

		printf(
			'<p class="description">%s<br />%s</p>',
			/* translators: %s: date and time. */
			sprintf( esc_html__( 'Starts: %s', 'mailhouse' ), esc_html( mailhouse_date( $row->start_at ) ) ),
			/* translators: %s: date and time. */
			sprintf( esc_html__( 'Nothing is sent after: %s', 'mailhouse' ), esc_html( mailhouse_date( $row->deadline_at ) ) )
		);

		$notes = array();

		if ( $stats['failed'] ) {
			/* translators: %s: a number of messages. */
			$notes[] = sprintf( __( '%s refused', 'mailhouse' ), number_format_i18n( $stats['failed'] ) );
		}

		if ( $stats['uncertain'] ) {
			/* translators: %s: a number of messages. */
			$notes[] = sprintf( __( '%s may or may not have gone, and are not tried again unless the centre says so', 'mailhouse' ), number_format_i18n( $stats['uncertain'] ) );
		}

		if ( $stats['skipped'] ) {
			/* translators: %s: a number of messages. */
			$notes[] = sprintf( __( '%s not sent: no longer subscribed', 'mailhouse' ), number_format_i18n( $stats['skipped'] ) );
		}

		if ( $stats['cancelled'] ) {
			/* translators: %s: a number of messages. */
			$notes[] = sprintf( __( '%s called off', 'mailhouse' ), number_format_i18n( $stats['cancelled'] ) );
		}

		if ( $notes ) {
			echo '<p class="description">' . esc_html( implode( '; ', $notes ) ) . '.</p>';
		}

		if ( '' !== (string) $row->error ) {
			echo '<p>' . esc_html( (string) $row->error ) . '</p>';
		}

		if ( ! in_array( $row->state, self::ACTIVE, true ) ) {
			return;
		}

		echo '<p>';

		// This site's administrator lifts this site's hold, and no other.
		if ( in_array( 'site', $holders, true ) ) {
			printf( '<a href="%s" class="button button-primary">%s</a> ', esc_url( Mailhouse_Emails::action_url( 'mailhouse_resume_campaign', $post->ID ) ), esc_html__( 'Resume', 'mailhouse' ) );
		} elseif ( in_array( $row->state, array( 'ready', 'sending' ), true ) ) {
			printf( '<a href="%s" class="button">%s</a> ', esc_url( Mailhouse_Emails::action_url( 'mailhouse_pause_campaign', $post->ID ) ), esc_html__( 'Pause', 'mailhouse' ) );
		}

		printf(
			'<a href="%s" class="button" onclick="return confirm(%s)">%s</a>',
			esc_url( Mailhouse_Emails::action_url( 'mailhouse_cancel_campaign', $post->ID ) ),
			esc_attr( wp_json_encode( __( 'Call this newsletter off on this site? What has not been sent will not be. This cannot be undone from here.', 'mailhouse' ) ) ),
			esc_html__( 'Call off', 'mailhouse' )
		);

		echo '</p>';
	}

	/**
	 * Label the record in the list of emails.
	 *
	 * @param string[] $states Labels.
	 * @param WP_Post  $post   The post.
	 * @return string[]
	 */
	public static function label( $states, $post ) {
		if ( $post && Mailhouse_Emails::POST_TYPE === $post->post_type && self::is_central( $post->ID ) ) {
			$states['mailhouse_central'] = __( 'From Mailhouse Central', 'mailhouse' );
		}

		return $states;
	}
}
