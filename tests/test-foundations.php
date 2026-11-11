<?php
/**
 * The 1.6.0 foundations: schema upgrades that do not need wp-admin, change
 * hooks on every subscriber and tag write, a row revision, consistent status
 * transitions, an atomic queue claim, and a delivery result richer than a
 * boolean.
 *
 * Usage: php tests/test-foundations.php
 *
 * Set MAILHOUSE_CLAIM_ROUNDS to repeat the two-process claim race more than
 * the default five times.
 *
 * @package Mailhouse
 */

require __DIR__ . '/bootstrap.php';

global $wpdb;

$saved_settings = get_option( 'mailhouse_settings' );

// Put back even if this file dies half-way: a suite that leaves the site's
// settings changed makes the next suite fail for no reason of its own.
register_shutdown_function(
	static function () use ( $saved_settings ) {
		update_option( 'mailhouse_settings', $saved_settings );
		delete_transient( 'mailhouse_queue_lock' );
	}
);
$cleanup        = array(
	'subscribers' => array(),
	'tags'        => array(),
	'posts'       => array(),
);

/**
 * A unique address for this run.
 *
 * @param string $label Short label.
 * @return string
 */
function mhf_address( $label ) {
	return strtolower( 'found-' . $label . '-' . wp_generate_password( 6, false ) . '@example.com' );
}

/**
 * Whether a table has a column.
 *
 * @param string $table  Short table name.
 * @param string $column Column name.
 * @return bool
 */
function mhf_has_column( $table, $column ) {
	global $wpdb;

	$name = Mailhouse_Install::table( $table );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$name} LIKE %s", $column ) );
}

/**
 * A subscriber's current revision.
 *
 * @param int $id Subscriber id.
 * @return int
 */
function mhf_revision( $id ) {
	return (int) Mailhouse_Subscribers::get( $id )->revision;
}

// Every hook under test, recorded as it fires.
$fired = array();

foreach (
	array(
		'mailhouse_subscriber_added'        => 1,
		'mailhouse_subscriber_updated'      => 3,
		'mailhouse_subscriber_confirmed'    => 1,
		'mailhouse_subscriber_unsubscribed' => 2,
		'mailhouse_subscriber_bounced'      => 2,
		'mailhouse_subscriber_resubscribed' => 2,
		'mailhouse_subscribers_deleting'    => 1,
		'mailhouse_subscribers_deleted'     => 1,
		'mailhouse_subscriber_tags_changed' => 3,
		'mailhouse_tag_saved'               => 1,
		'mailhouse_tag_deleted'             => 2,
	) as $hook => $args
) {
	add_action(
		$hook,
		static function () use ( &$fired, $hook ) {
			$fired[] = array(
				'hook' => $hook,
				'args' => func_get_args(),
			);
		},
		10,
		$args
	);
}

/**
 * The calls recorded for one hook since the last reset.
 *
 * @param string $hook Hook name.
 * @return array[]
 */
function mhf_calls( $hook ) {
	global $fired;

	return array_values(
		array_filter(
			$fired,
			static function ( $call ) use ( $hook ) {
				return $hook === $call['hook'];
			}
		)
	);
}

/**
 * Forget recorded calls.
 *
 * @return void
 */
function mhf_reset() {
	global $fired;

	$fired = array();
}

// Nothing in this file should reach a real mailbox. Sends that are meant to
// exercise a transport switch this off again around themselves.
$mhf_block_mail = static function () {
	return true;
};
add_filter( 'pre_wp_mail', $mhf_block_mail, 1 );

Mailhouse_Settings::set(
	array(
		'double_optin'    => true,
		'welcome_enabled' => false,
		'default_tags'    => array(),
		'log_enabled'     => true,
	)
);

echo "== Schema ==\n";

check( 'schema version is 1.2.0', '1.2.0' === Mailhouse_Install::DB_VERSION && '1.2.0' === get_option( 'mailhouse_db_version' ), (string) get_option( 'mailhouse_db_version' ) );
check( 'subscribers have a revision', mhf_has_column( 'subscribers', 'revision' ) );

foreach ( array( 'claim_token', 'claimed_at', 'provider_message_id', 'remote_job_id', 'reported' ) as $column ) {
	check( "queue has $column", mhf_has_column( 'queue', $column ) );
}

echo "\n== The schema upgrades without wp-admin ==\n";

check( 'the check is hooked to init, not only admin_init', false !== has_action( 'init', array( 'Mailhouse_Install', 'maybe_install' ) ) );
check( 'and runs before the webhook and tracking handlers', (int) has_action( 'init', array( 'Mailhouse_Install', 'maybe_install' ) ) < 1 );

// Put the site back to how an upload-over-the-top upgrade leaves it: new
// code, old schema, nobody logged in.
$subscribers_table = Mailhouse_Install::table( 'subscribers' );
$wpdb->query( "ALTER TABLE {$subscribers_table} DROP COLUMN revision" ); // phpcs:ignore
update_option( 'mailhouse_db_version', '1.1.0' );
delete_option( 'mailhouse_install_lock' );

check( 'precondition: the column is gone', ! mhf_has_column( 'subscribers', 'revision' ) );

// Somebody else is half-way through it.
add_option( 'mailhouse_install_lock', time(), '', false );
Mailhouse_Install::maybe_install();
check( 'a second request stands back while one is upgrading', ! mhf_has_column( 'subscribers', 'revision' ) );

// That somebody died ten minutes ago.
update_option( 'mailhouse_install_lock', time() - 600, false );
Mailhouse_Install::maybe_install();
check( 'a stale lock does not block the upgrade for ever', mhf_has_column( 'subscribers', 'revision' ) );
check( 'the version is recorded', '1.2.0' === get_option( 'mailhouse_db_version' ) );
check( 'the lock is released', false === get_option( 'mailhouse_install_lock' ) );

echo "\n== Changes to a subscriber are observable ==\n";

