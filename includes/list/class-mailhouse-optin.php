<?php
/**
 * Confirmation, unsubscribe and preference links.
 *
 * These are the pages a subscriber lands on from an email, so they have to
 * work for someone who is not logged in, on any theme, with no JavaScript, and
 * in an email client that mangles query strings. Hence real paths, a key in
 * the URL rather than a session, and a self-contained page that does not
 * depend on the theme having a template for it.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the links that arrive from an email.
 */
class Mailhouse_Optin {

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'init', array( __CLASS__, 'register_rewrites' ) );
		add_action( 'template_redirect', array( $this, 'handle' ), 1 );
	}

	/**
	 * Register the query vars the rewrites fill in.
	 *
	 * @param string[] $vars Existing vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = 'mailhouse_action';
		$vars[] = 'mailhouse_key';

		return $vars;
	}

	/**
	 * Add the pretty URLs.
	 *
	 * @return void
	 */
	public static function register_rewrites() {
		add_rewrite_rule(
			'^mailhouse/(confirm|unsubscribe|preferences)/([a-f0-9]{32})/?$',
			'index.php?mailhouse_action=$matches[1]&mailhouse_key=$matches[2]',
			'top'
		);
	}

	/**
	 * The link that confirms a subscription.
	 *
	 * @param object $subscriber Subscriber row.
	 * @return string
	 */
	public static function confirm_url( $subscriber ) {
		return self::url( 'confirm', $subscriber->confirm_key );
	}

	/**
	 * The link that removes somebody from the list.
	 *
	 * @param object $subscriber Subscriber row.
	 * @return string
	 */
	public static function unsubscribe_url( $subscriber ) {
		return self::url( 'unsubscribe', $subscriber->manage_key );
	}

	/**
	 * The link to the preferences page.
	 *
	 * @param object $subscriber Subscriber row.
	 * @return string
	 */
	public static function preferences_url( $subscriber ) {
		return self::url( 'preferences', $subscriber->manage_key );
	}

	/**
	 * Build one of the links.
	 *
	 * Falls back to a query string when the site has plain permalinks, so the
	 * links keep working on a site that never set them up.
	 *
	 * @param string $action confirm, unsubscribe or preferences.
	 * @param string $key    The subscriber's key.
	 * @return string
	 */
	public static function url( $action, $key ) {
		if ( ! $key ) {
			return home_url( '/' );
		}

		if ( get_option( 'permalink_structure' ) ) {
			return home_url( sprintf( '/mailhouse/%s/%s/', $action, $key ) );
		}

		return add_query_arg(
			array(
				'mailhouse_action' => $action,
				'mailhouse_key'    => $key,
			),
			home_url( '/' )
		);
	}

	/**
	 * Act on an incoming link.
	 *
	 * @return void
	 */
	public function handle() {
		$action = get_query_var( 'mailhouse_action' );
		$key    = get_query_var( 'mailhouse_key' );

		if ( ! $action && isset( $_GET['mailhouse_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_GET['mailhouse_action'] ) );
			$key    = isset( $_GET['mailhouse_key'] ) ? sanitize_text_field( wp_unslash( $_GET['mailhouse_key'] ) ) : '';
		}

		if ( ! in_array( $action, array( 'confirm', 'unsubscribe', 'preferences' ), true ) ) {
			return;
		}

		nocache_headers();

		switch ( $action ) {
			case 'confirm':
				$this->handle_confirm( $key );
				break;

			case 'unsubscribe':
				$this->handle_unsubscribe( $key );
				break;

			case 'preferences':
				$this->handle_preferences( $key );
				break;
		}
	}

	/**
	 * Confirm a pending subscription.
	 *
	 * A key that has already been used is not an error worth showing: the
	 * usual cause is a mail client prefetching the link, or the person
	 * clicking twice.
	 *
	 * @param string $key Confirmation key.
	 * @return void
	 */
	protected function handle_confirm( $key ) {
		$subscriber = Mailhouse_Subscribers::get_by_key( 'confirm_key', $key );
		$to_site    = 'page' !== mailhouse_option( 'confirm_destination', 'home' );

		if ( ! $subscriber ) {
			// A key that no longer resolves is usually a second click or a
			// mail scanner following the link, not an error worth a dead end.
			if ( $to_site ) {
				Mailhouse_Notice::redirect( 'expired' );
			}

			self::render(
				__( 'This link has expired', 'mailhouse' ),
				'<p>' . esc_html__( 'It may already have been used. If you are still not receiving anything, sign up again.', 'mailhouse' ) . '</p>',
				410
			);
		}

		// False when they had already confirmed, which is worth saying
		// differently: nothing was wrong and nothing needed doing.
		$was_pending = Mailhouse_Subscribers::confirm( $subscriber );

		$redirect = apply_filters( 'mailhouse_confirm_redirect', '', $subscriber );

		if ( $redirect ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( $to_site ) {
			Mailhouse_Notice::redirect( $was_pending ? 'confirmed' : 'already' );
		}

		self::render(
			__( 'You are on the list', 'mailhouse' ),
			'<p>' . esc_html__( 'Your email address is confirmed. The next issue will arrive in your inbox.', 'mailhouse' ) . '</p>' .
			'<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to the site', 'mailhouse' ) . '</a></p>'
		);
	}

	/**
	 * Remove somebody from the list.
	 *
	 * @param string $key Management key.
	 * @return void
	 */
	protected function handle_unsubscribe( $key ) {
		$subscriber = Mailhouse_Subscribers::get_by_key( 'manage_key', $key );

		// RFC 8058: the mail client POSTs here when the reader uses the
		// unsubscribe button the inbox itself renders. No page, no
		// confirmation step, just do it.
		$one_click = 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' );

		if ( ! $subscriber ) {
			if ( $one_click ) {
				status_header( 200 );
				exit;
			}

			self::render(
				__( 'Link not recognised', 'mailhouse' ),
				'<p>' . esc_html__( 'This unsubscribe link is no longer valid. You may already have been removed.', 'mailhouse' ) . '</p>',
				410
			);
		}

		if ( $one_click ) {
			Mailhouse_Subscribers::unsubscribe( $subscriber );
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo "Unsubscribed\n";
			exit;
		}

		// A single GET click is enough unless the site asked for a confirm
		// step. Some corporate mail scanners follow every link in a message,
		// which is the argument for the extra click.
		if ( mailhouse_option( 'unsubscribe_confirm' ) && empty( $_GET['confirm'] ) ) {
			$url = add_query_arg( 'confirm', '1', self::unsubscribe_url( $subscriber ) );

			self::render(
				__( 'Unsubscribe?', 'mailhouse' ),
				'<p>' . sprintf(
					/* translators: %s: email address. */
					esc_html__( 'Stop sending the newsletter to %s?', 'mailhouse' ),
					'<strong>' . esc_html( $subscriber->email ) . '</strong>'
				) . '</p>' .
				'<p><a class="mailhouse-button" href="' . esc_url( $url ) . '">' . esc_html__( 'Yes, unsubscribe me', 'mailhouse' ) . '</a></p>'
			);
		}

		Mailhouse_Subscribers::unsubscribe( $subscriber );

		self::render(
			__( 'You have been unsubscribed', 'mailhouse' ),
			'<p>' . sprintf(
				/* translators: %s: email address. */
				esc_html__( '%s has been removed from the list. Nothing further will be sent.', 'mailhouse' ),
				'<strong>' . esc_html( $subscriber->email ) . '</strong>'
			) . '</p>' .
			'<p><a href="' . esc_url( self::preferences_url( $subscriber ) ) . '">' . esc_html__( 'Changed your mind?', 'mailhouse' ) . '</a></p>'
		);
	}

	/**
	 * Show and save the preferences page.
	 *
	 * @param string $key Management key.
	 * @return void
	 */
	protected function handle_preferences( $key ) {
		$subscriber = Mailhouse_Subscribers::get_by_key( 'manage_key', $key );

		if ( ! $subscriber ) {
			self::render(
				__( 'Link not recognised', 'mailhouse' ),
				'<p>' . esc_html__( 'This link is no longer valid.', 'mailhouse' ) . '</p>',
				410
			);
		}

		$saved = false;

		if ( isset( $_POST['mailhouse_preferences'] ) && check_admin_referer( 'mailhouse_preferences_' . $subscriber->id ) ) {
			$first = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
			$last  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';

			Mailhouse_Subscribers::update(
				(int) $subscriber->id,
				array(
					'first_name' => $first,
					'last_name'  => $last,
				)
			);

			$tags     = isset( $_POST['tags'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['tags'] ) ) : array();
			$public   = self::public_tags();
			$allowed  = array_intersect( $tags, wp_list_pluck( $public, 'id' ) );
			$existing = wp_list_pluck( Mailhouse_Tags::for_subscriber( (int) $subscriber->id ), 'id' );
			$private  = array_diff( $existing, wp_list_pluck( $public, 'id' ) );

			Mailhouse_Tags::set( (int) $subscriber->id, array_merge( $private, $allowed ) );

			if ( 'unsubscribed' === $subscriber->status && ! empty( $_POST['resubscribe'] ) ) {
				Mailhouse_Subscribers::update(
					(int) $subscriber->id,
					array(
						'status'       => 'subscribed',
						'confirmed_at' => mailhouse_now(),
						// Spend the confirmation key here too. Left alive on a
						// subscribed row, the link in the original signup
						// email stays replayable -- and replaying it after a
						// later unsubscribe would put them back on the list.
						'confirm_key'  => null,
					)
				);
			}

			$subscriber = Mailhouse_Subscribers::get( (int) $subscriber->id );
			$saved      = true;
		}

		self::render( __( 'Your preferences', 'mailhouse' ), self::preferences_form( $subscriber, $saved ) );
	}

	/**
	 * The preferences form.
	 *
	 * @param object $subscriber Subscriber row.
	 * @param bool   $saved      Whether a save just happened.
	 * @return string
	 */
	protected static function preferences_form( $subscriber, $saved ) {
		$tags     = self::public_tags();
		$current  = wp_list_pluck( Mailhouse_Tags::for_subscriber( (int) $subscriber->id ), 'id' );
		$current  = array_map( 'intval', $current );
		$html     = '';

		if ( $saved ) {
			$html .= '<p class="mailhouse-notice">' . esc_html__( 'Saved.', 'mailhouse' ) . '</p>';
		}

		$html .= '<p>' . sprintf(
			/* translators: %s: email address. */
			esc_html__( 'Settings for %s.', 'mailhouse' ),
			'<strong>' . esc_html( $subscriber->email ) . '</strong>'
		) . '</p>';

		$html .= '<form method="post">';
		$html .= wp_nonce_field( 'mailhouse_preferences_' . $subscriber->id, '_wpnonce', true, false );
		$html .= '<input type="hidden" name="mailhouse_preferences" value="1" />';

		$html .= '<p><label for="mailhouse-first">' . esc_html__( 'First name', 'mailhouse' ) . '</label>';
		$html .= '<input type="text" id="mailhouse-first" name="first_name" value="' . esc_attr( $subscriber->first_name ) . '" /></p>';

		$html .= '<p><label for="mailhouse-last">' . esc_html__( 'Last name', 'mailhouse' ) . '</label>';
		$html .= '<input type="text" id="mailhouse-last" name="last_name" value="' . esc_attr( $subscriber->last_name ) . '" /></p>';

		if ( $tags ) {
			$html .= '<fieldset><legend>' . esc_html__( 'What you want to hear about', 'mailhouse' ) . '</legend>';

			foreach ( $tags as $tag ) {
				$html .= sprintf(
					'<p><label><input type="checkbox" name="tags[]" value="%d"%s /> %s</label></p>',
					(int) $tag->id,
					in_array( (int) $tag->id, $current, true ) ? ' checked="checked"' : '',
					esc_html( $tag->name )
				);
			}

			$html .= '</fieldset>';
		}

		if ( 'unsubscribed' === $subscriber->status ) {
			$html .= '<p><label><input type="checkbox" name="resubscribe" value="1" /> ' . esc_html__( 'Put me back on the list', 'mailhouse' ) . '</label></p>';
		}

		$html .= '<p><button type="submit" class="mailhouse-button">' . esc_html__( 'Save', 'mailhouse' ) . '</button></p>';
		$html .= '</form>';

		if ( 'unsubscribed' !== $subscriber->status ) {
			$html .= '<p><a href="' . esc_url( self::unsubscribe_url( $subscriber ) ) . '">' . esc_html__( 'Unsubscribe from everything', 'mailhouse' ) . '</a></p>';
		}

		return $html;
	}

	/**
	 * Tags a subscriber is allowed to choose for themselves.
	 *
	 * Everything is offered by default; a site with internal-only tags can
	 * filter them out.
	 *
	 * @return object[]
	 */
	protected static function public_tags() {
		/**
		 * Filter the tags shown on the preferences page.
		 *
		 * @param object[] $tags Tags.
		 */
		return (array) apply_filters( 'mailhouse_public_tags', Mailhouse_Tags::all() );
	}

	/**
	 * Print a standalone page and stop.
	 *
	 * A theme can take this over by providing mailhouse/message.php, but the
	 * built-in page needs no theme support at all -- these links have to work
	 * on the day somebody switches themes.
	 *
	 * @param string $title  Page title.
	 * @param string $body   Page body, already escaped.
	 * @param int    $status HTTP status.
	 * @return void
	 */
	public static function render( $title, $body, $status = 200 ) {
		status_header( $status );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$template = locate_template( 'mailhouse/message.php' );

		if ( $template ) {
			include $template;
			exit;
		}

		echo self::page_html( $title, $body ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled below, and the caller escapes the body.

		exit;
	}

	/**
	 * The built-in standalone page's markup.
	 *
	 * Separate from render() so a test can read it back without the exit.
	 *
	 * These pages take the same colours as the emails and the confirmation
	 * popup. Until 1.5.0 they took only the accent and were otherwise white,
	 * which on a site with a dark design meant a dark email, a dark popup and
	 * then a white page -- the one branded surface that never caught up when
	 * the message background and text colours arrived.
	 *
	 * @param string $title Page title.
	 * @param string $body  Page body, already escaped.
	 * @return string
	 */
	public static function page_html( $title, $body ) {
		$brand = mailhouse_brand_palette();

		$vars = sprintf(
			'--mh-accent: %s; --mh-on-accent: %s; --mh-surface: %s; --mh-ink: %s; --mh-muted: %s; --mh-edge: %s; --mh-field: %s;',
			$brand['accent'],
			$brand['on_accent'],
			$brand['surface'],
			$brand['ink'],
			$brand['muted'],
			$brand['edge'],
			$brand['field']
		);

		// A design the site has set to dark stays dark: asking the browser for
		// `light dark` there would let a reader who prefers light override a
		// decision that is not theirs to make, and the page would come back
		// white. A site still on the light default keeps following the reader,
		// which is what it has always done.
		$scheme = $brand['is_dark'] ? 'dark' : 'light dark';

		$dark = $brand['is_dark'] ? '' : '
		@media (prefers-color-scheme: dark) {
			:root { --mh-surface: #16181c; --mh-ink: #e8e8e8; --mh-muted: #a8acb3; --mh-edge: #3a3f47; --mh-field: #22252b; }
		}';

		ob_start();

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php echo esc_html( $title . ' - ' . get_bloginfo( 'name' ) ); ?></title>
	<style>
		:root { color-scheme: <?php echo esc_html( $scheme ); ?>; <?php echo esc_html( $vars ); ?> }
		body { margin: 0; padding: 3rem 1.25rem; font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: var(--mh-surface); color: var(--mh-ink); }
		main { max-width: 34rem; margin: 0 auto; }
		h1 { font-size: 1.6rem; line-height: 1.25; margin: 0 0 1rem; }
		p, fieldset { margin: 0 0 1rem; }
		fieldset { border: 1px solid var(--mh-edge); border-radius: 6px; padding: 1rem 1rem 0.25rem; }
		legend { padding: 0 0.4rem; font-weight: 600; }
		label { display: block; margin-bottom: 0.25rem; }
		input[type="text"] { width: 100%; padding: 0.6rem; font-size: 1rem; border: 1px solid var(--mh-edge); border-radius: 6px; background: var(--mh-field); color: inherit; }
		input[type="checkbox"] { margin-right: 0.4rem; }
		.mailhouse-button { display: inline-block; padding: 0.7rem 1.2rem; border: 0; border-radius: 6px; background: var(--mh-accent); color: var(--mh-on-accent); font-size: 1rem; text-decoration: none; cursor: pointer; }
		.mailhouse-notice { padding: 0.75rem 1rem; border-radius: 6px; border-left: 3px solid var(--mh-accent); background: var(--mh-field); }
		.mailhouse-site { display: block; margin-bottom: 2rem; font-weight: 600; text-decoration: none; color: var(--mh-muted); }
		a { color: var(--mh-accent); }<?php echo $dark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a literal stylesheet fragment, no input in it. ?>

	</style>
</head>
<body>
	<main>
		<a class="mailhouse-site" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
		<h1><?php echo esc_html( $title ); ?></h1>
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callers escape. ?>
	</main>
</body>
</html>
		<?php

		return (string) ob_get_clean();
	}
}
