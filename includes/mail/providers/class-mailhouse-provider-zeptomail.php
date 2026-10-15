<?php
/**
 * The Zoho ZeptoMail transport.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends through ZeptoMail's email API.
 */
class Mailhouse_Provider_ZeptoMail extends Mailhouse_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'zeptomail';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Zoho ZeptoMail';
	}

	/**
	 * When to pick this.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'ZeptoMail is for transactional mail only; Zoho does not allow newsletters through it. Copy the Send Mail token from the Mail Agent, and pick the data centre the account lives in.', 'mailhouse' );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'zeptomail_token'  => array(
				'label'       => __( 'Send Mail token', 'mailhouse' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'With or without the "Zoho-enczapikey" prefix. Stored encrypted, or define MAILHOUSE_ZEPTOMAIL_TOKEN in wp-config.php.', 'mailhouse' ),
			),
			'zeptomail_region' => array(
				'label'   => __( 'Data centre', 'mailhouse' ),
				'type'    => 'select',
				'options' => array(
					'com'    => __( 'United States (zeptomail.com)', 'mailhouse' ),
					'eu'     => __( 'Europe (zeptomail.eu)', 'mailhouse' ),
					'in'     => __( 'India (zeptomail.in)', 'mailhouse' ),
					'com.au' => __( 'Australia (zeptomail.com.au)', 'mailhouse' ),
					'jp'     => __( 'Japan (zeptomail.jp)', 'mailhouse' ),
					'ca'     => __( 'Canada (zeptomail.ca)', 'mailhouse' ),
					'sa'     => __( 'Saudi Arabia (zeptomail.sa)', 'mailhouse' ),
				),
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
			'from'    => array(
				'address' => $mail->From,
				'name'    => $mail->FromName,
			),
			'to'      => $this->wrapped( $this->to( $mail ) ),
			'subject' => $mail->Subject,
		);

		if ( $this->cc( $mail ) ) {
			$body['cc'] = $this->wrapped( $this->cc( $mail ) );
		}

		if ( $this->bcc( $mail ) ) {
			$body['bcc'] = $this->wrapped( $this->bcc( $mail ) );
		}

		$html = $this->html( $mail );
		if ( '' !== $html ) {
			$body['htmlbody'] = $html;
		}

		$text = $this->text( $mail );
		if ( '' !== $text ) {
			$body['textbody'] = $text;
		}

		foreach ( $this->reply_to( $mail ) as $address ) {
			$body['reply_to'][] = array(
				'address' => $address['email'],
				'name'    => $address['name'],
			);
		}

		$headers = $this->custom_headers( $mail );
		if ( $headers ) {
			$body['mime_headers'] = $headers;
		}

		foreach ( $this->attachments( $mail ) as $attachment ) {
			if ( $attachment['inline'] && $attachment['cid'] ) {
				$body['inline_images'][] = array(
					'content'   => $attachment['content'],
					'mime_type' => $attachment['type'],
					'cid'       => $attachment['cid'],
				);
				continue;
			}

			$body['attachments'][] = array(
				'content'   => $attachment['content'],
				'mime_type' => $attachment['type'],
				'name'      => $attachment['name'],
			);
		}

		$token = trim( (string) mailhouse_option( 'zeptomail_token' ) );
		$token = 0 === stripos( $token, 'Zoho-enczapikey' ) ? $token : 'Zoho-enczapikey ' . $token;

		$regions = array_keys( $this->fields()['zeptomail_region']['options'] );
		$region  = (string) mailhouse_option( 'zeptomail_region', 'com' );
		$region  = in_array( $region, $regions, true ) ? $region : 'com';

		$response = $this->request(
			'https://api.zeptomail.' . $region . '/v1.1/email',
			array(
				'headers' => array(
					'Authorization' => $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( $response['code'] < 200 || $response['code'] > 299 ) {
			if ( ! empty( $response['json']['error']['details'][0]['message'] ) ) {
				$this->fail( sprintf( 'HTTP %d - %s', $response['code'], $response['json']['error']['details'][0]['message'] ) );
			}

			$this->fail_response( $response );
		}

		return true;
	}

	/**
	 * Addresses in ZeptoMail's nested shape.
	 *
	 * @param array $addresses Email/name pairs.
	 * @return array
	 */
	protected function wrapped( $addresses ) {
		$out = array();

		foreach ( $addresses as $address ) {
			$out[] = array(
				'email_address' => array(
					'address' => $address['email'],
					'name'    => $address['name'],
				),
			);
		}

		return $out;
	}
}
