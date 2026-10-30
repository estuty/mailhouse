<?php
/**
 * Theme colours in the email design, and the post-confirmation popup.
 *
 * Usage: php tests/test-branding.php
 *
 * @package Mailhouse
 */

require __DIR__ . '/bootstrap.php';

$saved_settings = get_option( 'mailhouse_settings' );

add_filter(
	'pre_wp_mail',
	function () {
		return true;
	},
	1
);

echo "== Colour helpers ==\n";

check( 'a dark colour is lifted toward white', hexdec( ltrim( mailhouse_shade_colour( '#000000', 0.5 ), '#' ) ) > 0 );
check( 'a light colour is pushed toward black', mailhouse_shade_colour( '#ffffff', 0.5 ) < '#ffffff' );
check( 'shading nothing changes nothing', '#0b1020' === mailhouse_shade_colour( '#0b1020', 0 ) );
check( 'a dark brand gets light text', '#ffffff' === mailhouse_contrast_colour( '#0b1020' ) );
check( 'a light brand gets dark text', '#111827' === mailhouse_contrast_colour( '#f3f6fc' ) );
check( 'junk in gives a usable colour out', '#ffffff' === mailhouse_shade_colour( 'not-a-colour' ) );

// Subtle means visibly different but still the same kind of colour: a dark
// brand must not come back light, or the card would fight the page.
$lifted = mailhouse_shade_colour( '#0b1020', 0.07 );
check( 'the lift changes the colour', $lifted !== '#0b1020', $lifted );
check( 'but leaves a dark colour dark', '#ffffff' === mailhouse_contrast_colour( $lifted ), $lifted );

echo "== Reading the theme ==\n";

$available = Mailhouse_Palette::is_available();

check( 'the active theme is inspected for a palette', is_bool( $available ) );

if ( ! $available ) {
	echo "  -- this theme has no theme.json, so detection is skipped\n";
} else {
	$detected = Mailhouse_Palette::detect();

	check( 'colours were found', ! empty( $detected ), wp_json_encode( $detected ) );

	foreach ( array( 'template_accent', 'template_bg', 'template_card_bg', 'template_ink', 'template_button', 'template_header_bg' ) as $key ) {
		check( "$key was derived", ! empty( $detected[ $key ] ) && $detected[ $key ] === sanitize_hex_color( $detected[ $key ] ), isset( $detected[ $key ] ) ? $detected[ $key ] : 'missing' );
	}

	// WordPress's own theme.json sets a literal button background of #32373c,
	// and the merged styles hand it over as if the theme had asked for it.
	check(
		"core's default button grey does not pass as the theme's",
		'#32373c' !== $detected['template_button'],
		$detected['template_button']
	);

	check(
		'the card sits a shade off the page behind it',
		$detected['template_card_bg'] !== $detected['template_bg'],
		$detected['template_card_bg'] . ' vs ' . $detected['template_bg']
	);

	// contrast_colour() answers "what reads on top of this". A light card and
	// light ink would both answer the same way, which is the failure mode.
	check(
		'the text colour is readable on the card',
		mailhouse_contrast_colour( $detected['template_card_bg'] ) !== mailhouse_contrast_colour( $detected['template_ink'] ),
		$detected['template_ink'] . ' on ' . $detected['template_card_bg']
	);

	// A filter is the documented way to correct a bad guess.
	add_filter(
		'mailhouse_theme_colours',
		function ( $colours ) {
			$colours['template_accent'] = '#ff00ff';
			return $colours;
		}
	);

	check( 'the detected set is filterable', '#ff00ff' === Mailhouse_Palette::detect()['template_accent'] );
	remove_all_filters( 'mailhouse_theme_colours' );
}

echo "== The design uses them ==\n";

Mailhouse_Settings::set(
	array(
		'template_accent'  => '#3d82f6',
		'template_bg'      => '#0b1020',
		'template_card_bg'  => '#161c33',
		'template_ink'     => '#f3f6fc',
		'template_logo'    => '',
		'template_footer'  => 'Because you subscribed at example.com.',
		'company_address'  => "Example Ltd\n1 Example Street",
	)
);

$html = Mailhouse_Template::wrap( '<p>Hello</p><h2>Heading</h2>', array( 'subject' => 'Branded' ) );

