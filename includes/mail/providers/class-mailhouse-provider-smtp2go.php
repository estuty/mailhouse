<?php
/**
 * The SMTP2GO transport.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends through SMTP2GO's email API.
 */
class Mailhouse_Provider_SMTP2GO extends Mailhouse_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'smtp2go';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label() {
		return 'SMTP2GO';
	}

	/**
	 * When to pick this.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Create an API key under Sending, then API Keys, with permission to send email. Verify the sending domain first.', 'mailhouse' );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'smtp2go_key' => array(
				'label'       => __( 'API key', 'mailhouse' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Stored encrypted, or define MAILHOUSE_SMTP2GO_KEY in wp-config.php.', 'mailhouse' ),
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
			'sender'  => mailhouse_format_address( $mail->From, $mail->FromName ),
			'to'      => $this->lines( $this->to( $mail ) ),
			'subject' => $mail->Subject,
		);

		if ( $this->cc( $mail ) ) {
			$body['cc'] = $this->lines( $this->cc( $mail ) );
		}

		if ( $this->bcc( $mail ) ) {
			$body['bcc'] = $this->lines( $this->bcc( $mail ) );
		}

		$html = $this->html( $mail );
		if ( '' !== $html ) {
			$body['html_body'] = $html;
		}

		$text = $this->text( $mail );
		if ( '' !== $text ) {
			$body['text_body'] = $text;
		}

		$headers  = $this->custom_headers( $mail );
		$reply_to = $this->reply_to( $mail );

		if ( $reply_to ) {
			$headers['Reply-To'] = mailhouse_format_address( $reply_to[0]['email'], $reply_to[0]['name'] );
		}

		foreach ( $headers as $name => $value ) {
			$body['custom_headers'][] = array(
				'header' => $name,
				'value'  => $value,
			);
		}

		foreach ( $this->attachments( $mail ) as $attachment ) {
			$item = array(
				'filename' => $attachment['inline'] && $attachment['cid'] ? $attachment['cid'] : $attachment['name'],
				'fileblob' => $attachment['content'],
				'mimetype' => $attachment['type'],
			);

			$body[ $attachment['inline'] && $attachment['cid'] ? 'inlines' : 'attachments' ][] = $item;
		}

		$response = $this->request(
			'https://api.smtp2go.com/v3/email/send',
			array(
				'headers' => array(
					'X-Smtp2go-Api-Key' => mailhouse_option( 'smtp2go_key' ),
					'Content-Type'      => 'application/json',
					'Accept'            => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$succeeded = isset( $response['json']['data']['succeeded'] ) ? (int) $response['json']['data']['succeeded'] : 0;

		if ( $response['code'] < 200 || $response['code'] > 299 || $succeeded < 1 ) {
			if ( ! empty( $response['json']['data']['error'] ) && is_string( $response['json']['data']['error'] ) ) {
				$this->fail( sprintf( 'HTTP %d - %s', $response['code'], $response['json']['data']['error'] ) );
			}

			$this->fail_response( $response );
		}

		return true;
	}

	/**
	 * Addresses as `Name <email>` strings.
	 *
	 * @param array $addresses Email/name pairs.
	 * @return string[]
	 */
	protected function lines( $addresses ) {
		$out = array();

		foreach ( $addresses as $address ) {
			$out[] = mailhouse_format_address( $address['email'], $address['name'] );
		}

		return $out;
	}
}
