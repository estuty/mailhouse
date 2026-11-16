<?php
/**
 * The connector to Mailhouse Central: this site's email template.
 *
 * A newsletter written at the centre is a body. It is this site that wraps
 * it in a header, a footer and the site's colours, and this site that sends
 * it. For the centre to show a campaign as this site would send it, the
 * centre needs the wrapper: the template shell.
 *
 * The shell is the site's own template, rendered once with a marker where
 * each thing that differs from one email to the next would go: the body,
 * the subject, the preview text, and this subscriber's two links. Nothing
 * about any subscriber is in it. It is sent after connecting and again when
 * the design changes, and at most once an hour is it looked at to see
 * whether it has.
 *
 * The centre keeps it sanitised and refuses one that has no place for a
 * body or no unsubscribe link. A centre too old to know about shells
 * answers that there is no such address, and is asked again tomorrow.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Publishes this site's template shell.
 */
class Mailhouse_Central_Template {

	/**
	 * What this site announces when it will publish its shell.
	 */
	const CAPABILITY = 'template.v1';

	/**
	 * Seconds between looks at whether the shell has changed, when nothing
	 * has said that it has.
	 */
	const CHECK_EVERY = HOUR_IN_SECONDS;

	/**
	 * Seconds to wait after the centre could not be reached.
	 */
	const RETRY_AFTER = 900;

	/**
	 * What stands in the rendered template for the things that differ from
	 * one email to the next. Text for the three that are text, and addresses
	 * for the two that are addresses: the template escapes each for where it
	 * lands, and these come through that unchanged.
	 *
	 * @return array<string,string> Slot name => what is rendered in its place.
	 */
	protected static function markers() {
		return array(
			'content'         => 'MAILHOUSE-SLOT-CONTENT-4f1c9a',
			'subject'         => 'MAILHOUSE-SLOT-SUBJECT-4f1c9a',
			'preheader'       => 'MAILHOUSE-SLOT-PREHEADER-4f1c9a',
			'unsubscribe_url' => 'https://slot.mailhouse.invalid/unsubscribe-4f1c9a',
			'preferences_url' => 'https://slot.mailhouse.invalid/preferences-4f1c9a',
		);
	}

	/**
	 * Notice when the design may have changed.
	 *
	 * @return void
	 */
	public static function listen() {
		add_action( 'update_option_mailhouse_settings', array( __CLASS__, 'settings_saved' ), 10, 2 );
		add_action( 'update_option_blogname', array( __CLASS__, 'touch' ), 10, 0 );
		add_action( 'switch_theme', array( __CLASS__, 'touch' ), 10, 0 );
	}

	/**
	 * The plugin's settings were saved, which is where its colours and logo are.
	 *
	 * The same option holds the flag that says this site is connected. A
	 * save that clears it is the site being disconnected, and nothing about
	 * the connection is written while that is happening.
	 *
	 * @param mixed $old What the settings were.
	 * @param mixed $new What they are now.
	 * @return void
	 */
	public static function settings_saved( $old, $new ) {
		if ( ! is_array( $new ) || empty( $new[ Mailhouse_Central_Identity::FLAG_SETTING ] ) ) {
			return;
		}

		self::touch();
	}

	/**
	 * The design may have changed: look at the shell on the next run.
	 *
	 * @return void
	 */
	public static function touch() {
		if ( ! Mailhouse_Settings::get( Mailhouse_Central_Identity::FLAG_SETTING, false ) || '' === Mailhouse_Central_Identity::status() ) {
			return;
		}

		Mailhouse_Central_Identity::update(
			array(
				'template_checked_at' => 0,
				'template_next_at'    => 0,
			)
		);
	}

	/**
	 * This site's template with a marked place for each thing an email fills in.
	 *
	 * @return string
	 */
	public static function shell() {
		$markers = self::markers();

		$html = Mailhouse_Template::wrap(
			$markers['content'],
			array(
				'subject'         => $markers['subject'],
				'preview'         => $markers['preheader'],
				'unsubscribe_url' => $markers['unsubscribe_url'],
				'preferences_url' => $markers['preferences_url'],
			)
		);

		foreach ( $markers as $slot => $marker ) {
			$html = str_replace( $marker, '{{' . $slot . '}}', $html );
		}

		return $html;
	}

