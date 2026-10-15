<?php
/**
 * Base class for delivery transports.
 *
 * A transport is either something PHPMailer can drive itself (PHP mail, SMTP)
 * or an HTTP API. API transports receive the fully assembled PHPMailer object
 * and are responsible for turning it into whatever shape the provider wants.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * One way of getting mail off the server.
 */
abstract class Mailhouse_Provider {

	/**
	 * Machine id, matching the `mailer` setting.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * One line about when to pick this transport.
	 *
	 * @return string
	 */
	public function description() {
		return '';
	}

	/**
	 * Whether delivery happens over HTTP rather than through PHPMailer.
	 *
	 * @return bool
	 */
	public function is_api() {
		return true;
	}

	/**
	 * Whether the settings this transport needs have been filled in.
	 *
	 * @return bool
	 */
	public function is_configured() {
		foreach ( $this->fields() as $key => $field ) {
			if ( ! empty( $field['required'] ) && '' === (string) mailhouse_option( $key, '' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The settings this transport exposes.
	 *
	 * Each entry: label, type (text|password|number|select|checkbox), and
	 * optionally required, description, placeholder, options.
	 *
	 * @return array<string,array>
	 */
	public function fields() {
		return array();
	}

	/**
	 * Adjust the PHPMailer instance before sending.
	 *
	 * Only the non-API transports need this.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return void
	 */
	public function configure( $mail ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

	/**
	 * Deliver an assembled message.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return bool
	 * @throws PHPMailer\PHPMailer\Exception On any delivery failure.
	 */
	public function send( $mail ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return false;
	}

	/**
	 * Abort delivery with a message wp_mail() will turn into `wp_mail_failed`.
	 *
	 * @param string $message Reason.
	 * @return void
	 * @throws PHPMailer\PHPMailer\Exception Always.
	 */
	protected function fail( $message ) {
		throw new PHPMailer\PHPMailer\Exception( $this->label() . ': ' . $message );
	}

	/**
	 * POST to the provider and return the decoded body.
	 *
	 * @param string $url  Endpoint.
	 * @param array  $args Arguments for wp_remote_post.
	 * @return array{code:int,body:string,json:mixed}
	 * @throws PHPMailer\PHPMailer\Exception When the request itself fails.
	 */
	protected function request( $url, $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(),
				'user-agent'  => 'Mailhouse/' . MAILHOUSE_VERSION . '; ' . home_url(),
			)
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->fail( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		return array(
			'code' => $code,
			'body' => $body,
			'json' => json_decode( $body, true ),
		);
	}

	/**
	 * Turn an unsuccessful API response into a readable failure.
	 *
	 * Providers bury the useful sentence at different depths, so the common
	 * shapes are unwrapped here and anything unrecognised falls back to a
	 * trimmed body -- which still beats "delivery failed".
	 *
	 * @param array $response Result of request().
	 * @return void
	 * @throws PHPMailer\PHPMailer\Exception Always.
	 */
	protected function fail_response( $response ) {
		$json    = $response['json'];
		$message = '';

		if ( is_array( $json ) ) {
			if ( ! empty( $json['errors'] ) && is_array( $json['errors'] ) ) {
				$parts = array();

				foreach ( $json['errors'] as $error ) {
					if ( is_array( $error ) ) {
						$parts[] = isset( $error['message'] ) ? $error['message'] : wp_json_encode( $error );
					} else {
						$parts[] = (string) $error;
					}
				}

				$message = implode( '; ', $parts );
			} elseif ( ! empty( $json['message'] ) && is_string( $json['message'] ) ) {
				$message = $json['message'];
			} elseif ( ! empty( $json['Message'] ) && is_string( $json['Message'] ) ) {
				$message = $json['Message'];
			} elseif ( ! empty( $json['error'] ) ) {
				$message = is_array( $json['error'] ) && isset( $json['error']['message'] )
					? $json['error']['message']
					: wp_json_encode( $json['error'] );
			}
		}

		if ( '' === $message ) {
			$message = trim( wp_strip_all_tags( $response['body'] ) );
			$message = mb_substr( $message, 0, 300 );
		}

		if ( '' === $message ) {
			$message = __( 'no response body', 'mailhouse' );
		}

		$this->fail( sprintf( 'HTTP %d - %s', $response['code'], $message ) );
	}

	/**
	 * The To recipients as email/name pairs.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return array<int,array{email:string,name:string}>
	 */
	protected function to( $mail ) {
		return $this->addresses( $mail->getToAddresses() );
	}

	/**
	 * The Cc recipients.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return array
	 */
	protected function cc( $mail ) {
		return $this->addresses( $mail->getCcAddresses() );
	}

	/**
	 * The Bcc recipients.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return array
	 */
	protected function bcc( $mail ) {
		return $this->addresses( $mail->getBccAddresses() );
	}

	/**
	 * The Reply-To addresses.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return array
	 */
	protected function reply_to( $mail ) {
		return $this->addresses( array_values( $mail->getReplyToAddresses() ) );
	}

	/**
	 * Normalise PHPMailer's [address, name] tuples.
	 *
	 * @param array $list Tuples.
	 * @return array<int,array{email:string,name:string}>
	 */
	protected function addresses( $list ) {
		$out = array();

		foreach ( (array) $list as $item ) {
			if ( empty( $item[0] ) ) {
				continue;
			}

			$out[] = array(
				'email' => $item[0],
				'name'  => isset( $item[1] ) ? (string) $item[1] : '',
			);
		}

		return $out;
	}

	/**
	 * The HTML body, or an empty string for a plain-text message.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return string
	 */
	protected function html( $mail ) {
		return 'text/plain' === $mail->ContentType ? '' : (string) $mail->Body;
	}

	/**
	 * The plain-text body, generated from the HTML when none was supplied.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return string
	 */
	protected function text( $mail ) {
		if ( 'text/plain' === $mail->ContentType ) {
			return (string) $mail->Body;
		}

		if ( '' !== (string) $mail->AltBody ) {
			return (string) $mail->AltBody;
		}

		return mailhouse_html_to_text( (string) $mail->Body );
	}

	/**
	 * Attachments, read into memory and base64 encoded.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return array<int,array{name:string,type:string,content:string,cid:string,inline:bool}>
	 * @throws PHPMailer\PHPMailer\Exception When a file cannot be read.
	 */
	protected function attachments( $mail ) {
		$out = array();

		foreach ( $mail->getAttachments() as $attachment ) {
			$is_string = ! empty( $attachment[5] );
			$content   = '';

			if ( $is_string ) {
				$content = (string) $attachment[0];
			} else {
				$path = (string) $attachment[0];

				if ( ! is_readable( $path ) ) {
					$this->fail( sprintf( 'attachment could not be read: %s', $path ) );
				}

				$content = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}

			$out[] = array(
				'name'    => (string) $attachment[2],
				'type'    => $attachment[4] ? (string) $attachment[4] : 'application/octet-stream',
				'content' => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'cid'     => isset( $attachment[7] ) ? (string) $attachment[7] : '',
				'inline'  => isset( $attachment[6] ) && 'inline' === $attachment[6],
			);
		}

		return $out;
	}

	/**
	 * Custom headers worth forwarding.
	 *
	 * Anything the provider sets itself, or that only makes sense on a message
	 * we assembled locally, is dropped.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return array<string,string>
	 */
	protected function custom_headers( $mail ) {
		$skip = array(
			'from',
			'to',
			'cc',
			'bcc',
			'reply-to',
			'subject',
			'content-type',
			'content-transfer-encoding',
			'mime-version',
			'date',
			'message-id',
			'return-path',
			'x-mailer',
		);

		$out = array();

		foreach ( $mail->getCustomHeaders() as $header ) {
			$name = isset( $header[0] ) ? trim( (string) $header[0] ) : '';

			if ( '' === $name || in_array( strtolower( $name ), $skip, true ) ) {
				continue;
			}

			$out[ $name ] = isset( $header[1] ) ? (string) $header[1] : '';
		}

		return $out;
	}
}
