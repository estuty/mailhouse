<?php
/**
 * Delivery: everything that happens between wp_mail() and the wire.
 *
 * WordPress hands mail to PHP's mail() by default, which on most hosts either
 * fails outright or arrives in spam because nothing about the message is
 * authenticated. This module swaps in a real transport -- an SMTP server or a
 * provider's API -- without any caller having to know.
 *
 * The interception point is deliberate. Rather than short-circuiting wp_mail()
 * and re-implementing MIME assembly, the plugin substitutes a PHPMailer
 * subclass. WordPress then builds the message exactly as it always does --
 * every filter other plugins rely on still runs -- and only the final hand-off
 * is ours. That is also why `wp_mail_succeeded` and `wp_mail_failed` keep
 * firing normally, which is what the log listens to.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes wp_mail() through the configured transport.
 */
class Mailhouse_Mailer {

	/**
	 * Provider instances, keyed by id.
	 *
	 * @var array<string,Mailhouse_Provider>
	 */
	protected static $providers = null;

	/**
	 * The From address of the message most recently prepared.
	 *
	 * @var string
	 */
	protected static $last_from = '';

	/**
	 * Which kind of mail is being sent: `site` or `campaign`.
	 *
	 * Set by send() for everything the newsletter side sends, so campaigns
	 * can go through their own transport and keep their reputation apart
	 * from password resets and receipts.
	 *
	 * @var string
	 */
	protected static $stream = 'site';

	/**
	 * Whether the message being prepared came from Mailhouse itself, and is
	 * therefore already wrapped in the template.
	 *
	 * @var bool
	 */
	protected static $own = false;

	/**
	 * The stream currently being sent.
	 *
	 * @return string
	 */
	public static function stream() {
		return self::$stream;
	}

	/**
	 * The From address the last message actually used.
	 *
	 * @return string
	 */
	public static function last_from() {
		return self::$last_from;
	}

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_filter( 'pre_wp_mail', array( $this, 'install_phpmailer' ), 5, 2 );
		add_action( 'phpmailer_init', array( $this, 'configure' ), 20 );

