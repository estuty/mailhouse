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
	 * The provider's answer to the request most recently made, or null when
	 * no request has been made for the message in hand.
	 *
	 * @var array|null
	 */
	protected $last_response = null;

	/**
	 * Forget the last message. Called before each one, because one instance
	 * of a transport sends every message in a request.
	 *
	 * @return void
	 */
	public function begin() {
		$this->last_response = null;
	}

	/**
	 * What the provider called the message it just accepted.
	 *
	 * This is the id its bounce and delivery callbacks quote later, and so
	 * the only way to tie one of those back to a message that was sent.
	 *
	 * @return string The id, or '' when the provider gave none.
	 */
	public function message_id() {
		return '';
	}

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
		if ( ! Mailhouse_Mailer::has_result() ) {
			Mailhouse_Mailer::set_result(
				array_merge(
					$this->classify_response(),
					array( 'error' => $this->label() . ': ' . $message )
				)
			);
		}

		throw new PHPMailer\PHPMailer\Exception( $this->label() . ': ' . $message );
	}

	/**
	 * Sort a failure into "it did not go" and "nobody knows", from the
	 * provider's answer.
	 *
	 * @return array{outcome:string,permanent:bool}
	 */
	protected function classify_response() {
		$rejected = static function ( $permanent ) {
			return array(
				'outcome'   => 'rejected',
				'permanent' => (bool) $permanent,
			);
		};

		// No request was made: a missing key, an address the provider cannot
		// take. Nothing was sent; fixing the cause may let it through.
		if ( null === $this->last_response ) {
			return $rejected( false );
		}

		$code = (int) $this->last_response['code'];

		// "Not now": the provider answered, and the answer was to come back.
		if ( in_array( $code, array( 408, 425, 429, 503 ), true ) ) {
			return $rejected( false );
		}

		// Any other server error does not say whether the message was taken
		// before things went wrong.
		if ( $code >= 500 ) {
			return array(
				'outcome'   => 'ambiguous',
				'permanent' => false,
			);
		}

		// A client error is the provider refusing this request as it stands.
		// So is a refusal delivered inside a 200, which several providers do.
		if ( $code >= 200 && ( $code < 300 || $code >= 400 ) ) {
			return $rejected( true );
		}

		return $rejected( false );
	}

	/**
	 * Sort a failure of the HTTP request itself.
	 *
	 * Only a failure that happens before a request could have been written
	 * -- the name did not resolve, nothing answered, the TLS handshake fell
	 * over -- counts as "it did not go". A timeout or a dropped connection
	 * after that point is ambiguous: the provider may have the message.
	 *
	 * @param string $message The transport's error message.
	 * @return array{outcome:string,permanent:bool}
	 */
	public static function classify_transport_error( $message ) {
		$never_left = array(
			'outcome'   => 'rejected',
			'permanent' => false,
		);

		if ( preg_match( '/cURL error (\d+)/', (string) $message, $matches ) ) {
			$code = (int) $matches[1];

			// Unsupported protocol, bad URL, proxy or host not resolved,
			// connection refused, and the TLS set-up failures.
			if ( in_array( $code, array( 1, 3, 5, 6, 7, 35, 58, 59, 60, 77, 83, 90, 91 ), true ) ) {
				return $never_left;
			}

			// A timeout is only safe when it was the connection that timed
			// out, not the wait for an answer.
			if ( 28 === $code && preg_match( '/Connection timed out|Resolving timed out|Failed to connect/i', (string) $message ) ) {
				return $never_left;
			}
		}

		return array(
			'outcome'   => 'ambiguous',
			'permanent' => false,
		);
	}

	/**
	 * A header from the provider's last answer.
	 *
	 * @param string $name Header name, any case.
	 * @return string
	 */
	protected function response_header( $name ) {
		$headers = isset( $this->last_response['headers'] ) ? $this->last_response['headers'] : array();
		$value   = '';

		if ( $headers instanceof ArrayAccess ) {
			$value = isset( $headers[ $name ] ) ? $headers[ $name ] : '';
		} elseif ( is_array( $headers ) ) {
			foreach ( $headers as $key => $candidate ) {
				if ( 0 === strcasecmp( (string) $key, $name ) ) {
					$value = $candidate;
					break;
				}
			}
		}

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * A value from the JSON body of the provider's last answer.
	 *
	 * @param array $path Keys to follow, outermost first.
	 * @return string The value when it is a string or number, otherwise ''.
	 */
	protected function response_value( array $path ) {
		$value = isset( $this->last_response['json'] ) ? $this->last_response['json'] : null;

		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return '';
			}

			$value = $value[ $key ];
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * POST to the provider and return the decoded body.
	 *
	 * @param string $url  Endpoint.
	 * @param array  $args Arguments for wp_remote_post.
	 * @return array{code:int,body:string,json:mixed,headers:mixed}
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
			Mailhouse_Mailer::set_result(
				array_merge(
					self::classify_transport_error( $response->get_error_message() ),
					array( 'error' => $this->label() . ': ' . $response->get_error_message() )
				)
			);

			$this->fail( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		$this->last_response = array(
			'code'    => $code,
			'body'    => $body,
			'json'    => json_decode( $body, true ),
			'headers' => wp_remote_retrieve_headers( $response ),
		);

		return $this->last_response;
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
