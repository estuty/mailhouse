<?php
/**
 * The 1.1.0 changes: security hardening, the email design, post
 * notifications, the new transports and the backup transport.
 *
 * Usage: php tests/test-features.php
 *
 * @package Mailhouse
 */

require __DIR__ . '/bootstrap.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

$mailhouse_cleanup = array(
	'subscribers' => array(),
	'posts'       => array(),
);

/**
 * A throwaway address.
 *
 * @param string $label Prefix.
 * @return string
 */
function mh_address( $label ) {
	return strtolower( $label . '-' . wp_generate_password( 6, false ) . '@example.com' );
}

/**
 * Run every queued confirmation now.
 *
 * @return void
 */
function mh_run_confirmations() {
	foreach ( (array) _get_cron_array() as $hooks ) {
		if ( empty( $hooks['mailhouse_send_confirmation'] ) ) {
			continue;
		}

		foreach ( $hooks['mailhouse_send_confirmation'] as $event ) {
			do_action_ref_array( 'mailhouse_send_confirmation', $event['args'] );
			wp_unschedule_event( wp_next_scheduled( 'mailhouse_send_confirmation', $event['args'] ), 'mailhouse_send_confirmation', $event['args'] );
		}
	}
}

// Every email is captured instead of sent.
$sent    = array();
$capture = function ( $null, $atts ) use ( &$sent ) {
	$sent[] = $atts;
	return true;
};

add_filter( 'pre_wp_mail', $capture, 1, 2 );

echo "== Public signup cannot rewrite an existing subscriber ==\n";

$victim = mh_address( 'victim' );
$row    = Mailhouse_Subscribers::add(
	array(
		'email'       => $victim,
		'first_name'  => 'Victoria',
		'status'      => 'subscribed',
		'send_emails' => false,
	)
);

$mailhouse_cleanup['subscribers'][] = $row['id'];

Mailhouse_Subscribers::add(
	array(
		'email'      => $victim,
		'first_name' => 'Your account is locked, verify at evil.example',
		'fields'     => array( 'company' => 'Injected' ),
	)
);

$after = Mailhouse_Subscribers::get( $row['id'] );
check( 'stored name is kept', 'Victoria' === $after->first_name, $after->first_name );

Mailhouse_Subscribers::add(
	array(
		'email'           => $victim,
		'first_name'      => 'Vicky',
		'overwrite_names' => true,
		'send_emails'     => false,
	)
);
check( 'an admin edit can still change it', 'Vicky' === Mailhouse_Subscribers::get( $row['id'] )->first_name );

echo "== An opt-out is only undone by the owner ==\n";

$leaver = mh_address( 'leaver' );
$row    = Mailhouse_Subscribers::add(
	array(
		'email'       => $leaver,
		'status'      => 'subscribed',
		'send_emails' => false,
	)
);

$mailhouse_cleanup['subscribers'][] = $row['id'];
Mailhouse_Subscribers::unsubscribe( Mailhouse_Subscribers::get( $row['id'] ) );

$sent   = array();
$result = Mailhouse_Subscribers::add(
	array(
		'email'        => $leaver,
		'double_optin' => false,
		'async_emails' => true,
	)
);
mh_run_confirmations();

$leaver_row = Mailhouse_Subscribers::get( $row['id'] );
check( 'public signup leaves them unsubscribed', 'unsubscribed' === $leaver_row->status, $leaver_row->status );
check( 'and reports the same outcome as any signup', 'reconfirming' === $result['action'], $result['action'] );
check( 'a confirmation link is sent to the address', 1 === count( $sent ), 'count ' . count( $sent ) );
check( 'no welcome email', 0 === count( array_filter( $sent, function ( $m ) { return false !== stripos( $m['subject'], 'welcome' ); } ) ) );

Mailhouse_Subscribers::confirm( $leaver_row );
check( 'clicking the link brings them back', 'subscribed' === Mailhouse_Subscribers::get( $row['id'] )->status );

