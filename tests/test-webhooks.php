<?php
/**
 * Bounce and complaint webhooks.
 *
 * Drives real provider payloads through the endpoint's parsing and verification
 * without a network: every signature below is computed the way the provider
 * computes it, so a change that breaks verification fails here rather than in
 * production, where the symptom is silently ignoring every bounce.
 *
 * Usage: php tests/test-webhooks.php
 *
 * @package Mailhouse
 */

require __DIR__ . '/bootstrap.php';

global $wpdb;

$saved_settings = get_option( 'mailhouse_settings' );
$saved_key      = get_option( 'mailhouse_webhook_key' );

// Nothing should actually be sent while this runs.
add_filter(
	'pre_wp_mail',
	function () {
		return true;
	},
	1
);

/**
 * Add a subscriber for one of the cases below.
 *
 * @param string $slug   Short label, used in the address.
 * @param string $status Starting status.
 * @return object
 */
function mailhouse_fixture( $slug, $status = 'subscribed' ) {
	$email    = strtolower( 'hook-' . $slug . '-' . wp_generate_password( 5, false ) . '@example.com' );
	$existing = Mailhouse_Subscribers::get_by_email( $email );

	if ( $existing ) {
		Mailhouse_Subscribers::delete( array( (int) $existing->id ) );
	}

	$result = Mailhouse_Subscribers::add(
		array(
			'email'       => $email,
			'first_name'  => 'Hook',
			'status'      => $status,
			'source'      => 'webhook-test',
			'send_emails' => false,
		)
	);

	return Mailhouse_Subscribers::get( $result['id'] );
}

/**
 * The status a subscriber holds now.
 *
 * @param object $subscriber Subscriber row.
 * @return string
 */
function mailhouse_status_of( $subscriber ) {
	$fresh = Mailhouse_Subscribers::get( (int) $subscriber->id );

	return $fresh ? $fresh->status : 'gone';
}

$created = array();

echo "== The endpoint URL and its key ==\n";

$key = Mailhouse_Webhooks::key();

check( 'a key is generated on first use', 32 === strlen( $key ), $key );
check( 'the URL carries the provider and the key', false !== strpos( Mailhouse_Webhooks::url( 'mailgun' ), 'mailhouse_webhook=mailgun' ) && false !== strpos( Mailhouse_Webhooks::url( 'mailgun' ), $key ) );
check( 'the right key authenticates', Mailhouse_Webhooks::authenticates( $key ) );
check( 'a wrong key does not', ! Mailhouse_Webhooks::authenticates( str_repeat( 'a', 32 ) ) );
check( 'an empty key does not', ! Mailhouse_Webhooks::authenticates( '' ) );

$rotated = Mailhouse_Webhooks::rotate_key();

check( 'rotating produces a different key', $rotated !== $key );
check( 'the old key stops working', ! Mailhouse_Webhooks::authenticates( $key ) );

echo "== SendGrid ==\n";

$hard      = mailhouse_fixture( 'sg-hard' );
$spam      = mailhouse_fixture( 'sg-spam' );
$delivered = mailhouse_fixture( 'sg-ok' );
$created   = array_merge( $created, array( $hard, $spam, $delivered ) );

$batch = wp_json_encode(
	array(
		array(
			'email'  => $hard->email,
			'event'  => 'bounce',
			'type'   => 'bounce',
			'reason' => '550 5.1.1 The email account that you tried to reach does not exist',
		),
		array(
			'email' => $spam->email,
			'event' => 'spamreport',
		),
		array(
			'email' => $delivered->email,
			'event' => 'delivered',
		),
		array(
			'email' => $delivered->email,
			'event' => 'open',
		),
	)
);

$result = Mailhouse_Webhooks::process( 'sendgrid', $batch );

