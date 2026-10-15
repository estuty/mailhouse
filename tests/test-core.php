<?php
/**
 * Core behaviour: activation, settings, the list, merge tags, the queue and
 * the transport layer.
 *
 * Usage: php tests/test-core.php
 *
 * @package Mailhouse
 */

require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// A transport that records what it was handed instead of sending it. This is
// what makes the API path testable without a network or an account.
class_exists( 'Mailhouse_Provider' );

class MH_Test_Provider extends Mailhouse_Provider {
	public static $last = null;

	public function id() {
		return 'harness';
	}

	public function label() {
		return 'Harness';
	}

	public function send( $mail ) {
		self::$last = $mail;
		return true;
	}
}

add_filter(
	'mailhouse_providers',
	function ( $classes ) {
		$classes[] = 'MH_Test_Provider';
		return $classes;
	}
);

echo "== Activation ==\n";

$result = activate_plugin( 'mailhouse/mailhouse.php' );
check( 'activate_plugin returns no error', ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
check( 'plugin is active', is_plugin_active( 'mailhouse/mailhouse.php' ) );

global $wpdb;

foreach ( array( 'subscribers', 'tags', 'subscriber_tags', 'queue', 'log' ) as $name ) {
	$table = $wpdb->prefix . 'mailhouse_' . $name;
	check( "table $name exists", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
}

check( 'default confirmation email created', (bool) Mailhouse_Emails::get_automation( 'confirmation' ) );
check( 'default welcome email created', (bool) Mailhouse_Emails::get_automation( 'welcome' ) );

echo "== Settings and encryption ==\n";

// Put back at the end, so running the tests does not disturb a real setup.
$saved_settings = get_option( 'mailhouse_settings' );

Mailhouse_Settings::set( array( 'smtp_pass' => 'hunter2-secret' ) );
Mailhouse_Settings::flush();
check( 'secret round-trips', 'hunter2-secret' === Mailhouse_Settings::get( 'smtp_pass' ) );

$stored = get_option( 'mailhouse_settings' );
check( 'secret is not stored in plaintext', false === strpos( (string) $stored['smtp_pass'], 'hunter2' ), (string) $stored['smtp_pass'] );
check( 'ciphertext is marked', Mailhouse_Crypto::is_encrypted( $stored['smtp_pass'] ) );

Mailhouse_Settings::set( array( 'smtp_pass' => '' ) );

echo "== Subscribers ==\n";

$email = strtolower( 'harness+' . wp_generate_password( 6, false ) . '@example.com' );

$sent_mail = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$sent_mail ) {
		$sent_mail[] = $atts;
		return true;
	},
	1,
	2
);

$added = Mailhouse_Subscribers::add(
	array(
		'email'      => $email,
		'first_name' => 'Ada',
		'source'     => 'test',
		'tags'       => 'Harness',
	)
);

check( 'add returns an array', is_array( $added ), is_wp_error( $added ) ? $added->get_error_message() : '' );
check( 'new subscriber is pending', 'pending' === $added['status'] );
check( 'confirmation email was sent', 1 === count( $sent_mail ), 'count: ' . count( $sent_mail ) );
check( 'confirmation contains a confirm link', isset( $sent_mail[0]['message'] ) && false !== strpos( $sent_mail[0]['message'], 'mailhouse' ) );

$again = Mailhouse_Subscribers::add( array( 'email' => $email ) );
check( 'duplicate signup does not create a second row', ! $again['is_new'] );
check( 'duplicate signup is throttled', 1 === count( $sent_mail ), 'count: ' . count( $sent_mail ) );

$subscriber = Mailhouse_Subscribers::get( $added['id'] );
check( 'manage key generated', 32 === strlen( (string) $subscriber->manage_key ) );
check( 'tag attached', 1 === count( Mailhouse_Tags::for_subscriber( $subscriber->id ) ) );