Mailhouse_Subscribers::unsubscribe( Mailhouse_Subscribers::get( $row['id'] ) );
Mailhouse_Subscribers::add(
	array(
		'email'           => $leaver,
		'status'          => 'subscribed',
		'source'          => 'csv',
		'overwrite_names' => true,
		'send_emails'     => false,
	)
);
check( 'a CSV import does not resubscribe them', 'unsubscribed' === Mailhouse_Subscribers::get( $row['id'] )->status );

echo "== Merge tags are escaped in HTML ==\n";

$tricky = mh_address( 'tricky' );
$row    = Mailhouse_Subscribers::add(
	array(
		'email'       => $tricky,
		'first_name'  => 'Ann "quote" & co',
		'status'      => 'subscribed',
		'send_emails' => false,
		'fields'      => array( 'note' => '<script>alert(1)</script><b>x</b>' ),
	)
);

$mailhouse_cleanup['subscribers'][] = $row['id'];
$subscriber                         = Mailhouse_Subscribers::get( $row['id'] );
$html                               = Mailhouse_Merge_Tags::render( '<a href="https://example.com/?n={{first_name}}">Hi {{first_name}} {{note}}</a>', array( 'subscriber' => $subscriber ), 'html' );

check( 'quotes cannot break out of an attribute', false === strpos( $html, '"quote"' ), $html );
check( 'custom fields carry no markup', false === stripos( $html, '<script' ) && false === strpos( $html, '<b>' ), $html );
check( 'text mode leaves the subject alone', 'Hi Ann "quote" & co' === Mailhouse_Merge_Tags::render( 'Hi {{first_name}}', array( 'subscriber' => $subscriber ) ) );

$url_html = Mailhouse_Merge_Tags::render( '<a href="{{unsubscribe_url}}">x</a>', array( 'subscriber' => $subscriber ), 'html' );
check( 'URLs stay usable', false !== strpos( $url_html, '/mailhouse/unsubscribe/' ), $url_html );

echo "== Smaller fixes ==\n";

check( 'CSV export neutralises formulas', "'=HYPERLINK(1)" === Mailhouse_Tools::csv_cell( '=HYPERLINK(1)' ) );
check( 'CSV export leaves text alone', 'Alice' === Mailhouse_Tools::csv_cell( 'Alice' ) );

Mailhouse_Settings::set( array( 'trust_proxy' => true ) );
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.100.20';
check( 'the client-chosen X-Forwarded-For entry is ignored', '198.51.100.20' === mailhouse_ip(), mailhouse_ip() );
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
Mailhouse_Settings::set( array( 'trust_proxy' => false ) );

$redirect = wp_validate_redirect( esc_url_raw( 'javascript:alert(1)', array( 'http', 'https' ) ), '' );
check( 'a javascript: form redirect is dropped', '' === $redirect, $redirect );

$headers = "List-Unsubscribe: <http://localhost/mailhouse/unsubscribe/0123456789abcdef0123456789abcdef/>";
check( 'log redaction pattern matches the key', false === strpos( preg_replace( '#(/mailhouse/(?:unsubscribe|preferences|confirm)/)[a-f0-9]{32}#i', '$1[redacted]', $headers ), '0123456789abcdef' ) );

$encrypted = Mailhouse_Crypto::encrypt( 'top secret' );
check( 'secrets still round-trip', 'top secret' === Mailhouse_Crypto::decrypt( $encrypted ) );

if ( function_exists( 'openssl_encrypt' ) ) {
	// Build a value the way the OpenSSL path now writes it, with split keys.
	$key    = ( new ReflectionMethod( 'Mailhouse_Crypto', 'key' ) );
	$sub    = ( new ReflectionMethod( 'Mailhouse_Crypto', 'subkey' ) );
	$master = $key->invoke( null );
	$iv     = random_bytes( 16 );
	$cipher = openssl_encrypt( 'legacy-free', 'aes-256-cbc', $sub->invoke( null, $master, 'enc' ), OPENSSL_RAW_DATA, $iv );
	$mac    = hash_hmac( 'sha256', $iv . $cipher, $sub->invoke( null, $master, 'mac' ), true );
	$value  = Mailhouse_Crypto::PREFIX . 'p.' . base64_encode( $iv . $mac . $cipher );
	check( 'OpenSSL values with split keys decrypt', 'legacy-free' === Mailhouse_Crypto::decrypt( $value ) );

	$old_cipher = openssl_encrypt( 'old-style', 'aes-256-cbc', $master, OPENSSL_RAW_DATA, $iv );
	$old_mac    = hash_hmac( 'sha256', $iv . $old_cipher, $master, true );
	check( '1.0.0 OpenSSL values still decrypt', 'old-style' === Mailhouse_Crypto::decrypt( Mailhouse_Crypto::PREFIX . 'o.' . base64_encode( $iv . $old_mac . $old_cipher ) ) );
}

