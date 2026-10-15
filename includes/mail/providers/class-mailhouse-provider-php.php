<?php
/**
 * The PHP mail() transport.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hands the message to the server's own mail command.
 */
class Mailhouse_Provider_PHP extends Mailhouse_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'php';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'PHP mail (no change)', 'mailhouse' );
	}

	/**
	 * When to pick this.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'What WordPress does with no plugin at all. Nothing signs the message, so most inboxes treat it as suspicious. Fine for a local site; change it before you send anything that has to arrive.', 'mailhouse' );
	}

	/**
	 * Delivery is PHPMailer's own.
	 *
	 * @return bool
	 */
	public function is_api() {
		return false;
	}

	/**
	 * Use the mail() transport.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return void
	 */
	public function configure( $mail ) {
		$mail->isMail();
	}
}
