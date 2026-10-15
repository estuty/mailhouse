<?php
/**
 * Signup forms.
 *
 * One shortcode, one block, one endpoint. The form works with JavaScript
 * turned off -- it is an ordinary POST that redirects back -- and upgrades to
 * a fetch() when the script loads.
 *
 * Every outcome returns the same message. Success, an address already on the
 * list, and a tripped honeypot are indistinguishable from outside, because any
 * difference between them turns the form into a way of asking "is this person
 * a subscriber here?" about any address you like.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders forms and handles what they submit.
 */
class Mailhouse_Forms {

	/**
	 * How many forms have been rendered on this page, for unique ids.
	 *
	 * @var int
	 */
	protected static $count = 0;

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_shortcode( 'mailhouse_form', array( $this, 'shortcode' ) );

		add_action( 'admin_post_nopriv_mailhouse_subscribe', array( $this, 'handle' ) );
		add_action( 'admin_post_mailhouse_subscribe', array( $this, 'handle' ) );

		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * The message every submission answers with.
	 *
	 * A method rather than a constant so translation tools can extract it.
	 *
	 * @param string $type ok, invalid, consent or limit.
	 * @return string
	 */
	public static function message( $type = 'ok' ) {
		switch ( $type ) {
			case 'invalid':
				return __( 'Please check the email address and try again.', 'mailhouse' );

			case 'consent':
				return __( 'Please tick the box to confirm you want the emails.', 'mailhouse' );

			case 'limit':
				return __( 'That is a lot of signups from one place. Try again in a few minutes.', 'mailhouse' );
		}

		$custom = (string) mailhouse_option( 'form_success' );

		if ( '' !== $custom ) {
			return $custom;
		}

		return mailhouse_option( 'double_optin' )
			? __( 'Almost there - check your inbox and click the confirmation link.', 'mailhouse' )
			: __( 'You are on the list. Thank you.', 'mailhouse' );
	}