check( 'a batch is parsed', is_array( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
check( 'opens are ignored', 3 === $result['received'], wp_json_encode( $result ) );
check( 'a hard bounce retires the address', 'bounced' === mailhouse_status_of( $hard ), mailhouse_status_of( $hard ) );
check( 'a spam report unsubscribes', 'unsubscribed' === mailhouse_status_of( $spam ), mailhouse_status_of( $spam ) );
check( 'a delivery changes nothing', 'subscribed' === mailhouse_status_of( $delivered ) );

$logged = Mailhouse_Log::query( array( 'status' => 'bounced', 'per_page' => 1 ) );
check( "the provider's own wording is logged", $logged && false !== strpos( $logged[0]->error, '550 5.1.1' ), $logged ? $logged[0]->error : 'nothing logged' );
check( 'the log row is attributed to the subscriber', $logged && (int) $logged[0]->subscriber_id === (int) $hard->id );

$blocked   = mailhouse_fixture( 'sg-blocked' );
$created[] = $blocked;

Mailhouse_Webhooks::process(
	'sendgrid',
	wp_json_encode(
		array(
			array(
				'email' => $blocked->email,
				'event' => 'bounce',
				'type'  => 'blocked',
			),
		)
	)
);

check( 'SendGrid calls a soft bounce "blocked", and it is treated as one', 'subscribed' === mailhouse_status_of( $blocked ) );
check( 'the soft bounce was counted', 1 === (int) Mailhouse_Subscribers::get( (int) $blocked->id )->bounces );

echo "== Mailgun ==\n";

$permanent = mailhouse_fixture( 'mg-permanent' );
$temporary = mailhouse_fixture( 'mg-temporary' );
$created   = array_merge( $created, array( $permanent, $temporary ) );

/**
 * Build a Mailgun payload, signed when a key is given.
 *
 * @param string $event     Event name.
 * @param string $recipient Address.
 * @param string $severity  permanent or temporary.
 * @param string $secret    Signing key, or empty for unsigned.
 * @param int    $timestamp Signature timestamp.
 * @return string
 */
function mailhouse_mailgun_payload( $event, $recipient, $severity = 'permanent', $secret = '', $timestamp = 0 ) {
	$timestamp = $timestamp ? $timestamp : time();
	$token     = str_repeat( 'b', 50 );

	$payload = array(
		'event-data' => array(
			'event'           => $event,
			'severity'        => $severity,
			'recipient'       => $recipient,
			'delivery-status' => array( 'message' => 'mailbox unavailable' ),
		),
	);

	if ( $secret ) {
		$payload['signature'] = array(
			'timestamp' => (string) $timestamp,
			'token'     => $token,
			'signature' => hash_hmac( 'sha256', $timestamp . $token, $secret ),
		);
	}

	return wp_json_encode( $payload );
}

Mailhouse_Webhooks::process( 'mailgun', mailhouse_mailgun_payload( 'failed', $permanent->email, 'permanent' ) );
Mailhouse_Webhooks::process( 'mailgun', mailhouse_mailgun_payload( 'failed', $temporary->email, 'temporary' ) );

check( 'a permanent failure retires the address', 'bounced' === mailhouse_status_of( $permanent ) );
check( 'a temporary failure does not', 'subscribed' === mailhouse_status_of( $temporary ) );
check( 'the delivery status message is kept', (bool) Mailhouse_Log::count( array( 'search' => 'mailbox unavailable' ) ) );

echo "== Mailgun signature ==\n";

$secret    = 'key-' . wp_generate_password( 24, false );
$signed    = mailhouse_fixture( 'mg-signed' );
$created[] = $signed;

Mailhouse_Settings::set( array( 'webhook_mailgun_key' => $secret ) );

$valid  = mailhouse_mailgun_payload( 'failed', $signed->email, 'permanent', $secret );
$result = Mailhouse_Webhooks::process( 'mailgun', $valid );

check( 'a correctly signed callback is accepted', is_array( $result ) && 1 === $result['applied'], is_wp_error( $result ) ? $result->get_error_message() : '' );

$tampered = str_replace( $signed->email, 'someone-else@example.com', $valid );
$result   = Mailhouse_Webhooks::process( 'mailgun', $tampered );

// Mailgun signs a token rather than the body, so a changed body still
// verifies -- what the signature proves is that the callback came from
// Mailgun. The address being on nobody's list is what makes it harmless.
check( 'a body change keeps the signature valid (Mailgun signs a token)', is_array( $result ) );

$result = Mailhouse_Webhooks::process( 'mailgun', mailhouse_mailgun_payload( 'failed', $signed->email, 'permanent', 'wrong-secret' ) );
check( 'a callback signed with the wrong key is refused', is_wp_error( $result ) && 'mailhouse_signature' === $result->get_error_code() );

$result = Mailhouse_Webhooks::process( 'mailgun', mailhouse_mailgun_payload( 'failed', $signed->email, 'permanent', $secret, time() - DAY_IN_SECONDS ) );
check( 'a replayed callback from yesterday is refused', is_wp_error( $result ) );

$result = Mailhouse_Webhooks::process( 'mailgun', mailhouse_mailgun_payload( 'failed', $signed->email ) );
check( 'an unsigned callback is refused once a key is set', is_wp_error( $result ) );

Mailhouse_Settings::set( array( 'webhook_mailgun_key' => '' ) );

echo "== Resend, signed the Svix way ==\n";

$resend    = mailhouse_fixture( 'resend' );
$created[] = $resend;

$svix_secret = 'whsec_' . base64_encode( random_bytes( 24 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

Mailhouse_Settings::set( array( 'webhook_resend_secret' => $svix_secret ) );

$body = wp_json_encode(
	array(
		'type' => 'email.bounced',
		'data' => array(
			'to'     => array( $resend->email ),
			'bounce' => array(
				'type'    => 'Permanent',
				'message' => 'The recipient address does not exist',
			),
		),
	)
);

/**
 * Sign a body the way Svix does and put the headers in place.
 *
 * @param string $body      Raw body.
 * @param string $secret    whsec_ secret.
 * @param int    $timestamp Signature timestamp.
 * @param bool   $correct   Whether to sign correctly.
 * @return void
 */
function mailhouse_svix_headers( $body, $secret, $timestamp = 0, $correct = true ) {
	$timestamp = $timestamp ? $timestamp : time();
	$id        = 'msg_' . wp_generate_password( 12, false );
	$raw       = base64_decode( preg_replace( '/^whsec_/', '', $secret ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	$signed    = $correct ? $raw : 'something else entirely';

	$_SERVER['HTTP_SVIX_ID']        = $id;
	$_SERVER['HTTP_SVIX_TIMESTAMP'] = (string) $timestamp;
	$_SERVER['HTTP_SVIX_SIGNATURE'] = 'v1,' . base64_encode( hash_hmac( 'sha256', $id . '.' . $timestamp . '.' . $body, $signed, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

mailhouse_svix_headers( $body, $svix_secret );
$result = Mailhouse_Webhooks::process( 'resend', $body );

check( 'a correctly signed Resend callback is accepted', is_array( $result ) && 1 === $result['applied'], is_wp_error( $result ) ? $result->get_error_message() : '' );
check( 'the bounce retired the address', 'bounced' === mailhouse_status_of( $resend ) );

$resend2   = mailhouse_fixture( 'resend2' );
$created[] = $resend2;
$body2     = str_replace( $resend->email, $resend2->email, $body );

// Svix signs the body, so changing it must invalidate the signature.
mailhouse_svix_headers( $body, $svix_secret );
$result = Mailhouse_Webhooks::process( 'resend', $body2 );

check( 'a body changed after signing is refused', is_wp_error( $result ), is_array( $result ) ? 'accepted' : '' );
check( 'and the substituted address was left alone', 'subscribed' === mailhouse_status_of( $resend2 ) );

mailhouse_svix_headers( $body2, $svix_secret, 0, false );
check( 'a wrongly signed callback is refused', is_wp_error( Mailhouse_Webhooks::process( 'resend', $body2 ) ) );

mailhouse_svix_headers( $body2, $svix_secret, time() - DAY_IN_SECONDS );
check( 'a stale signature is refused', is_wp_error( Mailhouse_Webhooks::process( 'resend', $body2 ) ) );

unset( $_SERVER['HTTP_SVIX_ID'], $_SERVER['HTTP_SVIX_TIMESTAMP'], $_SERVER['HTTP_SVIX_SIGNATURE'] );
Mailhouse_Settings::set( array( 'webhook_resend_secret' => '' ) );

echo "== Brevo and Postmark ==\n";

$brevo     = mailhouse_fixture( 'brevo' );
$brevo_spam = mailhouse_fixture( 'brevo-spam' );
$created   = array_merge( $created, array( $brevo, $brevo_spam ) );

Mailhouse_Webhooks::process( 'brevo', wp_json_encode( array( 'event' => 'hard_bounce', 'email' => $brevo->email, 'reason' => 'unknown user' ) ) );
Mailhouse_Webhooks::process( 'brevo', wp_json_encode( array( 'event' => 'spam', 'email' => $brevo_spam->email ) ) );

check( 'a Brevo hard bounce retires the address', 'bounced' === mailhouse_status_of( $brevo ) );
check( 'a Brevo spam event unsubscribes', 'unsubscribed' === mailhouse_status_of( $brevo_spam ) );

$pm_hard   = mailhouse_fixture( 'pm-hard' );
$pm_soft   = mailhouse_fixture( 'pm-soft' );
$pm_off    = mailhouse_fixture( 'pm-off' );
$created   = array_merge( $created, array( $pm_hard, $pm_soft, $pm_off ) );

Mailhouse_Webhooks::process( 'postmark', wp_json_encode( array( 'RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => $pm_hard->email, 'Description' => 'The server was unable to deliver your message', 'Inactive' => true ) ) );
Mailhouse_Webhooks::process( 'postmark', wp_json_encode( array( 'RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'Email' => $pm_soft->email, 'Inactive' => false ) ) );
Mailhouse_Webhooks::process( 'postmark', wp_json_encode( array( 'RecordType' => 'SubscriptionChange', 'Email' => $pm_off->email, 'SuppressSending' => true ) ) );

check( 'a Postmark hard bounce retires the address', 'bounced' === mailhouse_status_of( $pm_hard ) );
check( 'a Postmark soft bounce does not', 'subscribed' === mailhouse_status_of( $pm_soft ) );
check( 'a Postmark suppression unsubscribes', 'unsubscribed' === mailhouse_status_of( $pm_off ) );

echo "== Soft bounces add up, a delivery clears them ==\n";

Mailhouse_Settings::set( array( 'soft_bounce_limit' => 3 ) );

$creeping  = mailhouse_fixture( 'creeping' );
$created[] = $creeping;

for ( $i = 1; $i <= 2; $i++ ) {
	Mailhouse_Webhooks::process( 'mailgun', mailhouse_mailgun_payload( 'failed', $creeping->email, 'temporary' ) );
}

check( 'two soft bounces leave the address alone', 'subscribed' === mailhouse_status_of( $creeping ) );
check( 'but they are counted', 2 === (int) Mailhouse_Subscribers::get( (int) $creeping->id )->bounces );

Mailhouse_Webhooks::process( 'mailgun', mailhouse_mailgun_payload( 'delivered', $creeping->email ) );

check( 'a confirmed delivery clears the count', 0 === (int) Mailhouse_Subscribers::get( (int) $creeping->id )->bounces );
check( 'and the address is still on the list', 'subscribed' === mailhouse_status_of( $creeping ) );

for ( $i = 1; $i <= 3; $i++ ) {
	Mailhouse_Webhooks::process( 'mailgun', mailhouse_mailgun_payload( 'failed', $creeping->email, 'temporary' ) );
}

check( 'reaching the limit retires the address', 'bounced' === mailhouse_status_of( $creeping ) );

Mailhouse_Settings::set( array( 'soft_bounce_limit' => 0 ) );

$forgiven  = mailhouse_fixture( 'forgiven' );
$created[] = $forgiven;

for ( $i = 1; $i <= 5; $i++ ) {
	Mailhouse_Webhooks::process( 'mailgun', mailhouse_mailgun_payload( 'failed', $forgiven->email, 'temporary' ) );
}

check( 'a limit of zero never retires on soft bounces', 'subscribed' === mailhouse_status_of( $forgiven ) );

Mailhouse_Settings::set( array( 'soft_bounce_limit' => 3 ) );

echo "== Queued mail is dropped, and other edges ==\n";

$queued    = mailhouse_fixture( 'queued' );
$created[] = $queued;

$campaign = wp_insert_post(
	array(
		'post_type'    => 'mailhouse_email',
		'post_status'  => 'publish',
		'post_title'   => 'Webhook harness issue',
		'post_content' => '<p>Body</p>',
	)
);

update_post_meta( $campaign, '_mailhouse_type', 'newsletter' );
Mailhouse_Queue::enqueue_one( $campaign, (int) $queued->id, $queued->email, time() + HOUR_IN_SECONDS );

$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mailhouse_queue WHERE subscriber_id = %d AND status = 'pending'", $queued->id ) );
check( 'a message is waiting for them', 1 === $pending );

Mailhouse_Webhooks::process( 'brevo', wp_json_encode( array( 'event' => 'hard_bounce', 'email' => $queued->email ) ) );

$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mailhouse_queue WHERE subscriber_id = %d AND status = 'pending'", $queued->id ) );
check( 'bouncing drops what was queued for them', 0 === $pending );

$pending_sub = mailhouse_fixture( 'pending-complaint', 'pending' );
$created[]   = $pending_sub;

Mailhouse_Webhooks::process( 'brevo', wp_json_encode( array( 'event' => 'spam', 'email' => $pending_sub->email ) ) );
check( 'a complaint unsubscribes even an unconfirmed address', 'unsubscribed' === mailhouse_status_of( $pending_sub ) );

$before = Mailhouse_Subscribers::count();
$result = Mailhouse_Webhooks::process( 'brevo', wp_json_encode( array( 'event' => 'hard_bounce', 'email' => 'nobody-here@example.com' ) ) );

check( 'an address that is not on the list is no error', is_array( $result ) && 0 === $result['applied'], is_wp_error( $result ) ? $result->get_error_message() : '' );
check( 'and nobody is created for it', Mailhouse_Subscribers::count() === $before );
check( 'but it is still logged', (bool) Mailhouse_Log::count( array( 'search' => 'nobody-here@example.com' ) ) );

check( 'a body that is not JSON is refused', is_wp_error( Mailhouse_Webhooks::process( 'brevo', 'not json at all' ) ) );
check( 'an unknown event type does nothing', 0 === Mailhouse_Webhooks::process( 'brevo', wp_json_encode( array( 'event' => 'opened', 'email' => $forgiven->email ) ) )['received'] );

$twice  = mailhouse_fixture( 'twice' );
$created[] = $twice;
$payload = wp_json_encode( array( 'event' => 'hard_bounce', 'email' => $twice->email ) );

Mailhouse_Webhooks::process( 'brevo', $payload );
$second = Mailhouse_Webhooks::process( 'brevo', $payload );

check( 'the same bounce twice changes nothing the second time', 0 === $second['applied'], wp_json_encode( $second ) );

echo "\n== Cleanup ==\n";

$ids = array();

foreach ( $created as $subscriber ) {
	$ids[] = (int) $subscriber->id;
}

Mailhouse_Subscribers::delete( $ids );
wp_delete_post( $campaign, true );

update_option( 'mailhouse_settings', $saved_settings );
update_option( 'mailhouse_webhook_key', $saved_key );
Mailhouse_Settings::flush();
Mailhouse_Log::clear();

echo "  cleaned up\n";

mailhouse_test_results();
