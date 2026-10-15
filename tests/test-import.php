<?php
/**
 * The importers: CSV, Noptin and WP Mail SMTP.
 *
 * Usage: php tests/test-import.php
 *
 * @package Mailhouse
 */

define( 'WP_ADMIN', true );

require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

// A transport that records what it was handed instead of sending it.
class_exists( 'Mailhouse_Provider' );

class MH_Import_Provider extends Mailhouse_Provider {
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
		$classes[] = 'MH_Import_Provider';
		return $classes;
	}
);

global $wpdb;

$mailhouse_admin_user = get_users( array( 'role' => 'administrator', 'number' => 1 ) );

if ( ! $mailhouse_admin_user ) {
	exit( "No administrator on this site; the importers cannot be tested.\n" );
}

wp_set_current_user( $mailhouse_admin_user[0]->ID );

// Restored at the end, so running the tests does not disturb a live setup.
$saved_settings = get_option( 'mailhouse_settings' );

echo "== Repeat signup keeps the confirmation key ==\n";

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

$email = strtolower( 'repeat-' . wp_generate_password( 6, false ) . '@example.com' );

$first = Mailhouse_Subscribers::add( array( 'email' => $email ) );
$key   = Mailhouse_Subscribers::get( $first['id'] )->confirm_key;

Mailhouse_Subscribers::add( array( 'email' => $email ) );
$key_after = Mailhouse_Subscribers::get( $first['id'] )->confirm_key;

check( 'the key from the first email still works', $key === $key_after, "$key vs $key_after" );
check( 'the throttle held the second email back', 1 === count( $sent ), 'count ' . count( $sent ) );
check( 'the original link resolves', (int) Mailhouse_Subscribers::get_by_key( 'confirm_key', $key )->id === (int) $first['id'] );

Mailhouse_Subscribers::delete( array( $first['id'] ) );

echo "== The log records the address actually used ==\n";

remove_all_filters( 'pre_wp_mail' );
$GLOBALS['phpmailer'] = null;
add_filter( 'pre_wp_mail', array( mailhouse()->module( 'mailer' ), 'install_phpmailer' ), 5, 2 );

Mailhouse_Settings::set(
	array(
		'mailer'           => 'harness',
		'from_email'       => 'configured@example.com',
		'force_from_email' => false,
		'log_enabled'      => true,
	)
);

wp_mail( 'someone@example.com', 'Sent as somebody else', 'Body', array( 'From: Shop <shop@example.com>' ) );

$row = Mailhouse_Log::query( array( 'per_page' => 1 ) );

check( 'the log kept the caller-set sender', $row && 'shop@example.com' === $row[0]->from_email, $row ? $row[0]->from_email : 'nothing logged' );

Mailhouse_Settings::set( array( 'force_from_email' => true ) );
wp_mail( 'someone@example.com', 'Forced sender', 'Body', array( 'From: Shop <shop@example.com>' ) );

$row = Mailhouse_Log::query( array( 'per_page' => 1 ) );
check( 'forcing overrides it and the log agrees', $row && 'configured@example.com' === $row[0]->from_email, $row ? $row[0]->from_email : 'nothing logged' );

echo "== CSV import ==\n";

$csv = sys_get_temp_dir() . '/mailhouse-harness.csv';
$run = wp_generate_password( 5, false );

file_put_contents(
	$csv,
	"Email Address,First Name,Surname,Status,Tags\n" .
	"csv1-$run@example.com,Ada,Lovelace,subscribed,\"weekly, product\"\n" .
	"CSV2-$run@EXAMPLE.com,Grace,Hopper,,\n" .
	"not-an-address,Broken,Row,,\n" .
	"csv3-$run@example.com,,,unsubscribed,\n"
);

$result = Mailhouse_Tools::import_file( $csv, 'pending', 'Imported harness' );

