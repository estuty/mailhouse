<?php
/**
 * Plugin Name:       Mailhouse
 * Plugin URI:        https://example.com/mailhouse/
 * Description:       One plugin for the whole mail path: reliable delivery over SMTP or a mail API with a searchable log, plus a self-hosted newsletter -- opt-in forms, double opt-in, tags, broadcasts and automations. Replaces a separate SMTP plugin and a separate newsletter plugin.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Mailhouse
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mailhouse
 * Domain Path:       /languages
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

define( 'MAILHOUSE_VERSION', '1.1.0' );
define( 'MAILHOUSE_FILE', __FILE__ );
define( 'MAILHOUSE_PATH', plugin_dir_path( __FILE__ ) );
define( 'MAILHOUSE_URL', plugin_dir_url( __FILE__ ) );
define( 'MAILHOUSE_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Locate a plugin class file.
 *
 * Files are named the WordPress way -- class-mailhouse-thing.php -- and live in
 * a handful of folders, so the class name alone is enough to find one. No
 * Composer, no build step, nothing to regenerate after adding a file.
 *
 * @param string $class Class name being loaded.
 * @return void
 */
function mailhouse_autoload( $class ) {
	if ( 0 !== strpos( $class, 'Mailhouse' ) ) {
		return;
	}

	$file = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';

	$dirs = array(
		'includes/',
		'includes/mail/',
		'includes/mail/providers/',
		'includes/list/',
		'includes/campaigns/',
		'includes/admin/',
	);

	foreach ( $dirs as $dir ) {
		$path = MAILHOUSE_PATH . $dir . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
			return;
		}
	}
}
spl_autoload_register( 'mailhouse_autoload' );

require_once MAILHOUSE_PATH . 'includes/functions.php';

/**
 * The plugin instance.
 *
 * Boots on `plugins_loaded` at priority 1: the mail layer has to be in place
 * before anything else on the site gets a chance to call wp_mail().
 *
 * @return Mailhouse
 */
function mailhouse() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Mailhouse();
	}

	return $instance;
}
add_action( 'plugins_loaded', 'mailhouse', 1 );

/**
 * Create tables, seed defaults and register rewrites on activation.
 *
 * @return void
 */
function mailhouse_activate() {
	require_once MAILHOUSE_PATH . 'includes/class-mailhouse-install.php';

	Mailhouse_Install::install();

	mailhouse()->boot();
	Mailhouse_Optin::register_rewrites();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'mailhouse_activate' );

/**
 * Clear scheduled work and rewrite rules on deactivation.
 *
 * Subscribers, campaigns and the log are left alone -- deactivating a plugin
 * is not a request to throw away a mailing list.
 *
 * @return void
 */
function mailhouse_deactivate() {
	wp_clear_scheduled_hook( 'mailhouse_process_queue' );
	wp_clear_scheduled_hook( 'mailhouse_daily_maintenance' );
	wp_clear_scheduled_hook( 'mailhouse_post_digest' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'mailhouse_deactivate' );
