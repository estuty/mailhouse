<?php
/**
 * The MailerSend transport.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends through MailerSend's email API.
 */
class Mailhouse_Provider_MailerSend extends Mailhouse_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'mailersend';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label() {
		return 'MailerSend';
	}

	/**
	 * When to pick this.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Create an API token under Integrations with "Email: full access". The From address must be on a domain verified in MailerSend.', 'mailhouse' );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'mailersend_key' => array(
				'label'       => __( 'API token', 'mailhouse' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Stored encrypted, or define MAILHOUSE_MAILERSEND_KEY in wp-config.php.', 'mailhouse' ),
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
			'from'    => $this->pair( $mail->From, $mail->FromName ),
			'to'      => $this->pairs( $this->to( $mail ) ),
			'subject' => $mail->Subject,
		);

		if ( $this->cc( $mail ) ) {
			$body['cc'] = $this->pairs( $this->cc( $mail ) );
		}

		if ( $this->bcc( $mail ) ) {
			$body['bcc'] = $this->pairs( $this->bcc( $mail ) );
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
			$body['reply_to'] = $this->pair( $reply_to[0]['email'], $reply_to[0]['name'] );
		}

		foreach ( $this->attachments( $mail ) as $attachment ) {
			$item = array(
				'content'     => $attachment['content'],
				'filename'    => $attachment['name'],
				'disposition' => $attachment['inline'] && $attachment['cid'] ? 'inline' : 'attachment',
			);

			if ( 'inline' === $item['disposition'] ) {
				$item['id'] = $attachment['cid'];
			}

			$body['attachments'][] = $item;
		}

		$response = $this->request(
			'https://api.mailersend.com/v1/email',
			array(
				'headers' => array(
					'Authorization'    => 'Bearer ' . mailhouse_option( 'mailersend_key' ),
					'Content-Type'     => 'application/json',
					'Accept'           => 'application/json',
					'X-Requested-With' => 'XMLHttpRequest',
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
	 * One address in MailerSend's shape.
	 *
	 * @param string $email Address.
	 * @param string $name  Name.
	 * @return array
	 */
	protected function pair( $email, $name ) {
		$out = array( 'email' => $email );

		if ( '' !== (string) $name ) {
			$out['name'] = (string) $name;
		}

		return $out;
	}

	/**
	 * A list of addresses in MailerSend's shape.
	 *
	 * @param array $addresses Email/name pairs.
	 * @return array
	 */
	protected function pairs( $addresses ) {
		$out = array();

		foreach ( $addresses as $address ) {
			$out[] = $this->pair( $address['email'], $address['name'] );
		}

		return $out;
	}
}
