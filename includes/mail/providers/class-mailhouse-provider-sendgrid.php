<?php
/**
 * The SendGrid transport.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends through SendGrid's v3 mail API.
 */
class Mailhouse_Provider_SendGrid extends Mailhouse_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'sendgrid';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label() {
		return 'SendGrid';
	}

	/**
	 * When to pick this.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Verify your sending domain in SendGrid first, then create an API key with Mail Send permission only.', 'mailhouse' );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'sendgrid_key' => array(
				'label'       => __( 'API key', 'mailhouse' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Stored encrypted, or define MAILHOUSE_SENDGRID_KEY in wp-config.php.', 'mailhouse' ),
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
		$personalization = array( 'to' => $this->format( $this->to( $mail ) ) );

		$cc = $this->format( $this->cc( $mail ) );
		if ( $cc ) {
			$personalization['cc'] = $cc;
		}

		$bcc = $this->format( $this->bcc( $mail ) );
		if ( $bcc ) {
			$personalization['bcc'] = $bcc;
		}

		$body = array(
			'personalizations' => array( $personalization ),
			'from'             => array(
				'email' => $mail->From,
				'name'  => $mail->FromName,
			),
			'subject'          => $mail->Subject,
			'content'          => array(),
		);

		// SendGrid requires the plain-text part before the HTML one.
		$text = $this->text( $mail );
		$html = $this->html( $mail );

		if ( '' !== $text ) {
			$body['content'][] = array(
				'type'  => 'text/plain',
				'value' => $text,
			);
		}

		if ( '' !== $html ) {
			$body['content'][] = array(
				'type'  => 'text/html',
				'value' => $html,
			);
		}

		$reply_to = $this->reply_to( $mail );
		if ( $reply_to ) {
			$body['reply_to'] = array(
				'email' => $reply_to[0]['email'],
				'name'  => $reply_to[0]['name'],
			);
		}

		$headers = $this->custom_headers( $mail );
		if ( $headers ) {
			$body['headers'] = $headers;
		}

		foreach ( $this->attachments( $mail ) as $attachment ) {
			$item = array(
				'content'     => $attachment['content'],
				'filename'    => $attachment['name'],
				'type'        => $attachment['type'],
				'disposition' => $attachment['inline'] ? 'inline' : 'attachment',
			);

			if ( $attachment['inline'] && $attachment['cid'] ) {
				$item['content_id'] = $attachment['cid'];
			}

			$body['attachments'][] = $item;
		}

		$response = $this->request(
			'https://api.sendgrid.com/v3/mail/send',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . mailhouse_option( 'sendgrid_key' ),
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
	 * Drop empty names, which SendGrid rejects.
	 *
	 * @param array $addresses Email/name pairs.
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
