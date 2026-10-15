<?php
/**
 * The HTML wrapper every campaign is sent inside.
 *
 * Email clients are not browsers. Outlook renders with Word, Gmail strips
 * anything it does not like, and a dark-mode inbox will happily invert colours
 * underneath you. So: one table, inline styles, no flexbox, no external
 * assets, and a plain-text alternative generated from the same content.
 *
 * A theme can replace the whole thing with mailhouse/email.php.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wraps campaign content in a sendable HTML document.
 */
class Mailhouse_Template {

	/**
	 * Wrap content.
	 *
	 * @param string $content HTML body content.
	 * @param array  $args    subject, preview, subscriber, unsubscribe_url, preferences_url, footer.
	 * @return string
	 */
	public static function wrap( $content, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'subject'         => '',
				'preview'         => '',
				'subscriber'      => null,
				'unsubscribe_url' => '',
				'preferences_url' => '',
				'footer'          => (string) mailhouse_option( 'template_footer' ),
				'address'         => (string) mailhouse_option( 'company_address' ),
				'accent'          => (string) mailhouse_option( 'template_accent', '#2b6cb0' ),
				'logo'            => (string) mailhouse_option( 'template_logo' ),
				'logo_width'      => (int) mailhouse_option( 'template_logo_width', 180 ),
				'header_bg'       => (string) mailhouse_option( 'template_header_bg', '#ffffff' ),
				'background'      => (string) mailhouse_option( 'template_bg', '#f3f4f6' ),
				'social'          => self::social_links(),
				'transactional'   => false,
			)
		);

		$template = locate_template( 'mailhouse/email.php' );

		if ( $template ) {
			ob_start();
			include $template;
			return (string) ob_get_clean();
		}

		$site       = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$accent     = self::colour( $args['accent'], '#2b6cb0' );
		$header_bg  = self::colour( $args['header_bg'], '#ffffff' );
		$background = self::colour( $args['background'], '#f3f4f6' );
		$header_ink = mailhouse_contrast_colour( $header_bg );
		$logo_width = min( 560, max( 40, (int) $args['logo_width'] ) );
		$font       = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

		$header = $args['logo']
			? sprintf(
				'<a href="%s" style="text-decoration:none;"><img src="%s" alt="%s" width="%d" style="display:block;margin:0 auto;border:0;width:%dpx;max-width:100%%;height:auto;" /></a>',
				esc_url( home_url( '/' ) ),
				esc_url( $args['logo'] ),
				esc_attr( $site ),
				$logo_width,
				$logo_width
			)
			: sprintf(
				'<a href="%s" style="color:%s;font-family:%s;font-size:20px;font-weight:700;text-decoration:none;">%s</a>',
				esc_url( home_url( '/' ) ),
				esc_attr( $header_ink ),
				esc_attr( $font ),
				esc_html( $site )
			);

		$links = array();

		if ( $args['preferences_url'] ) {
			$links[] = sprintf(
				'<a href="%s" style="color:#6b7280;">%s</a>',
				esc_url( $args['preferences_url'] ),
				esc_html__( 'Update your preferences', 'mailhouse' )
			);
		}

		if ( $args['unsubscribe_url'] ) {
			$links[] = sprintf(
				'<a href="%s" style="color:#6b7280;">%s</a>',
				esc_url( $args['unsubscribe_url'] ),
				esc_html__( 'Unsubscribe', 'mailhouse' )
			);
		}

		$footer_parts = array();

		if ( $args['social'] ) {
			$social = array();

			foreach ( $args['social'] as $network => $url ) {
				$social[] = sprintf(
					'<a href="%s" style="color:%s;font-weight:600;text-decoration:none;">%s</a>',
					esc_url( $url ),
					esc_attr( $accent ),
					esc_html( $network )
				);
			}

			$footer_parts[] = '<p class="mh-social" style="margin:0 0 12px;">' . implode( ' &nbsp;&middot;&nbsp; ', $social ) . '</p>';
		}

		if ( '' !== trim( (string) $args['footer'] ) ) {
			$footer_parts[] = wp_kses_post( wpautop( $args['footer'] ) );
		}

		if ( '' !== trim( (string) $args['address'] ) ) {
			$footer_parts[] = '<p style="margin:0 0 8px;">' . nl2br( esc_html( $args['address'] ) ) . '</p>';
		}

		if ( $links ) {
			$footer_parts[] = '<p style="margin:0;">' . implode( ' &nbsp;&middot;&nbsp; ', $links ) . '</p>';
		}

		$footer_parts[] = sprintf(
			'<p style="margin:12px 0 0;">&copy; %s <a href="%s" style="color:#6b7280;">%s</a></p>',
			esc_html( wp_date( 'Y' ) ),
			esc_url( home_url( '/' ) ),
			esc_html( $site )
		);

		// A preheader is the grey line the inbox shows after the subject. Left
		// empty, clients pull the first words of the body instead, which is
		// usually "View this email in your browser".
		$preview = '' !== $args['preview']
			? sprintf(
				'<div style="display:none;font-size:1px;color:%s;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">%s%s</div>',
				esc_attr( $background ),
				esc_html( $args['preview'] ),
				str_repeat( '&#847;&zwnj;&nbsp;', 30 )
			)
			: '';

		ob_start();
		?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "https://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="x-apple-disable-message-reformatting" />
	<meta name="color-scheme" content="light dark" />
	<meta name="supported-color-schemes" content="light dark" />
	<title><?php echo esc_html( $args['subject'] ); ?></title>
	<style type="text/css">
		body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; }
		img { border: 0; line-height: 100%; outline: none; text-decoration: none; }
		a { color: <?php echo esc_html( $accent ); ?>; }
		.mh-content h1, .mh-content h2, .mh-content h3 { line-height: 1.25; margin: 0 0 12px; color: #111827; }
		.mh-content p { margin: 0 0 16px; }
		.mh-content img { max-width: 100%; height: auto; }
		.mh-content blockquote { margin: 0 0 16px; padding: 0 0 0 16px; border-left: 3px solid #e5e7eb; color: #4b5563; }
		.mh-content pre, .mh-content code { font-family: Menlo, Consolas, monospace; font-size: 14px; background: #f3f4f6; border-radius: 4px; }
		.mh-content pre { padding: 12px; white-space: pre-wrap; word-break: break-word; }
		@media only screen and (max-width: 620px) {
			.mh-wrap { width: 100% !important; }
			.mh-pad { padding-left: 20px !important; padding-right: 20px !important; }
		}
		@media (prefers-color-scheme: dark) {
			.mh-body, .mh-outer { background-color: #111827 !important; }
			.mh-card { background-color: #1f2937 !important; }
			.mh-content, .mh-content h1, .mh-content h2, .mh-content h3 { color: #f3f4f6 !important; }
			.mh-foot, .mh-foot a { color: #9ca3af !important; }
			.mh-content a:not(.mh-button) { color: #93c5fd !important; }
		}
	</style>
</head>
<body class="mh-body" style="margin:0;padding:0;background-color:<?php echo esc_attr( $background ); ?>;">
	<?php echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
	<table role="presentation" class="mh-outer" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:<?php echo esc_attr( $background ); ?>;">
		<tr>
			<td align="center" style="padding:24px 12px;">
				<table role="presentation" class="mh-wrap mh-card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background-color:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb;">
					<tr>
						<td class="mh-pad" align="center" bgcolor="<?php echo esc_attr( $header_bg ); ?>" style="padding:24px 32px;background-color:<?php echo esc_attr( $header_bg ); ?>;border-bottom:3px solid <?php echo esc_attr( $accent ); ?>;">
							<?php echo $header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
						</td>
					</tr>
					<tr>
						<td class="mh-pad mh-content" style="padding:28px 32px;font-family:<?php echo esc_attr( $font ); ?>;font-size:16px;line-height:1.6;color:#1f2937;">
							<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- campaign content, authored in the admin; merge tags escaped. ?>
						</td>
					</tr>
				</table>

				<table role="presentation" class="mh-wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">
					<tr>
						<td class="mh-pad mh-foot" style="padding:20px 32px;font-family:<?php echo esc_attr( $font ); ?>;font-size:13px;line-height:1.6;color:#6b7280;text-align:center;">
							<?php echo implode( '', $footer_parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
		<?php

		$html = (string) ob_get_clean();

		/**
		 * Filter the finished HTML email.
		 *
		 * @param string $html Full document.
		 * @param array  $args Wrapper arguments.
		 */
		return apply_filters( 'mailhouse_email_html', $html, $args );
	}

	/**
	 * Wrap a message some other plugin (or WordPress itself) sent.
	 *
	 * Plain text is turned into HTML first: escaped, links made clickable,
	 * paragraphs kept. No unsubscribe link -- a password reset is not a
	 * newsletter, and the recipient may not be a subscriber at all.
	 *
	 * @param string $body    Message body.
	 * @param bool   $is_html Whether the body is HTML already.
	 * @param string $subject Subject.
	 * @return string
	 */
	public static function wrap_site_email( $body, $is_html, $subject ) {
		$content = $is_html ? (string) $body : self::text_to_html( (string) $body );

		return self::wrap(
			$content,
			array(
				'subject'       => $subject,
				'transactional' => true,
			)
		);
	}

	/**
	 * Plain text as safe HTML: escaped, links clickable, paragraphs kept.
	 *
	 * WordPress writes links in plain-text mail as `<https://...>`; the angle
	 * brackets are dropped so the link is not swallowed by them.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function text_to_html( $text ) {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text  = (string) preg_replace( '#<(https?://[^>\s]+)>#i', '$1', $text );
		$parts = preg_split( '#(https?://[^\s<>"\']+)#i', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$html  = '';

		foreach ( (array) $parts as $index => $part ) {
			if ( 1 === $index % 2 ) {
				// Trailing punctuation belongs to the sentence, not the link.
				$trail = '';

				while ( '' !== $part && false !== strpos( '.,;:!?)', substr( $part, -1 ) ) ) {
					$trail = substr( $part, -1 ) . $trail;
					$part  = substr( $part, 0, -1 );
				}

				$html .= sprintf( '<a href="%s">%s</a>%s', esc_url( $part ), esc_html( $part ), esc_html( $trail ) );
			} else {
				$html .= esc_html( $part );
			}
		}

		return wpautop( $html );
	}

	/**
	 * The social profile links that are filled in, keyed by network name.
	 *
	 * @return array<string,string>
	 */
	public static function social_links() {
		$networks = array(
			'social_facebook'  => 'Facebook',
			'social_x'         => 'X',
			'social_instagram' => 'Instagram',
			'social_linkedin'  => 'LinkedIn',
			'social_youtube'   => 'YouTube',
			'social_tiktok'    => 'TikTok',
		);

		$out = array();

		foreach ( $networks as $key => $label ) {
			$url = esc_url_raw( (string) mailhouse_option( $key, '' ), array( 'http', 'https' ) );

			if ( '' !== $url ) {
				$out[ $label ] = $url;
			}
		}

		return $out;
	}

	/**
	 * A hex colour, or the fallback when it is not one.
	 *
	 * @param string $value    Candidate.
	 * @param string $fallback Default.
	 * @return string
	 */
	protected static function colour( $value, $fallback ) {
		$value = sanitize_hex_color( (string) $value );

		return $value ? $value : $fallback;
	}
}
