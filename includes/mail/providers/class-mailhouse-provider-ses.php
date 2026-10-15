<?php
/**
 * The Amazon SES transport.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends through the Amazon SES v2 API, signed with AWS Signature Version 4.
 *
 * The message goes over as raw MIME, exactly as PHPMailer assembled it, so
 * attachments, inline images and custom headers need no translation.
 */
class Mailhouse_Provider_SES extends Mailhouse_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'ses';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Amazon SES';
	}

	/**
	 * When to pick this.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Create an IAM user allowed only ses:SendRawEmail and ses:SendEmail, and use its access key here. A new SES account is in the sandbox and can only mail verified addresses until AWS lifts it.', 'mailhouse' );
	}

	/**
	 * The regions SES runs in.
	 *
	 * @return string[]
	 */
	public static function regions() {
		return array(
			'us-east-1',
			'us-east-2',
			'us-west-1',
			'us-west-2',
			'ca-central-1',
			'sa-east-1',
			'eu-west-1',
			'eu-west-2',
			'eu-west-3',
			'eu-central-1',
			'eu-central-2',
			'eu-north-1',
			'eu-south-1',
			'il-central-1',
			'me-south-1',
			'af-south-1',
			'ap-south-1',
			'ap-northeast-1',
			'ap-northeast-2',
			'ap-northeast-3',
			'ap-southeast-1',
			'ap-southeast-2',
			'ap-southeast-3',
			'us-gov-west-1',
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'ses_access_key' => array(
				'label'    => __( 'Access key ID', 'mailhouse' ),
				'type'     => 'text',
				'required' => true,
			),
			'ses_secret_key' => array(
				'label'       => __( 'Secret access key', 'mailhouse' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Stored encrypted, or define MAILHOUSE_SES_SECRET_KEY in wp-config.php.', 'mailhouse' ),
			),
			'ses_region'     => array(
				'label'   => __( 'Region', 'mailhouse' ),
				'type'    => 'select',
				'options' => array_combine( self::regions(), self::regions() ),
			),
			'ses_config_set' => array(
				'label'       => __( 'Configuration set', 'mailhouse' ),
				'type'        => 'text',
				'description' => __( 'Optional. Needed if you want SES to report bounces and complaints through event destinations.', 'mailhouse' ),
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
		$region = (string) mailhouse_option( 'ses_region', 'us-east-1' );
		$region = in_array( $region, self::regions(), true ) ? $region : 'us-east-1';

		// wp_mail() leaves PHPMailer in `mail` mode, where To and Subject are
		// left out of the headers because PHP's mail() adds them. Rebuilt in
		// SMTP mode they are included, and Bcc is (rightly) left out -- it
		// goes in the envelope below instead.
		$mailer       = $mail->Mailer;
		$mail->Mailer = 'smtp';

		try {
			if ( ! $mail->preSend() ) {
				$this->fail( $mail->ErrorInfo );
			}

			$raw = $mail->getSentMIMEMessage();
		} finally {
			$mail->Mailer = $mailer;
		}

		$body = array(
			'FromEmailAddress' => $mail->From,
			'Destination'      => array(
				'ToAddresses'  => wp_list_pluck( $this->to( $mail ), 'email' ),
				'CcAddresses'  => wp_list_pluck( $this->cc( $mail ), 'email' ),
				'BccAddresses' => wp_list_pluck( $this->bcc( $mail ), 'email' ),
			),
			'Content'          => array(
				'Raw' => array(
					'Data' => base64_encode( $raw ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
			),
		);

		$config_set = trim( (string) mailhouse_option( 'ses_config_set', '' ) );

		if ( '' !== $config_set ) {
			$body['ConfigurationSetName'] = $config_set;
		}

		$payload = (string) wp_json_encode( $body );
		$host    = 'email.' . $region . '.amazonaws.com';
		$path    = '/v2/email/outbound-emails';

		$response = $this->request(
			'https://' . $host . $path,
			array(
				'headers' => self::sign(
					'POST',
					$host,
					$path,
					$payload,
					$region,
					trim( (string) mailhouse_option( 'ses_access_key' ) ),
					trim( (string) mailhouse_option( 'ses_secret_key' ) )
				),
				'body'    => $payload,
			)
		);

		if ( $response['code'] < 200 || $response['code'] > 299 ) {
			$this->fail_response( $response );
		}

		return true;
	}

	/**
	 * Headers for an AWS Signature Version 4 request.
	 *
	 * @param string $method  HTTP method.
	 * @param string $host    Host.
	 * @param string $path    Path.
	 * @param string $payload Body.
	 * @param string $region  Region.
	 * @param string $key     Access key id.
	 * @param string $secret  Secret key.
	 * @param int    $time    Unix time, for tests.
	 * @return array<string,string>
	 */
	public static function sign( $method, $host, $path, $payload, $region, $key, $secret, $time = 0 ) {
		$time      = $time ? $time : time();
		$amz_date  = gmdate( 'Ymd\THis\Z', $time );
		$datestamp = gmdate( 'Ymd', $time );
		$service   = 'ses';
		$type      = 'application/json';

		$canonical_headers = 'content-type:' . $type . "\n" . 'host:' . $host . "\n" . 'x-amz-date:' . $amz_date . "\n";
		$signed_headers    = 'content-type;host;x-amz-date';

		$canonical_request = implode(
			"\n",
			array(
				$method,
				$path,
				'',
				$canonical_headers,
				$signed_headers,
				hash( 'sha256', $payload ),
			)
		);

		$scope          = $datestamp . '/' . $region . '/' . $service . '/aws4_request';
		$string_to_sign = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );

		$signing_key = hash_hmac( 'sha256', $datestamp, 'AWS4' . $secret, true );
		$signing_key = hash_hmac( 'sha256', $region, $signing_key, true );
		$signing_key = hash_hmac( 'sha256', $service, $signing_key, true );
		$signing_key = hash_hmac( 'sha256', 'aws4_request', $signing_key, true );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		return array(
			'Content-Type'  => $type,
			'X-Amz-Date'    => $amz_date,
			'Authorization' => sprintf(
				'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
				$key,
				$scope,
				$signed_headers,
				$signature
			),
		);
	}
}
