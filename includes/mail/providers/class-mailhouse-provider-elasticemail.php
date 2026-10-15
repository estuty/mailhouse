<?php
/**
 * The Elastic Email transport.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends through Elastic Email's v4 transactional API.
 */
class Mailhouse_Provider_ElasticEmail extends Mailhouse_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'elasticemail';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Elastic Email';
	}

	/**
	 * When to pick this.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Create an API key under Settings, then Manage API Keys, with "Send Email" access. Verify the sending domain first.', 'mailhouse' );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'elasticemail_key' => array(
				'label'       => __( 'API key', 'mailhouse' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Stored encrypted, or define MAILHOUSE_ELASTICEMAIL_KEY in wp-config.php.', 'mailhouse' ),
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
		$recipients = array( 'To' => $this->lines( $this->to( $mail ) ) );

		if ( $this->cc( $mail ) ) {
			$recipients['CC'] = $this->lines( $this->cc( $mail ) );
		}

		if ( $this->bcc( $mail ) ) {
			$recipients['BCC'] = $this->lines( $this->bcc( $mail ) );
		}

		$parts = array();

		$html = $this->html( $mail );
		if ( '' !== $html ) {
			$parts[] = array(
				'ContentType' => 'HTML',
				'Content'     => $html,
				'Charset'     => 'utf-8',
			);
		}

		$text = $this->text( $mail );
		if ( '' !== $text ) {
			$parts[] = array(
				'ContentType' => 'PlainText',
				'Content'     => $text,
				'Charset'     => 'utf-8',
			);
		}

		$content = array(
			'Body'    => $parts,
			'From'    => mailhouse_format_address( $mail->From, $mail->FromName ),
			'Subject' => $mail->Subject,
		);

		$reply_to = $this->reply_to( $mail );
		if ( $reply_to ) {
			$content['ReplyTo'] = mailhouse_format_address( $reply_to[0]['email'], $reply_to[0]['name'] );
		}

		$headers = $this->custom_headers( $mail );
		if ( $headers ) {
			$content['Headers'] = $headers;
		}

		foreach ( $this->attachments( $mail ) as $attachment ) {
			$content['Attachments'][] = array(
				'BinaryContent' => $attachment['content'],
				'Name'          => $attachment['name'],
				'ContentType'   => $attachment['type'],
			);
		}

		$response = $this->request(
			'https://api.elasticemail.com/v4/emails/transactional',
			array(
				'headers' => array(
					'X-ElasticEmail-ApiKey' => mailhouse_option( 'elasticemail_key' ),
					'Content-Type'          => 'application/json',
					'Accept'                => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'Recipients' => $recipients,
						'Content'    => $content,
					)
				),
			)
		);

		if ( $response['code'] < 200 || $response['code'] > 299 ) {
			if ( ! empty( $response['json']['Error'] ) && is_string( $response['json']['Error'] ) ) {
				$this->fail( sprintf( 'HTTP %d - %s', $response['code'], $response['json']['Error'] ) );
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