mhf_reset();

$email = mhf_address( 'ada' );
$added = Mailhouse_Subscribers::add(
	array(
		'email'       => $email,
		'first_name'  => 'Ada',
		'status'      => 'pending',
		'send_emails' => false,
	)
);

$id                       = (int) $added['id'];
$cleanup['subscribers'][] = $id;

check( 'a new subscriber fires added', 1 === count( mhf_calls( 'mailhouse_subscriber_added' ) ) );
check( 'and not updated', 0 === count( mhf_calls( 'mailhouse_subscriber_updated' ) ) );
check( 'a new row starts at revision 0', 0 === mhf_revision( $id ) );

mhf_reset();
Mailhouse_Subscribers::update( $id, array( 'first_name' => 'Augusta' ) );

$calls = mhf_calls( 'mailhouse_subscriber_updated' );
check( 'a name change fires updated once', 1 === count( $calls ) );
check( 'with the row after the change', $calls && 'Augusta' === $calls[0]['args'][0]->first_name );
check( 'the row before it', $calls && 'Ada' === $calls[0]['args'][1]->first_name );
check( 'and exactly what changed', $calls && array( 'first_name' ) === $calls[0]['args'][2], $calls ? wp_json_encode( $calls[0]['args'][2] ) : '' );
check( 'the revision moves', 1 === mhf_revision( $id ) );

mhf_reset();
Mailhouse_Subscribers::update( $id, array( 'first_name' => 'Augusta' ) );
check( 'writing the same value is not a change', 0 === count( mhf_calls( 'mailhouse_subscriber_updated' ) ) && 1 === mhf_revision( $id ) );

mhf_reset();
Mailhouse_Subscribers::update( $id, array( 'sent' => 5, 'opens' => 2 ) );
check( 'counters do not fire the hook', 0 === count( mhf_calls( 'mailhouse_subscriber_updated' ) ) );
check( 'or move the revision', 1 === mhf_revision( $id ) );
check( 'but are still written', 5 === (int) Mailhouse_Subscribers::get( $id )->sent );

mhf_reset();
Mailhouse_Subscribers::update( $id, array( 'first_name' => 'Ada', 'sent' => 6 ) );
$calls = mhf_calls( 'mailhouse_subscriber_updated' );
check( 'a mixed write reports only the synchronised column', $calls && array( 'first_name' ) === $calls[0]['args'][2] );

mhf_reset();
Mailhouse_Subscribers::confirm( Mailhouse_Subscribers::get( $id ) );
$calls = mhf_calls( 'mailhouse_subscriber_updated' );
check( 'confirming fires updated with the status', $calls && in_array( 'status', $calls[0]['args'][2], true ) && in_array( 'confirmed_at', $calls[0]['args'][2], true ) );
check( 'and still fires confirmed', 1 === count( mhf_calls( 'mailhouse_subscriber_confirmed' ) ) );
check( 'the confirmation key is not reported as a change', $calls && ! in_array( 'confirm_key', $calls[0]['args'][2], true ) );

mhf_reset();
$again = Mailhouse_Subscribers::add(
	array(
		'email'           => $email,
		'first_name'      => 'Countess',
		'overwrite_names' => true,
		'send_emails'     => false,
	)
);
$calls = mhf_calls( 'mailhouse_subscriber_updated' );
check( 'adding an address already on the list reports what it changed', 'already_subscribed' === $again['action'] && $calls && array( 'first_name' ) === $calls[0]['args'][2] );
check( 'without firing added again', 0 === count( mhf_calls( 'mailhouse_subscriber_added' ) ) );

mhf_reset();
$revision = mhf_revision( $id );
Mailhouse_Subscribers::add( array( 'email' => $email, 'send_emails' => false ) );
check( 'adding it again with nothing new changes nothing', 0 === count( mhf_calls( 'mailhouse_subscriber_updated' ) ) && $revision === mhf_revision( $id ) );

mhf_reset();
Mailhouse_Subscribers::unsubscribe( Mailhouse_Subscribers::get( $id ), 'link' );
$calls = mhf_calls( 'mailhouse_subscriber_updated' );
check( 'unsubscribing fires updated', $calls && in_array( 'status', $calls[0]['args'][2], true ) && in_array( 'unsubscribed_at', $calls[0]['args'][2], true ) );
check( 'before the unsubscribed hook', 'mailhouse_subscriber_updated' === $fired[0]['hook'] && 'mailhouse_subscriber_unsubscribed' === $fired[ count( $fired ) - 1 ]['hook'] );

echo "\n== One way to change a status ==\n";

// What the admin edit form now does, instead of writing the column.
$email2 = mhf_address( 'grace' );
$row    = Mailhouse_Subscribers::add( array( 'email' => $email2, 'status' => 'subscribed', 'send_emails' => false ) );
$id2    = (int) $row['id'];
$cleanup['subscribers'][] = $id2;

$campaign = wp_insert_post(
	array(
		'post_type'    => Mailhouse_Emails::POST_TYPE,
		'post_status'  => 'publish',
		'post_title'   => 'Foundations campaign',
		'post_content' => '<p>Hello {{first_name|there}}</p>',
	)
);
$cleanup['posts'][] = $campaign;
update_post_meta( $campaign, '_mailhouse_type', 'newsletter' );
update_post_meta( $campaign, '_mailhouse_subject', 'Foundations subject' );

Mailhouse_Queue::enqueue_one( $campaign, $id2, $email2, time() + DAY_IN_SECONDS );

mhf_reset();
$changed = Mailhouse_Subscribers::set_status( Mailhouse_Subscribers::get( $id2 ), 'unsubscribed', 'admin' );
$after   = Mailhouse_Subscribers::get( $id2 );
$calls   = mhf_calls( 'mailhouse_subscriber_unsubscribed' );

