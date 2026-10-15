<?php
/**
 * The SMTP transport.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends over SMTP, using PHPMailer's own client.
 */
class Mailhouse_Provider_SMTP extends Mailhouse_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'smtp';
	}

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'SMTP server', 'mailhouse' );
	}

	/**
	 * When to pick this.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Works with any mailbox provider or relay: Google Workspace, Microsoft 365, Fastmail, your host, or a transactional service that also offers SMTP.', 'mailhouse' );
	}

	/**
	 * Delivery is PHPMailer's own.
	 *
	 * @return bool
	 */
	public function is_api() {
		return false;
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'smtp_preset'     => array(
				'label'       => __( 'Quick setup', 'mailhouse' ),
				'type'        => 'select',
				'options'     => array(
					''          => __( 'Fill in my own server', 'mailhouse' ),
					'gmail'     => __( 'Gmail / Google Workspace', 'mailhouse' ),
					'microsoft' => __( 'Microsoft 365 / Exchange Online', 'mailhouse' ),
					'outlook'   => __( 'Outlook.com / Hotmail', 'mailhouse' ),
					'zoho'      => __( 'Zoho Mail', 'mailhouse' ),
					'yahoo'     => __( 'Yahoo Mail', 'mailhouse' ),
					'icloud'    => __( 'iCloud Mail', 'mailhouse' ),
					'ses'       => __( 'Amazon SES (SMTP)', 'mailhouse' ),
					'hostinger' => __( 'Hostinger', 'mailhouse' ),
				),
				'description' => __( 'Fills in the host, port and encryption. Gmail, Microsoft, Yahoo and iCloud need an app password (with two-step verification on), not your normal password -- or use an API provider above for anything beyond a small site.', 'mailhouse' ),
			),
			'smtp_host'       => array(
				'label'       => __( 'Host', 'mailhouse' ),
				'type'        => 'text',
				'required'    => true,
				'placeholder' => 'smtp.example.com',
			),
			'smtp_encryption' => array(
				'label'   => __( 'Encryption', 'mailhouse' ),
				'type'    => 'select',
				'options' => array(
					'tls'  => __( 'STARTTLS (usually port 587)', 'mailhouse' ),
					'ssl'  => __( 'SSL/TLS (usually port 465)', 'mailhouse' ),
					'none' => __( 'None', 'mailhouse' ),
				),
			),
			'smtp_port'       => array(
				'label' => __( 'Port', 'mailhouse' ),
				'type'  => 'number',
			),
			'smtp_autotls'    => array(
				'label'       => __( 'Upgrade to TLS when the server offers it', 'mailhouse' ),
				'type'        => 'checkbox',
				'description' => __( 'Leave on unless an unencrypted connection is deliberate.', 'mailhouse' ),
			),
			'smtp_auth'       => array(
				'label' => __( 'Use a username and password', 'mailhouse' ),
				'type'  => 'checkbox',
			),
			'smtp_user'       => array(
				'label' => __( 'Username', 'mailhouse' ),
				'type'  => 'text',
			),
			'smtp_pass'       => array(
				'label'       => __( 'Password', 'mailhouse' ),
				'type'        => 'password',
				'description' => __( 'Stored encrypted. For a shared or backed-up database, define MAILHOUSE_SMTP_PASS in wp-config.php instead and leave this empty.', 'mailhouse' ),
			),
			'smtp_timeout'    => array(
				'label' => __( 'Timeout (seconds)', 'mailhouse' ),
				'type'  => 'number',
			),
		);
	}

	/**
	 * A host is the one thing without a workable default.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== (string) mailhouse_option( 'smtp_host', '' );
	}

	/**
	 * Point PHPMailer at the SMTP server.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mail Message.
	 * @return void
	 */
	public function configure( $mail ) {
		$encryption = (string) mailhouse_option( 'smtp_encryption', 'tls' );

		$mail->isSMTP();
		$mail->Host        = (string) mailhouse_option( 'smtp_host' );
		$mail->Port        = (int) mailhouse_option( 'smtp_port', 587 );
		$mail->SMTPSecure  = in_array( $encryption, array( 'ssl', 'tls' ), true ) ? $encryption : '';
		$mail->SMTPAutoTLS = (bool) mailhouse_option( 'smtp_autotls', true ) && 'ssl' !== $encryption;
		$mail->SMTPAuth    = (bool) mailhouse_option( 'smtp_auth', true );
		$mail->Timeout     = max( 5, (int) mailhouse_option( 'smtp_timeout', 30 ) );

		if ( $mail->SMTPAuth ) {
			$mail->Username = (string) mailhouse_option( 'smtp_user' );
			$mail->Password = (string) mailhouse_option( 'smtp_pass' );
		}
	}
}