Mailhouse_Subscribers::confirm( $subscriber );
$subscriber = Mailhouse_Subscribers::get( $added['id'] );
check( 'confirm sets subscribed', 'subscribed' === $subscriber->status );
check( 'confirm clears the key', empty( $subscriber->confirm_key ) );
check( 'welcome email queued', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mailhouse_queue WHERE subscriber_id = %d", $subscriber->id ) ) );

check( 'lookup by manage key works', (int) Mailhouse_Subscribers::get_by_key( 'manage_key', $subscriber->manage_key )->id === (int) $subscriber->id );
check( 'lookup with a junk key fails', null === Mailhouse_Subscribers::get_by_key( 'manage_key', 'nope' ) );

echo "== Merge tags ==\n";

$rendered = Mailhouse_Merge_Tags::render(
	'Hi {{first_name|there}} <{{email}}> [[site_name]] {{last_name|friend}} {{nonsense}}',
	array( 'subscriber' => $subscriber )
);

check( 'first name replaced', false !== strpos( $rendered, 'Hi Ada' ), $rendered );
check( 'email replaced', false !== strpos( $rendered, $email ) );
check( 'double bracket form works', false !== strpos( $rendered, get_bloginfo( 'name' ) ) );
check( 'fallback used for an empty value', false !== strpos( $rendered, 'friend' ) );
check( 'unknown tag left alone', false !== strpos( $rendered, '{{nonsense}}' ) );

echo "== Opt-in links ==\n";

$unsub = Mailhouse_Optin::unsubscribe_url( $subscriber );
check( 'unsubscribe URL contains the key', false !== strpos( $unsub, $subscriber->manage_key ), $unsub );

echo "== Campaign and queue ==\n";

$campaign = wp_insert_post(
	array(
		'post_type'    => 'mailhouse_email',
		'post_status'  => 'publish',
		'post_title'   => 'Harness issue',
		'post_content' => '<p>Hello {{first_name}}, read <a href="https://example.com/post">this</a>.</p>',
	)
);

update_post_meta( $campaign, '_mailhouse_type', 'newsletter' );
update_post_meta( $campaign, '_mailhouse_subject', 'Issue for {{first_name|you}}' );

$message = Mailhouse_Emails::render( $campaign, $subscriber );

check( 'subject merged', 'Issue for Ada' === $message['subject'], $message['subject'] );
check( 'body wrapped in the template', false !== strpos( $message['html'], '<!DOCTYPE' ) );
check( 'unsubscribe link in the footer', false !== strpos( $message['html'], $subscriber->manage_key ) );
check( 'plain text alternative generated', false !== strpos( $message['text'], 'Hello Ada' ), $message['text'] );

$queued = Mailhouse_Queue::enqueue_campaign( $campaign );
check( 'campaign queued for the subscriber', $queued >= 1, "queued: $queued" );
check( 'campaign marked as sending', 'sending' === Mailhouse_Emails::status( $campaign ) );

$again = Mailhouse_Queue::enqueue_campaign( $campaign );
check( 'queueing twice adds nothing', 0 === $again, "second run queued: $again" );

$before = count( $sent_mail );
delete_transient( 'mailhouse_queue_lock' );
$processed = Mailhouse_Queue::process();
check( 'queue processed at least the campaign and welcome', $processed >= 1, "processed: $processed" );
check( 'mail actually went out', count( $sent_mail ) > $before );

$stats = Mailhouse_Queue::campaign_stats( $campaign );
check( 'campaign stats show a send', 1 === $stats['sent'], wp_json_encode( $stats ) );
check( 'campaign marked sent', 'sent' === Mailhouse_Emails::status( $campaign ), Mailhouse_Emails::status( $campaign ) );

echo "== Unsubscribe blocks future sends ==\n";

Mailhouse_Subscribers::unsubscribe( Mailhouse_Subscribers::get( $subscriber->id ) );
check( 'status is unsubscribed', 'unsubscribed' === Mailhouse_Subscribers::get( $subscriber->id )->status );

$campaign2 = wp_insert_post(
	array(
		'post_type'   => 'mailhouse_email',
		'post_status' => 'publish',
		'post_title'  => 'Harness issue 2',
		'post_content' => '<p>Second</p>',
	)
);
update_post_meta( $campaign2, '_mailhouse_type', 'newsletter' );

$queued2 = Mailhouse_Queue::enqueue_campaign( $campaign2 );
check( 'unsubscribed people are not queued', 0 === $queued2, "queued: $queued2" );

echo "== Tracking ==\n";

$url = Mailhouse_Tracking::url( 'click', 123, 'https://example.com/x' );
parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
check( 'tracking URL is signed', ! empty( $args['k'] ) );

$html = Mailhouse_Tracking::prepare( '<html><body><a href="https://example.com/a">a</a><a href="' . $unsub . '">u</a></body></html>', 5 );
check( 'tracking off by default leaves links alone', false !== strpos( $html, 'href="https://example.com/a"' ) );

Mailhouse_Settings::set( array( 'track_clicks' => true, 'track_opens' => true ) );
$html = Mailhouse_Tracking::prepare( '<html><body><a href="https://example.com/a">a</a><a href="' . $unsub . '">u</a></body></html>', 5 );
check( 'click tracking rewrites ordinary links', false !== strpos( $html, 'mailhouse_track=click' ) );
check( 'unsubscribe link is left alone', false !== strpos( $html, 'href="' . $unsub . '"' ), $html );
check( 'open pixel added', false !== strpos( $html, 'mailhouse_track=open' ) );
Mailhouse_Settings::set( array( 'track_clicks' => false, 'track_opens' => false ) );

echo "== Form ==\n";

$form = Mailhouse_Forms::render( array( 'title' => 'Join', 'name' => 'first' ) );
check( 'form has an email field', false !== strpos( $form, 'name="email"' ) );
check( 'form has a signed config', false !== strpos( $form, 'mailhouse_signature' ) );
check( 'form has a honeypot', false !== strpos( $form, 'mailhouse_website' ) );
check( 'form has a nonce', false !== strpos( $form, 'mailhouse_nonce' ) );
check( 'form posts to admin-post', false !== strpos( $form, 'admin-post.php' ) );

echo "== Log ==\n";

$count_before = Mailhouse_Log::count();
do_action( 'wp_mail_succeeded', array( 'to' => array( 'x@example.com' ), 'subject' => 'Harness log', 'message' => 'body', 'headers' => array(), 'attachments' => array() ) );
check( 'success is logged', Mailhouse_Log::count() === $count_before + 1 );

do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'Nope', array( 'to' => array( 'y@example.com' ), 'subject' => 'Harness fail' ) ) );
$failed = Mailhouse_Log::query( array( 'status' => 'failed', 'per_page' => 1 ) );
check( 'failure is logged with its reason', $failed && 'Nope' === $failed[0]->error );