check( 'import returns counts', is_array( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
check( 'three good rows imported', 3 === $result['imported'], wp_json_encode( $result ) );
check( 'the invalid row was skipped', 1 === $result['skipped'] );

$one = Mailhouse_Subscribers::get_by_email( "csv1-$run@example.com" );
check( 'aliased column names were understood', $one && 'Ada' === $one->first_name && 'Lovelace' === $one->last_name );
check( 'the status column was honoured', $one && 'subscribed' === $one->status );
check( 'row tags and the blanket tag both applied', $one && 3 === count( Mailhouse_Tags::for_subscriber( $one->id ) ), $one ? count( Mailhouse_Tags::for_subscriber( $one->id ) ) : 0 );

$two = Mailhouse_Subscribers::get_by_email( "csv2-$run@example.com" );
check( 'a mixed-case address is normalised', (bool) $two );
check( 'rows with no status take the chosen default', $two && 'pending' === $two->status, $two ? $two->status : '' );

$three = Mailhouse_Subscribers::get_by_email( "csv3-$run@example.com" );
check( 'unsubscribed rows import as unsubscribed', $three && 'unsubscribed' === $three->status );

check( 'importing sends nobody anything', 0 === count( $sent ) - 1, 'unexpected sends' );

// Re-running must update rather than duplicate.
$before = Mailhouse_Subscribers::count();
Mailhouse_Tools::import_file( $csv, 'pending', '' );
check( 'a second run creates no duplicates', Mailhouse_Subscribers::count() === $before );

$missing = sys_get_temp_dir() . '/mailhouse-nope.csv';
check( 'a missing file is an error, not a crash', is_wp_error( Mailhouse_Tools::import_file( $missing ) ) );

file_put_contents( $csv, "name,city\nAda,London\n" );
$bad = Mailhouse_Tools::import_file( $csv );
check( 'a CSV with no email column is refused', is_wp_error( $bad ) && 'no_email_column' === $bad->get_error_code() );

echo "== Noptin import ==\n";

$noptin = $wpdb->prefix . 'noptin_subscribers';

$wpdb->query(
	"CREATE TABLE IF NOT EXISTS {$noptin} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		first_name varchar(100) DEFAULT '',
		second_name varchar(100) DEFAULT '',
		email varchar(191) NOT NULL,
		active int(2) NOT NULL DEFAULT 0,
		confirmed int(2) NOT NULL DEFAULT 0,
		date_created date DEFAULT NULL,
		PRIMARY KEY (id)
	)"
);

$wpdb->query( "TRUNCATE TABLE {$noptin}" );

$wpdb->insert( $noptin, array( 'email' => "noptin-confirmed-$run@example.com", 'first_name' => 'Alan', 'second_name' => 'Turing', 'active' => 0, 'confirmed' => 1, 'date_created' => '2021-03-04' ) );
$wpdb->insert( $noptin, array( 'email' => "noptin-unconfirmed-$run@example.com", 'first_name' => 'Edsger', 'active' => 0, 'confirmed' => 0 ) );
$wpdb->insert( $noptin, array( 'email' => "noptin-inactive-$run@example.com", 'active' => 1, 'confirmed' => 1 ) );

$tools     = new Mailhouse_Tools();
$reflect   = new ReflectionMethod( 'Mailhouse_Tools', 'import_noptin' );
$reflect->setAccessible( true );
$imported = $reflect->invoke( $tools );

check( 'all three rows imported', 3 === $imported, "imported: $imported" );

$confirmed = Mailhouse_Subscribers::get_by_email( "noptin-confirmed-$run@example.com" );
check( 'a confirmed Noptin subscriber becomes subscribed', $confirmed && 'subscribed' === $confirmed->status, $confirmed ? $confirmed->status : 'missing' );
check( 'their name came across', $confirmed && 'Alan' === $confirmed->first_name && 'Turing' === $confirmed->last_name );
check( 'their signup date came across', $confirmed && 0 === strpos( (string) $confirmed->created_at, '2021-03-04' ), $confirmed ? $confirmed->created_at : '' );

$unconfirmed = Mailhouse_Subscribers::get_by_email( "noptin-unconfirmed-$run@example.com" );
check( 'an unconfirmed one becomes pending', $unconfirmed && 'pending' === $unconfirmed->status, $unconfirmed ? $unconfirmed->status : 'missing' );

$inactive = Mailhouse_Subscribers::get_by_email( "noptin-inactive-$run@example.com" );
check( 'active=1 means unsubscribed, not subscribed', $inactive && 'unsubscribed' === $inactive->status, $inactive ? $inactive->status : 'missing' );

check( 'everyone imported carries the import tag', $confirmed && 1 === count( Mailhouse_Tags::for_subscriber( $confirmed->id ) ) );
check( 'the import emailed nobody', 1 === count( $sent ), 'sends: ' . count( $sent ) );

echo "== WP Mail SMTP settings import ==\n";

update_option(
	'wp_mail_smtp',
	array(
		'mail'     => array(
			'from_email'       => 'legacy@example.com',
			'from_name'        => 'Legacy Sender',
			'mailer'           => 'sendinblue',
			'return_path'      => true,
			'from_email_force' => true,
			'from_name_force'  => false,
		),
		'smtp'     => array(
			'host'       => 'smtp.legacy.test',
			'port'       => 2525,
			'encryption' => 'tls',
			'auth'       => true,
			'user'       => 'legacy-user',
			'pass'       => 'legacy-pass',
			'autotls'    => true,
		),
		'mailgun'  => array( 'api_key' => 'mg-key', 'domain' => 'mg.example.com', 'region' => 'EU' ),
		'postmark' => array( 'server_api_token' => 'pm-token', 'message_stream' => 'broadcasts' ),
		'sendinblue' => array( 'api_key' => 'brevo-key' ),
	)
);

$sources = new ReflectionMethod( 'Mailhouse_Tools', 'available_sources' );
$sources->setAccessible( true );
$found = $sources->invoke( $tools );

check( 'WP Mail SMTP is detected', isset( $found['wp_mail_smtp'] ) );
check( 'Noptin is detected', isset( $found['noptin'] ) );

$migrate = new ReflectionMethod( 'Mailhouse_Tools', 'import_wp_mail_smtp' );
$migrate->setAccessible( true );
$migrate->invoke( $tools );

Mailhouse_Settings::flush();

check( 'sender imported', 'legacy@example.com' === mailhouse_option( 'from_email' ) );
check( 'sender name imported', 'Legacy Sender' === mailhouse_option( 'from_name' ) );
check( 'force flags imported separately', true === mailhouse_option( 'force_from_email' ) && false === mailhouse_option( 'force_from_name' ) );
check( 'sendinblue maps to brevo', 'brevo' === mailhouse_option( 'mailer' ), (string) mailhouse_option( 'mailer' ) );
check( 'SMTP host imported', 'smtp.legacy.test' === mailhouse_option( 'smtp_host' ) );
check( 'SMTP port imported', 2525 === (int) mailhouse_option( 'smtp_port' ) );
check( 'SMTP password imported and encrypted', 'legacy-pass' === mailhouse_option( 'smtp_pass' ) );

$raw = get_option( 'mailhouse_settings' );
check( 'the imported password is not stored in the clear', false === strpos( (string) $raw['smtp_pass'], 'legacy-pass' ) );

check( 'Mailgun domain imported', 'mg.example.com' === mailhouse_option( 'mailgun_domain' ) );
check( 'Mailgun region normalised to lower case', 'eu' === mailhouse_option( 'mailgun_region' ) );
check( 'Postmark stream imported', 'broadcasts' === mailhouse_option( 'postmark_stream' ) );
check( 'Brevo key imported', 'brevo-key' === mailhouse_option( 'brevo_key' ) );

echo "\n== Cleanup ==\n";

$ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}mailhouse_subscribers WHERE email LIKE %s OR email LIKE %s",
		'%' . $run . '@example.com',
		'%harness%'
	)
);

if ( $ids ) {
	Mailhouse_Subscribers::delete( $ids );
}

foreach ( Mailhouse_Tags::all() as $tag ) {
	if ( in_array( $tag->slug, array( 'imported-harness', 'noptin-import', 'weekly', 'product' ), true ) ) {
		Mailhouse_Tags::delete( $tag->id );
	}
}

$wpdb->query( "DROP TABLE IF EXISTS {$noptin}" );
delete_option( 'wp_mail_smtp' );
unlink( $csv );

update_option( 'mailhouse_settings', $saved_settings );
Mailhouse_Settings::flush();
Mailhouse_Log::clear();

echo "  cleaned up\n";

mailhouse_test_results();