<?php
/**
 * Updates from GitHub releases.
 *
 * GitHub is never actually called: `pre_http_request` stands in for it, which
 * means the release shapes worth worrying about -- no attached zip, a renamed
 * repository, a rate limit -- can each be put in front of the code on demand.
 * What is real is WordPress: `wp_update_plugins()` is run for the end-to-end
 * checks, because the whole question is whether core accepts what we hand it.
 *
 * Usage: php tests/test-updates.php
 *
 * @package Mailhouse
 */

require __DIR__ . '/bootstrap.php';

require_once ABSPATH . 'wp-admin/includes/plugin.php';

/**
 * Stand in for the GitHub API, and count what was asked of it.
 *
 * @param mixed $release Decoded release to answer with, or a status code to
 *                       fail with.
 * @return void
 */
function mailhouse_stub_github( $release ) {
	$GLOBALS['mailhouse_http_calls'] = 0;

	remove_all_filters( 'pre_http_request' );

	add_filter(
		'pre_http_request',
		function ( $preempt, $args, $url ) use ( $release ) {
			// The .org endpoint is answered too, so running
			// wp_update_plugins() never reaches the network.
			if ( false !== strpos( $url, 'api.wordpress.org' ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'plugins'      => array(),
							'translations' => array(),
							'no_update'    => array(),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			if ( false === strpos( $url, 'api.github.com' ) ) {
				return $preempt;
			}

			++$GLOBALS['mailhouse_http_calls'];

			if ( is_int( $release ) ) {
				return array(
					'headers'  => array(),
					'body'     => '{"message":"Not Found"}',
					'response' => array(
						'code'    => $release,
						'message' => 'Error',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $release ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		},
		10,
		3
	);
}

/**
 * A release as GitHub reports one.
 *
 * @param string $tag    Tag name.
 * @param array  $assets Asset names to attach.
 * @param string $body   Release notes.
 * @return array
 */
function mailhouse_fake_release( $tag = 'v9.9.0', $assets = array( 'mailhouse-9.9.0.zip' ), $body = 'Notes.' ) {
	$attached = array();

	foreach ( $assets as $name ) {
		$attached[] = array(
			'name'                 => $name,
			'browser_download_url' => 'https://github.com/estuty/mailhouse/releases/download/' . $tag . '/' . $name,
		);
	}

	return array(
		'tag_name'     => $tag,
		'name'         => 'Mailhouse ' . ltrim( $tag, 'v' ),
		'body'         => $body,
		'html_url'     => 'https://github.com/estuty/mailhouse/releases/tag/' . $tag,
		'published_at' => '2026-10-30T12:00:00Z',
		'draft'        => false,
		'prerelease'   => false,
		'assets'       => $attached,
	);
}

/**
 * Forget the cached release so the next call asks again.
 *
 * @return void
 */
function mailhouse_forget_release() {
	delete_site_transient( 'mailhouse_latest_release' );
	delete_site_transient( 'update_plugins' );
}

$updater = mailhouse()->module( 'updater' );
$headers = get_plugin_data( MAILHOUSE_FILE, false, false );

echo "== The header that routes the check ==\n";

check( 'the plugin declares an Update URI', ! empty( $headers['UpdateURI'] ), isset( $headers['UpdateURI'] ) ? $headers['UpdateURI'] : 'missing' );
check(
	'its host is the one the filter is named for',
	'github.com' === wp_parse_url( $headers['UpdateURI'], PHP_URL_HOST ),
	(string) wp_parse_url( (string) $headers['UpdateURI'], PHP_URL_HOST )
);
check( 'Plugin URI is no longer the placeholder', false === strpos( (string) $headers['PluginURI'], 'example.com' ), $headers['PluginURI'] );
check( 'the updater module is loaded', $updater instanceof Mailhouse_Updater );

echo "== Answering only for ourselves ==\n";

mailhouse_stub_github( mailhouse_fake_release() );
mailhouse_forget_release();

// Every plugin on the site that updates from github.com arrives at the same
// filter. Answering for one of them would offer Mailhouse as an update to it,
// and WordPress would install it straight over the top.
$other = $updater->offer( false, array( 'Version' => '1.0.0' ), 'some-other-plugin/some-other-plugin.php' );
check( "another plugin's update is left alone", false === $other, wp_json_encode( $other ) );
check( 'and asking about it costs no request', 0 === $GLOBALS['mailhouse_http_calls'], (string) $GLOBALS['mailhouse_http_calls'] );

$kept = $updater->offer( array( 'new_version' => '2.0.0' ), array(), 'another/another.php' );
check( "a decision another filter already made is passed through", isset( $kept['new_version'] ) && '2.0.0' === $kept['new_version'] );

echo "== What WordPress is handed ==\n";

mailhouse_forget_release();
$offer = $updater->offer( false, $headers, MAILHOUSE_BASENAME );

check( 'an update is offered for this plugin', is_array( $offer ), wp_json_encode( $offer ) );

foreach ( array( 'id', 'slug', 'plugin', 'new_version', 'url', 'package' ) as $field ) {
	// Core drops the answer silently if any one of these is missing, so the
	// symptom of getting this wrong is no update and no error.
	check( "the required field $field is present", ! empty( $offer[ $field ] ), isset( $offer[ $field ] ) ? (string) $offer[ $field ] : 'missing' );
}

check( 'the slug matches the plugin folder', 'mailhouse' === $offer['slug'], $offer['slug'] );
check( 'the plugin field is the installed basename', MAILHOUSE_BASENAME === $offer['plugin'], $offer['plugin'] );
check( 'the leading v is stripped from the tag', '9.9.0' === $offer['new_version'], $offer['new_version'] );
check( 'the package is the attached zip', false !== strpos( $offer['package'], 'mailhouse-9.9.0.zip' ), $offer['package'] );
check( 'the requirements come from the real headers', '7.4' === $offer['requires_php'], (string) $offer['requires_php'] );

echo "== Choosing what to install ==\n";

mailhouse_stub_github( mailhouse_fake_release( 'v9.9.0', array( 'extras.zip', 'mailhouse-9.9.0.zip', 'notes.txt' ) ) );
mailhouse_forget_release();
$offer = $updater->offer( false, $headers, MAILHOUSE_BASENAME );
check( 'the version-named zip wins over anything else attached', false !== strpos( $offer['package'], 'mailhouse-9.9.0.zip' ), $offer['package'] );

mailhouse_stub_github( mailhouse_fake_release( 'v9.9.0', array( 'something-else.zip' ) ) );
mailhouse_forget_release();
$offer = $updater->offer( false, $headers, MAILHOUSE_BASENAME );
check( 'another zip is used rather than nothing', is_array( $offer ) && false !== strpos( $offer['package'], 'something-else.zip' ), wp_json_encode( $offer ) );

// GitHub's own source archive unpacks to a folder named after the repository
// and the commit. WordPress installs a plugin into whatever folder the zip
// contains, so that would land beside this one as a second copy instead of
// replacing it.
mailhouse_stub_github( mailhouse_fake_release( 'v9.9.0', array() ) );
mailhouse_forget_release();
$offer = $updater->offer( false, $headers, MAILHOUSE_BASENAME );
check( 'a release with no zip on it offers nothing', false === $offer, wp_json_encode( $offer ) );

mailhouse_stub_github( mailhouse_fake_release( 'v9.9.0', array( 'notes.txt', 'checksums.txt' ) ) );
mailhouse_forget_release();
check( 'and neither does one with only loose files', false === $updater->offer( false, $headers, MAILHOUSE_BASENAME ) );

echo "== When GitHub does not answer ==\n";

mailhouse_stub_github( 404 );
mailhouse_forget_release();
check( 'a missing repository offers no update', false === $updater->offer( false, $headers, MAILHOUSE_BASENAME ) );
check( 'the failure cost one request', 1 === $GLOBALS['mailhouse_http_calls'], (string) $GLOBALS['mailhouse_http_calls'] );

$updater->offer( false, $headers, MAILHOUSE_BASENAME );
$updater->offer( false, $headers, MAILHOUSE_BASENAME );
// Unauthenticated GitHub allows sixty requests an hour. Re-asking on every
// row of the Plugins screen would spend that in a minute.
check( 'and is remembered rather than retried', 1 === $GLOBALS['mailhouse_http_calls'], (string) $GLOBALS['mailhouse_http_calls'] );
check( 'the backoff is recorded', 'none' === get_site_transient( 'mailhouse_latest_release' ) );

mailhouse_stub_github( 403 );
mailhouse_forget_release();
check( 'a rate limit offers no update either', false === $updater->offer( false, $headers, MAILHOUSE_BASENAME ) );

mailhouse_stub_github( mailhouse_fake_release() );
mailhouse_forget_release();
$updater->offer( false, $headers, MAILHOUSE_BASENAME );
$updater->offer( false, $headers, MAILHOUSE_BASENAME );
$updater->offer( false, $headers, MAILHOUSE_BASENAME );
check( 'a success is cached too', 1 === $GLOBALS['mailhouse_http_calls'], (string) $GLOBALS['mailhouse_http_calls'] );

$updater->forget();
check( 'and can be thrown away', false === get_site_transient( 'mailhouse_latest_release' ) );

echo "== Garbage in ==\n";

mailhouse_stub_github( array( 'message' => 'nope' ) );
mailhouse_forget_release();
check( 'a response with no tag offers nothing', false === $updater->offer( false, $headers, MAILHOUSE_BASENAME ) );

mailhouse_stub_github( mailhouse_fake_release( '', array( 'mailhouse-9.9.0.zip' ) ) );
mailhouse_forget_release();
check( 'an empty tag offers nothing', false === $updater->offer( false, $headers, MAILHOUSE_BASENAME ) );

mailhouse_stub_github( mailhouse_fake_release( 'v1.0.0', array( 'mailhouse-1.0.0.zip' ) ) );
mailhouse_forget_release();
$offer = $updater->offer( false, $headers, MAILHOUSE_BASENAME );
// Reported, not withheld: WordPress decides response from no_update itself,
// and a plugin that says nothing when current shows no View details link.
check( 'an older release is still reported', is_array( $offer ) && '1.0.0' === $offer['new_version'], wp_json_encode( $offer ) );

echo "== Through wp_update_plugins() ==\n";

mailhouse_stub_github( mailhouse_fake_release( 'v9.9.0' ) );
mailhouse_forget_release();
wp_update_plugins();
$state = get_site_transient( 'update_plugins' );

check( 'core ran a check', is_object( $state ) && isset( $state->last_checked ) );
check(
	'a newer release lands in the update list',
	is_object( $state ) && isset( $state->response[ MAILHOUSE_BASENAME ] ),
	is_object( $state ) ? implode( ', ', array_keys( (array) $state->response ) ) : 'no transient'
);

if ( is_object( $state ) && isset( $state->response[ MAILHOUSE_BASENAME ] ) ) {
	$row = $state->response[ MAILHOUSE_BASENAME ];
	check( 'with the version from the tag', '9.9.0' === $row->new_version, $row->new_version );
	check( 'and a package to install', ! empty( $row->package ), isset( $row->package ) ? $row->package : 'missing' );
}

mailhouse_stub_github( mailhouse_fake_release( 'v0.0.1', array( 'mailhouse-0.0.1.zip' ) ) );
mailhouse_forget_release();
wp_update_plugins();
$state = get_site_transient( 'update_plugins' );

check(
	'an older release is filed as no update rather than offered',
	is_object( $state ) && ! isset( $state->response[ MAILHOUSE_BASENAME ] ) && isset( $state->no_update[ MAILHOUSE_BASENAME ] )
);

echo "== The details panel ==\n";

mailhouse_stub_github(
	mailhouse_fake_release(
		'v9.9.0',
		array( 'mailhouse-9.9.0.zip' ),
		"## New\n\n- First thing\n- Second thing\n\n## Fixed\n\n- Third thing\n\nSomething with `code` and **weight** and a [link](https://example.com/x).\n\n<script>alert(1)</script>"
	)
);
mailhouse_forget_release();

check( 'another API action is left alone', 'untouched' === $updater->details( 'untouched', 'query_plugins', (object) array( 'slug' => 'mailhouse' ) ) );
check( "another plugin's details are left alone", 'untouched' === $updater->details( 'untouched', 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
check( 'a request with no slug is left alone', 'untouched' === $updater->details( 'untouched', 'plugin_information', (object) array() ) );

$info = $updater->details( false, 'plugin_information', (object) array( 'slug' => 'mailhouse' ) );

check( 'our own details are answered', is_object( $info ), wp_json_encode( $info ) );
check( 'with the release version', '9.9.0' === $info->version, $info->version );
check( 'a download link', ! empty( $info->download_link ), (string) $info->download_link );
check( 'the requirements', '6.0' === $info->requires && '7.4' === $info->requires_php, $info->requires . ' / ' . $info->requires_php );
check( 'and a date', '2026-10-30T12:00:00Z' === $info->last_updated, (string) $info->last_updated );

$log = $info->sections['changelog'];

check( 'headings become headings', false !== strpos( $log, '<h4>New</h4>' ) && false !== strpos( $log, '<h4>Fixed</h4>' ), $log );
check( 'list items become list items', 3 === substr_count( $log, '<li>' ), (string) substr_count( $log, '<li>' ) );
// One pattern over the whole body would have swallowed the heading between
// the two lists into a single enormous item.
check( 'two lists stay two lists', 2 === substr_count( $log, '<ul>' ), (string) substr_count( $log, '<ul>' ) );
check( 'code stays code', false !== strpos( $log, '<code>code</code>' ) );
check( 'emphasis survives', false !== strpos( $log, '<strong>weight</strong>' ) );
check( 'links are linked, and marked untrusted', false !== strpos( $log, 'rel="nofollow ugc"' ) && false !== strpos( $log, 'href="https://example.com/x"' ), $log );
// The repository is filterable, so release notes are not necessarily ours.
check( 'markup in the notes is escaped, not rendered', false === strpos( $log, '<script>' ), $log );

echo "== The row link ==\n";

$meta = $updater->row_meta( array( 'existing' ), 'some-other-plugin/some-other-plugin.php' );
check( "no link is added to another plugin's row", array( 'existing' ) === $meta, wp_json_encode( $meta ) );

wp_set_current_user( 0 );
$meta = $updater->row_meta( array( 'existing' ), MAILHOUSE_BASENAME );
check( 'and none for somebody who cannot update plugins', array( 'existing' ) === $meta, wp_json_encode( $meta ) );

$admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

if ( $admin ) {
	wp_set_current_user( (int) $admin[0] );
	$meta = $updater->row_meta( array( 'existing' ), MAILHOUSE_BASENAME );

	check( 'an administrator gets the link', 2 === count( $meta ), wp_json_encode( $meta ) );
	check( 'it is nonced', false !== strpos( implode( '', $meta ), '_wpnonce' ), implode( '', $meta ) );
	check( 'and points at the handler', false !== strpos( implode( '', $meta ), 'action=mailhouse_check_updates' ) );
	wp_set_current_user( 0 );
} else {
	echo "  -- no administrator on this site, so the capable case is skipped\n";
}

echo "== Saying so on the status panel ==\n";

mailhouse_forget_release();
check( 'before any check it says so', 'not checked yet' === Mailhouse_Updater::status(), Mailhouse_Updater::status() );

mailhouse_stub_github( 404 );
mailhouse_forget_release();
$updater->offer( false, $headers, MAILHOUSE_BASENAME );
// Otherwise a site whose checks are all failing looks exactly like a site
// that is up to date, and never finds out.
check( 'a failing check is visible', false !== strpos( Mailhouse_Updater::status(), 'could not reach' ), Mailhouse_Updater::status() );

$GLOBALS['mailhouse_http_calls'] = 0;
Mailhouse_Updater::status();
check( 'and drawing the panel costs no request', 0 === $GLOBALS['mailhouse_http_calls'], (string) $GLOBALS['mailhouse_http_calls'] );

mailhouse_stub_github( mailhouse_fake_release( 'v9.9.0' ) );
mailhouse_forget_release();
$updater->offer( false, $headers, MAILHOUSE_BASENAME );
check( 'a newer release is named', '9.9.0 is available' === Mailhouse_Updater::status(), Mailhouse_Updater::status() );

mailhouse_stub_github( mailhouse_fake_release( 'v' . MAILHOUSE_VERSION, array( 'mailhouse-' . MAILHOUSE_VERSION . '.zip' ) ) );
mailhouse_forget_release();
$updater->offer( false, $headers, MAILHOUSE_BASENAME );
check( 'and the current one reads as current', 'up to date' === Mailhouse_Updater::status(), Mailhouse_Updater::status() );

echo "== Pointing it somewhere else ==\n";

check( 'the repository defaults to this one', 'estuty/mailhouse' === Mailhouse_Updater::repo(), Mailhouse_Updater::repo() );

add_filter(
	'mailhouse_update_repo',
	function () {
		return 'someone/fork';
	}
);

check( 'and a fork can redirect it', 'someone/fork' === Mailhouse_Updater::repo(), Mailhouse_Updater::repo() );
remove_all_filters( 'mailhouse_update_repo' );

remove_all_filters( 'pre_http_request' );
mailhouse_forget_release();

mailhouse_test_results();
