<?php
/**
 * Every admin screen, and the public form endpoint.
 *
 * Renders each screen and fails on any notice or warning raised inside the
 * plugin, then drives the signup endpoint through its accept and reject paths.
 *
 * Usage: php tests/test-admin.php
 *
 * @package Mailhouse
 */

define( 'WP_ADMIN', true );

require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

$issues = array();

set_error_handler(
	function ( $no, $string, $file, $line ) use ( &$issues ) {
		// Ignore anything raised inside WordPress itself; we are testing the
		// plugin, not the version of core installed here.
		if ( false !== strpos( str_replace( '\\', '/', $file ), '/mailhouse/' ) ) {
			$issues[] = "$string in $file:$line";
		}

		return true;
	}
);

$mailhouse_admin_user = get_users( array( 'role' => 'administrator', 'number' => 1 ) );

if ( ! $mailhouse_admin_user ) {
	exit( "No administrator on this site; the admin screens cannot be tested.\n" );
}

wp_set_current_user( $mailhouse_admin_user[0]->ID );

echo "== Admin screens ==\n";

$screens = array(
	'Dashboard'   => function () {
		mailhouse()->module( 'admin' )->dashboard();
	},
	'Subscribers' => function () {
		mailhouse()->module( 'admin' )->subscribers();
	},
	'Add form'    => function () {
		$_GET['action'] = 'edit';
		mailhouse()->module( 'admin' )->subscribers();
		unset( $_GET['action'] );
	},
	'Tags'        => function () {
		mailhouse()->module( 'admin' )->tags();
	},
	'Email log'   => function () {
		mailhouse()->module( 'admin' )->log();
	},
	'Settings'    => function () {
		$settings = new Mailhouse_Admin_Settings();

		foreach ( array( 'delivery', 'newsletter', 'design', 'signup', 'log', 'advanced' ) as $tab ) {
			$_GET['tab'] = $tab;
			$settings->render();
		}

		unset( $_GET['tab'] );
	},
	'Tools'       => function () {
		$tools = new Mailhouse_Tools();
		$tools->render();
	},
);

foreach ( $screens as $label => $render ) {
	$issues = array();

	set_current_screen( 'toplevel_page_mailhouse' );

	ob_start();

	try {
		$render();
		$html = (string) ob_get_clean();
	} catch ( Throwable $e ) {
		ob_end_clean();
		$html   = '';
		$issues[] = get_class( $e ) . ': ' . $e->getMessage();
	}

	check( "$label renders", '' !== $html && ! $issues, $issues ? implode( ' | ', $issues ) : 'empty output' );
	check( "$label output is a wrapped screen", false !== strpos( $html, 'class="wrap mailhouse"' ) );
}

echo "== Email editor panels ==\n";

$campaign = wp_insert_post(
	array(
		'post_type'   => 'mailhouse_email',
		'post_status' => 'publish',
		'post_title'  => 'Panel harness',
	)
);

update_post_meta( $campaign, '_mailhouse_type', 'newsletter' );

$emails = mailhouse()->module( 'emails' );
$post   = get_post( $campaign );

// The new post panel is rendered hidden on every email, so a broadcast
// exercises it too; a new_post email checks it with its values loaded.
foreach ( array( 'newsletter', 'new_post' ) as $type ) {
	update_post_meta( $campaign, '_mailhouse_type', $type );
	update_post_meta( $campaign, '_mailhouse_frequency', 'weekly' );

	$issues = array();
	ob_start();
	$emails->settings_box( get_post( $campaign ) );
	$html = (string) ob_get_clean();

	check( "settings_box renders for $type", ! $issues && false !== strpos( $html, 'mailhouse_frequency' ), implode( ' | ', $issues ) );
}

update_post_meta( $campaign, '_mailhouse_type', 'newsletter' );

foreach ( array( 'settings_box', 'send_box', 'tags_box' ) as $method ) {
	$issues = array();

	ob_start();

	try {
		$emails->$method( $post );
		$html = (string) ob_get_clean();
	} catch ( Throwable $e ) {
		ob_end_clean();
		$html     = '';
		$issues[] = get_class( $e ) . ': ' . $e->getMessage();
	}

	check( "$method renders", '' !== $html && ! $issues, $issues ? implode( ' | ', $issues ) : 'empty output' );
}

echo "== Public form endpoint ==\n";

// Redirects end the request, so catch them instead.
add_filter(
	'wp_redirect',
	function ( $location ) {
		throw new RuntimeException( 'REDIRECT ' . $location );
	}
);

$sent = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$sent ) {
		$sent[] = $atts;
		return true;
	},
	1,
	2
);

$forms = mailhouse()->module( 'forms' );

/**
 * Post a form submission and return the URL it redirected to.
 *
 * @param array $post Fields.
 * @return string
 */