check( 'the page background is applied', false !== strpos( $html, '#0b1020' ) );
check( 'the card background is applied', false !== strpos( $html, '#161c33' ), 'card colour missing' );
check( 'the text colour is applied', false !== strpos( $html, '#f3f6fc' ) );
check( 'the brand colour is applied', false !== strpos( $html, '#3d82f6' ) );
check( 'the card is no longer hardcoded white', false === strpos( $html, 'background-color:#ffffff;border-radius:10px' ) );
check( 'headings follow the text colour', false !== strpos( $html, '.mh-content h1, .mh-content h2, .mh-content h3 { line-height: 1.25; margin: 0 0 12px; color: #f3f6fc' ) );
check( 'the footer and address survive', false !== strpos( $html, 'Because you subscribed' ) && false !== strpos( $html, '1 Example Street' ) );

// A dark design must not be "corrected" into somebody else's greys.
check( 'no dark-mode override on an already-dark design', false === strpos( $html, 'prefers-color-scheme: dark' ) );

Mailhouse_Settings::set(
	array(
		'template_bg'      => '#f3f4f6',
		'template_card_bg' => '#ffffff',
		'template_ink'     => '#1f2937',
	)
);

$light = Mailhouse_Template::wrap( '<p>Hello</p>', array( 'subject' => 'Light' ) );

check( 'a light design still gets the dark-mode override', false !== strpos( $light, 'prefers-color-scheme: dark' ) );

echo "== Every kind of email is wrapped ==\n";

$subscriber_id = Mailhouse_Subscribers::add(
	array(
		'email'       => strtolower( 'brand-' . wp_generate_password( 6, false ) . '@example.com' ),
		'first_name'  => 'Bran',
		'send_emails' => false,
	)
)['id'];

$subscriber = Mailhouse_Subscribers::get( $subscriber_id );

foreach ( array( 'confirmation', 'welcome' ) as $type ) {
	$email = Mailhouse_Emails::get_automation( $type );

	if ( ! $email ) {
		check( "a $type email exists to wrap", false, 'automation missing' );
		continue;
	}

	$message = Mailhouse_Emails::render( $email, $subscriber );

	check( "the $type email has the header", false !== strpos( $message['html'], 'mh-card' ) );
	check( "the $type email has the footer", false !== strpos( $message['html'], 'mh-foot' ) );
	check( "the $type email carries the brand colour", false !== strpos( $message['html'], '#3d82f6' ) );
}

// WordPress's own mail, when the site asked for one design everywhere.
Mailhouse_Settings::set( array( 'template_wrap_all' => true ) );

$site_mail = Mailhouse_Template::wrap_site_email( "Someone asked to reset their password.\n\nhttps://example.com/reset", false, 'Password reset' );

check( 'a plain-text site email is wrapped too', false !== strpos( $site_mail, 'mh-card' ) && false !== strpos( $site_mail, 'mh-foot' ) );
check( 'its link survives as a link', false !== strpos( $site_mail, '<a href="https://example.com/reset"' ), 'link not linkified' );
check( 'it carries no unsubscribe footer', false === strpos( $site_mail, 'Unsubscribe' ) );

echo "== The confirmation button ==\n";

$pending = Mailhouse_Subscribers::add(
	array(
		'email'       => strtolower( 'btn-' . wp_generate_password( 6, false ) . '@example.com' ),
		'status'      => 'pending',
		'send_emails' => false,
	)
);

$pending_sub = Mailhouse_Subscribers::get( $pending['id'] );

// 'html' is the mode the email body is rendered in; the default 'text' mode
// strips markup on purpose, for the plain-text alternative.
$button = Mailhouse_Merge_Tags::render( '{{confirm_button}}', array( 'subscriber' => $pending_sub ), 'html' );
$plain  = Mailhouse_Merge_Tags::render( '{{confirm_button}}', array( 'subscriber' => $pending_sub ) );

check( 'the confirm button renders as markup, not escaped text', false !== strpos( $button, '<a class="mh-button"' ), substr( $button, 0, 70 ) );
check( 'it points at the right confirmation link', false !== strpos( $button, (string) $pending_sub->confirm_key ) );
check( 'it carries the button colour', false !== strpos( $button, (string) mailhouse_option( 'template_button' ) ) );
check( 'the plain-text alternative keeps the label, not the markup', false === strpos( $plain, '<' ) && false !== strpos( $plain, 'Confirm' ), $plain );
check( 'with no subscriber in context it renders as nothing', '' === trim( Mailhouse_Merge_Tags::render( '{{confirm_button}}', array(), 'html' ) ) );

