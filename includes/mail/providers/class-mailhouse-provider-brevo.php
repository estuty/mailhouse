<?php
/**
 * The Brevo transport.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends through Brevo's transactional email API.
 */
class Mailhouse_Provider_Brevo extends Mailhouse_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'brevo';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Brevo (formerly Sendinblue)', 'mailhouse' );
	}

	/**
	 * When to pick this.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'The sending address has to be a verified sender or a verified domain in Brevo, otherwise the API rejects the message.', 'mailhouse' );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'brevo_key' => array(
				'label'       => __( 'API key', 'mailhouse' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'An v3 API key. Stored encrypted, or define MAILHOUSE_BREVO_KEY in wp-config.php.', 'mailhouse' ),
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
		$body = array(
			'sender'  => array( 'email' => $mail->From ),
			'to'      => $this->format( $this->to( $mail ) ),
			'subject' => $mail->Subject,
		);

		if ( '' !== (string) $mail->FromName ) {
			$body['sender']['name'] = $mail->FromName;
		}

		$cc = $this->format( $this->cc( $mail ) );
		if ( $cc ) {
			$body['cc'] = $cc;
		}

		$bcc = $this->format( $this->bcc( $mail ) );
		if ( $bcc ) {
			$body['bcc'] = $bcc;
		}

		$html = $this->html( $mail );
		if ( '' !== $html ) {
			$body['htmlContent'] = $html;
		}

		$text = $this->text( $mail );
		if ( '' !== $text ) {
			$body['textContent'] = $text;
		}

		// Brevo insists on an HTML part unless the message is text-only.
		if ( ! isset( $body['htmlContent'] ) && ! isset( $body['textContent'] ) ) {
			$body['textContent'] = ' ';
		}

		$reply_to = $this->reply_to( $mail );
		if ( $reply_to ) {
			$body['replyTo'] = array( 'email' => $reply_to[0]['email'] );

			if ( '' !== $reply_to[0]['name'] ) {
				$body['replyTo']['name'] = $reply_to[0]['name'];
			}
		}

		$headers = $this->custom_headers( $mail );
		if ( $headers ) {
			$body['headers'] = $headers;
		}

		foreach ( $this->attachments( $mail ) as $attachment ) {
			$body['attachment'][] = array(
				'content' => $attachment['content'],
				'name'    => $attachment['name'],
			);
		}

		$response = $this->request(
			'https://api.brevo.com/v3/smtp/email',
			array(
				'headers' => array(
					'api-key'      => mailhouse_option( 'brevo_key' ),
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( $response['code'] < 200 || $response['code'] > 299 ) {
			$this->fail_response( $response );
		}

		return true;
	}

	/**
	 * Email/name pairs in Brevo's shape.
	 *
	 * @param array $addresses Addresses.
	 * @return array
	 */
	protected function format( $addresses ) {
		$out = array();

		foreach ( $addresses as $address ) {
			$item = array( 'email' => $address['email'] );

			if ( '' !== $address['name'] ) {
				$item['name'] = $address['name'];
			}

			$out[] = $item;
		}

		return $out;
	}
}