	/**
	 * The site's name and colours, for showing beside the shell.
	 *
	 * @return array<string,string>
	 */
	protected static function brand() {
		return array(
			'site_name' => substr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 0, 200 ),
			'accent'    => substr( (string) mailhouse_option( 'template_accent', '#2b6cb0' ), 0, 32 ),
			'header_bg' => substr( (string) mailhouse_option( 'template_header_bg', '#ffffff' ), 0, 32 ),
			'background' => substr( (string) mailhouse_option( 'template_bg', '#f3f4f6' ), 0, 32 ),
			'card_bg'   => substr( (string) mailhouse_option( 'template_card_bg', '#ffffff' ), 0, 32 ),
			'ink'       => substr( (string) mailhouse_option( 'template_ink', '#1f2937' ), 0, 32 ),
		);
	}

	/**
	 * Who this site's newsletters are from.
	 *
	 * @return array<string,string|null>
	 */
	protected static function from() {
		$name  = (string) mailhouse_option( 'campaign_from_name' );
		$email = (string) mailhouse_option( 'campaign_from_email' );
		$reply = (string) mailhouse_option( 'campaign_reply_to' );

		return array(
			'name'     => substr( '' !== $name ? $name : (string) mailhouse_option( 'from_name' ), 0, 200 ),
			'email'    => substr( '' !== $email ? $email : (string) mailhouse_option( 'from_email' ), 0, 254 ),
			'reply_to' => '' !== $reply ? substr( $reply, 0, 254 ) : ( '' !== (string) mailhouse_option( 'reply_to' ) ? substr( (string) mailhouse_option( 'reply_to' ), 0, 254 ) : null ),
		);
	}

	/**
	 * Send the shell to the centre if it is new, or has changed.
	 *
	 * @param int $timeout Seconds to wait for the centre.
	 * @return string What happened: waiting, fresh, unchanged, published, unsupported, refused or failed.
	 */
	public static function work( $timeout = Mailhouse_Central_Client::TIMEOUT ) {
		// Only a site in good standing, at the address it registered with:
		// a copy of a site says nothing to the centre, and neither does one
		// that is switched off, suspended or gone.
		if ( Mailhouse_Central::disabled() || 'connected' !== Mailhouse_Central_Identity::status() || ! Mailhouse_Central_Identity::at_registered_address() ) {
			return 'waiting';
		}

		$state = Mailhouse_Central_Identity::state();
		$now   = time();

		if ( $now < (int) $state['template_next_at'] ) {
			return 'waiting';
		}

		if ( '' !== (string) $state['template_hash'] && $now - (int) $state['template_checked_at'] < self::CHECK_EVERY ) {
			return 'fresh';
		}

		$shell = self::shell();
		$hash  = hash( 'sha256', $shell );

		if ( $hash === (string) $state['template_hash'] || $hash === (string) $state['template_refused'] ) {
			// The one the centre has, or the one it would not take. Either
			// way there is nothing new to say.
			Mailhouse_Central_Identity::update( array( 'template_checked_at' => $now ) );
			return 'unchanged';
		}

		$answer = Mailhouse_Central_Client::signed(
			'PUT',
			'/api/v1/connector/template',
			array(
				'shell_html' => $shell,
				'shell_hash' => $hash,
				'brand'      => self::brand(),
				'from'       => self::from(),
			),
			$timeout
		);

		if ( is_wp_error( $answer ) ) {
			Mailhouse_Central_Identity::update( array( 'template_next_at' => $now + self::RETRY_AFTER ) );
			return 'failed';
		}

		$body = is_array( $answer['body'] ) ? $answer['body'] : array();

		switch ( (int) $answer['status'] ) {
			case 200:
				Mailhouse_Central_Identity::update(
					array(
						'template_hash'         => $hash,
						'template_refused'      => '',
						'template_published_at' => $now,
						'template_checked_at'   => $now,
						'template_next_at'      => 0,
						'template_error'        => '',
					)
				);
				return 'published';

			case 404:
			case 405:
				// A centre from before shells. Nothing is wrong; ask again tomorrow.
				Mailhouse_Central_Identity::update(
					array(
						'template_checked_at' => $now,
						'template_next_at'    => $now + DAY_IN_SECONDS,
						'template_error'      => '',
					)
				);
				return 'unsupported';

			case 413:
			case 422:
				// The centre will not keep this shell, and says why. It is
				// not sent again until it is a different shell.
				Mailhouse_Central_Identity::update(
					array(
						'template_refused'    => $hash,
						'template_checked_at' => $now,
						'template_error'      => Mailhouse_Central_Protocol::text( isset( $body['detail'] ) && is_string( $body['detail'] ) ? $body['detail'] : __( 'The centre would not keep this site’s email template.', 'mailhouse' ), 300 ),
					)
				);
				return 'refused';
		}

		Mailhouse_Central_Identity::update( array( 'template_next_at' => $now + self::RETRY_AFTER ) );

		return 'failed';
	}
}
