<?php
/**
 * The Mailjet transport.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends through Mailjet's Send API v3.1.
 */
class Mailhouse_Provider_Mailjet extends Mailhouse_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'mailjet';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Mailjet';
	}

	/**
	 * When to pick this.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Both keys come from Account settings, then API Key Management. The From address must be a sender or domain validated in Mailjet.', 'mailhouse' );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'mailjet_key'    => array(
				'label'    => __( 'API key', 'mailhouse' ),
				'type'     => 'text',
				'required' => true,
			),
			'mailjet_secret' => array(
				'label'       => __( 'Secret key', 'mailhouse' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Stored encrypted, or define MAILHOUSE_MAILJET_SECRET in wp-config.php.', 'mailhouse' ),
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
		$message = array(
			'From'    => $this->pair( $mail->From, $mail->FromName ),
			'To'      => $this->pairs( $this->to( $mail ) ),
			'Subject' => $mail->Subject,
		);

		if ( $this->cc( $mail ) ) {
			$message['Cc'] = $this->pairs( $this->cc( $mail ) );
		}

		if ( $this->bcc( $mail ) ) {
			$message['Bcc'] = $this->pairs( $this->bcc( $mail ) );
		}

		$text = $this->text( $mail );
		if ( '' !== $text ) {
			$message['TextPart'] = $text;
		}

		$html = $this->html( $mail );
		if ( '' !== $html ) {
			$message['HTMLPart'] = $html;
		}

		$reply_to = $this->reply_to( $mail );
		if ( $reply_to ) {
			$message['ReplyTo'] = $this->pair( $reply_to[0]['email'], $reply_to[0]['name'] );
		}

		$headers = $this->custom_headers( $mail );
		if ( $headers ) {
			$message['Headers'] = $headers;
		}

		foreach ( $this->attachments( $mail ) as $attachment ) {
			$item = array(
				'ContentType'   => $attachment['type'],
				'Filename'      => $attachment['name'],
				'Base64Content' => $attachment['content'],
			);

			if ( $attachment['inline'] && $attachment['cid'] ) {
				$item['ContentID']                 = $attachment['cid'];
				$message['InlinedAttachments'][] = $item;
			} else {
				$message['Attachments'][] = $item;
			}
		}

		$response = $this->request(
			'https://api.mailjet.com/v3.1/send',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( mailhouse_option( 'mailjet_key' ) . ':' . mailhouse_option( 'mailjet_secret' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'Messages' => array( $message ) ) ),
			)
		);

		$status = isset( $response['json']['Messages'][0]['Status'] ) ? $response['json']['Messages'][0]['Status'] : '';

		if ( $response['code'] < 200 || $response['code'] > 299 || 'success' !== $status ) {
			if ( ! empty( $response['json']['Messages'][0]['Errors'][0]['ErrorMessage'] ) ) {
				$this->fail( sprintf( 'HTTP %d - %s', $response['code'], $response['json']['Messages'][0]['Errors'][0]['ErrorMessage'] ) );
			}

			$this->fail_response( $response );
		}

		return true;
	}

	/**
	 * One address in Mailjet's shape.
	 *
	 * @param string $email Address.
	 * @param string $name  Name.
	 * @return array
	 */
	protected function pair( $email, $name ) {
		$out = array( 'Email' => $email );

		if ( '' !== (string) $name ) {
			$out['Name'] = (string) $name;
		}

		return $out;
	}

	/**
	 * A list of addresses in Mailjet's shape.
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