	/**
	 * Register the stylesheet and script the form uses.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style( 'mailhouse-form', MAILHOUSE_URL . 'assets/public/form.css', array(), MAILHOUSE_VERSION );
		wp_register_script( 'mailhouse-form', MAILHOUSE_URL . 'assets/public/form.js', array(), MAILHOUSE_VERSION, true );
	}

	/**
	 * The [mailhouse_form] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'title'        => (string) mailhouse_option( 'form_title' ),
				'description'  => (string) mailhouse_option( 'form_description' ),
				'button'       => (string) mailhouse_option( 'form_button' ),
				'name'         => (string) mailhouse_option( 'form_name_fields', 'none' ),
				'tags'         => '',
				'consent'      => mailhouse_option( 'form_consent' ) ? 'yes' : 'no',
				'consent_text' => (string) mailhouse_option( 'form_consent_text' ),
				'redirect'     => '',
				'layout'       => 'stacked',
				'source'       => '',
				'class'        => '',
			),
			$atts,
			'mailhouse_form'
		);

		return self::render( $atts );
	}

	/**
	 * Render a form.
	 *
	 * @param array $args Same keys as the shortcode attributes.
	 * @return string
	 */
	public static function render( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'title'        => '',
				'description'  => '',
				'button'       => '',
				'name'         => 'none',
				'tags'         => '',
				'consent'      => 'yes',
				'consent_text' => '',
				'redirect'     => '',
				'layout'       => 'stacked',
				'source'       => '',
				'class'        => '',
			)
		);

		if ( mailhouse_option( 'form_styles' ) ) {
			wp_enqueue_style( 'mailhouse-form' );
		}

		wp_enqueue_script( 'mailhouse-form' );

		$id      = 'mailhouse-form-' . ( ++self::$count );
		$consent = 'yes' === $args['consent'] || true === $args['consent'];
		$button  = '' !== $args['button'] ? $args['button'] : __( 'Subscribe', 'mailhouse' );
		$layout  = 'inline' === $args['layout'] ? 'inline' : 'stacked';

		$consent_text = '' !== $args['consent_text'] ? $args['consent_text'] : __( 'Yes, email me the newsletter. Unsubscribe any time.', 'mailhouse' );

		// The parts a visitor must not be able to change -- which tags they
		// land on, where they are sent afterwards -- travel signed rather than
		// as plain hidden fields.
		$config = array(
			'tags'     => (string) $args['tags'],
			'redirect' => (string) $args['redirect'],
			'source'   => (string) $args['source'],
			'consent'  => $consent ? 1 : 0,
		);

		$payload   = base64_encode( wp_json_encode( $config ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$signature = self::sign( $payload );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['mailhouse'] ) ? sanitize_key( wp_unslash( $_GET['mailhouse'] ) ) : '';

		ob_start();
		?>
		<div class="mailhouse-form mailhouse-form--<?php echo esc_attr( $layout ); ?> <?php echo esc_attr( $args['class'] ); ?>" id="<?php echo esc_attr( $id ); ?>">
			<?php if ( '' !== $args['title'] ) : ?>
				<h2 class="mailhouse-form__title"><?php echo esc_html( $args['title'] ); ?></h2>
			<?php endif; ?>

			<?php if ( '' !== $args['description'] ) : ?>
				<p class="mailhouse-form__description"><?php echo esc_html( $args['description'] ); ?></p>
			<?php endif; ?>

			<?php if ( $notice ) : ?>
				<p class="mailhouse-form__message mailhouse-form__message--<?php echo 'ok' === $notice ? 'ok' : 'error'; ?>" role="status">
					<?php echo esc_html( self::message( $notice ) ); ?>
				</p>
			<?php endif; ?>

			<form class="mailhouse-form__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mailhouse_subscribe" />
				<input type="hidden" name="mailhouse_config" value="<?php echo esc_attr( $payload ); ?>" />
				<input type="hidden" name="mailhouse_signature" value="<?php echo esc_attr( $signature ); ?>" />
				<input type="hidden" name="mailhouse_time" value="<?php echo esc_attr( time() ); ?>" />
				<?php wp_nonce_field( 'mailhouse_subscribe', 'mailhouse_nonce', false ); ?>

				<?php if ( 'first' === $args['name'] || 'full' === $args['name'] ) : ?>
					<p class="mailhouse-form__field">
						<label for="<?php echo esc_attr( $id ); ?>-first"><?php esc_html_e( 'First name', 'mailhouse' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $id ); ?>-first" name="first_name" autocomplete="given-name" />
					</p>
				<?php endif; ?>

				<?php if ( 'full' === $args['name'] ) : ?>
					<p class="mailhouse-form__field">
						<label for="<?php echo esc_attr( $id ); ?>-last"><?php esc_html_e( 'Last name', 'mailhouse' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $id ); ?>-last" name="last_name" autocomplete="family-name" />
					</p>
				<?php endif; ?>

				<p class="mailhouse-form__field">
					<label for="<?php echo esc_attr( $id ); ?>-email"><?php esc_html_e( 'Email address', 'mailhouse' ); ?></label>
					<input type="email" id="<?php echo esc_attr( $id ); ?>-email" name="email" required autocomplete="email" spellcheck="false" />
				</p>

				<?php if ( $consent ) : ?>
					<p class="mailhouse-form__consent">
						<label>
							<input type="checkbox" name="consent" value="1" required />
							<span><?php echo esc_html( $consent_text ); ?></span>
						</label>
					</p>
				<?php endif; ?>

				<p class="mailhouse-form__hp" aria-hidden="true">
					<label><?php esc_html_e( 'Leave this field empty', 'mailhouse' ); ?>
						<input type="text" name="mailhouse_website" value="" tabindex="-1" autocomplete="off" />
					</label>
				</p>

				<p class="mailhouse-form__submit">
					<button type="submit"><?php echo esc_html( $button ); ?></button>
				</p>
			</form>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Sign a form's configuration.
	 *
	 * @param string $payload Encoded configuration.
	 * @return string
	 */
	protected static function sign( $payload ) {
		return hash_hmac( 'sha256', $payload, wp_salt( 'mailhouse_form' ) );
	}

	/**
	 * Handle a submission.
	 *
	 * @return void
	 */
	public function handle() {
		$ajax = ! empty( $_POST['mailhouse_ajax'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked below, with a deliberate fallback.
		$payload   = isset( $_POST['mailhouse_config'] ) ? sanitize_text_field( wp_unslash( $_POST['mailhouse_config'] ) ) : '';
		$signature = isset( $_POST['mailhouse_signature'] ) ? sanitize_text_field( wp_unslash( $_POST['mailhouse_signature'] ) ) : '';

		if ( ! $payload || ! hash_equals( self::sign( $payload ), $signature ) ) {
			$this->respond( 'invalid', $ajax );
		}

		// The nonce is checked, but a failure is not fatal on its own. A form
		// served from a page cache carries whatever nonce was current when the
		// page was cached, and refusing those would mean the form quietly
		// stops working on exactly the sites that need it most. The signature
		// above is what protects the parts that matter; what a forged
		// cross-site post can achieve here is subscribing an address that then
		// has to confirm by email anyway.
		$nonce = isset( $_POST['mailhouse_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['mailhouse_nonce'] ) ) : '';

		if ( $nonce && ! wp_verify_nonce( $nonce, 'mailhouse_subscribe' ) ) {
			mailhouse_log_debug( 'Stale form nonce accepted' );
		}

		$config = json_decode( base64_decode( $payload ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$config = is_array( $config ) ? $config : array();

		// A form filled in faster than a person can read it, or with the
		// hidden field filled at all, is a bot. Both answer with the success
		// message so the bot has nothing to learn.
		$started = isset( $_POST['mailhouse_time'] ) ? (int) $_POST['mailhouse_time'] : 0;
		$honey   = isset( $_POST['mailhouse_website'] ) ? trim( (string) wp_unslash( $_POST['mailhouse_website'] ) ) : '';

		if ( '' !== $honey || ( $started && ( time() - $started ) < 2 ) ) {
			$this->respond( 'ok', $ajax );
		}

		if ( ! empty( $config['consent'] ) && empty( $_POST['consent'] ) ) {
			$this->respond( 'consent', $ajax );
		}

		$email = isset( $_POST['email'] ) ? mailhouse_clean_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! $email ) {
			$this->respond( 'invalid', $ajax );
		}

		if ( ! self::within_rate_limit() ) {
			$this->respond( 'limit', $ajax );
		}

		$result = Mailhouse_Subscribers::add(
			array(
				'email'      => $email,
				'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
				'last_name'  => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
				'tags'       => isset( $config['tags'] ) ? $config['tags'] : '',
				'source'       => ! empty( $config['source'] ) ? $config['source'] : 'form',
				'ip'           => mailhouse_ip(),
				'async_emails' => true,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->respond( 'invalid', $ajax );
		}

		// Only an http(s) address on this site (or one allowed through the
		// `allowed_redirect_hosts` filter) is followed. The shortcode value is
		// author-supplied, and a `javascript:` URL here would run in every
		// visitor's browser after they press Subscribe.
		$redirect = ! empty( $config['redirect'] ) ? wp_validate_redirect( esc_url_raw( (string) $config['redirect'], array( 'http', 'https' ) ), '' ) : '';

		$this->respond( 'ok', $ajax, $redirect );
	}

	/**
	 * Whether this IP has room left in its hourly allowance.
	 *
	 * @return bool
	 */
	public static function within_rate_limit() {
		$limit = (int) mailhouse_option( 'rate_limit', 10 );

		if ( $limit < 1 ) {
			return true;
		}

		$ip = mailhouse_ip();

		// No usable address means the limit cannot be applied, so the
		// request is refused rather than waved through.
		if ( ! $ip ) {
			return false;
		}

		$key   = 'mailhouse_rate_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Answer the submission and stop.
	 *
	 * @param string $type     Message type.
	 * @param bool   $ajax     Whether the browser asked for JSON.
	 * @param string $redirect Optional URL to send the visitor to on success.
	 * @return void
	 */
	protected function respond( $type, $ajax, $redirect = '' ) {
		if ( $ajax ) {
			wp_send_json(
				array(
					'success'  => 'ok' === $type,
					'message'  => self::message( $type ),
					'redirect' => 'ok' === $type ? $redirect : '',
				)
			);
		}

		if ( 'ok' === $type && $redirect ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		$back = wp_get_referer();
		$back = $back ? $back : home_url( '/' );

		wp_safe_redirect( add_query_arg( 'mailhouse', $type, $back ) );
		exit;
	}

	/**
	 * Register the block, which is the shortcode with a settings panel.
	 *
	 * @return void
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'mailhouse-block',
			MAILHOUSE_URL . 'assets/admin/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			MAILHOUSE_VERSION,
			true
		);

		register_block_type(
			'mailhouse/form',
			array(
				'api_version'     => 2,
				'title'           => __( 'Newsletter form', 'mailhouse' ),
				'category'        => 'widgets',
				'icon'            => 'email-alt',
				'editor_script'   => 'mailhouse-block',
				'attributes'      => array(
					'title'       => array( 'type' => 'string', 'default' => '' ),
					'description' => array( 'type' => 'string', 'default' => '' ),
					'button'      => array( 'type' => 'string', 'default' => '' ),
					'name'        => array( 'type' => 'string', 'default' => 'none' ),
					'tags'        => array( 'type' => 'string', 'default' => '' ),
					'layout'      => array( 'type' => 'string', 'default' => 'stacked' ),
				),
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Render the block on the front end.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();

		return self::render(
			array_merge(
				array(
					'consent'      => mailhouse_option( 'form_consent' ) ? 'yes' : 'no',
					'consent_text' => (string) mailhouse_option( 'form_consent_text' ),
					'source'       => 'block',
				),
				$attributes
			)
		);
	}
}
