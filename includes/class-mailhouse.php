<?php
/**
 * The plugin container.
 *
 * Two jobs live here, deliberately in one plugin: getting mail off the server
 * reliably, and running the mailing list that depends on it. Splitting them
 * across two plugins is how sites end up with a newsletter that silently stops
 * arriving because the SMTP plugin was reconfigured.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots the modules and holds them.
 */
class Mailhouse {

	/**
	 * Loaded modules, keyed by short name.
	 *
	 * @var array<string,object>
	 */
	protected $modules = array();

	/**
	 * Boot on construction.
	 */
	public function __construct() {
		$this->boot();
	}

	/**
	 * Load every module and register the cron schedule.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->modules ) {
			return;
		}

		load_plugin_textdomain( 'mailhouse', false, dirname( MAILHOUSE_BASENAME ) . '/languages' );

		$this->modules = array(
			'mailer'      => new Mailhouse_Mailer(),
			'log'         => new Mailhouse_Log(),
			'subscribers' => new Mailhouse_Subscribers(),
			'forms'       => new Mailhouse_Forms(),
			'optin'       => new Mailhouse_Optin(),
			'emails'      => new Mailhouse_Emails(),
			'queue'       => new Mailhouse_Queue(),
			'automations' => new Mailhouse_Automations(),
			'tracking'    => new Mailhouse_Tracking(),
		);

		if ( is_admin() ) {
			$this->modules['admin'] = new Mailhouse_Admin();
		}

		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( 'init', array( $this, 'schedule_events' ) );

		// An upload-over-the-top upgrade never fires the activation hook, so
		// the schema check repeats on admin load. It costs one option read.
		add_action( 'admin_init', array( 'Mailhouse_Install', 'install' ) );

		/**
		 * Fires once every module is loaded.
		 *
		 * @param Mailhouse $plugin Plugin instance.
		 */
		do_action( 'mailhouse_loaded', $this );
	}

	/**
	 * One of the loaded modules.
	 *
	 * @param string $name Short name, e.g. `queue`.
	 * @return object|null
	 */
	public function module( $name ) {
		return isset( $this->modules[ $name ] ) ? $this->modules[ $name ] : null;
	}

	/**
	 * Add the one-minute schedule the send queue runs on.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function cron_schedules( $schedules ) {
		$schedules['mailhouse_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (Mailhouse)', 'mailhouse' ),
		);

		return $schedules;
	}

	/**
	 * Make sure the recurring events exist.
	 *
	 * @return void
	 */
	public function schedule_events() {
		if ( ! wp_next_scheduled( 'mailhouse_process_queue' ) ) {
			wp_schedule_event( time() + 60, 'mailhouse_minute', 'mailhouse_process_queue' );
		}

		if ( ! wp_next_scheduled( 'mailhouse_post_digest' ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'mailhouse_post_digest' );
		}

		if ( ! wp_next_scheduled( 'mailhouse_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mailhouse_daily_maintenance' );
		}
	}
}
