<?php
/**
 * The send queue.
 *
 * Nothing is sent during the request that asks for it. A campaign is written
 * to a table one row per recipient, and a cron job drains it a batch at a
 * time. That is the difference between a list of 5,000 arriving and a PHP
 * timeout halfway through with no record of who already got it.
 *
 * Rows survive the send: they are what the open and click counts hang off, and
 * what tells you whether a particular person actually received the last issue.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queues recipients and sends them in batches.
 */
class Mailhouse_Queue {

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_action( 'mailhouse_process_queue', array( __CLASS__, 'process' ) );

		add_action( 'wp_ajax_nopriv_mailhouse_run_queue', array( __CLASS__, 'ajax_process' ) );
		add_action( 'wp_ajax_mailhouse_run_queue', array( __CLASS__, 'ajax_process' ) );

		if ( is_admin() ) {
			add_action( 'admin_post_mailhouse_send_campaign', array( $this, 'handle_send' ) );
			add_action( 'admin_post_mailhouse_pause_campaign', array( $this, 'handle_pause' ) );
			add_action( 'admin_post_mailhouse_resume_campaign', array( $this, 'handle_resume' ) );
			add_action( 'admin_post_mailhouse_cancel_campaign', array( $this, 'handle_cancel' ) );
		}
	}

	/**
	 * The queue table.
	 *
	 * @return string
	 */
	public static function table() {
		return Mailhouse_Install::table( 'queue' );
	}

	/**
	 * How many subscribers a campaign would go to.
	 *
	 * @param int $tag_id Tag to limit to, or 0 for everyone.
	 * @return int
	 */
	public static function recipient_count( $tag_id = 0 ) {
		return Mailhouse_Subscribers::count(
			array(
				'status' => 'subscribed',
				'tag'    => (int) $tag_id,
			)
		);
	}

	/**
	 * Queue every recipient of a campaign.
	 *
	 * @param int $post_id Campaign id.
	 * @param int $when    Unix timestamp to send at, or 0 for as soon as possible.
	 * @return int Number of rows queued.
	 */
	public static function enqueue_campaign( $post_id, $when = 0 ) {
		global $wpdb;

		$post = get_post( $post_id );

		if ( ! $post || Mailhouse_Emails::POST_TYPE !== $post->post_type ) {
			return 0;
		}

		$tag_id     = (int) get_post_meta( $post_id, '_mailhouse_tag', true );
		$subscribers = Mailhouse_Subscribers::query(
			array(
				'status'   => 'subscribed',
				'tag'      => $tag_id,
				'per_page' => 0,
			)
		);

		if ( ! $subscribers ) {
			return 0;
		}

		$scheduled = $when ? gmdate( 'Y-m-d H:i:s', $when ) : mailhouse_now();
		$table     = self::table();
		$queued    = 0;

		// Chunked so a large list does not build one enormous query.
		foreach ( array_chunk( $subscribers, 200 ) as $chunk ) {
			$values = array();
			$params = array();

			foreach ( $chunk as $subscriber ) {
				$values[] = '(%d, %d, %s, %s, %s)';
				$params[] = (int) $post_id;
				$params[] = (int) $subscriber->id;
				$params[] = $subscriber->email;
				$params[] = 'pending';
				$params[] = $scheduled;
			}

			// INSERT IGNORE: the unique key on (campaign, subscriber) is what
			// stops a double click on Send from mailing everybody twice.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$queued += (int) $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"INSERT IGNORE INTO {$table} (campaign_id, subscriber_id, email, status, scheduled_at) VALUES " . implode( ', ', $values ),
					$params
				)
			);
		}

		update_post_meta( $post_id, '_mailhouse_status', $when ? 'scheduled' : 'sending' );

		if ( $when ) {
			update_post_meta( $post_id, '_mailhouse_send_at', $scheduled );
		}

		/**
		 * Fires once a campaign has been queued.
		 *
		 * @param int $post_id Campaign id.
		 * @param int $queued  Rows queued.
		 */
		do_action( 'mailhouse_campaign_queued', $post_id, $queued );

		if ( ! $when ) {
			self::kick();
		}

		return $queued;
	}

	/**
	 * Queue a single email to one subscriber, outside any campaign.
	 *
	 * @param int    $post_id       Email id.
	 * @param int    $subscriber_id Subscriber id.
	 * @param string $email         Address.
	 * @param int    $when          Timestamp, or 0 for now.
	 * @return bool
	 */
	public static function enqueue_one( $post_id, $subscriber_id, $email, $when = 0 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'INSERT IGNORE INTO ' . self::table() . ' (campaign_id, subscriber_id, email, status, scheduled_at) VALUES (%d, %d, %s, %s, %s)',
				(int) $post_id,
				(int) $subscriber_id,
				$email,
				'pending',
				$when ? gmdate( 'Y-m-d H:i:s', $when ) : mailhouse_now()
			)
		);

		if ( $inserted && ! $when ) {
			self::kick();
		}

		return (bool) $inserted;
	}

	/**
	 * Ask the site to start working through the queue now.
	 *
	 * A non-blocking loopback request, so the visitor who triggered the send
	 * is not the one waiting for it. If the request never lands -- some hosts
	 * block loopbacks -- the minute cron picks the work up anyway.
	 *
	 * @return void
	 */
	public static function kick() {
		if ( get_transient( 'mailhouse_queue_lock' ) ) {
			return;
		}

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
				'body'      => array(
					'action' => 'mailhouse_run_queue',
					'token'  => self::token(),
				),
			)
		);
	}

	/**
	 * The shared secret the loopback request proves it knows.
	 *
	 * @return string
	 */
	protected static function token() {
		return hash_hmac( 'sha256', 'mailhouse_queue', wp_salt( 'mailhouse_queue' ) );
	}

	/**
	 * The loopback endpoint.
	 *
	 * @return void
	 */
	public static function ajax_process() {
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( ! hash_equals( self::token(), $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		self::process();

		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Send the next batch.
	 *
	 * @return int Number of messages sent.
	 */
	public static function process() {
		if ( get_transient( 'mailhouse_queue_lock' ) ) {
			return 0;
		}

		// The transient keeps the pace to one batch a minute, which is what
		// "emails per minute" in the settings promises. It is not what stops
		// two runs sending the same message: claim() is.
		set_transient( 'mailhouse_queue_lock', 1, 2 * MINUTE_IN_SECONDS );

		self::reap_stale();

		$batch = max( 1, (int) mailhouse_option( 'batch_size', 20 ) );
		$rows  = self::claim( $batch );
		$sent  = 0;
		$start = time();

		foreach ( $rows as $row ) {
			// Leave time for the response to finish rather than being killed
			// mid-send by max_execution_time.
			if ( ( time() - $start ) > 20 ) {
				break;
			}

			if ( self::send_row( $row ) ) {
				$sent++;
			}
		}

		// Whatever the time budget did not reach goes back for the next run.
		if ( $rows ) {
			self::release( $rows[0]->claim_token );
		}

		foreach ( array_unique( wp_list_pluck( $rows, 'campaign_id' ) ) as $campaign_id ) {
			self::maybe_finish( (int) $campaign_id );
		}

		delete_transient( 'mailhouse_queue_lock' );

		return $sent;
	}

	/**
	 * How long a claimed row may sit unsent before it is taken to have been
	 * abandoned by a process that died.
	 */
	const STALE_AFTER = 600; // 10 minutes.

	/**
	 * Take the next batch of due rows, and nobody else's.
	 *
	 * One UPDATE decides which rows belong to this run: it marks them as
	 * being sent and stamps them with a token, and the database guarantees
	 * that two runs arriving together come away with different rows. Reading
	 * the rows first and marking them afterwards -- which is what this did
	 * before -- leaves a gap in which both read the same ones.
	 *
	 * It also means a row says `sending` before anything is handed to a
	 * transport, so a process that dies part-way leaves evidence of where
	 * it was.
	 *
	 * @param int $limit Batch size.
	 * @return object[] The claimed rows, each carrying the batch's claim_token.
	 */
	public static function claim( $limit ) {
		global $wpdb;

		$table = self::table();
		$token = mailhouse_token( 16 );
		$now   = mailhouse_now();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'sending', claim_token = %s, claimed_at = %s
				 WHERE status = 'pending' AND scheduled_at <= %s
				 ORDER BY scheduled_at ASC, id ASC LIMIT %d",
				$token,
				$now,
				$now,
				max( 1, (int) $limit )
			)
		);

		if ( ! $claimed ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE claim_token = %s ORDER BY scheduled_at ASC, id ASC", $token )
		);
	}

	/**
	 * Hand back rows that were claimed and never attempted.
	 *
	 * @param string $token The claim to release; empty releases every claim,
	 *                      which is only right when nothing can be sending.
	 * @return int Rows returned to the queue.
	 */
	public static function release( $token = '' ) {
		global $wpdb;

		$table = self::table();

		if ( '' === (string) $token ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->query( "UPDATE {$table} SET status = 'pending', claim_token = NULL, claimed_at = NULL WHERE status = 'sending'" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET status = 'pending', claim_token = NULL, claimed_at = NULL WHERE status = 'sending' AND claim_token = %s", $token )
		);
	}

	/**
	 * Recover rows claimed by a run that never finished.
	 *
	 * They go back to pending and are tried again, which is what happened to
	 * them before claims existed: a run that died left its rows untouched,
	 * and the next one picked them up.
	 *
	 * @return int Rows recovered.
	 */
	public static function reap_stale() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', claim_token = NULL, claimed_at = NULL WHERE status = 'sending' AND claimed_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER )
			)
		);
	}

	/**
	 * Send one queued message.
	 *
	 * @param object $row Queue row.
	 * @return bool
	 */
	protected static function send_row( $row ) {
		$subscriber = Mailhouse_Subscribers::get( (int) $row->subscriber_id );

		// Somebody who unsubscribed after the campaign was queued must not get
		// it. This is the check that makes that true.
		if ( ! $subscriber || 'subscribed' !== $subscriber->status ) {
			self::settle(
				$row,
				array(
					'status' => 'skipped',
					'error'  => __( 'No longer subscribed.', 'mailhouse' ),
				)
			);

			return false;
		}

		$message = Mailhouse_Emails::render( (int) $row->campaign_id, $subscriber );

		if ( '' === $message['subject'] ) {
			self::settle(
				$row,
				array(
					'status' => 'failed',
					'error'  => __( 'The email no longer exists.', 'mailhouse' ),
				)
			);

			return false;
		}

		$html = Mailhouse_Tracking::prepare( $message['html'], (int) $row->id );

		$sent = Mailhouse_Mailer::send(
			$subscriber->email,
			$message['subject'],
			$html,
			array(
				'text'          => $message['text'],
				'headers'       => self::headers( $subscriber ),
				'from'          => mailhouse_clean_email( mailhouse_option( 'campaign_from_email' ) ),
				'from_name'     => (string) mailhouse_option( 'campaign_from_name' ),
				'reply_to'      => mailhouse_clean_email( mailhouse_option( 'campaign_reply_to' ) ),
				'campaign_id'   => (int) $row->campaign_id,
				'subscriber_id' => (int) $subscriber->id,
			)
		);

		if ( $sent ) {
			$result = Mailhouse_Mailer::last_result();

			self::settle(
				$row,
				array(
					'status'              => 'sent',
					'sent_at'             => mailhouse_now(),
					'attempts'            => (int) $row->attempts + 1,
					'error'               => '',
					// What the transport called this message, where it said.
					// It is the only thing a bounce reported hours later can
					// be matched back to.
					'provider_message_id' => '' !== $result['message_id'] ? substr( $result['message_id'], 0, 191 ) : null,
				)
			);

			Mailhouse_Subscribers::update( (int) $subscriber->id, array( 'sent' => (int) $subscriber->sent + 1 ) );

			return true;
		}

		$attempts = (int) $row->attempts + 1;
		$failed   = $attempts >= 3;

		self::settle(
			$row,
			array(
				'status'       => $failed ? 'failed' : 'pending',
				'attempts'     => $attempts,
				'error'        => __( 'Delivery failed. See the email log for the reason.', 'mailhouse' ),
				// Back off rather than hammering a transport that is refusing
				// everything.
				'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + ( $attempts * 5 * MINUTE_IN_SECONDS ) ),
			)
		);

		return false;
	}

	/**
	 * Record what became of a claimed row, and let go of the claim.
	 *
	 * @param object $row    Queue row.
	 * @param array  $fields Column/value pairs.
	 * @return void
	 */
	protected static function settle( $row, $fields ) {
		global $wpdb;

		$fields['claim_token'] = null;
		$fields['claimed_at']  = null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table(), $fields, array( 'id' => (int) $row->id ) );
	}

	/**
	 * The headers every bulk message carries.
	 *
	 * List-Unsubscribe is what puts the unsubscribe button in Gmail's own
	 * interface. Providers now expect it on bulk mail, and its absence counts
	 * against deliverability.
	 *
	 * @param object $subscriber Recipient.
	 * @return string[]
	 */
	protected static function headers( $subscriber ) {
		$url     = Mailhouse_Optin::unsubscribe_url( $subscriber );
		$headers = array(
			'List-Unsubscribe: <' . $url . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
			'Precedence: bulk',
		);

		/**
		 * Filter the headers added to campaign email.
		 *
		 * @param string[] $headers    Headers.
		 * @param object   $subscriber Recipient.
		 */
		return apply_filters( 'mailhouse_campaign_headers', $headers, $subscriber );
	}

	/**
	 * Mark a campaign finished once nothing is left pending.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return void
	 */
	protected static function maybe_finish( $campaign_id ) {
		$stats = self::campaign_stats( $campaign_id );

		if ( $stats['pending'] > 0 ) {
			return;
		}

		$status = Mailhouse_Emails::status( $campaign_id );

		if ( in_array( $status, array( 'sending', 'scheduled' ), true ) ) {
			update_post_meta( $campaign_id, '_mailhouse_status', 'sent' );
			update_post_meta( $campaign_id, '_mailhouse_sent_count', $stats['sent'] );

			/**
			 * Fires when the last message of a campaign has gone out.
			 *
			 * @param int   $campaign_id Campaign id.
			 * @param array $stats       Campaign totals.
			 */
			do_action( 'mailhouse_campaign_finished', $campaign_id, $stats );
		}
	}

	/**
	 * Totals for one campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array{total:int,pending:int,sent:int,failed:int,skipped:int,opened:int,clicked:int}
	 */
	public static function campaign_stats( $campaign_id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(status IN ('pending', 'sending')) AS pending,
					SUM(status = 'sent') AS sent,
					SUM(status = 'failed') AS failed,
					SUM(status = 'skipped') AS skipped,
					SUM(opened_at IS NOT NULL) AS opened,
					SUM(clicked_at IS NOT NULL) AS clicked
				 FROM {$table} WHERE campaign_id = %d",
				(int) $campaign_id
			),
			ARRAY_A
		);

		$stats = array_fill_keys( array( 'total', 'pending', 'sent', 'failed', 'skipped', 'opened', 'clicked' ), 0 );

		foreach ( (array) $row as $key => $value ) {
			$stats[ $key ] = (int) $value;
		}

		return $stats;
	}

	/**
	 * How many messages are waiting across the whole site.
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('pending', 'sending')" );
	}

	/**
	 * Start a campaign from the editor.
	 *
	 * @return void
	 */
	public function handle_send() {
		$post_id = $this->authorise( 'mailhouse_send_campaign' );

		$send_at = (string) get_post_meta( $post_id, '_mailhouse_send_at', true );
		$when    = 0;

		// A schedule in the past is treated as "now", not as an error.
		if ( $send_at ) {
			$timestamp = strtotime( $send_at . ' UTC' );

			if ( $timestamp > time() ) {
				$when = $timestamp;
			}
		}

		$queued = self::enqueue_campaign( $post_id, $when );

		$this->redirect_back( $post_id, $queued ? 'queued' : 'empty' );
	}

	/**
	 * Hold a campaign that is part-way through.
	 *
	 * @return void
	 */
	public function handle_pause() {
		$post_id = $this->authorise( 'mailhouse_pause_campaign' );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table(), array( 'status' => 'paused' ), array( 'campaign_id' => $post_id, 'status' => 'pending' ) );

		update_post_meta( $post_id, '_mailhouse_status', 'paused' );

		$this->redirect_back( $post_id, 'paused' );
	}

	/**
	 * Carry on with a paused campaign.
	 *
	 * @return void
	 */
	public function handle_resume() {
		$post_id = $this->authorise( 'mailhouse_resume_campaign' );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array(
				'status'       => 'pending',
				'scheduled_at' => mailhouse_now(),
			),
			array(
				'campaign_id' => $post_id,
				'status'      => 'paused',
			)
		);

		update_post_meta( $post_id, '_mailhouse_status', 'sending' );

		self::kick();

		$this->redirect_back( $post_id, 'resumed' );
	}

	/**
	 * Abandon whatever has not gone out yet.
	 *
	 * @return void
	 */
	public function handle_cancel() {
		$post_id = $this->authorise( 'mailhouse_cancel_campaign' );

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'DELETE FROM ' . self::table() . ' WHERE campaign_id = %d AND status IN (%s, %s)',
				$post_id,
				'pending',
				'paused'
			)
		);

		$stats = self::campaign_stats( $post_id );

		update_post_meta( $post_id, '_mailhouse_status', $stats['sent'] ? 'sent' : '' );
		delete_post_meta( $post_id, '_mailhouse_send_at' );

		$this->redirect_back( $post_id, 'cancelled' );
	}

	/**
	 * Check the nonce and capability for a campaign action.
	 *
	 * @param string $action Action name.
	 * @return int Campaign id.
	 */
	protected function authorise( $action ) {
		$post_id = isset( $_GET['email'] ) ? absint( $_GET['email'] ) : 0;

		check_admin_referer( $action . '_' . $post_id );

		if ( ! mailhouse_can_manage() || ! $post_id ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mailhouse' ), '', array( 'response' => 403 ) );
		}

		return $post_id;
	}

	/**
	 * Return to the campaign editor with a notice.
	 *
	 * @param int    $post_id Campaign id.
	 * @param string $notice  Notice key.
	 * @return void
	 */
	protected function redirect_back( $post_id, $notice ) {
		wp_safe_redirect(
			add_query_arg( 'mailhouse_notice', $notice, get_edit_post_link( $post_id, 'raw' ) )
		);
		exit;
	}
}