echo "== Email design wraps every email ==\n";

Mailhouse_Settings::set(
	array(
		'template_wrap_all' => true,
		'template_logo'     => 'https://example.com/logo.png',
		'template_button'   => '#ffcc00',
		'social_x'          => 'https://x.com/example',
		'company_address'   => '1 Test Street',
	)
);

$mailer = new ReflectionMethod( 'Mailhouse_Mailer', 'apply_template' );
$mailer->setAccessible( true );

if ( ! class_exists( 'PHPMailer\\PHPMailer\\PHPMailer' ) ) {
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
}

$mail              = new PHPMailer\PHPMailer\PHPMailer( true );
$mail->ContentType = 'text/plain';
$mail->Subject     = 'Password Reset';
$mail->Body        = "Someone asked to reset the password.\n\nTo reset it, visit:\n<http://localhost/wp-login.php?action=rp&key=abc&login=admin>\n\nThanks & bye <3";
$mailer->invoke( mailhouse()->module( 'mailer' ), $mail );

check( 'plain text becomes HTML', 'text/html' === $mail->ContentType );
check( 'the logo is in the header', false !== strpos( $mail->Body, 'https://example.com/logo.png' ) );
check( 'the reset link is clickable and intact', false !== strpos( $mail->Body, 'href="http://localhost/wp-login.php?action=rp&#038;key=abc&#038;login=admin"' ), substr( $mail->Body, strpos( $mail->Body, 'wp-login' ) - 40, 160 ) );
check( 'plain text is escaped, not trusted', false !== strpos( $mail->Body, 'Thanks &amp; bye &lt;3' ) );
check( 'site mail has no unsubscribe link', false === stripos( $mail->Body, 'Unsubscribe' ) );
check( 'the original text is kept as the plain-text part', false !== strpos( $mail->AltBody, 'Someone asked' ) );
check( 'social links and address appear', false !== strpos( $mail->Body, 'https://x.com/example' ) && false !== strpos( $mail->Body, '1 Test Street' ) );

$designed              = new PHPMailer\PHPMailer\PHPMailer( true );
$designed->ContentType = 'text/html';
$designed->Body        = '<html><body><p>Shop receipt</p></body></html>';
$mailer->invoke( mailhouse()->module( 'mailer' ), $designed );
check( 'a complete HTML document is left alone', '<html><body><p>Shop receipt</p></body></html>' === $designed->Body );

$button = Mailhouse_Merge_Tags::button( 'https://example.com', 'Go' );
check( 'button text is dark on a light button', false !== strpos( $button, 'color:#111827' ) );

echo "== New post notifications ==\n";

$automation = wp_insert_post(
	array(
		'post_type'    => 'mailhouse_email',
		'post_status'  => 'publish',
		'post_title'   => 'Notify harness',
		'post_content' => '<p>New: </p>{{post_card}}',
	)
);
$mailhouse_cleanup['posts'][] = $automation;
update_post_meta( $automation, '_mailhouse_type', 'new_post' );
update_post_meta( $automation, '_mailhouse_post_types', array( 'post' ) );
update_post_meta( $automation, '_mailhouse_subject', 'Fresh: {{post_title}}' );

$issues_before = wp_list_pluck( get_posts( array( 'post_type' => 'mailhouse_email', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => -1, 'meta_key' => '_mailhouse_generated', 'meta_value' => 1, 'fields' => 'ids' ) ), 'ID' );

