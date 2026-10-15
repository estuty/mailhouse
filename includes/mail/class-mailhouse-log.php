<?php
/**
 * The email log.
 *
 * The point of a log is answering "did it go out, and if not, why" without
 * asking the customer to check their spam folder. Bodies are off by default:
 * password resets and order confirmations are exactly the kind of thing that
 * should not sit in a database table for a year.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records the outcome of every wp_mail() call.
 */
class Mailhouse_Log {

	/**
	 * Attribution for the message currently being sent.
	 *
	 * @var array
	 */
	protected static $context = array();

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_action( 'wp_mail_succeeded', array( __CLASS__, 'record_success' ) );
		add_action( 'wp_mail_failed', array( __CLASS__, 'record_failure' ) );
		add_action( 'mailhouse_daily_maintenance', array( __CLASS__, 'purge' ) );
	}

	/**
	 * The log table.
	 *
	 * @return string
	 */
	public static function table() {
		return Mailhouse_Install::table( 'log' );
	}

	/**
	 * Tag the next message with the campaign and subscriber it belongs to.
	 *
	 * @param array $context Keys: campaign_id, subscriber_id.
	 * @return void
	 */
	public static function set_context( array $context ) {
		self::$context = $context;
	}

	/**
	 * Log a delivered message.
	 *
	 * @param array $data wp_mail() data.
	 * @return void
	 */
	public static function record_success( $data ) {
		self::record( 'sent', is_array( $data ) ? $data : array(), '' );
	}

	/**
	 * Log a failed message and, if asked, warn an administrator.
	 *
	 * @param WP_Error $error Failure, carrying the mail data.
	 * @return void
	 */
	public static function record_failure( $error ) {
		$data    = is_wp_error( $error ) ? (array) $error->get_error_data() : array();
		$message = is_wp_error( $error ) ? $error->get_error_message() : __( 'Unknown error', 'mailhouse' );

		self::record( 'failed', $data, $message );

		self::maybe_alert( $data, $message );
	}

	/**
	 * Write a row.
	 *
	 * @param string $status sent or failed.
	 * @param array  $data   wp_mail() data.
	 * @param string $error  Failure message, if any.
	 * @return int Row id, or 0 when logging is off.
	 */
	public static function record( $status, $data, $error = '' ) {
		if ( ! mailhouse_option( 'log_enabled' ) ) {
			return 0;
		}

		global $wpdb;

		$to = isset( $data['to'] ) ? $data['to'] : array();
		$to = is_array( $to ) ? implode( ', ', $to ) : (string) $to;

		$headers = isset( $data['headers'] ) ? $data['headers'] : array();
		$headers = is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;

		// The unsubscribe header carries the subscriber's management key,
		// which is as good as a password to their preferences. It has no
		// business sitting in a log anybody with admin access can read.
		$headers = (string) preg_replace( '#(/mailhouse/(?:unsubscribe|preferences|confirm)/)[a-f0-9]{32}#i', '$1[redacted]', $headers );

		$attachments = isset( $data['attachments'] ) ? (array) $data['attachments'] : array();
		$names       = array();

		foreach ( $attachments as $attachment ) {
			$names[] = is_string( $attachment ) ? basename( $attachment ) : '';
		}

		$save_body = mailhouse_option( 'log_save_content' );

		$row = array(
			'created_at'    => mailhouse_now(),
			'status'        => $status,
			'mailer'        => Mailhouse_Mailer::provider() ? Mailhouse_Mailer::provider()->id() : (string) mailhouse_option( 'mailer', 'php' ),
			'from_email'    => Mailhouse_Mailer::last_from() ? Mailhouse_Mailer::last_from() : mailhouse_clean_email( mailhouse_option( 'from_email' ) ),
			'to_email'      => $to,
			'subject'       => isset( $data['subject'] ) ? (string) $data['subject'] : '',
			'error'         => $error,
			'headers'       => $headers,
			'body'          => $save_body && isset( $data['message'] ) ? (string) $data['message'] : '',
			'attachments'   => implode( ', ', array_filter( $names ) ),
			'campaign_id'   => isset( self::$context['campaign_id'] ) ? (int) self::$context['campaign_id'] : 0,
			'subscriber_id' => isset( self::$context['subscriber_id'] ) ? (int) self::$context['subscriber_id'] : 0,
		);

		/**
		 * Filter a log row before it is written. Return false to skip it.
		 *
		 * @param array|false $row Row.
		 */
		$row = apply_filters( 'mailhouse_log_row', $row );

		if ( ! $row ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( self::table(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Email an administrator about a failure, at most once an hour.
	 *
	 * The rate limit is not politeness. A failing transport plus an alert per
	 * failure is a loop that fills a queue with alerts about alerts.
	 *
	 * @param array  $data    Mail data.
	 * @param string $message Failure message.
	 * @return void
	 */
	protected static function maybe_alert( $data, $message ) {
		if ( ! mailhouse_option( 'alerts_enabled' ) ) {
			return;
		}

		$to = mailhouse_clean_email( mailhouse_option( 'alerts_email' ) );

		if ( ! $to || get_transient( 'mailhouse_alert_sent' ) ) {
			return;
		}

		// Never alert about the alert itself.
		$recipients = isset( $data['to'] ) ? (array) $data['to'] : array();

		foreach ( $recipients as $recipient ) {
			if ( false !== stripos( (string) $recipient, $to ) ) {
				return;
			}
		}

		set_transient( 'mailhouse_alert_sent', 1, HOUR_IN_SECONDS );

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Email delivery is failing', 'mailhouse' ),
			get_bloginfo( 'name' )
		);

		$body = sprintf(
			/* translators: 1: transport name, 2: error message, 3: subject of the failed email, 4: log URL. */
			__(
				"An email could not be sent.\n\nTransport: %1\$s\nError: %2\$s\nSubject: %3\$s\n\nFurther alerts are held back for an hour. The full log is at:\n%4\$s",
				'mailhouse'
			),
			(string) mailhouse_option( 'mailer' ),
			$message,
			isset( $data['subject'] ) ? $data['subject'] : '',
			mailhouse_admin_url( 'log' )
		);

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Query the log.
	 *
	 * @param array $args status, search, campaign_id, per_page, page, orderby, order.
	 * @return object[]
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'      => '',
				'search'      => '',
				'campaign_id' => 0,
				'per_page'    => 20,
				'page'        => 1,
				'orderby'     => 'created_at',
				'order'       => 'DESC',
			)
		);

		$where = self::where( $args );

		$orderby = in_array( $args['orderby'], array( 'created_at', 'status', 'subject', 'id' ), true ) ? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$offset  = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );
		$table   = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE {$where['sql']} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
				array_merge( $where['params'], array( (int) $args['per_page'], $offset ) )
			)
		);
	}

	/**
	 * Count rows matching a query.
	 *
	 * @param array $args Same shape as query().
	 * @return int
	 */
	public static function count( $args = array() ) {
		global $wpdb;

		$where = self::where( wp_parse_args( $args, array( 'status' => '', 'search' => '', 'campaign_id' => 0 ) ) );
		$table = self::table();

		$sql = "SELECT COUNT(*) FROM {$table} WHERE " . $where['sql'];

		if ( $where['params'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $where['params'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Build the WHERE clause shared by query() and count().
	 *
	 * @param array $args Query arguments.
	 * @return array{sql:string,params:array}
	 */
	protected static function where( $args ) {
		$sql    = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$sql[]    = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['campaign_id'] ) ) {
			$sql[]    = 'campaign_id = %d';
			$params[] = (int) $args['campaign_id'];
		}

		if ( ! empty( $args['search'] ) ) {
			global $wpdb;

			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$sql[]    = '(to_email LIKE %s OR subject LIKE %s OR error LIKE %s)';
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
	 * One row.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Delete rows.
	 *
	 * @param int[] $ids Row ids.
	 * @return int Rows deleted.
	 */
	public static function delete( $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', (array) $ids ) );

		if ( ! $ids ) {
			return 0;
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
	}

	/**
	 * Empty the log.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Try a logged message again.
	 *
	 * Only possible when the body was saved -- otherwise there is nothing to
	 * send, and pretending otherwise would deliver an empty email.
	 *
	 * @param int $id Row id.
	 * @return true|WP_Error
	 */
	public static function resend( $id ) {
		$row = self::get( $id );

		if ( ! $row ) {
			return new WP_Error( 'mailhouse_log_missing', __( 'That log entry no longer exists.', 'mailhouse' ) );
		}

		if ( '' === (string) $row->body ) {
			return new WP_Error(
				'mailhouse_no_body',
				__( 'The message body was not saved, so there is nothing to resend. Turn on "Save message content" if you need this.', 'mailhouse' )
			);
		}

		$headers = array_filter( explode( "\n", (string) $row->headers ) );
		$to      = array_filter( array_map( 'trim', explode( ',', (string) $row->to_email ) ) );

		$sent = wp_mail( $to, (string) $row->subject, (string) $row->body, $headers );

		if ( ! $sent ) {
			return new WP_Error( 'mailhouse_resend_failed', __( 'The retry failed too. The new log entry has the reason.', 'mailhouse' ) );
		}

		return true;
	}

	/**
	 * Drop entries past the retention window.
	 *
	 * @return void
	 */
	public static function purge() {
		$days = (int) mailhouse_option( 'log_retention_days', 30 );

		if ( $days < 1 ) {
			return;
		}

		global $wpdb;

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Sent and failed counts over a recent window.
	 *
	 * @param int $days How far back to look.
	 * @return array{sent:int,failed:int}
	 */
	public static function stats( $days = 7 ) {
		global $wpdb;

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$table} WHERE created_at >= %s GROUP BY status", $cutoff )
		);

		$stats = array(
			'sent'   => 0,
			'failed' => 0,
		);

		foreach ( (array) $rows as $row ) {
			$stats[ $row->status ] = (int) $row->total;
		}

		return $stats;
	}
}
