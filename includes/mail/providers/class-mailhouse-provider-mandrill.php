<?php
/**
 * The Mailchimp Transactional (Mandrill) transport.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends through Mandrill's messages API.
 */
class Mailhouse_Provider_Mandrill extends Mailhouse_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'mandrill';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Mailchimp Transactional (Mandrill)';
	}

	/**
	 * When to pick this.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Needs a Mailchimp Transactional add-on. Create the key under Settings, then SMTP & API Info, and verify the sending domain.', 'mailhouse' );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'mandrill_key' => array(
				'label'       => __( 'API key', 'mailhouse' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Stored encrypted, or define MAILHOUSE_MANDRILL_KEY in wp-config.php.', 'mailhouse' ),
			),
		);
	}

	/**
	 * Deliver the message.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return bool
	 * @throws PHPMailer\PHPMailer\Exception On failure.
	 */
	public function send( $mail ) {
		$to = array();

		foreach ( array( 'to' => $this->to( $mail ), 'cc' => $this->cc( $mail ), 'bcc' => $this->bcc( $mail ) ) as $type => $addresses ) {
			foreach ( $addresses as $address ) {
				$to[] = array(
					'email' => $address['email'],
					'name'  => $address['name'],
					'type'  => $type,
				);
			}
		}

		$message = array(
			'subject'             => $mail->Subject,
			'from_email'          => $mail->From,
			'from_name'           => $mail->FromName,
			'to'                  => $to,
			'preserve_recipients' => true,
			'headers'             => $this->custom_headers( $mail ),
		);

		$html = $this->html( $mail );
		if ( '' !== $html ) {
			$message['html'] = $html;
		}

		$text = $this->text( $mail );
		if ( '' !== $text ) {
			$message['text'] = $text;
		}

		$reply_to = $this->reply_to( $mail );
		if ( $reply_to ) {
			$message['headers']['Reply-To'] = mailhouse_format_address( $reply_to[0]['email'], $reply_to[0]['name'] );
		}

		if ( ! $message['headers'] ) {
			unset( $message['headers'] );
		}

		foreach ( $this->attachments( $mail ) as $attachment ) {
			$inline = $attachment['inline'] && $attachment['cid'];

			$message[ $inline ? 'images' : 'attachments' ][] = array(
				'type'    => $attachment['type'],
				'name'    => $inline ? $attachment['cid'] : $attachment['name'],
				'content' => $attachment['content'],
			);
		}

		$response = $this->request(
			'https://mandrillapp.com/api/1.0/messages/send',
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'key'     => (string) mailhouse_option( 'mandrill_key' ),
						'message' => $message,
					)
				),
			)
		);

		if ( $response['code'] < 200 || $response['code'] > 299 ) {
			$this->fail_response( $response );
		}

		// A 200 can still carry per-recipient refusals.
		foreach ( (array) $response['json'] as $result ) {
			if ( is_array( $result ) && isset( $result['status'] ) && in_array( $result['status'], array( 'rejected', 'invalid' ), true ) ) {
				$this->fail(
					sprintf(
						'%s - %s',
						isset( $result['email'] ) ? $result['email'] : '',
						isset( $result['reject_reason'] ) && $result['reject_reason'] ? $result['reject_reason'] : $result['status']
					)
				);
			}
		}

		return true;
	}
}