$article = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'Harness article',
		'post_content' => 'Body of the harness article.',
	)
);
$mailhouse_cleanup['posts'][] = $article;

check( 'publishing schedules a notification', (bool) wp_next_scheduled( 'mailhouse_notify_post', array( $article ) ) );
check( 'nothing is sent in the publish request', ! get_post_meta( $article, '_mailhouse_notified', true ) );

Mailhouse_Automations::notify_post( $article );
$issue = (int) get_post_meta( $article, '_mailhouse_notified', true );
$mailhouse_cleanup['posts'][] = $issue;

check( 'an issue is created', $issue > 0 );
check( 'the issue is queued', in_array( Mailhouse_Emails::status( $issue ), array( 'sending', 'sent' ), true ) || ! Mailhouse_Subscribers::count( array( 'status' => 'subscribed' ) ) );

$rendered = Mailhouse_Emails::render( $issue, Mailhouse_Subscribers::get_by_email( $tricky ) );
check( 'the subject names the post', 'Fresh: Harness article' === $rendered['subject'], $rendered['subject'] );
check( 'the card links to the post', false !== strpos( $rendered['html'], get_permalink( $article ) ) );
check( 'the card has a Read more button', false !== strpos( $rendered['html'], 'Read more' ) );

Mailhouse_Automations::notify_post( $article );
check( 'a second run does not notify twice', $issue === (int) get_post_meta( $article, '_mailhouse_notified', true ) );

$secret_post = wp_insert_post(
	array(
		'post_type'     => 'post',
		'post_status'   => 'publish',
		'post_title'    => 'Protected harness',
		'post_password' => 'pw',
		'post_content'  => 'Private body.',
	)
);
$mailhouse_cleanup['posts'][] = $secret_post;
Mailhouse_Automations::notify_post( $secret_post );
check( 'password-protected posts are never sent', ! get_post_meta( $secret_post, '_mailhouse_notified', true ) );

$skipped = wp_insert_post(
	array(
		'post_type'   => 'post',
		'post_status' => 'draft',
		'post_title'  => 'Skipped harness',
	)
);
$mailhouse_cleanup['posts'][] = $skipped;
update_post_meta( $skipped, '_mailhouse_skip_notify', 1 );
wp_publish_post( $skipped );
Mailhouse_Automations::notify_post( $skipped );
check( '"Don\'t notify" is respected', ! get_post_meta( $skipped, '_mailhouse_notified', true ) );

update_post_meta( $automation, '_mailhouse_mode', 'review' );
$sent     = array();
$reviewed = wp_insert_post(
	array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'post_title'  => 'Review harness',
	)
);
$mailhouse_cleanup['posts'][] = $reviewed;
Mailhouse_Automations::notify_post( $reviewed );
$review_issue                 = (int) get_post_meta( $reviewed, '_mailhouse_notified', true );
$mailhouse_cleanup['posts'][] = $review_issue;

check( 'review mode leaves a draft', 'draft' === get_post_status( $review_issue ) );
check( 'review mode queues nothing', '' === Mailhouse_Emails::status( $review_issue ) );
check( 'review mode emails the admin', 1 === count( $sent ) && false !== strpos( $sent[0]['subject'], 'Ready to review' ), wp_json_encode( wp_list_pluck( $sent, 'subject' ) ) );

update_post_meta( $automation, '_mailhouse_mode', 'send' );
update_post_meta( $automation, '_mailhouse_frequency', 'daily' );
update_post_meta( $automation, '_mailhouse_digest_hour', 0 );

$digest_posts = array();

foreach ( array( 'Digest one', 'Digest two' ) as $title ) {
	$id                           = wp_insert_post(
		array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => $title,
		)
	);
	$digest_posts[]               = $id;
	$mailhouse_cleanup['posts'][] = $id;
	Mailhouse_Automations::notify_post( $id );
}

check( 'digest posts are collected, not sent', 2 === count( (array) get_post_meta( $automation, '_mailhouse_digest_queue', true ) ) );

delete_post_meta( $automation, '_mailhouse_digest_last' );
Mailhouse_Automations::send_digests();

