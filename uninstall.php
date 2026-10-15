<?php
/**
 * Uninstall.
 *
 * Deleting a plugin is not a request to delete a mailing list, so nothing goes
 * unless the site explicitly asked for it under Settings -> Advanced. The
 * option that records that choice is the last thing removed.
 *
 * @package Mailhouse
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'mailhouse_settings', array() );

if ( empty( $settings['delete_data'] ) ) {
	return;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-mailhouse-install.php';

global $wpdb;

Mailhouse_Install::drop_tables();

// The emails, their meta, and the flag left on any post that triggered a
// notification.
$posts = get_posts(
	array(
		'post_type'      => 'mailhouse_email',
		'post_status'    => array( 'any', 'trash', 'auto-draft' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $posts as $post_id ) {
	wp_delete_post( $post_id, true );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_mailhouse_' ) . '%' ) );

delete_option( 'mailhouse_settings' );
delete_option( 'mailhouse_db_version' );
delete_option( 'mailhouse_installed' );
delete_option( 'mailhouse_key' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_mailhouse_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mailhouse_' ) . '%'
	)
);

wp_clear_scheduled_hook( 'mailhouse_process_queue' );
wp_clear_scheduled_hook( 'mailhouse_daily_maintenance' );
wp_clear_scheduled_hook( 'mailhouse_post_digest' );
wp_unschedule_hook( 'mailhouse_send_confirmation' );