		// wp_mail() sets the sender before `phpmailer_init` fires, and
		// PHPMailer rejects an address it considers invalid there and then --
		// which is what happens on any host whose name has no dot in it, and
		// on plenty of local installs. Correcting the address through these
		// filters means the message never reaches that point.
		add_filter( 'wp_mail_from', array( $this, 'filter_from' ), 20 );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), 20 );
	}

	/**
	 * Replace the sender WordPress invented.
	 *
	 * Only applies when no caller set a From header, which is exactly when
	 * WordPress falls back to `wordpress@<host>`.
	 *
	 * @param string $email Address WordPress is about to use.
	 * @return string
	 */
	public function filter_from( $email ) {
		$configured = mailhouse_clean_email( mailhouse_option( 'from_email' ) );

		if ( ! $configured ) {
			return $email;
		}

		if ( mailhouse_option( 'force_from_email' ) || strtolower( (string) $email ) === self::wordpress_default_from() ) {
			return $configured;
		}

		return $email;
	}

	/**
	 * Replace the sender name WordPress invented.
	 *
	 * @param string $name Name WordPress is about to use.
	 * @return string
	 */
	public function filter_from_name( $name ) {
		$configured = (string) mailhouse_option( 'from_name' );

		if ( '' === $configured ) {
			return $name;
		}

		if ( mailhouse_option( 'force_from_name' ) || 'WordPress' === $name || '' === (string) $name ) {
			return $configured;
		}

		return $name;
	}

	/**
	 * Put our PHPMailer subclass in place of the global one.
	 *
	 * Returning null lets wp_mail() carry on as normal; it will pick up the
	 * global we just set instead of creating a stock PHPMailer.
	 *
	 * @param null|bool $short_circuit Whatever an earlier filter decided.
	 * @param array     $atts          wp_mail() arguments.
	 * @return null|bool
	 */
	public function install_phpmailer( $short_circuit, $atts ) {
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}

		global $phpmailer;

		if ( $phpmailer instanceof Mailhouse_PHPMailer ) {
			return null;
		}

		if ( ! class_exists( 'PHPMailer\\PHPMailer\\PHPMailer' ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}

		// True: exceptions on, the same way wp_mail() constructs it. wp_mail()
		// catches them and turns them into `wp_mail_failed`.
		$phpmailer = new Mailhouse_PHPMailer( true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return null;
	}

	/**
	 * Apply the sender rules and hand the transport its configuration.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail The message, fully built by wp_mail().
	 * @return void
	 */
	public function configure( $mail ) {
		$mail->CharSet = 'UTF-8';

		$this->apply_from( $mail );
		$this->apply_reply_to( $mail );

		if ( ! self::$own ) {
			$this->apply_template( $mail );
		}

		// Remembered so the log can record the address the message actually
		// went out as, rather than the one in the settings -- they differ
		// whenever a plugin sets its own sender and forcing is off.
		self::$last_from = (string) $mail->From;

		$provider = self::provider();

		if ( $provider ) {
			$provider->configure( $mail );
		}

		if ( mailhouse_option( 'return_path' ) && is_email( $mail->From ) ) {
			// Bounces come back to the sending address rather than to the web
			// server's system user, where nobody would ever read them.
			$mail->Sender = $mail->From;
		}

		/**
		 * Last word on the outgoing message.
		 *
		 * @param PHPMailer\PHPMailer\PHPMailer $mail     Message.
		 * @param Mailhouse_Provider|null       $provider Transport.
		 */
		do_action( 'mailhouse_phpmailer', $mail, $provider );
	}

	/**
	 * Put the branded header and footer around mail from everyone else.
	 *
	 * WordPress's own messages (password resets, new users, comment
	 * notices) and other plugins' plain or simple HTML mail all get the
	 * same look as the newsletter. A message that is already a complete
	 * HTML document -- a shop receipt with its own design, say -- is left
	 * as it is.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return void
	 */
	protected function apply_template( $mail ) {
		if ( ! mailhouse_option( 'template_wrap_all' ) ) {
			return;
		}

		$body    = (string) $mail->Body;
		$is_html = 'text/html' === $mail->ContentType;

		if ( '' === trim( $body ) || ( $is_html && preg_match( '/<(?:html|body)[\s>]/i', $body ) ) ) {
			return;
		}

		if ( ! in_array( $mail->ContentType, array( 'text/plain', 'text/html' ), true ) ) {
			return;
		}

		/**
		 * Whether to wrap this message in the email template.
		 *
		 * @param bool                          $wrap Default true.
		 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
		 */
		if ( ! apply_filters( 'mailhouse_wrap_email', true, $mail ) ) {
			return;
		}

		$wrapped = Mailhouse_Template::wrap_site_email( $body, $is_html, (string) $mail->Subject );

		if ( '' === (string) $mail->AltBody ) {
			$mail->AltBody = $is_html ? mailhouse_html_to_text( $body ) : $body;
		}

		$mail->isHTML( true );
		$mail->Body = $wrapped;
	}

	/**
	 * Force the From address and name when the settings say to.
	 *
	 * A caller that set its own From is left alone unless forcing is on --
	 * except when it is the address WordPress invents, `wordpress@example.com`,
	 * which is nobody's mailbox and fails SPF at most providers.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return void
	 */
	protected function apply_from( $mail ) {
		$from_email = mailhouse_clean_email( mailhouse_option( 'from_email' ) );
		$from_name  = (string) mailhouse_option( 'from_name' );

		$is_default = ( '' === (string) $mail->From ) || strtolower( $mail->From ) === self::wordpress_default_from();

		if ( $from_email && ( mailhouse_option( 'force_from_email' ) || $is_default ) ) {
			try {
				$mail->setFrom( $from_email, $mail->FromName, false );
			} catch ( \Exception $e ) {
				mailhouse_log_debug( 'Could not set the From address', $e->getMessage() );
			}
		}

		if ( '' !== $from_name && ( mailhouse_option( 'force_from_name' ) || '' === (string) $mail->FromName || 'WordPress' === $mail->FromName ) ) {
			$mail->FromName = $from_name;
		}
	}

	/**
	 * Add the default Reply-To, if one is configured and none was set.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return void
	 */
	protected function apply_reply_to( $mail ) {
		$reply_to = mailhouse_clean_email( mailhouse_option( 'reply_to' ) );

		if ( ! $reply_to || $mail->getReplyToAddresses() ) {
			return;
		}

		try {
			$mail->addReplyTo( $reply_to );
		} catch ( \Exception $e ) {
			mailhouse_log_debug( 'Could not set Reply-To', $e->getMessage() );
		}
	}

	/**
	 * The address WordPress uses when nobody supplied one.
	 *
	 * @return string
	 */
	public static function wordpress_default_from() {
		$sitename = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );

		if ( 0 === strpos( $sitename, 'www.' ) ) {
			$sitename = substr( $sitename, 4 );
		}

		return 'wordpress@' . $sitename;
	}

	/**
	 * Every transport the plugin knows about.
	 *
	 * @return array<string,Mailhouse_Provider>
	 */
	public static function providers() {
		if ( null === self::$providers ) {
			$classes = array(
				'Mailhouse_Provider_PHP',
				'Mailhouse_Provider_SMTP',
				'Mailhouse_Provider_SendGrid',
				'Mailhouse_Provider_Mailgun',
				'Mailhouse_Provider_Brevo',
				'Mailhouse_Provider_Postmark',
				'Mailhouse_Provider_Resend',
				'Mailhouse_Provider_SES',
				'Mailhouse_Provider_Mailjet',
				'Mailhouse_Provider_SparkPost',
				'Mailhouse_Provider_SMTP2GO',
				'Mailhouse_Provider_ElasticEmail',
				'Mailhouse_Provider_MailerSend',
				'Mailhouse_Provider_ZeptoMail',
				'Mailhouse_Provider_Mandrill',
			);

			/**
			 * Filter the available transports.
			 *
			 * @param string[] $classes Class names, each extending Mailhouse_Provider.
			 */
			$classes = apply_filters( 'mailhouse_providers', $classes );

			self::$providers = array();

			foreach ( $classes as $class ) {
				if ( ! class_exists( $class ) ) {
					continue;
				}

				$provider = new $class();

				self::$providers[ $provider->id() ] = $provider;
			}
		}

		return self::$providers;
	}

	/**
	 * The transport currently selected, or null when it is unknown.
	 *
	 * @param string $id Optional transport id; defaults to the setting.
	 * @return Mailhouse_Provider|null
	 */
	public static function provider( $id = '' ) {
		if ( ! $id && 'campaign' === self::$stream ) {
			$campaign = (string) mailhouse_option( 'campaign_mailer', '' );
			$id       = $campaign ? $campaign : '';
		}

		$id        = $id ? $id : (string) mailhouse_option( 'mailer', 'php' );
		$providers = self::providers();

		return isset( $providers[ $id ] ) ? $providers[ $id ] : null;
	}

	/**
	 * The backup transport, when one is set, ready, and not the same as the
	 * one that just failed.
	 *
	 * @return Mailhouse_Provider|null
	 */
	public static function fallback() {
		$id = (string) mailhouse_option( 'fallback_mailer', '' );

		if ( '' === $id ) {
			return null;
		}

		$primary  = self::provider();
		$fallback = self::provider( $id );

		if ( ! $fallback || ( $primary && $primary->id() === $fallback->id() ) || ! $fallback->is_configured() ) {
			return null;
		}

		return $fallback;
	}

	/**
	 * Whether the selected transport has everything it needs to send.
	 *
	 * @return bool
	 */
	public static function is_ready() {
		$provider = self::provider();

		return $provider ? $provider->is_configured() : false;
	}

	/**
	 * Send a message, tagging the log entry with its campaign and subscriber.
	 *
	 * Everything the newsletter side sends goes through here rather than
	 * calling wp_mail() directly, so a delivery failure can be traced back to
	 * the campaign and the recipient that produced it.
	 *
	 * @param string $to          Recipient address.
	 * @param string $subject     Subject.
	 * @param string $html        HTML body.
	 * @param array  $args        Optional: text, headers, from, from_name, reply_to,
	 *                            campaign_id, subscriber_id, attachments.
	 * @return bool
	 */
	public static function send( $to, $subject, $html, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'text'          => '',
				'headers'       => array(),
				'from'          => '',
				'from_name'     => '',
				'reply_to'      => '',
				'campaign_id'   => 0,
				'subscriber_id' => 0,
				'attachments'   => array(),
			)
		);

		$headers = (array) $args['headers'];

		if ( $args['from'] && is_email( $args['from'] ) ) {
			$name      = $args['from_name'] ? $args['from_name'] : '';
			$headers[] = $name ? sprintf( 'From: %s <%s>', $name, $args['from'] ) : 'From: ' . $args['from'];
		}

		if ( $args['reply_to'] && is_email( $args['reply_to'] ) ) {
			$headers[] = 'Reply-To: ' . $args['reply_to'];
		}

		$headers[] = 'Content-Type: text/html; charset=UTF-8';

		// Picked up by the log so the entry can be attributed, then dropped
		// again before the message goes out.
		Mailhouse_Log::set_context(
			array(
				'campaign_id'   => (int) $args['campaign_id'],
				'subscriber_id' => (int) $args['subscriber_id'],
			)
		);

		$alt = $args['text'] ? $args['text'] : mailhouse_html_to_text( $html );

		$callback = function ( $mail ) use ( $alt ) {
			if ( $alt && ! $mail->AltBody ) {
				$mail->AltBody = $alt;
			}
		};

		add_action( 'phpmailer_init', $callback, 30 );

		self::$own    = true;
		self::$stream = ( $args['campaign_id'] || $args['subscriber_id'] ) ? 'campaign' : 'site';

		try {
			$sent = wp_mail( $to, $subject, $html, $headers, (array) $args['attachments'] );
		} finally {
			self::$own    = false;
			self::$stream = 'site';
		}

		remove_action( 'phpmailer_init', $callback, 30 );

		Mailhouse_Log::set_context( array() );

		return $sent;
	}
}
