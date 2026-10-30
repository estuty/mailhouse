<?php
/**
 * The popup a subscriber lands on after clicking a link in an email.
 *
 * Confirming used to end on a bare standalone page, which is a dead end: the
 * person has just proved they want to hear from you, and the reward is a page
 * with nothing on it. Sending them to the site itself with the message over
 * the top keeps them where there is something to read.
 *
 * It is a real element printed into the page, not something JavaScript builds.
 * Mail clients open links in all sorts of odd browsers, and a confirmation
 * nobody can see because a script did not run is worse than a plain page.
 * JavaScript only adds the niceties: dismissing without a reload, Escape,
 * and tidying the address bar so a refresh does not show it twice.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows a one-off message on the front end.
 */
class Mailhouse_Notice {

	/**
	 * The query argument carrying the message key.
	 */
	const QUERY = 'mailhouse_notice';

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_footer', array( $this, 'render' ), 20 );
	}

	/**
	 * Every message this can show.
	 *
	 * @return array<string,array{title:string,body:string,tone:string}>
	 */
	public static function messages() {
		$messages = array(
			'confirmed'    => array(
				'title' => __( 'You are on the list', 'mailhouse' ),
				'body'  => __( 'Your email address is confirmed. The next issue will arrive in your inbox.', 'mailhouse' ),
				'tone'  => 'success',
			),
			'already'      => array(
				'title' => __( 'You are already subscribed', 'mailhouse' ),
				'body'  => __( 'That link had been used before. Nothing more to do -- you are on the list.', 'mailhouse' ),
				'tone'  => 'success',
			),
			// By far the commonest reason for landing here is a second click
			// or a refresh, so the wording leads with the reassuring case
			// rather than announcing a failure at somebody who did nothing
			// wrong. It still has to be true for a genuinely old link.
			'expired'      => array(
				'title' => __( 'This link has already been used', 'mailhouse' ),
				'body'  => __( 'If you have just confirmed, you are on the list and there is nothing else to do. If you are still not receiving anything, sign up again.', 'mailhouse' ),
				'tone'  => 'neutral',
			),
			'unsubscribed' => array(
				'title' => __( 'You have been unsubscribed', 'mailhouse' ),
				'body'  => __( 'Nothing further will be sent. You can sign up again at any time.', 'mailhouse' ),
				'tone'  => 'neutral',
			),
		);

		/**
		 * Filter the messages shown after an email link is clicked.
		 *
		 * @param array $messages Key to title, body and tone.
		 */
		return apply_filters( 'mailhouse_notices', $messages );
	}

	/**
	 * Where to send somebody, with the message in tow.
	 *
	 * @param string $key Message key.
	 * @return string
	 */
	public static function url( $key ) {
		/**
		 * Filter where an email link lands.
		 *
		 * @param string $url The destination before the message is added.
		 * @param string $key Message key.
		 */
		$url = apply_filters( 'mailhouse_notice_destination', home_url( '/' ), $key );

		return add_query_arg( self::QUERY, $key, $url );
	}

	/**
	 * Send somebody to the destination and stop.
	 *
	 * @param string $key Message key.
	 * @return void
	 */
	public static function redirect( $key ) {
		nocache_headers();
		wp_safe_redirect( self::url( $key ) );
		exit;
	}

	/**
	 * The message key on this request, if any.
	 *
	 * @return string
	 */
	protected static function current() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a key from a link in an email, naming a message and nothing else.
		$key = isset( $_GET[ self::QUERY ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY ] ) ) : '';

		return array_key_exists( $key, self::messages() ) ? $key : '';
	}

	/**
	 * Load the popup's stylesheet and script, only on a request that shows one.
	 *
	 * @return void
	 */
	public function assets() {
		if ( ! self::current() ) {
			return;
		}

		wp_enqueue_style( 'mailhouse-notice', MAILHOUSE_URL . 'assets/public/notice.css', array(), MAILHOUSE_VERSION );
		wp_enqueue_script( 'mailhouse-notice', MAILHOUSE_URL . 'assets/public/notice.js', array(), MAILHOUSE_VERSION, true );
	}

	/**
	 * Print the popup.
	 *
	 * @return void
	 */
	public function render() {
		$key = self::current();

		if ( ! $key ) {
			return;
		}

		$messages = self::messages();
		$message  = $messages[ $key ];

		// The same colours the emails and the standalone pages use, so the
		// popup belongs to the site rather than looking like a plugin.
		// Following the reader's dark-mode preference instead would put a
		// white card on a dark site, which is the mismatch this is meant to
		// avoid.
		$brand = mailhouse_brand_palette();

		$tones = array(
			'success' => $brand['accent'],
			'warning' => '#b45309',
			'neutral' => mailhouse_shade_colour( $brand['ink'], 0.25 ),
		);

		$tone  = isset( $tones[ $message['tone'] ] ) ? $tones[ $message['tone'] ] : $brand['accent'];
		$close = remove_query_arg( self::QUERY );

		$style = sprintf(
			'--mailhouse-tone: %s; --mailhouse-on-tone: %s; --mailhouse-surface: %s; --mailhouse-ink: %s; --mailhouse-muted: %s; --mailhouse-edge: %s;',
			$tone,
			// The tone is not always the accent, so this one is still worked
			// out here: a pale warning colour needs dark text on it too.
			mailhouse_contrast_colour( $tone ),
			$brand['surface'],
			$brand['ink'],
			$brand['muted'],
			$brand['edge']
		);

		?>
		<div class="mailhouse-popup" id="mailhouse-popup" style="<?php echo esc_attr( $style ); ?>">
			<a class="mailhouse-popup__backdrop" href="<?php echo esc_url( $close ); ?>" aria-hidden="true" tabindex="-1"></a>

			<div class="mailhouse-popup__box" role="dialog" aria-modal="true" aria-labelledby="mailhouse-popup-title">
				<p class="mailhouse-popup__mark" aria-hidden="true">
					<?php echo 'success' === $message['tone'] ? '&#10003;' : '&#33;'; ?>
				</p>

				<h2 class="mailhouse-popup__title" id="mailhouse-popup-title"><?php echo esc_html( $message['title'] ); ?></h2>
				<p class="mailhouse-popup__body"><?php echo esc_html( $message['body'] ); ?></p>

				<a class="mailhouse-popup__button" href="<?php echo esc_url( $close ); ?>" data-mailhouse-close>
					<?php esc_html_e( 'Continue to the site', 'mailhouse' ); ?>
				</a>
			</div>
		</div>
		<?php
	}
}
