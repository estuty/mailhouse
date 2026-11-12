<?php
/**
 * What this site tells the centre about itself.
 *
 * Counts, versions and whether things are set up. Never an address, a
 * subscriber, a host name or a credential: there is no field here that
 * could carry one, and the centre keeps only the fields it knows.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Heartbeat contents.
 */
class Mailhouse_Central_Status {

	/**
	 * What this version of the connector can do, as the centre names it.
	 *
	 * Empty for now: this version registers and reports in, and nothing
	 * else. Synchronisation (`sync.v1`) is the next step.
	 *
	 * @return string[]
	 */
	public static function capabilities() {
		/**
		 * Filter the capabilities the connector announces.
		 *
		 * @param string[] $capabilities Capability names.
		 */
		return array_values( array_filter( (array) apply_filters( 'mailhouse_central_capabilities', array( 'sync.v1', 'reconcile.v1' ) ), 'is_string' ) );
	}

	/**
	 * The body of a heartbeat.
	 *
	 * @return array
	 */
	public static function report() {
		$state            = Mailhouse_Central_Identity::state();
		$now              = time() + (int) $state['clock_offset'];
		list( $site_url ) = Mailhouse_Central_Identity::site_url();
		$subscribers      = Mailhouse_Subscribers::counts();
		$site_mailer      = (string) mailhouse_option( 'mailer', 'php' );
		$campaign_mailer  = (string) mailhouse_option( 'campaign_mailer', '' );
		$provider         = Mailhouse_Mailer::provider( $site_mailer );
		$error            = (string) $state['last_error'];

		return array(
			'site_url_hash'  => Mailhouse_Central_Protocol::site_url_hash( (string) $site_url ),
			'plugin_version' => MAILHOUSE_VERSION,
			'schema_version' => Mailhouse_Install::DB_VERSION,
			'capabilities'   => self::capabilities(),
			'clock'          => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
			'timezone'       => wp_timezone_string(),
			'cron'           => array(
				'last_tick' => $state['last_tick_at'] ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $state['last_tick_at'] ) : null,
				'disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			),
			// Which transport, and whether it is set up. Nothing about how.
			'transport'      => array(
				'site'     => $site_mailer,
				'campaign' => '' !== $campaign_mailer ? $campaign_mailer : $site_mailer,
				'ready'    => $provider ? (bool) $provider->is_configured() : false,
			),
			'tracking'       => array(
				'opens'  => (bool) mailhouse_option( 'track_opens', false ),
				'clicks' => (bool) mailhouse_option( 'track_clicks', false ),
			),
			'outbox'         => Mailhouse_Central_Sync::report(),
			'sync'           => Mailhouse_Central_Reconcile::report(),
			'queue'          => self::queue(),
			'subscribers'    => array(
				'subscribed'   => (int) $subscribers['subscribed'],
				'pending'      => (int) $subscribers['pending'],
				'unsubscribed' => (int) $subscribers['unsubscribed'],
				'bounced'      => (int) $subscribers['bounced'],
			),
			'last_error'     => '' === $error ? null : substr( $error, 0, 500 ),
		);
	}

	/**
	 * How much is waiting in the send queue.
	 *
	 * @return array<string,int>
	 */
	protected static function queue() {
		global $wpdb;

		$table  = Mailhouse_Queue::table();
		$counts = array(
			'pending' => 0,
			'sending' => 0,
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} WHERE status IN ('pending', 'sending') GROUP BY status" );

		foreach ( $rows as $row ) {
			$counts[ $row->status ] = (int) $row->total;
		}

		return $counts;
	}
}