// The built-in wording, used when the editable confirmation email has been
// deleted, should get the same button rather than a bare link.
$fallback = new ReflectionMethod( 'Mailhouse_Automations', 'fallback_confirmation' );
$fallback->setAccessible( true );
$built_in = $fallback->invoke( null, $pending_sub );

check( 'the built-in confirmation email uses a button too', false !== strpos( $built_in['html'], 'mh-button' ) );
check( 'and still prints the link for clients that strip buttons', false !== strpos( $built_in['html'], (string) $pending_sub->confirm_key ) );

Mailhouse_Subscribers::delete( array( $pending['id'] ) );

echo "== The confirmation popup ==\n";

$keys = array_keys( Mailhouse_Notice::messages() );

check( 'the messages include a confirmation', in_array( 'confirmed', $keys, true ) );
check( 'and an expired link', in_array( 'expired', $keys, true ) );

$url = Mailhouse_Notice::url( 'confirmed' );

check( 'the popup URL points at the site', 0 === strpos( $url, home_url() ), $url );
check( 'and carries the message key', false !== strpos( $url, 'mailhouse_notice=confirmed' ) );

add_filter(
	'mailhouse_notice_destination',
	function () {
		return home_url( '/thanks/' );
	}
);

check( 'the destination is filterable', false !== strpos( Mailhouse_Notice::url( 'confirmed' ), '/thanks/' ) );
remove_all_filters( 'mailhouse_notice_destination' );

$notice = mailhouse()->module( 'notice' );

// Nothing should print on an ordinary request.
unset( $_GET[ Mailhouse_Notice::QUERY ] );

ob_start();
$notice->render();
check( 'nothing is printed without the query argument', '' === trim( (string) ob_get_clean() ) );

$_GET[ Mailhouse_Notice::QUERY ] = 'confirmed';

ob_start();
$notice->render();
$popup = (string) ob_get_clean();

check( 'the popup is printed', false !== strpos( $popup, 'mailhouse-popup' ) );
check( 'it is a dialog for assistive tech', false !== strpos( $popup, 'role="dialog"' ) && false !== strpos( $popup, 'aria-modal="true"' ) );
check( 'it is labelled by its own title', false !== strpos( $popup, 'aria-labelledby="mailhouse-popup-title"' ) );
check( 'it says what happened', false !== strpos( $popup, 'You are on the list' ) );
check( 'it uses the brand colour', false !== strpos( $popup, '#3d82f6' ), 'tone colour missing' );
check( 'it can be dismissed without JavaScript', false !== strpos( $popup, 'mailhouse-popup__button' ) && false !== strpos( $popup, 'href=' ) );

$_GET[ Mailhouse_Notice::QUERY ] = 'expired';

ob_start();
$notice->render();
$expired = (string) ob_get_clean();

check( 'a used link gets its own wording', false !== strpos( $expired, 'already been used' ) );

// The calm tone is derived from the site's own text colour rather than being a
// fixed grey, so the assertion is that it is *not* the celebratory one.
preg_match( '/--mailhouse-tone: (#[0-9a-f]{6})/i', $expired, $expired_tone );

check( 'it has a tone of its own', ! empty( $expired_tone[1] ), $expired );
check( 'and it is not the brand colour used for success', ! empty( $expired_tone[1] ) && '#3d82f6' !== strtolower( $expired_tone[1] ), isset( $expired_tone[1] ) ? $expired_tone[1] : '' );
check( 'the popup carries the site surface, not a fixed white', false !== strpos( $expired, '--mailhouse-surface: #1c2130' ) || false !== strpos( $expired, '--mailhouse-surface: #' ) );

$_GET[ Mailhouse_Notice::QUERY ] = '<script>alert(1)</script>';

ob_start();
$notice->render();
$injected = (string) ob_get_clean();

check( 'an unknown key prints nothing at all', '' === trim( $injected ), $injected );

unset( $_GET[ Mailhouse_Notice::QUERY ] );