check( 'an admin unsubscribe goes through', true === $changed && 'unsubscribed' === $after->status );
check( 'records when', ! empty( $after->unsubscribed_at ) );
check( 'fires the unsubscribed hook with its source', $calls && 'admin' === $calls[0]['args'][1] );
check( 'and drops what was queued for them', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Mailhouse_Queue::table() . ' WHERE subscriber_id = %d', $id2 ) ) ); // phpcs:ignore

mhf_reset();
Mailhouse_Subscribers::set_status( Mailhouse_Subscribers::get( $id2 ), 'pending', 'admin' );
$after = Mailhouse_Subscribers::get( $id2 );
check( 'an admin can set pending, and the row can then confirm', 'pending' === $after->status && 32 === strlen( (string) $after->confirm_key ) );

mhf_reset();
Mailhouse_Subscribers::set_status( Mailhouse_Subscribers::get( $id2 ), 'subscribed', 'admin' );
$after = Mailhouse_Subscribers::get( $id2 );
check( 'an admin can set subscribed', 'subscribed' === $after->status && ! empty( $after->confirmed_at ) );
check( 'which spends the confirmation key', null === $after->confirm_key );
check( 'does not fire confirmed, so nobody is sent a welcome', 0 === count( mhf_calls( 'mailhouse_subscriber_confirmed' ) ) );
check( 'but is still observable', 1 === count( mhf_calls( 'mailhouse_subscriber_updated' ) ) );

mhf_reset();
Mailhouse_Subscribers::set_status( Mailhouse_Subscribers::get( $id2 ), 'bounced', 'admin' );
check( 'an admin can retire an address', 'bounced' === Mailhouse_Subscribers::get( $id2 )->status && 1 === count( mhf_calls( 'mailhouse_subscriber_bounced' ) ) );

check( 'setting the status it already has does nothing', false === Mailhouse_Subscribers::set_status( Mailhouse_Subscribers::get( $id2 ), 'bounced', 'admin' ) );
check( 'an unknown status is refused', false === Mailhouse_Subscribers::set_status( Mailhouse_Subscribers::get( $id2 ), 'banana', 'admin' ) );

// The preferences page: only the owner of the address gets here.
Mailhouse_Subscribers::set_status( Mailhouse_Subscribers::get( $id2 ), 'unsubscribed', 'admin' );
Mailhouse_Subscribers::update( $id2, array( 'confirm_key' => mailhouse_token( 16 ) ) );

mhf_reset();
$back  = Mailhouse_Subscribers::resubscribe( Mailhouse_Subscribers::get( $id2 ), 'owner' );
$after = Mailhouse_Subscribers::get( $id2 );
$calls = mhf_calls( 'mailhouse_subscriber_resubscribed' );

check( 'the owner can resubscribe', true === $back && 'subscribed' === $after->status );
check( 'which spends the confirmation key', null === $after->confirm_key );
check( 'fires resubscribed, saying who', $calls && 'owner' === $calls[0]['args'][1] );
check( 'and does not fire confirmed', 0 === count( mhf_calls( 'mailhouse_subscriber_confirmed' ) ) );
check( 'resubscribing somebody already subscribed does nothing', false === Mailhouse_Subscribers::resubscribe( $after, 'owner' ) );

Mailhouse_Subscribers::set_status( Mailhouse_Subscribers::get( $id2 ), 'bounced', 'admin' );
check( 'a bounced address cannot resubscribe itself', false === Mailhouse_Subscribers::resubscribe( Mailhouse_Subscribers::get( $id2 ), 'owner' ) );

echo "\n== Tags are observable too ==\n";

mhf_reset();
$tag_a = Mailhouse_Tags::create( 'Foundations A ' . wp_generate_password( 4, false ) );
$tag_b = Mailhouse_Tags::create( 'Foundations B ' . wp_generate_password( 4, false ) );
$tag_c = Mailhouse_Tags::create( 'Foundations C ' . wp_generate_password( 4, false ) );
$cleanup['tags'] = array( $tag_a, $tag_b, $tag_c );

$calls = mhf_calls( 'mailhouse_tag_saved' );
check( 'creating a tag fires tag_saved', 3 === count( $calls ) && (int) $calls[0]['args'][0]->id === $tag_a );

mhf_reset();
Mailhouse_Tags::create( Mailhouse_Tags::get( $tag_a )->name );
check( 'creating one that exists does not', 0 === count( mhf_calls( 'mailhouse_tag_saved' ) ) );

mhf_reset();
Mailhouse_Tags::update( $tag_c, 'Foundations renamed ' . wp_generate_password( 4, false ) );
check( 'renaming fires tag_saved', 1 === count( mhf_calls( 'mailhouse_tag_saved' ) ) );

mhf_reset();
$revision = mhf_revision( $id );
Mailhouse_Tags::attach( $id, array( $tag_a, $tag_b ) );
$calls = mhf_calls( 'mailhouse_subscriber_tags_changed' );
check( 'attaching fires tags_changed once', 1 === count( $calls ) );
check( 'with what was added', $calls && array( $tag_a, $tag_b ) === $calls[0]['args'][1] && array() === $calls[0]['args'][2] );
check( 'and moves the revision once', $revision + 1 === mhf_revision( $id ) );

mhf_reset();
Mailhouse_Tags::attach( $id, array( $tag_a ) );
check( 'attaching a tag they already have is not a change', 0 === count( mhf_calls( 'mailhouse_subscriber_tags_changed' ) ) && $revision + 1 === mhf_revision( $id ) );

$pivot    = Mailhouse_Tags::pivot();
$added_at = $wpdb->get_var( $wpdb->prepare( "SELECT added_at FROM {$pivot} WHERE subscriber_id = %d AND tag_id = %d", $id, $tag_a ) ); // phpcs:ignore
$wpdb->query( $wpdb->prepare( "UPDATE {$pivot} SET added_at = %s WHERE subscriber_id = %d AND tag_id = %d", '2020-01-01 00:00:00', $id, $tag_a ) ); // phpcs:ignore

