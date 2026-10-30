<?php
/**
 * Small helpers shared across the plugin.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read a setting.
 *
 * @param string $key     Setting key, e.g. `smtp_host`.
 * @param mixed  $default Returned when the key has never been saved.
 * @return mixed
 */
function mailhouse_option( $key, $default = null ) {
	return Mailhouse_Settings::get( $key, $default );
}

/**
 * The current MySQL datetime, in site-independent UTC.
 *
 * Every timestamp the plugin stores is UTC. Local time is applied on the way
 * out, when a date is shown to a human.
 *
 * @return string
 */
function mailhouse_now() {
	return current_time( 'mysql', true );
}

/**
 * A cryptographically random hex token.
 *
 * @param int $bytes Number of random bytes; the string is twice as long.
 * @return string
 */
function mailhouse_token( $bytes = 32 ) {
	return bin2hex( random_bytes( $bytes ) );
}

/**
 * The requester's IP address, or an empty string.
 *
 * Proxy headers are trusted only when the site opts in, because anybody can
 * send an X-Forwarded-For header.
 *
 * @return string
 */
function mailhouse_ip() {
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
	$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';

	if ( ! mailhouse_option( 'trust_proxy' ) ) {
		return $remote;
	}

	// Single-value headers set by the proxy itself come first.
	foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP' ) as $key ) {
		if ( empty( $_SERVER[ $key ] ) ) {
			continue;
		}

		$value = trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );

		if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
			return $value;
		}
	}

	// X-Forwarded-For is a list the client starts and each proxy appends to,
	// so the leftmost entry is whatever the client chose to send. The
	// rightmost public address is the one the nearest proxy actually saw.
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$hops = array_reverse( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) );

		foreach ( $hops as $hop ) {
			if ( filter_var( $hop, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $hop;
			}
		}
	}

	return $remote;
}

/**
 * Format a stored UTC datetime for display in the site's timezone.
 *
 * @param string $datetime MySQL datetime in UTC.
 * @return string
 */
function mailhouse_date( $datetime ) {
	if ( empty( $datetime ) || '0000-00-00 00:00:00' === $datetime ) {
		return '&mdash;';
	}

	$timestamp = strtotime( $datetime . ' UTC' );

	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
}

/**
 * Turn an HTML email body into a readable plain-text alternative.
 *
 * Links become "text <url>" so the address survives in a text-only client,
 * and block-level tags become line breaks.
 *
 * @param string $html HTML body.
 * @return string
 */
function mailhouse_html_to_text( $html ) {
	$text = preg_replace( '#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $html );
	$text = preg_replace( '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', '$2 <$1>', $text );
	$text = preg_replace( '#<(br|/p|/div|/tr|/h[1-6]|/li)[^>]*>#i', "\n", $text );
	$text = preg_replace( '#<li[^>]*>#i', '- ', $text );
	$text = wp_strip_all_tags( $text );
	$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	$text = preg_replace( "/[ \t]+/", ' ', $text );
	$text = preg_replace( "/\n{3,}/", "\n\n", $text );

	return trim( $text );
}

/**
 * Normalise an email address for storage and comparison.
 *
 * @param string $email Raw address.
 * @return string Lowercased address, or an empty string when invalid.
 */
function mailhouse_clean_email( $email ) {
	$email = strtolower( trim( (string) $email ) );

	return is_email( $email ) ? $email : '';
}

/**
 * Split a "Name <email@example.com>" string into its parts.
 *
 * @param string $value Address, with or without a display name.
 * @return array{email:string,name:string}
 */
function mailhouse_parse_address( $value ) {
	$value = trim( (string) $value );
	$name  = '';

	if ( preg_match( '/^(.*)<(.+)>$/', $value, $matches ) ) {
		$name  = trim( trim( $matches[1] ), '"' );
		$value = trim( $matches[2] );
	}

	return array(
		'email' => mailhouse_clean_email( $value ),
		'name'  => $name,
	);
}

/**
 * Format an address as `Name <email@example.com>`.
 *
 * A display name containing a comma, a quote or an angle bracket is quoted,
 * because an unquoted one splits the header at the comma and silently sends to
 * an address nobody typed.
 *
 * @param string $email Address.
 * @param string $name  Optional display name.
 * @return string
 */
function mailhouse_format_address( $email, $name = '' ) {
	$name = trim( (string) $name );

	if ( '' === $name ) {
		return $email;
	}

	if ( preg_match( '/[,;<>@"\\\\]/', $name ) ) {
		$name = '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\"' ), $name ) . '"';
	}

	return sprintf( '%s <%s>', $name, $email );
}

/**
 * The admin URL for one of the plugin's screens.
 *
 * @param string $page Page slug suffix, e.g. `subscribers`.
 * @param array  $args Extra query arguments.
 * @return string
 */
function mailhouse_admin_url( $page = '', $args = array() ) {
	$slug = $page ? 'mailhouse-' . $page : 'mailhouse';

	return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
}

/**
 * Whether the current user may manage the plugin.
 *
 * @return bool
 */
