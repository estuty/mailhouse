<?php
/**
 * The PHPMailer WordPress ends up using.
 *
 * Only one method is overridden. Everything up to the point of transmission --
 * address validation, MIME assembly, the `phpmailer_init` hook other plugins
 * attach to -- is stock WordPress behaviour.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * PHPMailer with an API transport bolted on.
 */
class Mailhouse_PHPMailer extends PHPMailer\PHPMailer\PHPMailer {

	/**
	 * Send the message.
	 *
	 * SMTP and PHP mail() are things PHPMailer already does well, so those
	 * fall straight through to the parent. An API transport still gets the
	 * message assembled first: preSend() is what fills in Body, AltBody and
	 * the attachment list the provider then reads.
	 *
	 * @return bool
	 * @throws PHPMailer\PHPMailer\Exception When assembly or delivery fails.
	 */
	public function send() {
		$provider = Mailhouse_Mailer::provider();

		try {
			return $this->send_with( $provider );
		} catch ( PHPMailer\PHPMailer\Exception $e ) {
			$fallback = Mailhouse_Mailer::fallback();

			if ( ! $fallback ) {
				throw $e;
			}

			// The primary transport failed; one more try through the backup.
			// The first error is kept in the debug log, and if the backup
			// fails too its error is the one the log entry records.
			mailhouse_log_debug( 'Primary transport failed, trying the backup', $e->getMessage() );

			$fallback->configure( $this );

			return $this->send_with( $fallback );
		}
	}

	/**
	 * Send through one transport.
	 *
	 * @param Mailhouse_Provider|null $provider Transport.
	 * @return bool
	 * @throws PHPMailer\PHPMailer\Exception When assembly or delivery fails.
	 */
	protected function send_with( $provider ) {
		if ( ! $provider || ! $provider->is_api() ) {
			return parent::send();
		}

		if ( ! $this->preSend() ) {
			return false;
		}

		return $provider->send( $this );
	}
}