mhf_reset();
Mailhouse_Tags::set( $id, array( $tag_a, $tag_c ) );
$calls = mhf_calls( 'mailhouse_subscriber_tags_changed' );
check( 'set() fires once with the true difference', 1 === count( $calls ) && array( $tag_c ) === $calls[0]['args'][1] && array( $tag_b ) === $calls[0]['args'][2], $calls ? wp_json_encode( array( $calls[0]['args'][1], $calls[0]['args'][2] ) ) : 'no call' );
check( 'and leaves alone the tag that did not change', '2020-01-01 00:00:00' === $wpdb->get_var( $wpdb->prepare( "SELECT added_at FROM {$pivot} WHERE subscriber_id = %d AND tag_id = %d", $id, $tag_a ) ) ); // phpcs:ignore

mhf_reset();
Mailhouse_Tags::set( $id, array( $tag_c, $tag_a ) );
check( 'set() to what they already have is not a change', 0 === count( mhf_calls( 'mailhouse_subscriber_tags_changed' ) ) );

mhf_reset();
Mailhouse_Tags::detach( $id, array( $tag_c ) );
$calls = mhf_calls( 'mailhouse_subscriber_tags_changed' );
check( 'detaching fires with what was removed', 1 === count( $calls ) && array( $tag_c ) === $calls[0]['args'][2] );

mhf_reset();
$revision = mhf_revision( $id );
$doomed   = Mailhouse_Tags::get( $tag_a );
Mailhouse_Tags::delete( $tag_a );
$calls = mhf_calls( 'mailhouse_tag_deleted' );
check( 'deleting a tag fires tag_deleted with the tag', 1 === count( $calls ) && $doomed->slug === $calls[0]['args'][0]->slug );
check( 'and everybody who had it', $calls && array( $id ) === $calls[0]['args'][1] );
check( 'whose revision moves, because their tags did', $revision + 1 === mhf_revision( $id ) );

echo "\n== A deletion says who was deleted ==\n";

$email3 = mhf_address( 'gone' );
$row    = Mailhouse_Subscribers::add( array( 'email' => $email3, 'status' => 'subscribed', 'send_emails' => false ) );

mhf_reset();
Mailhouse_Subscribers::delete( array( (int) $row['id'] ) );

$calls = mhf_calls( 'mailhouse_subscribers_deleting' );
check( 'deleting fires before the rows go, with the rows keyed by id', 1 === count( $calls ) && is_array( $calls[0]['args'][0] ) && $email3 === $calls[0]['args'][0][ (int) $row['id'] ]->email );
check( 'and the original hook still fires after', 'mailhouse_subscribers_deleted' === $fired[ count( $fired ) - 1 ]['hook'] );
check( 'in that order', 'mailhouse_subscribers_deleting' === $fired[0]['hook'] );

echo "\n== Built-in merge tags cannot be shadowed ==\n";

Mailhouse_Subscribers::set_status( Mailhouse_Subscribers::get( $id ), 'subscribed', 'admin' );
Mailhouse_Subscribers::update(
	$id,
	array(
		'fields' => wp_json_encode(
			array(
				'unsubscribe_url' => 'https://evil.example/',
				'email'           => 'someone-else@example.com',
				'company'         => 'Analytical Engines',
			)
		),
	)
);

$values = Mailhouse_Merge_Tags::values( array( 'subscriber' => Mailhouse_Subscribers::get( $id ) ) );
check( 'a field called unsubscribe_url does not replace the link', false === strpos( $values['unsubscribe_url'], 'evil.example' ) && false !== strpos( $values['unsubscribe_url'], 'unsubscribe' ) );
check( 'a field called email does not replace the address', $email === $values['email'] );
check( 'an ordinary custom field still works', 'Analytical Engines' === $values['company'] );

echo "\n== Old links keep working ==\n";

$subscriber = Mailhouse_Subscribers::get( $id );
$expected   = get_option( 'permalink_structure' )
	? home_url( '/mailhouse/unsubscribe/' . $subscriber->manage_key . '/' )
	: add_query_arg( array( 'mailhouse_action' => 'unsubscribe', 'mailhouse_key' => $subscriber->manage_key ), home_url( '/' ) );

check( 'the unsubscribe link has the shape 1.4.0 sent', Mailhouse_Optin::unsubscribe_url( $subscriber ) === $expected );

// The signature 1.4.0 put in every tracked link, recomputed by hand.
$signed = substr( hash_hmac( 'sha256', 'click|77|https://example.org/a', wp_salt( 'mailhouse_track' ) ), 0, 20 );
check( 'a click link signed by 1.4.0 still verifies', false !== strpos( Mailhouse_Tracking::url( 'click', 77, 'https://example.org/a' ), 'k=' . $signed ) );

echo "\n== The queue claims rows atomically ==\n";

$queue = Mailhouse_Queue::table();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$queue} WHERE campaign_id = %d", $campaign ) ); // phpcs:ignore

$people = array();

for ( $i = 0; $i < 12; $i++ ) {
	$row      = Mailhouse_Subscribers::add( array( 'email' => mhf_address( 'q' . $i ), 'status' => 'subscribed', 'send_emails' => false ) );
	$people[] = (int) $row['id'];
	Mailhouse_Queue::enqueue_one( $campaign, (int) $row['id'], Mailhouse_Subscribers::get( (int) $row['id'] )->email, time() - 60 );
}

$cleanup['subscribers'] = array_merge( $cleanup['subscribers'], $people );

// Everything else due on this site is held back so the counts below are ours.
$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET status = 'mhf_held' WHERE status = 'pending' AND campaign_id <> %d", $campaign ) ); // phpcs:ignore

$first  = Mailhouse_Queue::claim( 5 );
$second = Mailhouse_Queue::claim( 5 );