function mailhouse_can_manage() {
	return current_user_can( mailhouse_capability() );
}

/**
 * The capability that guards every admin screen and action.
 *
 * @return string
 */
function mailhouse_capability() {
	return apply_filters( 'mailhouse_capability', 'manage_options' );
}

/**
 * Write a line to the debug log when WP_DEBUG_LOG is on.
 *
 * @param string $message Message.
 * @param mixed  $context Optional data, printed after the message.
 * @return void
 */
function mailhouse_log_debug( $message, $context = null ) {
	if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
		return;
	}

	if ( null !== $context ) {
		$message .= ' ' . wp_json_encode( $context );
	}

	error_log( '[Mailhouse] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Lift a colour slightly off itself.
 *
 * Used to separate the email card from the page behind it. The direction is
 * decided by the colour's own lightness, so a dark brand gets a slightly
 * lighter card and a light one a slightly darker page -- rather than a fixed
 * "add white" that turns black into grey and white into nothing.
 *
 * @param string $hex    Starting colour, `#rrggbb`.
 * @param float  $amount How far to move, 0 to 1.
 * @return string `#rrggbb`.
 */
function mailhouse_shade_colour( $hex, $amount = 0.06 ) {
	$clean = ltrim( (string) sanitize_hex_color( $hex ), '#' );

	if ( 3 === strlen( $clean ) ) {
		$clean = $clean[0] . $clean[0] . $clean[1] . $clean[1] . $clean[2] . $clean[2];
	}

	if ( 6 !== strlen( $clean ) ) {
		return '#ffffff';
	}

	$amount = min( 1, max( 0, (float) $amount ) );

	// A dark colour moves toward white, a light one toward black.
	$target = '#ffffff' === mailhouse_contrast_colour( '#' . $clean ) ? 255 : 0;
	$out    = '';

	foreach ( str_split( $clean, 2 ) as $pair ) {
		$channel = hexdec( $pair );
		$mixed   = (int) round( $channel + ( ( $target - $channel ) * $amount ) );

		$out .= str_pad( dechex( max( 0, min( 255, $mixed ) ) ), 2, '0', STR_PAD_LEFT );
	}

	return '#' . $out;
}

/**
 * Black or white, whichever reads better on a background colour.
 *
 * @param string $hex Background, `#rrggbb`.
 * @return string `#ffffff` or `#111827`.
 */
function mailhouse_contrast_colour( $hex ) {
	$hex = ltrim( (string) sanitize_hex_color( $hex ), '#' );

	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}

	if ( 6 !== strlen( $hex ) ) {
		return '#ffffff';
	}

	$channels = array_map(
		static function ( $pair ) {
			$c = hexdec( $pair ) / 255;

			return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		},
		str_split( $hex, 2 )
	);

	$luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

	return $luminance > 0.4 ? '#111827' : '#ffffff';
}

/**
 * The brand colours, as one palette everything on the front end lays out with.
 *
 * The emails, the popup and the standalone unsubscribe and preferences pages
 * all have to agree, and each used to work its own out. That is how the
 * standalone pages came to be white on a site whose design is dark: they were
 * written before there was a message background to read, and never caught up.
 * One place decides now.
 *
 * `is_dark` is here because the right answer to the reader's dark-mode
 * preference depends on the design. A site that has set a dark design should
 * stay dark whatever the reader prefers; a site still on the light default can
 * follow them. Callers need to know which they are looking at.
 *
 * @return array{accent:string,surface:string,ink:string,on_accent:string,muted:string,edge:string,field:string,is_dark:bool}
 */
function mailhouse_brand_palette() {
	$pick = static function ( $key, $fallback ) {
		$colour = sanitize_hex_color( (string) mailhouse_option( $key, $fallback ) );

		return $colour ? $colour : $fallback;
	};

	$accent  = $pick( 'template_accent', '#2b6cb0' );
	$surface = $pick( 'template_card_bg', '#ffffff' );
	$ink     = $pick( 'template_ink', '#1f2937' );

	$palette = array(
		'accent'    => $accent,
		'surface'   => $surface,
		'ink'       => $ink,
		// A brand colour can be pale, and white on pale yellow is not a button
		// anybody can read.
		'on_accent' => mailhouse_contrast_colour( $accent ),
		'muted'     => mailhouse_shade_colour( $ink, 0.3 ),
		// On a dark palette a card is only a shade off the page behind it, so
		// it needs an edge to read as a card rather than as floating text.
		'edge'      => mailhouse_shade_colour( $surface, 0.18 ),
		'field'     => mailhouse_shade_colour( $surface, 0.05 ),
		// mailhouse_contrast_colour() answers white for a dark background,
		// which is the cheapest honest way to ask whether the design is dark.
		'is_dark'   => '#ffffff' === mailhouse_contrast_colour( $surface ),
	);

	/**
	 * Filter the brand palette used on the front end.
	 *
	 * @param array $palette Colours, plus `is_dark`.
	 */
	return apply_filters( 'mailhouse_brand_palette', $palette );
}
