<?php
/**
 * Sample email template.
 *
 * Copy this file into your theme as `mailhouse/email.php` and it replaces the
 * built-in wrapper around every campaign. Leave it here and it does nothing --
 * the plugin only looks in the theme.
 *
 * Available:
 *
 * @var string $content HTML body, merge tags already resolved.
 * @var array  $args    subject, preview, subscriber, unsubscribe_url,
 *                      preferences_url, footer, address, accent, logo.
 *
 * Email clients are not browsers. Tables, inline styles, no flexbox, no
 * external assets, and nothing that needs JavaScript.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "https://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $args['subject'] ); ?></title>
</head>
<body style="margin:0;padding:0;background:#ffffff;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
		<tr>
			<td align="center" style="padding:24px 12px;">
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">
					<tr>
						<td style="padding:0 0 16px;font-family:Georgia,serif;font-size:18px;">
							<?php bloginfo( 'name' ); ?>
						</td>
					</tr>
					<tr>
						<td style="font-family:Georgia,serif;font-size:16px;line-height:1.6;color:#111111;">
							<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- campaign content, authored in the admin. ?>
						</td>
					</tr>
					<tr>
						<td style="padding:24px 0 0;font-family:Georgia,serif;font-size:13px;color:#666666;">
							<?php if ( ! empty( $args['address'] ) ) : ?>
								<p style="margin:0 0 8px;"><?php echo nl2br( esc_html( $args['address'] ) ); ?></p>
							<?php endif; ?>

							<?php if ( ! empty( $args['unsubscribe_url'] ) ) : ?>
								<p style="margin:0;">
									<a href="<?php echo esc_url( $args['unsubscribe_url'] ); ?>" style="color:#666666;">
										<?php esc_html_e( 'Unsubscribe', 'mailhouse' ); ?>
									</a>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