check( 'a claim takes what it asked for', 5 === count( $first ) );
check( 'marks the rows as being sent', 5 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE claim_token = %s AND status = 'sending'", $first[0]->claim_token ) ) ); // phpcs:ignore
check( 'and stamps when', ! empty( $first[0]->claimed_at ) );
check( 'a second claim gets different rows', 5 === count( $second ) && array() === array_intersect( wp_list_pluck( $first, 'id' ), wp_list_pluck( $second, 'id' ) ) );
check( 'with its own token', $first[0]->claim_token !== $second[0]->claim_token );
check( 'a third gets what is left', 2 === count( Mailhouse_Queue::claim( 5 ) ) );
check( 'and a fourth gets nothing', array() === Mailhouse_Queue::claim( 5 ) );

$stats = Mailhouse_Queue::campaign_stats( $campaign );
check( 'rows being sent still count as unfinished', 12 === $stats['pending'], wp_json_encode( $stats ) );
check( 'so the site-wide count does too', Mailhouse_Queue::pending_count() >= 12 );

Mailhouse_Queue::release( $first[0]->claim_token );
check( 'releasing a claim returns its rows', 5 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE campaign_id = %d AND status = 'pending' AND claim_token IS NULL", $campaign ) ) ); // phpcs:ignore

// A process that died mid-batch leaves its rows claimed.
$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET claimed_at = %s WHERE claim_token = %s", gmdate( 'Y-m-d H:i:s', time() - 3600 ), $second[0]->claim_token ) ); // phpcs:ignore
$reaped = Mailhouse_Queue::reap_stale();
check( 'a claim abandoned long ago is recovered', 5 === $reaped, (string) $reaped );
check( 'a recent one is left alone', 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE campaign_id = %d AND status = 'sending'", $campaign ) ) ); // phpcs:ignore

$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET status = 'pending', claim_token = NULL, claimed_at = NULL WHERE campaign_id = %d", $campaign ) ); // phpcs:ignore

echo "\n== Two processes claiming at once ==\n";

$rounds = max( 1, (int) ( getenv( 'MAILHOUSE_CLAIM_ROUNDS' ) ? getenv( 'MAILHOUSE_CLAIM_ROUNDS' ) : 5 ) );
$worker = __DIR__ . '/helpers/claim-worker.php';
$ids    = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$queue} WHERE campaign_id = %d", $campaign ) ) ); // phpcs:ignore

// Fan the twelve rows out to five hundred, so there is something to race for.
$values = array();

for ( $i = 0; $i < 488; $i++ ) {
	$values[] = $wpdb->prepare( '(%d, %d, %s, %s, %s)', $campaign, 900000000 + $i, 'race' . $i . '@example.com', 'pending', gmdate( 'Y-m-d H:i:s', time() - 60 ) );
}

$wpdb->query( "INSERT IGNORE INTO {$queue} (campaign_id, subscriber_id, email, status, scheduled_at) VALUES " . implode( ',', $values ) ); // phpcs:ignore

$doubles = 0;
$missed  = 0;

for ( $round = 0; $round < $rounds; $round++ ) {
	$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET status = 'pending', claim_token = NULL, claimed_at = NULL WHERE campaign_id = %d", $campaign ) ); // phpcs:ignore

	$start = microtime( true ) + 1.5;
	$procs = array();
	$pipes = array();

	for ( $p = 0; $p < 2; $p++ ) {
		$procs[ $p ] = proc_open(
			array( PHP_BINARY, $worker, (string) $campaign, sprintf( '%.4f', $start ) ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes[ $p ],
			null,
			null
		);
	}

	$claimed = array();

	for ( $p = 0; $p < 2; $p++ ) {
		$out = stream_get_contents( $pipes[ $p ][1] );
		fclose( $pipes[ $p ][1] );
		fclose( $pipes[ $p ][2] );
		proc_close( $procs[ $p ] );

		foreach ( array_filter( array_map( 'intval', explode( ',', trim( (string) $out ) ) ) ) as $row_id ) {
			$claimed[] = $row_id;
		}
	}

	$doubles += count( $claimed ) - count( array_unique( $claimed ) );
	$missed  += 500 - count( array_unique( $claimed ) );
}

check( "no row was claimed twice in $rounds rounds of 500", 0 === $doubles, "$doubles doubles" );
check( 'and none was left behind', 0 === $missed, "$missed missed" );

$wpdb->query( $wpdb->prepare( "DELETE FROM {$queue} WHERE campaign_id = %d AND subscriber_id >= %d", $campaign, 900000000 ) ); // phpcs:ignore
$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET status = 'pending', claim_token = NULL, claimed_at = NULL WHERE campaign_id = %d", $campaign ) ); // phpcs:ignore

echo "\n== A send reports more than true or false ==\n";

// From here on, mail goes to a transport: the real provider classes, with
// the HTTP layer answered by this function.
remove_filter( 'pre_wp_mail', $mhf_block_mail, 1 );

$http        = null;
$http_calls  = array();
$http_filter = static function ( $pre, $args, $url ) use ( &$http, &$http_calls ) {
	$http_calls[] = $url;

	return is_callable( $http ) ? $http( $url, $args ) : new WP_Error( 'http_request_failed', 'test harness: no response configured' );
};
add_filter( 'pre_http_request', $http_filter, 10, 3 );

/**
 * A canned HTTP response.
 *
 * @param int    $code    Status code.
 * @param mixed  $body    Body; arrays are encoded as JSON.
 * @param array  $headers Response headers.
 * @return array
 */
function mhf_reply( $code, $body = '', $headers = array() ) {
	return array(
		'headers'  => $headers,
		'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

Mailhouse_Settings::set(
	array(
		'mailer'           => 'sendgrid',
		'campaign_mailer'  => '',
		'fallback_mailer'  => '',
		'from_email'       => 'sender@example.com',
		'sendgrid_key'     => 'SG.test',
		'mailgun_key'      => 'key-test',
		'mailgun_domain'   => 'mg.example.com',
		'brevo_key'        => 'xkeysib-test',
		'postmark_token'   => 'pm-test',
		'resend_key'       => 're_test',
		'ses_access_key'   => 'AKIATEST',
		'ses_secret_key'   => 'secret',
		'mailjet_key'      => 'mj-key',
		'mailjet_secret'   => 'mj-secret',
		'sparkpost_key'    => 'sp-test',
		'smtp2go_key'      => 'api-test',
		'elasticemail_key' => 'ee-test',
		'mailersend_key'   => 'ms-test',
		'zeptomail_token'  => 'zm-test',
		'mandrill_key'     => 'md-test',
	)
);
Mailhouse_Settings::flush();

$send = static function ( $args = array() ) {
	return Mailhouse_Mailer::send( 'reader@example.com', 'Result test', '<p>Body</p>', $args );
};

$http = static function () {
	return mhf_reply( 202, '', array( 'x-message-id' => 'sg-abc123' ) );
};
$sent   = $send();
$result = Mailhouse_Mailer::last_result();
check( 'an accepted message still returns true', true === $sent );
check( 'and says accepted', 'accepted' === $result['outcome'], wp_json_encode( $result ) );
check( "with the provider's id for it", 'sg-abc123' === $result['message_id'], (string) $result['message_id'] );

$cases = array(
	'a 400 is a refusal that will not change'      => array( mhf_reply( 400, array( 'errors' => array( array( 'message' => 'bad request' ) ) ) ), 'rejected', true ),
	'a 401 likewise'                               => array( mhf_reply( 401, array( 'errors' => array( array( 'message' => 'no' ) ) ) ), 'rejected', true ),
	'a 429 is a refusal worth retrying'            => array( mhf_reply( 429, array( 'errors' => array( array( 'message' => 'slow down' ) ) ) ), 'rejected', false ),
	'a 503 likewise'                               => array( mhf_reply( 503, 'unavailable' ), 'rejected', false ),
	'a 500 does not say whether it took the message' => array( mhf_reply( 500, 'oops' ), 'ambiguous', false ),
	'a 502 neither'                                => array( mhf_reply( 502, 'bad gateway' ), 'ambiguous', false ),
	'a host that does not resolve never got it'    => array( new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: api.sendgrid.com' ), 'rejected', false ),
	'nor one that refused the connection'          => array( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to api.sendgrid.com port 443' ), 'rejected', false ),
	'nor one the connection to which timed out'    => array( new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out after 20001 milliseconds' ), 'rejected', false ),
	'a timeout after the request went is unknown'  => array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received' ), 'ambiguous', false ),
	'so is a connection reset mid-reply'           => array( new WP_Error( 'http_request_failed', 'cURL error 56: Recv failure: Connection reset by peer' ), 'ambiguous', false ),
	'and an error nobody recognises'               => array( new WP_Error( 'http_request_failed', 'something odd happened' ), 'ambiguous', false ),
);

foreach ( $cases as $label => $case ) {
	$http = static function () use ( $case ) {
		return $case[0];
	};

	$sent   = $send();
	$result = Mailhouse_Mailer::last_result();

	check( $label, false === $sent && $case[1] === $result['outcome'] && $case[2] === $result['permanent'], wp_json_encode( $result ) );
}

check( 'a failure carries the reason', '' !== (string) $result['error'] );

// A message that never reaches a transport at all.
$sent   = Mailhouse_Mailer::send( 'not an address', 'Result test', '<p>Body</p>' );
$result = Mailhouse_Mailer::last_result();
check( 'a message that cannot be assembled was never sent', false === $sent && 'rejected' === $result['outcome'] && true === $result['permanent'], wp_json_encode( $result ) );

echo "\n== Every API transport hands back its message id ==\n";

$providers = array(
	'sendgrid'     => array( mhf_reply( 202, '', array( 'x-message-id' => 'id-sendgrid' ) ), 'id-sendgrid' ),
	'mailgun'      => array( mhf_reply( 200, array( 'id' => '<id-mailgun@mg.example.com>', 'message' => 'Queued. Thank you.' ) ), 'id-mailgun@mg.example.com' ),
	'brevo'        => array( mhf_reply( 201, array( 'messageId' => '<id-brevo@smtp-relay.mailin.fr>' ) ), 'id-brevo@smtp-relay.mailin.fr' ),
	'postmark'     => array( mhf_reply( 200, array( 'ErrorCode' => 0, 'Message' => 'OK', 'MessageID' => 'id-postmark' ) ), 'id-postmark' ),
	'resend'       => array( mhf_reply( 200, array( 'id' => 'id-resend' ) ), 'id-resend' ),
	'ses'          => array( mhf_reply( 200, array( 'MessageId' => 'id-ses' ) ), 'id-ses' ),
	'mailjet'      => array( mhf_reply( 200, array( 'Messages' => array( array( 'Status' => 'success', 'To' => array( array( 'Email' => 'reader@example.com', 'MessageUUID' => 'id-mailjet', 'MessageID' => 123 ) ) ) ) ) ), 'id-mailjet' ),
	'sparkpost'    => array( mhf_reply( 200, array( 'results' => array( 'total_accepted_recipients' => 1, 'id' => 'id-sparkpost' ) ) ), 'id-sparkpost' ),
	'smtp2go'      => array( mhf_reply( 200, array( 'data' => array( 'succeeded' => 1, 'failed' => 0, 'email_id' => 'id-smtp2go' ) ) ), 'id-smtp2go' ),
	'elasticemail' => array( mhf_reply( 200, array( 'TransactionID' => 'tx-elastic', 'MessageID' => 'id-elastic' ) ), 'id-elastic' ),
	'mailersend'   => array( mhf_reply( 202, '', array( 'x-message-id' => 'id-mailersend' ) ), 'id-mailersend' ),
	'zeptomail'    => array( mhf_reply( 201, array( 'data' => array( array( 'code' => 'EM_104', 'message' => 'Email request received' ) ), 'message' => 'OK', 'request_id' => 'id-zepto' ) ), 'id-zepto' ),
	'mandrill'     => array( mhf_reply( 200, array( array( 'email' => 'reader@example.com', 'status' => 'sent', '_id' => 'id-mandrill' ) ) ), 'id-mandrill' ),
);

foreach ( $providers as $provider_id => $case ) {
	Mailhouse_Settings::set( array( 'mailer' => $provider_id ) );
	Mailhouse_Settings::flush();

	$http = static function () use ( $case ) {
		return $case[0];
	};

	$sent   = $send();
	$result = Mailhouse_Mailer::last_result();

	check( "$provider_id", true === $sent && 'accepted' === $result['outcome'] && $case[1] === $result['message_id'], wp_json_encode( $result ) );
}

// A refusal delivered inside a 200.
Mailhouse_Settings::set( array( 'mailer' => 'mandrill' ) );
Mailhouse_Settings::flush();
$http = static function () {
	return mhf_reply( 200, array( array( 'email' => 'reader@example.com', 'status' => 'rejected', 'reject_reason' => 'hard-bounce' ) ) );
};
$sent   = $send();
$result = Mailhouse_Mailer::last_result();
check( 'a refusal inside a 200 is still a refusal', false === $sent && 'rejected' === $result['outcome'] && true === $result['permanent'], wp_json_encode( $result ) );

echo "\n== The backup transport can be kept out of it ==\n";

Mailhouse_Settings::set( array( 'mailer' => 'sendgrid', 'fallback_mailer' => 'postmark' ) );
Mailhouse_Settings::flush();

$http = static function ( $url ) {
	return false !== strpos( $url, 'sendgrid' )
		? mhf_reply( 500, 'oops' )
		: mhf_reply( 200, array( 'ErrorCode' => 0, 'MessageID' => 'id-backup' ) );
};

$http_calls = array();
$sent       = $send();
$result     = Mailhouse_Mailer::last_result();
check( 'by default the backup still takes over, as before', true === $sent && 2 === count( $http_calls ) && 'id-backup' === $result['message_id'], wp_json_encode( array( $http_calls, $result ) ) );

$http_calls = array();
$sent       = $send( array( 'no_fallback' => true ) );
$result     = Mailhouse_Mailer::last_result();
check( 'asked not to, it does not', false === $sent && 1 === count( $http_calls ), wp_json_encode( $http_calls ) );
check( 'and the unclear result stands', 'ambiguous' === $result['outcome'], wp_json_encode( $result ) );

$http_calls = array();
$send();
check( 'the request lasts for one message only', 2 === count( $http_calls ) );

Mailhouse_Settings::set( array( 'fallback_mailer' => '' ) );
Mailhouse_Settings::flush();

echo "\n== SMTP failures are sorted the same way ==\n";

$smtp_cases = array(
	array( 'smtp', 'connect_host', 0, 'rejected', false, 'could not connect' ),
	array( 'smtp', 'smtp_connect_failed', 0, 'rejected', false, 'connect failed' ),
	array( 'smtp', 'authenticate', 535, 'rejected', false, 'could not authenticate' ),
	array( 'smtp', 'from_failed', 550, 'rejected', true, 'sender refused for good' ),
	array( 'smtp', 'recipients_failed', 550, 'rejected', true, 'recipient refused for good' ),
	array( 'smtp', 'recipients_failed', 451, 'rejected', false, 'recipient refused for now' ),
	array( 'smtp', 'data_not_accepted', 554, 'rejected', true, 'body refused with a reply' ),
	array( 'smtp', 'data_not_accepted', 452, 'rejected', false, 'body deferred with a reply' ),
	array( 'smtp', 'data_not_accepted', 0, 'ambiguous', false, 'connection lost after the body went' ),
	array( 'smtp', '', 0, 'ambiguous', false, 'an SMTP error nobody recognises' ),
	array( 'mail', 'instantiate', 0, 'rejected', false, 'mail() refused it' ),
	array( 'mail', '', 0, 'ambiguous', false, 'mail() failed some other way' ),
);

foreach ( $smtp_cases as $case ) {
	$result = Mailhouse_PHPMailer::classify_native( $case[0], $case[1], $case[2] );

	check( $case[5], $case[3] === $result['outcome'] && $case[4] === $result['permanent'], wp_json_encode( $result ) );
}

// And once for real: nothing listens on this port.
Mailhouse_Settings::set(
	array(
		'mailer'       => 'smtp',
		'smtp_host'    => '127.0.0.1',
		'smtp_port'    => 9,
		'smtp_auth'    => false,
		'smtp_timeout' => 5,
	)
);
Mailhouse_Settings::flush();

$sent   = $send();
$result = Mailhouse_Mailer::last_result();
check( 'a real SMTP server that is not there never got the message', false === $sent && 'rejected' === $result['outcome'] && false === $result['permanent'], wp_json_encode( $result ) );

echo "\n== The queue keeps the id, and behaves as it did ==\n";

Mailhouse_Settings::set( array( 'mailer' => 'postmark', 'batch_size' => 50 ) );
Mailhouse_Settings::flush();
delete_transient( 'mailhouse_queue_lock' );

$counter = 0;
$http    = static function () use ( &$counter ) {
	++$counter;

	return mhf_reply( 200, array( 'ErrorCode' => 0, 'MessageID' => 'queued-' . $counter ) );
};

// enqueue_one() queues a single message and leaves the campaign's own status
// alone; a campaign started from the editor is marked as sending.
update_post_meta( $campaign, '_mailhouse_status', 'sending' );

$processed = Mailhouse_Queue::process();
$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$queue} WHERE campaign_id = %d", $campaign ) ); // phpcs:ignore

check( 'the batch went out', 12 === $processed, (string) $processed );
check( 'every row is marked sent', 12 === count( wp_list_filter( $rows, array( 'status' => 'sent' ) ) ) );
check( 'with the id the provider gave', 12 === count( array_filter( wp_list_pluck( $rows, 'provider_message_id' ), static function ( $value ) { return 0 === strpos( (string) $value, 'queued-' ); } ) ) );
check( 'and no claim left on it', 0 === count( array_filter( wp_list_pluck( $rows, 'claim_token' ) ) ) );
check( 'the campaign finished', 'sent' === Mailhouse_Emails::status( $campaign ) );

// A failure is still retried exactly as before.
$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET status = 'pending', attempts = 0, sent_at = NULL WHERE campaign_id = %d LIMIT 1", $campaign ) ); // phpcs:ignore
update_post_meta( $campaign, '_mailhouse_status', 'sending' );
delete_transient( 'mailhouse_queue_lock' );

$http = static function () {
	return mhf_reply( 500, 'oops' );
};

Mailhouse_Queue::process();
$retry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$queue} WHERE campaign_id = %d AND status <> 'sent'", $campaign ) ); // phpcs:ignore
check( 'a failed send goes back to pending for another try, as it always has', $retry && 'pending' === $retry->status && 1 === (int) $retry->attempts && null === $retry->claim_token, $retry ? $retry->status : 'no row' );

echo "\n== Provider callbacks say which message ==\n";

$captured = array();
$capture  = static function ( $events ) use ( &$captured ) {
	$captured = $events;

	return array(); // Nothing is applied: this is about parsing.
};
add_filter( 'mailhouse_webhook_events', $capture );

Mailhouse_Settings::set( array( 'webhook_mailgun_key' => '', 'webhook_resend_secret' => '', 'webhook_sendgrid_key' => '' ) );
Mailhouse_Settings::flush();

$payloads = array(
	'sendgrid' => array(
		array( array( 'email' => 'a@example.com', 'event' => 'bounce', 'reason' => '550', 'sg_message_id' => 'sgmsg.filter0001.1', 'sg_event_id' => 'sgevt', 'timestamp' => 1760000000 ) ),
		array( 'sgmsg', 'sgevt', 1760000000 ),
	),
	'mailgun'  => array(
		array( 'event-data' => array( 'event' => 'failed', 'severity' => 'permanent', 'recipient' => 'a@example.com', 'id' => 'mgevt', 'timestamp' => 1760000001.5, 'message' => array( 'headers' => array( 'message-id' => 'mgmsg@mg.example.com' ) ) ) ),
		array( 'mgmsg@mg.example.com', 'mgevt', 1760000001 ),
	),
	'brevo'    => array(
		array( 'event' => 'hard_bounce', 'email' => 'a@example.com', 'message-id' => '<brmsg@smtp-relay.mailin.fr>', 'id' => 4242, 'ts_event' => 1760000002 ),
		array( 'brmsg@smtp-relay.mailin.fr', '4242', 1760000002 ),
	),
	'postmark' => array(
		array( 'RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'a@example.com', 'MessageID' => 'pmmsg', 'ID' => 987, 'BouncedAt' => '2025-10-09T08:53:23Z' ),
		array( 'pmmsg', '987', strtotime( '2025-10-09T08:53:23Z' ) ),
	),
	'resend'   => array(
		array( 'type' => 'email.bounced', 'created_at' => '2025-10-09T08:53:24Z', 'data' => array( 'email_id' => 'rsmsg', 'to' => array( 'a@example.com' ), 'bounce' => array( 'type' => 'Permanent', 'message' => 'gone' ) ) ),
		array( 'rsmsg', '', strtotime( '2025-10-09T08:53:24Z' ) ),
	),
);

foreach ( $payloads as $provider_id => $case ) {
	$captured = array();
	Mailhouse_Webhooks::process( $provider_id, wp_json_encode( $case[0] ) );

	$event = $captured ? $captured[0] : array();

	check(
		"$provider_id: message id, event id and time",
		$event
			&& $case[1][0] === $event['message_id']
			&& $case[1][1] === $event['event_id']
			&& $case[1][2] === $event['occurred_at'],
		wp_json_encode( $event )
	);
	check( "$provider_id: and everything it carried before", $event && 'a@example.com' === $event['email'] && 'hard' === $event['type'] );
}

remove_filter( 'mailhouse_webhook_events', $capture );

echo "\n== Uninstall leaves nothing behind ==\n";

$uninstall = (string) file_get_contents( MAILHOUSE_PATH . 'uninstall.php' ); // phpcs:ignore

foreach ( array( 'mailhouse_webhook_key', 'mailhouse_new_post_seeded', 'mailhouse_install_lock' ) as $option ) {
	check( "uninstall removes $option", false !== strpos( $uninstall, "'" . $option . "'" ) );
}

echo "\n== Cleanup ==\n";

remove_filter( 'pre_http_request', $http_filter, 10 );

$wpdb->query( "UPDATE {$queue} SET status = 'pending' WHERE status = 'mhf_held'" ); // phpcs:ignore
$wpdb->query( $wpdb->prepare( "DELETE FROM {$queue} WHERE campaign_id = %d", $campaign ) ); // phpcs:ignore

Mailhouse_Subscribers::delete( array_map( 'intval', $cleanup['subscribers'] ) );

foreach ( $cleanup['tags'] as $tag_id ) {
	Mailhouse_Tags::delete( (int) $tag_id );
}

foreach ( $cleanup['posts'] as $post_id ) {
	wp_delete_post( $post_id, true );
}

$log = Mailhouse_Log::table();
$wpdb->query( "DELETE FROM {$log} WHERE subject IN ('Result test', 'Foundations subject')" ); // phpcs:ignore

delete_transient( 'mailhouse_queue_lock' );
update_option( 'mailhouse_settings', $saved_settings );
Mailhouse_Settings::flush();

echo "  cleaned up\n";

mailhouse_test_results();
