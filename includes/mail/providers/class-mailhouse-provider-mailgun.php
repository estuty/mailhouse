<?php
/**
 * The Mailgun transport.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends through Mailgun's messages API.
 */
class Mailhouse_Provider_Mailgun extends Mailhouse_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'mailgun';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Mailgun';
	}

	/**
	 * When to pick this.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Use the sending domain exactly as it appears in Mailgun, and pick the region the domain was created in -- an EU domain will not answer on the US endpoint.', 'mailhouse' );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'mailgun_key'    => array(
				'label'       => __( 'API key', 'mailhouse' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'A sending key from Mailgun. Stored encrypted, or define MAILHOUSE_MAILGUN_KEY in wp-config.php.', 'mailhouse' ),
			),
			'mailgun_domain' => array(
				'label'       => __( 'Sending domain', 'mailhouse' ),
				'type'        => 'text',
				'required'    => true,
				'placeholder' => 'mg.example.com',
			),
			'mailgun_region' => array(
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
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return bool
	 * @throws PHPMailer\PHPMailer\Exception On failure.
	 */
	public function send( $mail ) {
		$fields = array(
			'from'    => $this->one_line( $mail->FromName, $mail->From ),
			'to'      => $this->join( $this->to( $mail ) ),
			'subject' => $mail->Subject,
		);

		$cc = $this->join( $this->cc( $mail ) );
		if ( '' !== $cc ) {
			$fields['cc'] = $cc;
		}

		$bcc = $this->join( $this->bcc( $mail ) );
		if ( '' !== $bcc ) {
			$fields['bcc'] = $bcc;
		}

		$text = $this->text( $mail );
		if ( '' !== $text ) {
			$fields['text'] = $text;
		}

		$html = $this->html( $mail );
		if ( '' !== $html ) {
			$fields['html'] = $html;
		}

		$reply_to = $this->reply_to( $mail );
		if ( $reply_to ) {
			$fields['h:Reply-To'] = $reply_to[0]['email'];
		}

		foreach ( $this->custom_headers( $mail ) as $name => $value ) {
			$fields[ 'h:' . $name ] = $value;
		}

		$files    = array();
		$position = 0;

		foreach ( $this->attachments( $mail ) as $attachment ) {
			$files[] = array(
				// Inline parts are referenced by cid: in the HTML, and Mailgun
				// matches them on filename.
				'field'    => $attachment['inline'] ? 'inline' : 'attachment',
				'name'     => $attachment['name'],
				'type'     => $attachment['type'],
				'content'  => base64_decode( $attachment['content'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				'position' => $position++,
			);
		}

		$endpoint = sprintf(
			'https://%s/v3/%s/messages',
			'eu' === mailhouse_option( 'mailgun_region', 'us' ) ? 'api.eu.mailgun.net' : 'api.mailgun.net',
			rawurlencode( (string) mailhouse_option( 'mailgun_domain' ) )
		);

		$headers = array(
			'Authorization' => 'Basic ' . base64_encode( 'api:' . mailhouse_option( 'mailgun_key' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);

		if ( $files ) {
			$boundary                = 'mailhouse' . wp_generate_password( 24, false );
			$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
			$body                    = $this->multipart( $fields, $files, $boundary );
		} else {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			$body                    = http_build_query( $fields );
		}

		$response = $this->request(
			$endpoint,
			array(
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( $response['code'] < 200 || $response['code'] > 299 ) {
			$this->fail_response( $response );
		}

		return true;
	}

	/**
	 * Build a multipart/form-data body.
	 *
	 * @param array  $fields   Scalar fields.
	 * @param array  $files    File parts.
	 * @param string $boundary Boundary string.
	 * @return string
	 */
	protected function multipart( $fields, $files, $boundary ) {
		$body = '';

		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
			$body .= $value . "\r\n";
		}

		foreach ( $files as $file ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $file['field'] . '"; filename="' . $file['name'] . '"' . "\r\n";
			$body .= 'Content-Type: ' . $file['type'] . "\r\n\r\n";
			$body .= $file['content'] . "\r\n";
		}

		return $body . '--' . $boundary . "--\r\n";
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
