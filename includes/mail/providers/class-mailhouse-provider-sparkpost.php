<?php
/**
 * The SparkPost transport.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends through SparkPost's transmissions API.
 */
class Mailhouse_Provider_SparkPost extends Mailhouse_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'sparkpost';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label() {
		return 'SparkPost';
	}

	/**
	 * When to pick this.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'An API key with the "Transmissions: Read/Write" permission. Pick the region the account was created in.', 'mailhouse' );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'sparkpost_key'    => array(
				'label'       => __( 'API key', 'mailhouse' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Stored encrypted, or define MAILHOUSE_SPARKPOST_KEY in wp-config.php.', 'mailhouse' ),
			),
			'sparkpost_region' => array(
				'label'   => __( 'Region', 'mailhouse' ),
				'type'    => 'select',
				'options' => array(
					'us' => __( 'US', 'mailhouse' ),
					'eu' => __( 'EU', 'mailhouse' ),
				),
			),
		);
	}

	/**
	 * Deliver the message.
	 *
	 * Cc and Bcc work the SparkPost way: every recipient is listed, and
	 * `header_to` makes the visible To line show only the real To.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return bool
	 * @throws PHPMailer\PHPMailer\Exception On failure.
	 */
	public function send( $mail ) {
		$to        = $this->to( $mail );
		$header_to = implode( ', ', array_map( array( $this, 'line' ), $to ) );
		$recipients = array();

		foreach ( array_merge( $to, $this->cc( $mail ), $this->bcc( $mail ) ) as $address ) {
			$recipients[] = array(
				'address' => array(
					'email'     => $address['email'],
					'name'      => $address['name'],
					'header_to' => $header_to,
				),
			);
		}

		$content = array(
			'from'    => array(
				'email' => $mail->From,
				'name'  => $mail->FromName,
			),
			'subject' => $mail->Subject,
		);

		$html = $this->html( $mail );
		if ( '' !== $html ) {
			$content['html'] = $html;
		}

		$text = $this->text( $mail );
		if ( '' !== $text ) {
			$content['text'] = $text;
		}

		$reply_to = $this->reply_to( $mail );
		if ( $reply_to ) {
			$content['reply_to'] = $this->line( $reply_to[0] );
		}

		$headers = $this->custom_headers( $mail );
		$cc      = $this->cc( $mail );

		if ( $cc ) {
			$headers['CC'] = implode( ', ', array_map( array( $this, 'line' ), $cc ) );
		}

		if ( $headers ) {
			$content['headers'] = $headers;
		}

		foreach ( $this->attachments( $mail ) as $attachment ) {
			$inline = $attachment['inline'] && $attachment['cid'];

			$content[ $inline ? 'inline_images' : 'attachments' ][] = array(
				'name' => $inline ? $attachment['cid'] : $attachment['name'],
				'type' => $attachment['type'],
				'data' => $attachment['content'],
			);
		}

		$host = 'eu' === mailhouse_option( 'sparkpost_region', 'us' ) ? 'api.eu.sparkpost.com' : 'api.sparkpost.com';

		$response = $this->request(
			'https://' . $host . '/api/v1/transmissions',
			array(
				'headers' => array(
					'Authorization' => (string) mailhouse_option( 'sparkpost_key' ),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'options'    => array( 'transactional' => 'campaign' !== Mailhouse_Mailer::stream() ),
						'recipients' => $recipients,
						'content'    => $content,
					)
				),
			)
		);

		if ( $response['code'] < 200 || $response['code'] > 299 ) {
			$this->fail_response( $response );
		}

		return true;
	}

	/**
	 * One address as `Name <email>`.
	 *
	 * @param array $address Email/name pair.
	 * @return string
	 */
	public function line( $address ) {
		return mailhouse_format_address( $address['email'], $address['name'] );
	}
}
