<?php
/**
 * Settings storage.
 *
 * Everything lives in one option so a page load costs one query. Secrets --
 * API keys, the SMTP password -- are encrypted at rest and can be moved out of
 * the database entirely by defining a constant in wp-config.php, which is the
 * safer place for them: a database dump handed to a developer then carries no
 * live credentials.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's settings.
 */
class Mailhouse_Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'mailhouse_settings';

	/**
	 * Setting keys holding a secret.
	 *
	 * These are encrypted in the database, never printed back into a form
	 * field, and can be overridden by the matching constant.
	 *
	 * @var string[]
	 */
	const SECRETS = array(
		'smtp_pass',
		'sendgrid_key',
		'mailgun_key',
		'brevo_key',
		'postmark_token',
		'resend_key',
		'ses_secret_key',
		'mailjet_secret',
		'sparkpost_key',
		'smtp2go_key',
		'elasticemail_key',
		'mailersend_key',
		'zeptomail_token',
		'mandrill_key',
		'webhook_mailgun_key',
		'webhook_resend_secret',
	);

	/**
	 * Loaded settings, or null before the first read.
	 *
	 * @var array|null
	 */
	protected static $settings = null;

	/**
	 * The default value of every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		$defaults = array(
			// Delivery.
			'mailer'              => 'php',
			'from_email'          => '',
			'from_name'           => '',
			'force_from_email'    => false,
			'force_from_name'     => false,
			'reply_to'            => '',
			'return_path'         => true,
			'fallback_mailer'     => '',

			'smtp_host'           => '',
			'smtp_port'           => 587,
			'smtp_encryption'     => 'tls',
			'smtp_autotls'        => true,
			'smtp_auth'           => true,
			'smtp_user'           => '',
			'smtp_pass'           => '',
			'smtp_timeout'        => 30,

			'sendgrid_key'        => '',
			'mailgun_key'         => '',
			'mailgun_domain'      => '',
			'mailgun_region'      => 'us',
			'brevo_key'           => '',
			'postmark_token'      => '',
			'postmark_stream'     => 'outbound',
			'resend_key'          => '',
			'ses_access_key'      => '',
			'ses_secret_key'      => '',
			'ses_region'          => 'us-east-1',
			'ses_config_set'      => '',
			'mailjet_key'         => '',
			'mailjet_secret'      => '',
			'sparkpost_key'       => '',
			'sparkpost_region'    => 'us',
			'smtp2go_key'         => '',
			'elasticemail_key'    => '',
			'mailersend_key'      => '',
			'zeptomail_token'     => '',
			'zeptomail_region'    => 'com',
			'mandrill_key'        => '',
			'smtp_preset'         => '',

			// Log.
			'log_enabled'         => true,
			'log_save_content'    => false,
			'log_retention_days'  => 30,
			'alerts_enabled'      => false,
			'alerts_email'        => '',

			// Bounce and complaint callbacks.
			'webhook_mailgun_key'   => '',
			'webhook_resend_secret' => '',
			'webhook_sendgrid_key'  => '',
			'soft_bounce_limit'     => 3,

			// List and opt-in.
			'double_optin'        => true,
			'welcome_enabled'     => true,
			'unsubscribe_confirm' => false,
			'confirm_destination' => 'home',
			'default_tags'        => array(),
			'subscribe_comment'   => false,
			'subscribe_register'  => false,
			'comment_label'       => '',
			'trust_proxy'         => false,
			'rate_limit'          => 10,

			// Forms.
			'form_title'          => '',
			'form_description'    => '',
			'form_button'         => '',
			'form_name_fields'    => 'none',
			'form_consent'        => true,
			'form_consent_text'   => '',
			'form_success'        => '',
			'form_styles'         => true,

			// Campaigns.
			'campaign_from_name'  => '',
			'campaign_from_email' => '',
			'campaign_reply_to'   => '',
			'batch_size'          => 20,
			'campaign_mailer'     => '',
			'template_logo'       => '',
			'template_logo_width' => 180,
			'template_header_bg'  => '#ffffff',
			'template_bg'         => '#f3f4f6',
			'template_card_bg'    => '#ffffff',
			'template_ink'        => '#1f2937',
			'template_accent'     => '#2b6cb0',
			'template_button'     => '#2b6cb0',
			'template_footer'     => '',
			'company_address'     => '',
			'template_wrap_all'   => true,
			'social_facebook'     => '',
			'social_x'            => '',
			'social_instagram'    => '',
			'social_linkedin'     => '',
			'social_youtube'      => '',
			'social_tiktok'       => '',
			'track_opens'         => false,
			'track_clicks'        => false,

			// Housekeeping.
			'delete_data'         => false,
		);

		/**
		 * Filter the default settings.
		 *
		 * @param array $defaults Defaults.
		 */
		return apply_filters( 'mailhouse_default_settings', $defaults );
	}

	/**
	 * All settings, defaults filled in.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$settings ) {
			$stored = get_option( self::OPTION, array() );

			if ( ! is_array( $stored ) ) {
				$stored = array();
			}

			self::$settings = array_merge( self::defaults(), $stored );
		}

		return self::$settings;
	}

	/**
	 * Read one setting.
	 *
	 * A secret defined as a constant wins over the stored value, so a site can
	 * keep credentials in wp-config.php and out of the database.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$constant = self::constant_for( $key );

		if ( $constant && defined( $constant ) ) {
			return constant( $constant );
		}

		$settings = self::all();

		if ( ! array_key_exists( $key, $settings ) ) {
			return $default;
		}

		$value = $settings[ $key ];

		if ( in_array( $key, self::SECRETS, true ) ) {
			$value = Mailhouse_Crypto::decrypt( $value );
		}

		/**
		 * Filter a single setting on read.
		 *
		 * @param mixed  $value Value.
		 * @param string $key   Setting key.
		 */
		return apply_filters( 'mailhouse_option', $value, $key );
	}

	/**
	 * Write settings, merged over what is already stored.
	 *
	 * @param array $values Key/value pairs.
	 * @return void
	 */
	public static function set( array $values ) {
		$settings = self::all();

		foreach ( $values as $key => $value ) {
			if ( in_array( $key, self::SECRETS, true ) ) {
				$value = Mailhouse_Crypto::encrypt( $value );
			}

			$settings[ $key ] = $value;
		}

		self::$settings = $settings;

		update_option( self::OPTION, $settings, true );
	}

	/**
	 * Forget the in-memory copy, so the next read hits the database.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$settings = null;
	}

	/**
	 * The wp-config.php constant that can override a setting.
	 *
	 * @param string $key Setting key.
	 * @return string Constant name, or an empty string when the key has none.
	 */
	public static function constant_for( $key ) {
		if ( ! in_array( $key, self::SECRETS, true ) ) {
			return '';
		}

		return 'MAILHOUSE_' . strtoupper( $key );
	}

	/**
	 * Whether a secret is currently supplied by a constant.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_constant( $key ) {
		$constant = self::constant_for( $key );

		return $constant && defined( $constant );
	}

	/**
	 * Whether a secret has a value at all.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function has_secret( $key ) {
		return '' !== (string) self::get( $key, '' );
	}
}
