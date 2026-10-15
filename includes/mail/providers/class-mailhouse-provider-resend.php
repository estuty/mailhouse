<?php
/**
 * The Resend transport.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends through Resend's email API.
 */
class Mailhouse_Provider_Resend extends Mailhouse_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'resend';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Resend';
	}

	/**
	 * When to pick this.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Add and verify the domain in Resend, then create an API key with send access.', 'mailhouse' );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'resend_key' => array(
				'label'       => __( 'API key', 'mailhouse' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Stored encrypted, or define MAILHOUSE_RESEND_KEY in wp-config.php.', 'mailhouse' ),
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
			'from'    => mailhouse_format_address( $mail->From, $mail->FromName ),
			'to'      => $this->flatten( $this->to( $mail ) ),
			'subject' => $mail->Subject,
		);

		$cc = $this->flatten( $this->cc( $mail ) );
		if ( $cc ) {
			$body['cc'] = $cc;
		}

		$bcc = $this->flatten( $this->bcc( $mail ) );
		if ( $bcc ) {
			$body['bcc'] = $bcc;
		}

		$html = $this->html( $mail );
		if ( '' !== $html ) {
			$body['html'] = $html;
		}

		$text = $this->text( $mail );
		if ( '' !== $text ) {
			$body['text'] = $text;
		}

		$reply_to = $this->reply_to( $mail );
		if ( $reply_to ) {
			$body['reply_to'] = $reply_to[0]['email'];
		}

		$headers = $this->custom_headers( $mail );
		if ( $headers ) {
			$body['headers'] = $headers;
		}

		foreach ( $this->attachments( $mail ) as $attachment ) {
			$body['attachments'][] = array(
				'filename'     => $attachment['name'],
				'content'      => $attachment['content'],
				'content_type' => $attachment['type'],
			);
		}

		$response = $this->request(
			'https://api.resend.com/emails',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . mailhouse_option( 'resend_key' ),
					'Content-Type'  => 'application/json',
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
	 * Addresses as a flat list of formatted strings.
	 *
	 * @param array $addresses Email/name pairs.
	 * @return string[]
	 */
	protected function flatten( $addresses ) {
		$out = array();

		foreach ( $addresses as $address ) {
			$out[] = mailhouse_format_address( $address['email'], $address['name'] );
		}

		return $out;
	}
}