echo "== Providers ==\n";

$providers = Mailhouse_Mailer::providers();
foreach ( array( 'php', 'smtp', 'sendgrid', 'mailgun', 'brevo', 'postmark', 'resend' ) as $id ) {
	check( "transport $id registered", isset( $providers[ $id ] ) );
}
check( 'php transport is not an API', ! $providers['php']->is_api() );
check( 'smtp transport is not an API', ! $providers['smtp']->is_api() );
check( 'sendgrid transport is an API', $providers['sendgrid']->is_api() );
check( 'smtp is unconfigured without a host', ! $providers['smtp']->is_configured() );

echo "== PHPMailer substitution and transport routing ==\n";

remove_all_filters( 'pre_wp_mail' );
$GLOBALS['phpmailer'] = null;
add_filter( 'pre_wp_mail', array( mailhouse()->module( 'mailer' ), 'install_phpmailer' ), 5, 2 );

Mailhouse_Settings::set(
	array(
		'mailer'           => 'harness',
		'from_email'       => 'sender@example.com',
		'from_name'        => 'Harness',
		'force_from_email' => true,
		'return_path'      => true,
		'reply_to'         => 'replies@example.com',
	)
);

$result = wp_mail( 'target@example.com', 'Harness subject', '<p>Harness body</p>', array( 'Content-Type: text/html; charset=UTF-8' ) );
$mail   = MH_Test_Provider::$last;

check( 'wp_mail reports success', true === $result );
check( 'our PHPMailer subclass is installed', $GLOBALS['phpmailer'] instanceof Mailhouse_PHPMailer );
check( 'the API transport received the message', $mail instanceof PHPMailer\PHPMailer\PHPMailer );
check( 'from address forced', $mail && 'sender@example.com' === $mail->From, $mail ? $mail->From : '' );
check( 'from name applied', $mail && 'Harness' === $mail->FromName, $mail ? $mail->FromName : '' );
check( 'return path set', $mail && 'sender@example.com' === $mail->Sender );
$reply = $mail ? array_values( $mail->getReplyToAddresses() ) : array();
check( 'reply-to added', $reply && 'replies@example.com' === $reply[0][0], wp_json_encode( $reply ) );
check( 'recipient carried through', $mail && 'target@example.com' === $mail->getToAddresses()[0][0] );
check( 'subject carried through', $mail && 'Harness subject' === $mail->Subject );
check( 'body assembled by preSend', $mail && false !== strpos( $mail->Body, 'Harness body' ) );

$logged = Mailhouse_Log::query( array( 'per_page' => 1 ) );
check( 'the send reached the log', $logged && 'sent' === $logged[0]->status && 'Harness subject' === $logged[0]->subject, $logged ? $logged[0]->subject : 'nothing logged' );

echo "\n== Cleanup ==\n";

Mailhouse_Subscribers::delete( array( $subscriber->id ) );

foreach ( Mailhouse_Tags::all() as $tag ) {
	if ( 'harness' === $tag->slug ) {
		Mailhouse_Tags::delete( (int) $tag->id );
	}
}

wp_delete_post( $campaign, true );
wp_delete_post( $campaign2, true );

update_option( 'mailhouse_settings', $saved_settings );
Mailhouse_Settings::flush();

echo "  cleaned up\n";

mailhouse_test_results();