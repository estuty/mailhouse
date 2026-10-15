<?php
/**
 * Open and click tracking.
 *
 * Both are off by default. Tracking an open means embedding an invisible image
 * that reports back when somebody reads their mail, and tracking a click means
 * routing every link through this site first. Those are real privacy costs, so
 * they are a decision the site makes on purpose rather than a default.
 *
 * Open rates are also less trustworthy than they look: Apple Mail Privacy
 * Protection fetches the image whether or not anyone read anything.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the tracking pixel and rewrites links, then handles the hits.
 */
class Mailhouse_Tracking {

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'handle' ), 1 );
	}

	/**
	 * Add tracking to a rendered message.
	 *
	 * @param string $html     Message HTML.
	 * @param int    $queue_id Queue row id, which identifies the recipient.
	 * @return string
	 */
	public static function prepare( $html, $queue_id ) {
		if ( mailhouse_option( 'track_clicks' ) ) {
			$html = self::rewrite_links( $html, $queue_id );
		}

		if ( mailhouse_option( 'track_opens' ) ) {
			$pixel = sprintf(
				'<img src="%s" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;" />',
				esc_url( self::url( 'open', $queue_id ) )
			);

			$html = str_replace( '</body>', $pixel . '</body>', $html );
		}

		return $html;
	}

	/**
	 * Point every outbound link at the click endpoint.
	 *
	 * The unsubscribe and preferences links are deliberately left alone. A
	 * one-click unsubscribe has to work even if tracking is broken, blocked or
	 * turned off between sending and clicking.
	 *
	 * @param string $html     Message HTML.
	 * @param int    $queue_id Queue row id.
	 * @return string
	 */
	protected static function rewrite_links( $html, $queue_id ) {
		return (string) preg_replace_callback(
			'/href=(["\'])(https?:\/\/[^"\']+)\1/i',
			static function ( $matches ) use ( $queue_id ) {
				$url = $matches[2];

				if ( false !== strpos( $url, '/mailhouse/' ) || false !== strpos( $url, 'mailhouse_action=' ) ) {
					return $matches[0];
				}

				return 'href="' . esc_url( self::url( 'click', $queue_id, $url ) ) . '"';
			},
			$html
		);
	}

	/**
	 * Build a signed tracking URL.
	 *
	 * @param string $type     open or click.
	 * @param int    $queue_id Queue row id.
	 * @param string $url      Destination, for clicks.
	 * @return string
	 */
	public static function url( $type, $queue_id, $url = '' ) {
		$args = array(
			'mailhouse_track' => $type,
			'id'              => (int) $queue_id,
		);

		if ( 'click' === $type ) {
			$args['u'] = rawurlencode( $url );
		}

		$args['k'] = self::signature( $type, $queue_id, $url );

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * The signature that proves a tracking URL was built here.
	 *
	 * Without it, anybody could inflate a campaign's numbers -- or worse, use
	 * the click endpoint as an open redirect pointing anywhere they like.
	 *
	 * @param string $type     open or click.
	 * @param int    $queue_id Queue row id.
	 * @param string $url      Destination.
	 * @return string
	 */
	protected static function signature( $type, $queue_id, $url = '' ) {
		return substr( hash_hmac( 'sha256', $type . '|' . (int) $queue_id . '|' . $url, wp_salt( 'mailhouse_track' ) ), 0, 20 );
	}

	/**
	 * Answer a tracking hit.
	 *
	 * @return void
	 */
	public function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a signed URL from an email, not a form.
		if ( empty( $_GET['mailhouse_track'] ) ) {
			return;
		}

		$type     = sanitize_key( wp_unslash( $_GET['mailhouse_track'] ) );
		$queue_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$key      = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';
		$url      = isset( $_GET['u'] ) ? rawurldecode( wp_unslash( $_GET['u'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $type, array( 'open', 'click' ), true ) || ! hash_equals( self::signature( $type, $queue_id, $url ), $key ) ) {
			return;
		}

		nocache_headers();

		if ( 'open' === $type ) {
			self::record_open( $queue_id );
			self::pixel();
		}

		self::record_click( $queue_id );

		$url = esc_url_raw( $url );

		if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) {
			$url = home_url( '/' );
		}

		// The destination came out of a signature this site produced, so it is
		// not attacker-controlled; wp_redirect rather than wp_safe_redirect
		// because a campaign may legitimately link off-site.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Record an open.
	 *
	 * @param int $queue_id Queue row id.
	 * @return void
	 */
	protected static function record_open( $queue_id ) {
		global $wpdb;

		$table = Mailhouse_Queue::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET opens = opens + 1, opened_at = COALESCE(opened_at, %s) WHERE id = %d",
				mailhouse_now(),
				$queue_id
			)
		);

		self::bump_subscriber( $queue_id, 'opens' );
	}

	/**
	 * Record a click, which also counts as an open.
	 *
	 * @param int $queue_id Queue row id.
	 * @return void
	 */
	protected static function record_click( $queue_id ) {
		global $wpdb;

		$table = Mailhouse_Queue::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET clicks = clicks + 1, clicked_at = COALESCE(clicked_at, %s), opened_at = COALESCE(opened_at, %s) WHERE id = %d",
				mailhouse_now(),
				mailhouse_now(),
				$queue_id
			)
		);

		self::bump_subscriber( $queue_id, 'clicks' );
	}

	/**
	 * Add one to a subscriber's lifetime counter.
	 *
	 * @param int    $queue_id Queue row id.
	 * @param string $column   opens or clicks.
	 * @return void
	 */
	protected static function bump_subscriber( $queue_id, $column ) {
		global $wpdb;

		if ( ! in_array( $column, array( 'opens', 'clicks' ), true ) ) {
			return;
		}

		$queue       = Mailhouse_Queue::table();
		$subscribers = Mailhouse_Subscribers::table();

		// The column name is whitelisted immediately above.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$subscribers} SET {$column} = {$column} + 1
				 WHERE id = (SELECT subscriber_id FROM {$queue} WHERE id = %d)",
				$queue_id
			)
		);
	}

	/**
	 * Send a 1x1 transparent GIF and stop.
	 *
	 * @return void
	 */
	protected static function pixel() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$gif = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );

		header( 'Content-Type: image/gif' );
		header( 'Content-Length: ' . strlen( $gif ) );

		echo $gif; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}
}