echo "== The standalone pages use the design too ==\n";

Mailhouse_Settings::set(
	array(
		'template_accent'  => '#3d82f6',
		'template_card_bg' => '#161c33',
		'template_ink'     => '#f3f6fc',
	)
);

$brand = mailhouse_brand_palette();

check( 'the palette reads the message background', '#161c33' === $brand['surface'], $brand['surface'] );
check( 'the palette reads the text colour', '#f3f6fc' === $brand['ink'], $brand['ink'] );
check( 'a dark design is recognised as dark', true === $brand['is_dark'] );

foreach ( array( 'accent', 'surface', 'ink', 'on_accent', 'muted', 'edge', 'field' ) as $slot ) {
	check( "the $slot colour is a usable hex", (string) sanitize_hex_color( $brand[ $slot ] ) === $brand[ $slot ], $brand[ $slot ] );
}

$page = Mailhouse_Optin::page_html( 'Your preferences', '<p class="mailhouse-notice">Saved.</p>' );

check( 'the page takes the message background', false !== strpos( $page, '--mh-surface: #161c33' ) );
check( 'the page takes the text colour', false !== strpos( $page, '--mh-ink: #f3f6fc' ) );
check( 'the page takes the brand colour', false !== strpos( $page, '--mh-accent: #3d82f6' ) );

// The whole point of the change: this page used to be white whatever the
// design said.
check( 'the page is no longer hardcoded white', false === strpos( $page, 'background: #fff;' ) );
check( 'nor hardcoded near-black text', false === strpos( $page, 'color: #1a1a1a' ) );

// Same rule the emails follow: a design the site chose is not up for
// renegotiation by the reader's browser.
check( 'no dark-mode override on an already-dark design', false === strpos( $page, 'prefers-color-scheme: dark' ) );
check( 'and the browser is told the page is dark', false !== strpos( $page, 'color-scheme: dark;' ) );

check( 'the title still lands in the page', false !== strpos( $page, 'Your preferences' ) );
check( 'the body still lands in the page', false !== strpos( $page, 'Saved.' ) );

// The popup and the page are the same surface now, which is the thing that
// used to drift.
$popup_brand = mailhouse_brand_palette();
check(
	'the popup and the page agree on the surface',
	false !== strpos( $page, '--mh-surface: ' . $popup_brand['surface'] ),
	$popup_brand['surface']
);

Mailhouse_Settings::set( array( 'template_accent' => '#ffe066' ) );

$pale = Mailhouse_Optin::page_html( 'Pale', '' );

check( 'button text goes dark on a pale brand colour', false !== strpos( $pale, '--mh-on-accent: #111827' ), $pale );

Mailhouse_Settings::set(
	array(
		'template_accent'  => '#2b6cb0',
		'template_card_bg' => '#ffffff',
		'template_ink'     => '#1f2937',
	)
);

$light_page = Mailhouse_Optin::page_html( 'Light', '' );

check( 'a light design is not reported as dark', false === mailhouse_brand_palette()['is_dark'] );
check( 'a light design still follows the reader at night', false !== strpos( $light_page, 'prefers-color-scheme: dark' ) );
check( 'and asks the browser for either scheme', false !== strpos( $light_page, 'color-scheme: light dark' ) );

$escaped = Mailhouse_Optin::page_html( '<script>alert(1)</script>', '<p>body</p>' );

check( 'the page title is escaped', false === strpos( $escaped, '<script>alert(1)</script>' ) );
check( 'the body is passed through for the caller to have escaped', false !== strpos( $escaped, '<p>body</p>' ) );

add_filter(
	'mailhouse_brand_palette',
	function ( $palette ) {
		$palette['surface'] = '#ff00ff';

		return $palette;
	}
);

check( 'the palette is filterable', false !== strpos( Mailhouse_Optin::page_html( 'F', '' ), '--mh-surface: #ff00ff' ) );

remove_all_filters( 'mailhouse_brand_palette' );

echo "\n== Cleanup ==\n";

Mailhouse_Subscribers::delete( array( $subscriber_id ) );
update_option( 'mailhouse_settings', $saved_settings );
Mailhouse_Settings::flush();
Mailhouse_Log::clear();

echo "  cleaned up\n";

mailhouse_test_results();
