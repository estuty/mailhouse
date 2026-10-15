<?php
/**
 * The Postmark transport.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends through Postmark's email API.
 */
class Mailhouse_Provider_Postmark extends Mailhouse_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'postmark';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Postmark';
	}

	/**
	 * When to pick this.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Postmark separates transactional mail from bulk mail. Put the newsletter on a broadcast stream and everything else on the default outbound stream, or they will suspend the account.', 'mailhouse' );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'postmark_token'  => array(
				'label'       => __( 'Server API token', 'mailhouse' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'From the API Tokens tab of a Postmark server. Stored encrypted, or define MAILHOUSE_POSTMARK_TOKEN in wp-config.php.', 'mailhouse' ),
			),
			'postmark_stream' => array(
				'label'       => __( 'Message stream', 'mailhouse' ),
				'type'        => 'text',
				'placeholder' => 'outbound',
				'description' => __( 'The stream ID used for site email. Campaigns can use a different one, set under Newsletter.', 'mailhouse' ),
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
		$stream = (string) mailhouse_option( 'postmark_stream', 'outbound' );

		$body = array(
			'From'          => $this->one_line( $mail->FromName, $mail->From ),
			'To'            => $this->join( $this->to( $mail ) ),
			'Subject'       => $mail->Subject,
			'MessageStream' => $stream ? $stream : 'outbound',
		);

		$cc = $this->join( $this->cc( $mail ) );
		if ( '' !== $cc ) {
			$body['Cc'] = $cc;
		}

		$bcc = $this->join( $this->bcc( $mail ) );
		if ( '' !== $bcc ) {
			$body['Bcc'] = $bcc;
		}

		$html = $this->html( $mail );
		if ( '' !== $html ) {
			$body['HtmlBody'] = $html;
		}

		$text = $this->text( $mail );
		if ( '' !== $text ) {
			$body['TextBody'] = $text;
		}

		$reply_to = $this->reply_to( $mail );
		if ( $reply_to ) {
			$body['ReplyTo'] = $this->one_line( $reply_to[0]['name'], $reply_to[0]['email'] );
		}

		foreach ( $this->custom_headers( $mail ) as $name => $value ) {
			$body['Headers'][] = array(
				'Name'  => $name,
				'Value' => $value,
			);
		}

		foreach ( $this->attachments( $mail ) as $attachment ) {
			$item = array(
				'Name'        => $attachment['name'],
				'Content'     => $attachment['content'],
				'ContentType' => $attachment['type'],
			);

			if ( $attachment['inline'] && $attachment['cid'] ) {
				$item['ContentID'] = 'cid:' . $attachment['cid'];
			}

			$body['Attachments'][] = $item;
		}

		$response = $this->request(
			'https://api.postmarkapp.com/email',
			array(
				'headers' => array(
					'X-Postmark-Server-Token' => mailhouse_option( 'postmark_token' ),
					'Content-Type'            => 'application/json',
					'Accept'                  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		// Postmark answers 200 with a non-zero ErrorCode for things it accepted
		// the shape of but refused to send, such as an inactive recipient.
		$error_code = isset( $response['json']['ErrorCode'] ) ? (int) $response['json']['ErrorCode'] : 0;

		if ( $response['code'] < 200 || $response['code'] > 299 || 0 !== $error_code ) {
			$this->fail_response( $response );
		}

		return true;
	}

	/**
	 * Addresses as one comma-separated header value.
	 *
	 * @param array $addresses Email/name pairs.
	 * @return string
	 */
	protected function join( $addresses ) {
		$parts = array();

		foreach ( $addresses as $address ) {
			$parts[] = $this->one_line( $address['name'], $address['email'] );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Format one address as `Name <email>`.
	 *
	 * @param string $name  Display name.
	 * @param string $email Address.
	 * @return string
	 */
	protected function one_line( $name, $email ) {
		return mailhouse_format_address( $email, $name );
	}
}