$digest = get_posts(
	array(
		'post_type'      => 'mailhouse_email',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'orderby'        => 'ID',
		'order'          => 'DESC',
		'meta_key'       => '_mailhouse_generated',
		'meta_value'     => 1,
	)
);
$digest = $digest ? $digest[0] : null;

if ( $digest ) {
	$mailhouse_cleanup['posts'][] = $digest->ID;
}

check( 'the digest issue is created', $digest && false !== strpos( $digest->post_title, '2 new posts' ), $digest ? $digest->post_title : 'none' );
check( 'the digest lists both posts', $digest && false !== strpos( $digest->post_content, 'Digest one' ) && false !== strpos( $digest->post_content, 'Digest two' ) );
check( 'the digest queue is emptied', ! get_post_meta( $automation, '_mailhouse_digest_queue', true ) );

Mailhouse_Automations::send_digests();
$again = get_posts( array( 'post_type' => 'mailhouse_email', 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'fields' => 'ids' ) );
check( 'a digest is not sent twice in one day', $digest && (int) $again[0] === (int) $digest->ID );

echo "== New transports ==\n";

$requests = array();
$respond  = array();

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$requests, &$respond ) {
		$requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		foreach ( $respond as $needle => $reply ) {
			if ( false !== strpos( $url, $needle ) ) {
				return $reply;
			}
		}

		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	},
	10,
	3
);

/**
 * A canned HTTP reply.
 *
 * @param int    $code Status.
 * @param string $body Body.
 * @return array
 */
function mh_reply( $code, $body ) {
	return array(
		'headers'  => array(),
		'body'     => $body,
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
		'cookies'  => array(),
	);
}

$respond = array(
	'api.mailjet.com'    => mh_reply( 200, '{"Messages":[{"Status":"success"}]}' ),
	'api.smtp2go.com'    => mh_reply( 200, '{"data":{"succeeded":1,"failed":0}}' ),
	'mandrillapp.com'    => mh_reply( 200, '[{"email":"x@example.com","status":"sent"}]' ),
	'api.mailersend.com' => mh_reply( 202, '' ),
	'amazonaws.com'      => mh_reply( 200, '{"MessageId":"abc"}' ),
);

Mailhouse_Settings::set(
	array(
		'ses_access_key'   => 'AKIDEXAMPLE',
		'ses_secret_key'   => 'secret',
		'ses_region'       => 'eu-west-1',
		'mailjet_key'      => 'k',
		'mailjet_secret'   => 's',
		'sparkpost_key'    => 'k',
		'smtp2go_key'      => 'k',
		'elasticemail_key' => 'k',
		'mailersend_key'   => 'k',
		'zeptomail_token'  => 'tok',
		'zeptomail_region' => 'eu',
		'mandrill_key'     => 'k',
	)
);

// Real sends from here, so the capture above is lifted.
remove_filter( 'pre_wp_mail', $capture, 1 );

$expected = array(
	'ses'          => 'https://email.eu-west-1.amazonaws.com/v2/email/outbound-emails',
	'mailjet'      => 'https://api.mailjet.com/v3.1/send',
	'sparkpost'    => 'https://api.sparkpost.com/api/v1/transmissions',
	'smtp2go'      => 'https://api.smtp2go.com/v3/email/send',
	'elasticemail' => 'https://api.elasticemail.com/v4/emails/transactional',
	'mailersend'   => 'https://api.mailersend.com/v1/email',
	'zeptomail'    => 'https://api.zeptomail.eu/v1.1/email',
	'mandrill'     => 'https://mandrillapp.com/api/1.0/messages/send',
);

