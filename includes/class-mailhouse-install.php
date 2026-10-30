<?php
/**
 * Database schema and first-run setup.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's tables.
 */
class Mailhouse_Install {

	/**
	 * Schema version. Bumped whenever a CREATE TABLE below changes.
	 */
	const DB_VERSION = '1.1.0';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_OPTION = 'mailhouse_db_version';

	/**
	 * The name of one of the plugin's tables.
	 *
	 * @param string $name Short name: subscribers, tags, subscriber_tags, queue, log.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'mailhouse_' . $name;
	}

	/**
	 * Create or upgrade every table, then record the version.
	 *
	 * Safe to call on every admin load: it returns after a single option read
	 * once the schema is current. That matters because uploading a new copy of
	 * a plugin over the old one never re-runs the activation hook.
	 *
	 * @param bool $force Run dbDelta even when the version matches.
	 * @return void
	 */
	public static function install( $force = false ) {
		if ( ! $force && get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();

		// 191, not 255: the longest VARCHAR that still fits a unique index
		// under utf8mb4 on MySQL 5.6.
		$subscribers = self::table( 'subscribers' );
		dbDelta(
			"CREATE TABLE {$subscribers} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(191) NOT NULL,
				first_name varchar(100) NOT NULL DEFAULT '',
				last_name varchar(100) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				source varchar(60) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				confirm_key varchar(64) DEFAULT NULL,
				manage_key varchar(64) NOT NULL,
				fields longtext DEFAULT NULL,
				sent int(10) unsigned NOT NULL DEFAULT 0,
				opens int(10) unsigned NOT NULL DEFAULT 0,
				clicks int(10) unsigned NOT NULL DEFAULT 0,
				bounces int(10) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				confirmed_at datetime DEFAULT NULL,
				unsubscribed_at datetime DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY email (email),
				KEY status (status),
				KEY manage_key (manage_key),
				KEY confirm_key (confirm_key),
				KEY created_at (created_at)
			) {$collate};"
		);

		$tags = self::table( 'tags' );
		dbDelta(
			"CREATE TABLE {$tags} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				slug varchar(191) NOT NULL,
				description text DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug)
			) {$collate};"
		);

		$pivot = self::table( 'subscriber_tags' );
		dbDelta(
			"CREATE TABLE {$pivot} (
				subscriber_id bigint(20) unsigned NOT NULL,
				tag_id bigint(20) unsigned NOT NULL,
				added_at datetime NOT NULL,
				PRIMARY KEY  (subscriber_id,tag_id),
				KEY tag_id (tag_id)
			) {$collate};"
		);

		// One row per recipient per campaign. It starts life as a queue entry
		// and stays on afterwards as the send record, which is what the open
		// and click columns hang off.
		$queue = self::table( 'queue' );
		dbDelta(
			"CREATE TABLE {$queue} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
				subscriber_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(191) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				error text DEFAULT NULL,
				scheduled_at datetime NOT NULL,
				sent_at datetime DEFAULT NULL,
				opened_at datetime DEFAULT NULL,
				clicked_at datetime DEFAULT NULL,
				opens int(10) unsigned NOT NULL DEFAULT 0,
				clicks int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY campaign_subscriber (campaign_id,subscriber_id),
				KEY status_scheduled (status,scheduled_at),
				KEY campaign_status (campaign_id,status)
			) {$collate};"
		);

		$log = self::table( 'log' );
		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'sent',
				mailer varchar(30) NOT NULL DEFAULT '',
				from_email varchar(191) NOT NULL DEFAULT '',
				to_email text DEFAULT NULL,
				subject text DEFAULT NULL,
				error text DEFAULT NULL,
				headers longtext DEFAULT NULL,
				body longtext DEFAULT NULL,
				attachments text DEFAULT NULL,
				campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
				subscriber_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY created_at (created_at),
				KEY campaign_id (campaign_id)
			) {$collate};"
		);

		self::seed_defaults();

		// Added in 1.1.0, so it runs on upgrades too, not just new installs.
		Mailhouse_Emails::create_default_new_post();

		update_option( self::DB_OPTION, self::DB_VERSION );
	}

	/**
	 * Fill in the settings and automated emails a new install needs.
	 *
	 * Runs once. Re-activating the plugin will not overwrite anything the site
	 * has since edited.
	 *
	 * @return void
	 */
	protected static function seed_defaults() {
		if ( get_option( 'mailhouse_installed' ) ) {
			return;
		}

		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		$domain = preg_replace( '/^www\./', '', (string) $domain );

		// On a fresh install the theme's own colours are a far better starting
		// point than a generic blue. Only here, never on upgrade: changing the
		// look of a site's email behind its back would be worse than a
		// mismatch it has already accepted.
		$colours = Mailhouse_Palette::detect();

		Mailhouse_Settings::set(
			array_merge(
				array(
					'from_email'          => get_option( 'admin_email' ),
					'from_name'           => get_bloginfo( 'name' ),
					'campaign_from_name'  => get_bloginfo( 'name' ),
					'campaign_from_email' => 'newsletter@' . $domain,
					'alerts_email'        => get_option( 'admin_email' ),
				),
				$colours
			)
		);

		Mailhouse_Emails::create_default_emails();

		update_option( 'mailhouse_installed', gmdate( 'Y-m-d H:i:s' ), false );
	}

	/**
	 * Drop every table. Used by uninstall.php, and only when asked.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		foreach ( array( 'log', 'queue', 'subscriber_tags', 'tags', 'subscribers' ) as $name ) {
			$table = self::table( $name );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}