function submit( $post ) {
	global $forms;

	// The signup limit refuses a request it cannot attribute to an address.
	$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

	$_POST    = $post;
	$_REQUEST = $post;

	try {
		$forms->handle();
	} catch ( RuntimeException $e ) {
		return $e->getMessage();
	}

	return 'no redirect';
}

$config    = base64_encode( wp_json_encode( array( 'tags' => 'Form harness', 'redirect' => '', 'source' => 'harness', 'consent' => 1 ) ) );
$signature = hash_hmac( 'sha256', $config, wp_salt( 'mailhouse_form' ) );
$address   = 'form-harness-' . wp_generate_password( 6, false ) . '@example.com';
$address   = strtolower( $address );

$result = submit(
	array(
		'mailhouse_config'    => $config,
		'mailhouse_signature' => $signature,
		'mailhouse_time'      => time() - 10,
		'mailhouse_nonce'     => wp_create_nonce( 'mailhouse_subscribe' ),
		'email'               => $address,
		'first_name'          => 'Grace',
		'consent'             => '1',
	)
);

// The confirmation is sent straight after the request, from cron, so the
// response time does not reveal whether the address was new. Run it now.
foreach ( (array) _get_cron_array() as $mailhouse_hooks ) {
	if ( ! empty( $mailhouse_hooks['mailhouse_send_confirmation'] ) ) {
		foreach ( $mailhouse_hooks['mailhouse_send_confirmation'] as $mailhouse_event ) {
			do_action_ref_array( 'mailhouse_send_confirmation', $mailhouse_event['args'] );
			wp_unschedule_event( wp_next_scheduled( 'mailhouse_send_confirmation', $mailhouse_event['args'] ), 'mailhouse_send_confirmation', $mailhouse_event['args'] );
		}
	}
}

check( 'valid submission redirects with the success flag', false !== strpos( $result, 'mailhouse=ok' ), $result );
check( 'valid submission creates a pending subscriber', (bool) Mailhouse_Subscribers::get_by_email( $address ) );
check( 'valid submission sends one confirmation', 1 === count( $sent ), 'count ' . count( $sent ) );
check( 'the form tag was applied', 1 === count( Mailhouse_Tags::for_subscriber( Mailhouse_Subscribers::get_by_email( $address )->id ) ) );

$before = Mailhouse_Subscribers::count();

$result = submit(
	array(
		'mailhouse_config'    => $config,
		'mailhouse_signature' => $signature,
		'mailhouse_time'      => time() - 10,
		'email'               => 'bot@example.com',
		'consent'             => '1',
		'mailhouse_website'   => 'http://spam.example',
	)
);

check( 'honeypot answers exactly like success', false !== strpos( $result, 'mailhouse=ok' ), $result );
check( 'honeypot creates nothing', Mailhouse_Subscribers::count() === $before );

$result = submit(
	array(
		'mailhouse_config'    => $config,
		'mailhouse_signature' => $signature,
		'mailhouse_time'      => time(),
		'email'               => 'fast@example.com',
		'consent'             => '1',
	)
);

check( 'a form filled in instantly is treated as a bot', false !== strpos( $result, 'mailhouse=ok' ) );
check( 'the speed trap creates nothing', Mailhouse_Subscribers::count() === $before );

$result = submit(
	array(
		'mailhouse_config'    => $config,
		'mailhouse_signature' => 'forged',
		'mailhouse_time'      => time() - 10,
		'email'               => 'forged@example.com',
		'consent'             => '1',
	)
);

check( 'a tampered configuration is rejected', false !== strpos( $result, 'mailhouse=invalid' ), $result );

$result = submit(
	array(
		'mailhouse_config'    => $config,
		'mailhouse_signature' => $signature,
		'mailhouse_time'      => time() - 10,
		'email'               => 'no-consent@example.com',
	)
);

check( 'missing consent is rejected', false !== strpos( $result, 'mailhouse=consent' ), $result );

$result = submit(
	array(
		'mailhouse_config'    => $config,
		'mailhouse_signature' => $signature,
		'mailhouse_time'      => time() - 10,
		'email'               => 'not-an-address',
		'consent'             => '1',
	)
);

check( 'an invalid address is rejected', false !== strpos( $result, 'mailhouse=invalid' ), $result );

echo "== Opt-in pages ==\n";

$subscriber = Mailhouse_Subscribers::get_by_email( $address );

check( 'confirm URL uses a real path', false !== strpos( Mailhouse_Optin::confirm_url( $subscriber ), $subscriber->confirm_key ) );

echo "\n== Cleanup ==\n";

Mailhouse_Subscribers::delete( array( (int) $subscriber->id ) );

foreach ( Mailhouse_Tags::all() as $tag ) {
	if ( 'form-harness' === $tag->slug ) {
		Mailhouse_Tags::delete( (int) $tag->id );
	}
}

wp_delete_post( $campaign, true );

echo "  cleaned up\n";

mailhouse_test_results();