foreach ( $expected as $id => $url ) {
	Mailhouse_Settings::set(
		array(
			'mailer'          => $id,
			'fallback_mailer' => '',
		)
	);

	$requests = array();
	$error    = '';
	$on_fail  = function ( $e ) use ( &$error ) {
		$error = $e->get_error_message();
	};

	add_action( 'wp_mail_failed', $on_fail );
	$ok = wp_mail(
		array( 'To Person <to@example.com>' ),
		'Transport test ' . $id,
		'<p>Hello</p>',
		array( 'Content-Type: text/html', 'Cc: cc@example.com', 'Bcc: bcc@example.com', 'Reply-To: reply@example.com' )
	);
	remove_action( 'wp_mail_failed', $on_fail );

	$request = $requests ? end( $requests ) : null;
	$body    = $request ? (string) $request['args']['body'] : '';

	check( "$id sends", $ok, $error );
	check( "$id calls the right endpoint", $request && $url === $request['url'], $request ? $request['url'] : 'no request' );
	check( "$id includes every recipient", false !== strpos( $body, 'to@example.com' ) && false !== strpos( $body, 'cc@example.com' ) && false !== strpos( $body, 'bcc@example.com' ), substr( $body, 0, 300 ) );

	if ( 'ses' === $id ) {
		$json = json_decode( $body, true );
		$raw  = base64_decode( $json['Content']['Raw']['Data'] );

		check( 'ses raw message has To and Subject', false !== strpos( $raw, "To: To Person <to@example.com>" ) && false !== strpos( $raw, 'Subject: Transport test ses' ), substr( $raw, 0, 400 ) );
		check( 'ses raw message hides Bcc', false === stripos( $raw, 'bcc@example.com' ) );
		check( 'ses request is signed', 0 === strpos( $request['args']['headers']['Authorization'], 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/' ) );
	}

	if ( 'zeptomail' === $id ) {
		check( 'zeptomail token gets its prefix', 'Zoho-enczapikey tok' === $request['args']['headers']['Authorization'] );
	}
}

echo "== Backup transport ==\n";

$respond['api.sendgrid.com'] = mh_reply( 401, '{"errors":[{"message":"bad key"}]}' );
Mailhouse_Settings::set(
	array(
		'mailer'          => 'sendgrid',
		'sendgrid_key'    => 'SG.bad',
		'fallback_mailer' => 'mailjet',
	)
);

$requests = array();
$ok       = wp_mail( 'to@example.com', 'Fallback test', 'Body' );
$urls     = wp_list_pluck( $requests, 'url' );

check( 'the main transport was tried first', isset( $urls[0] ) && false !== strpos( $urls[0], 'sendgrid' ), wp_json_encode( $urls ) );
check( 'the backup delivered it', $ok && isset( $urls[1] ) && false !== strpos( $urls[1], 'mailjet' ), wp_json_encode( $urls ) );

Mailhouse_Settings::set( array( 'fallback_mailer' => '' ) );
$ok = wp_mail( 'to@example.com', 'No fallback', 'Body' );
check( 'without a backup the failure is reported', ! $ok );

echo "== Newsletter transport ==\n";

Mailhouse_Settings::set(
	array(
		'mailer'          => 'mailjet',
		'campaign_mailer' => 'mailersend',
	)
);

$requests = array();
Mailhouse_Mailer::send( 'to@example.com', 'Campaign', '<p>x</p>', array( 'campaign_id' => 1 ) );
$campaign_url = $requests ? end( $requests )['url'] : '';
$requests     = array();
wp_mail( 'to@example.com', 'Site', 'x' );
$site_url = $requests ? end( $requests )['url'] : '';

check( 'campaign mail uses the newsletter transport', false !== strpos( $campaign_url, 'mailersend' ), $campaign_url );
check( 'site mail uses the main transport', false !== strpos( $site_url, 'mailjet' ), $site_url );

echo "\n== Cleanup ==\n";

Mailhouse_Settings::set(
	array(
		'mailer'          => 'php',
		'campaign_mailer' => '',
		'fallback_mailer' => '',
		'template_logo'   => '',
		'social_x'        => '',
		'company_address' => '',
		'template_button' => '#2b6cb0',
	)
);

Mailhouse_Subscribers::delete( array_map( 'intval', $mailhouse_cleanup['subscribers'] ) );

foreach ( array_unique( $mailhouse_cleanup['posts'] ) as $id ) {
	if ( $id ) {
		wp_delete_post( (int) $id, true );
	}
}

wp_clear_scheduled_hook( 'mailhouse_notify_post' );

echo "  cleaned up\n";

mailhouse_test_results();
