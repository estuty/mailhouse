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
	 * are left to it. An API transport still gets the message assembled
	 * first: preSend() is what fills in Body, AltBody and the attachment list
	 * the provider then reads.
	 *
	 * Either way, what happened is reported to Mailhouse_Mailer as it
	 * happens, in more detail than the boolean this returns.
	 *
	 * @return bool
	 * @throws PHPMailer\PHPMailer\Exception When assembly or delivery fails.
	 */
	public function send() {
		$provider = Mailhouse_Mailer::provider();

		try {
			return $this->send_with( $provider );
		} catch ( PHPMailer\PHPMailer\Exception $e ) {
			$fallback = Mailhouse_Mailer::fallback_allowed() ? Mailhouse_Mailer::fallback() : null;

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
		Mailhouse_Mailer::begin_attempt();

		if ( ! $provider || ! $provider->is_api() ) {
			return $this->send_native();
		}

		$provider->begin();

		if ( ! $this->assemble() ) {
			return false;
		}

		$sent = $provider->send( $this );

		if ( $sent ) {
			Mailhouse_Mailer::set_result(
				array(
					'outcome'    => 'accepted',
					'message_id' => $provider->message_id(),
				)
			);
		}

		return $sent;
	}

	/**
	 * Send through PHPMailer's own SMTP or mail() transport.
	 *
	 * This is PHPMailer::send() with its two halves pulled apart, because
	 * which half failed is the whole question: a message that could not be
	 * assembled never went anywhere, and one that failed in transit might
	 * have.
	 *
	 * @return bool
	 * @throws PHPMailer\PHPMailer\Exception When assembly or delivery fails.
	 */
	protected function send_native() {
		try {
			if ( ! $this->assemble() ) {
				return false;
			}

			try {
				$sent = $this->postSend();
			} catch ( PHPMailer\PHPMailer\Exception $e ) {
				$smtp = $this->smtp ? $this->smtp->getError() : array();

				Mailhouse_Mailer::set_result(
					array_merge(
						self::classify_native(
							(string) $this->Mailer,
							$this->failure_key( $e->getMessage() ),
							isset( $smtp['smtp_code'] ) ? (int) $smtp['smtp_code'] : 0
						),
						array( 'error' => $e->getMessage() )
					)
				);

				throw $e;
			}

			if ( $sent ) {
				$id = ( 'smtp' === $this->Mailer && $this->smtp ) ? (string) $this->smtp->getLastTransactionID() : '';

				Mailhouse_Mailer::set_result(
					array(
						'outcome'    => 'accepted',
						// The server's own name for the message when it gave
						// one, and otherwise the Message-ID we sent it under.
						'message_id' => '' !== $id ? $id : $this->getLastMessageID(),
					)
				);
			}

			return $sent;
		} catch ( PHPMailer\PHPMailer\Exception $e ) {
			$this->mailHeader = '';
			$this->setError( $e->getMessage() );

			if ( $this->exceptions ) {
				throw $e;
			}

			return false;
		}
	}

	/**
	 * Build the message, reporting a failure to do so as what it is: a
	 * message that was never sent and never will be as it stands.
	 *
	 * @return bool
	 * @throws PHPMailer\PHPMailer\Exception When the message cannot be built.
	 */
	protected function assemble() {
		$unsendable = array(
			'outcome'   => 'rejected',
			'permanent' => true,
		);

		try {
			$built = $this->preSend();
		} catch ( PHPMailer\PHPMailer\Exception $e ) {
			Mailhouse_Mailer::set_result( array_merge( $unsendable, array( 'error' => $e->getMessage() ) ) );

			throw $e;
		}

		if ( ! $built ) {
			Mailhouse_Mailer::set_result( array_merge( $unsendable, array( 'error' => (string) $this->ErrorInfo ) ) );
		}

		return $built;
	}

	/**
	 * Which of PHPMailer's own errors a failure message is.
	 *
	 * PHPMailer reports transport failures as text. The text comes from its
	 * language table, so comparing against the table identifies the failure
	 * whatever language the site runs in.
	 *
	 * @param string $message Exception message.
	 * @return string A language key, or '' when it is none of them.
	 */
	protected function failure_key( $message ) {
		$keys = array(
			'connect_host',
			'smtp_connect_failed',
			'authenticate',
			'from_failed',
			'recipients_failed',
			'data_not_accepted',
			'instantiate',
			'execute',
		);

		foreach ( $keys as $key ) {
			$text = (string) $this->lang( $key );

			if ( '' !== $text && 0 === strpos( (string) $message, $text ) ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * Sort a failure of PHPMailer's own transports into "it did not go" and
	 * "nobody knows".
	 *
	 * The rule is the one every transport follows: a failure counts as a
	 * refusal only when it can be shown the message never left. Everything
	 * else is ambiguous, because the cost of guessing wrong is somebody
	 * receiving the same email twice.
	 *
	 * @param string $mailer    PHPMailer's Mailer: smtp, mail, sendmail, qmail.
	 * @param string $key       The failure, as one of PHPMailer's language keys.
	 * @param int    $smtp_code The SMTP reply code that came with it, or 0.
	 * @return array{outcome:string,permanent:bool}
	 */
	public static function classify_native( $mailer, $key, $smtp_code = 0 ) {
		$smtp_code = (int) $smtp_code;

		$rejected = static function ( $permanent ) {
			return array(
				'outcome'   => 'rejected',
				'permanent' => (bool) $permanent,
			);
		};

		$ambiguous = array(
			'outcome'   => 'ambiguous',
			'permanent' => false,
		);

		if ( 'smtp' === $mailer ) {
			switch ( $key ) {
				// No conversation happened, or it ended before a message was
				// offered. Worth another try once the server or the password
				// is right.
				case 'connect_host':
				case 'smtp_connect_failed':
				case 'authenticate':
					return $rejected( false );

				// Refused by name, before any content was sent.
				case 'from_failed':
				case 'recipients_failed':
					return $rejected( $smtp_code >= 500 );

				// The body was offered. A reply code means the server
				// answered and said no; no code means the line went dead
				// with the message already on it.
				case 'data_not_accepted':
					return $smtp_code >= 400 ? $rejected( $smtp_code >= 500 ) : $ambiguous;
			}

			return $ambiguous;
		}

		if ( in_array( $mailer, array( 'mail', 'sendmail', 'qmail' ), true ) && in_array( $key, array( 'instantiate', 'execute' ), true ) ) {
			return $rejected( false );
		}

		return $ambiguous;
	}
}
