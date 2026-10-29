<?php
/**
 * The settings screen.
 *
 * Fields are declared once, as data, and rendered and sanitised from the same
 * declaration -- so adding a setting is one array entry rather than three
 * edits that can drift apart.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the settings.
 */
class Mailhouse_Admin_Settings {

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_action( 'admin_post_mailhouse_save_settings', array( $this, 'save' ) );
		add_action( 'admin_post_mailhouse_rotate_webhook_key', array( $this, 'rotate_webhook_key' ) );
	}

	/**
	 * Replace the webhook secret.
	 *
	 * @return void
	 */
	public function rotate_webhook_key() {
		check_admin_referer( 'mailhouse_rotate_webhook_key' );

		if ( ! mailhouse_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mailhouse' ), '', array( 'response' => 403 ) );
		}

		Mailhouse_Webhooks::rotate_key();

		wp_safe_redirect( mailhouse_admin_url( 'settings', array( 'tab' => 'log', 'updated' => 1 ) ) );
		exit;
		add_action( 'admin_post_mailhouse_preview_template', array( $this, 'preview' ) );
	}

	/**
	 * The tab currently being viewed.
	 *
	 * @return string
	 */
	protected function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'delivery';
		$tabs = $this->tabs();

		return isset( $tabs[ $tab ] ) ? $tab : 'delivery';
	}

	/**
	 * Every tab, section and field.
	 *
	 * @return array
	 */
	protected function tabs() {
		$tabs = array(
			'delivery'   => array(
				'label'    => __( 'Delivery', 'mailhouse' ),
				'sections' => array(
					array(
						'title'  => __( 'Who the mail comes from', 'mailhouse' ),
						'fields' => array(
							'from_email'       => array(
								'label'       => __( 'From address', 'mailhouse' ),
								'type'        => 'email',
								'description' => __( 'Use an address at your own domain. A Gmail or Yahoo address here will be rejected by most inboxes, because you cannot prove you are allowed to send as them.', 'mailhouse' ),
							),
							'from_name'        => array(
								'label' => __( 'From name', 'mailhouse' ),
								'type'  => 'text',
							),
							'force_from_email' => array(
								'label'       => __( 'Force this address on every email', 'mailhouse' ),
								'type'        => 'checkbox',
								'description' => __( 'Overrides plugins that set their own sender. Turn it off if a shop or form plugin needs to send as somebody else.', 'mailhouse' ),
							),
							'force_from_name'  => array(
								'label' => __( 'Force this name on every email', 'mailhouse' ),
								'type'  => 'checkbox',
							),
							'reply_to'         => array(
								'label'       => __( 'Default Reply-To', 'mailhouse' ),
								'type'        => 'email',
								'description' => __( 'Where replies should land, when the sending address is not a mailbox anybody reads.', 'mailhouse' ),
							),
							'return_path'      => array(
								'label'       => __( 'Send bounces back to the From address', 'mailhouse' ),
								'type'        => 'checkbox',
								'description' => __( 'Otherwise they go to the web server, where nobody will ever see them.', 'mailhouse' ),
							),
						),
					),
					array(
						'title'  => __( 'Transport', 'mailhouse' ),
						'fields' => array(
							'mailer' => array(
								'label'   => __( 'Send email using', 'mailhouse' ),
								'type'    => 'mailer',
								'options' => array(),
							),
						),
					),
					array(
						'title'       => __( 'Backup and newsletter transport', 'mailhouse' ),
						'description' => __( 'Optional. The settings for whichever transport you pick here appear above, under the transport list.', 'mailhouse' ),
						'fields'      => array(
							'fallback_mailer' => array(
								'label'       => __( 'If sending fails, try', 'mailhouse' ),
								'type'        => 'provider',
								'empty'       => __( 'Nothing -- report the failure', 'mailhouse' ),
								'description' => __( 'A message the main transport refuses is retried once through this one, so an expired API key or a provider outage does not stop password resets.', 'mailhouse' ),
							),
							'campaign_mailer' => array(
								'label'       => __( 'Send newsletters using', 'mailhouse' ),
								'type'        => 'provider',
								'empty'       => __( 'The same transport as everything else', 'mailhouse' ),
								'description' => __( 'Campaigns, notifications and confirmation emails go through this one. Keeping bulk mail apart means a spam complaint about a newsletter cannot hurt the delivery of receipts and password resets.', 'mailhouse' ),
							),
						),
					),
				),
			),
			'newsletter' => array(
				'label'    => __( 'Newsletter', 'mailhouse' ),
				'sections' => array(
					array(
						'title'  => __( 'Campaign sender', 'mailhouse' ),
						'fields' => array(
							'campaign_from_name'  => array(
								'label' => __( 'From name', 'mailhouse' ),
								'type'  => 'text',
							),
							'campaign_from_email' => array(
								'label'       => __( 'From address', 'mailhouse' ),
								'type'        => 'email',
								'description' => __( 'Worth keeping separate from transactional mail: if the newsletter gets marked as spam, receipts and password resets are not dragged down with it.', 'mailhouse' ),
							),
							'campaign_reply_to'   => array(
								'label' => __( 'Reply-To', 'mailhouse' ),
								'type'  => 'email',
							),
						),
					),
					array(
						'title'  => __( 'Sending', 'mailhouse' ),
						'fields' => array(
							'batch_size' => array(
								'label'       => __( 'Emails per minute', 'mailhouse' ),
								'type'        => 'number',
								'description' => __( 'The queue runs once a minute and sends this many. Keep it under whatever your host or provider allows -- 20 a minute is 28,000 a day.', 'mailhouse' ),
							),
						),
					),
					array(
						'title'       => __( 'Tracking', 'mailhouse' ),
						'description' => __( 'Both are off by default. Tracking opens means embedding an invisible image that reports when somebody reads their mail; tracking clicks means routing every link through this site. Turn them on deliberately, and say so in your privacy policy.', 'mailhouse' ),
						'fields'      => array(
							'track_opens'  => array(
								'label'       => __( 'Track opens', 'mailhouse' ),
								'type'        => 'checkbox',
								'description' => __( 'Apple Mail loads the image whether or not anyone read the email, so treat the number as a rough signal, not a fact.', 'mailhouse' ),
							),
							'track_clicks' => array(
								'label' => __( 'Track clicks', 'mailhouse' ),
								'type'  => 'checkbox',
							),
						),
					),
				),
			),
			'design'     => array(
				'label'    => __( 'Email design', 'mailhouse' ),
				'sections' => array(
					array(
						'title'  => __( 'Every email', 'mailhouse' ),
						'fields' => array(
							'template_wrap_all' => array(
								'label'       => __( 'Use this design for all email the site sends', 'mailhouse' ),
								'type'        => 'checkbox',
								'description' => __( 'Password resets, new account emails, comment notices and other plugins\' messages get the same header and footer as the newsletter. Emails that arrive as a complete designed page (most shop receipts) are left as they are.', 'mailhouse' ),
							),
						),
					),
					array(
						'title'  => __( 'Header', 'mailhouse' ),
						'fields' => array(
							'template_logo'       => array(
								'label'       => __( 'Logo', 'mailhouse' ),
								'type'        => 'image',
								'description' => __( 'PNG or JPG, about twice the width it is shown at so it stays sharp. Leave empty to show the site title instead.', 'mailhouse' ),
							),
							'template_logo_width' => array(
								'label'       => __( 'Logo width (pixels)', 'mailhouse' ),
								'type'        => 'number',
								'description' => __( 'Between 40 and 560. 180 suits most logos.', 'mailhouse' ),
							),
							'template_header_bg'  => array(
								'label' => __( 'Header background', 'mailhouse' ),
								'type'  => 'color',
							),
						),
					),
					array(
						'title'  => __( 'Colours', 'mailhouse' ),
						'fields' => array(
							'template_accent' => array(
								'label'       => __( 'Brand colour (links and the line under the header)', 'mailhouse' ),
								'type'        => 'color',
							),
							'template_button' => array(
								'label'       => __( 'Button colour', 'mailhouse' ),
								'type'        => 'color',
								'description' => __( 'The button text turns black or white automatically, whichever is easier to read.', 'mailhouse' ),
							),
							'template_bg'     => array(
								'label' => __( 'Page background', 'mailhouse' ),
								'type'  => 'color',
							),
						),
					),
					array(
						'title'  => __( 'Footer', 'mailhouse' ),
						'fields' => array(
							'template_footer'  => array(
								'label'       => __( 'Footer text', 'mailhouse' ),
								'type'        => 'textarea',
								'description' => __( 'For example, why people are getting this email. Shown on every email, above the address.', 'mailhouse' ),
							),
							'company_address'  => array(
								'label'       => __( 'Postal address', 'mailhouse' ),
								'type'        => 'textarea',
								'description' => __( 'A physical address in bulk email is required by CAN-SPAM in the US and expected everywhere else.', 'mailhouse' ),
							),
							'social_facebook'  => array(
								'label'       => __( 'Facebook page', 'mailhouse' ),
								'type'        => 'url',
								'placeholder' => 'https://facebook.com/...',
							),
							'social_x'         => array(
								'label'       => __( 'X (Twitter)', 'mailhouse' ),
								'type'        => 'url',
								'placeholder' => 'https://x.com/...',
							),
							'social_instagram' => array(
								'label'       => __( 'Instagram', 'mailhouse' ),
								'type'        => 'url',
								'placeholder' => 'https://instagram.com/...',
							),
							'social_linkedin'  => array(
								'label'       => __( 'LinkedIn', 'mailhouse' ),
								'type'        => 'url',
								'placeholder' => 'https://linkedin.com/...',
							),
							'social_youtube'   => array(
								'label'       => __( 'YouTube', 'mailhouse' ),
								'type'        => 'url',
								'placeholder' => 'https://youtube.com/...',
							),
							'social_tiktok'    => array(
								'label'       => __( 'TikTok', 'mailhouse' ),
								'type'        => 'url',
								'placeholder' => 'https://tiktok.com/@...',
							),
						),
					),
					array(
						'title'  => __( 'See it', 'mailhouse' ),
						'fields' => array(
							'template_preview' => array(
								'label' => __( 'Preview', 'mailhouse' ),
								'type'  => 'preview',
							),
						),
					),
				),
			),
			'signup'     => array(
				'label'    => __( 'Signups', 'mailhouse' ),
				'sections' => array(
					array(
						'title'  => __( 'Opt-in', 'mailhouse' ),
						'fields' => array(
							'double_optin'        => array(
								'label'       => __( 'Require email confirmation (double opt-in)', 'mailhouse' ),
								'type'        => 'checkbox',
								'description' => __( 'Strongly recommended. Without it anybody can add any address to your list, and your delivery rate pays for it.', 'mailhouse' ),
							),
							'welcome_enabled'     => array(
								'label' => __( 'Send the welcome email after confirmation', 'mailhouse' ),
								'type'  => 'checkbox',
							),
							'unsubscribe_confirm' => array(
								'label'       => __( 'Ask for confirmation before unsubscribing', 'mailhouse' ),
								'type'        => 'checkbox',
								'description' => __( 'Useful where corporate mail scanners follow every link in a message and unsubscribe people by accident.', 'mailhouse' ),
							),
							'rate_limit'          => array(
								'label'       => __( 'Signups allowed per IP per hour', 'mailhouse' ),
								'type'        => 'number',
								'description' => __( 'Zero turns the limit off.', 'mailhouse' ),
							),
						),
					),
					array(
						'title'  => __( 'Form defaults', 'mailhouse' ),
						'fields' => array(
							'form_title'        => array(
								'label' => __( 'Heading', 'mailhouse' ),
								'type'  => 'text',
							),
							'form_description'  => array(
								'label' => __( 'Description', 'mailhouse' ),
								'type'  => 'textarea',
							),
							'form_button'       => array(
								'label'       => __( 'Button label', 'mailhouse' ),
								'type'        => 'text',
								'placeholder' => __( 'Subscribe', 'mailhouse' ),
							),
							'form_name_fields'  => array(
								'label'   => __( 'Name fields', 'mailhouse' ),
								'type'    => 'select',
								'options' => array(
									'none'  => __( 'Email only', 'mailhouse' ),
									'first' => __( 'First name', 'mailhouse' ),
									'full'  => __( 'First and last name', 'mailhouse' ),
								),
							),
							'form_consent'      => array(
								'label' => __( 'Show a consent checkbox', 'mailhouse' ),
								'type'  => 'checkbox',
							),
							'form_consent_text' => array(
								'label' => __( 'Consent wording', 'mailhouse' ),
								'type'  => 'text',
							),
							'form_success'      => array(
								'label'       => __( 'Message after signing up', 'mailhouse' ),
								'type'        => 'text',
								'description' => __( 'The same message is shown whether the address was new or already on the list -- anything else would let a stranger test who is subscribed.', 'mailhouse' ),
							),
							'form_styles'       => array(
								'label'       => __( 'Load the built-in form stylesheet', 'mailhouse' ),
								'type'        => 'checkbox',
								'description' => __( 'Turn off if your theme styles the form itself.', 'mailhouse' ),
							),
						),
					),
					array(
						'title'  => __( 'Elsewhere on the site', 'mailhouse' ),
						'fields' => array(
							'subscribe_comment'  => array(
								'label' => __( 'Offer signup on the comment form', 'mailhouse' ),
								'type'  => 'checkbox',
							),
							'subscribe_register' => array(
								'label' => __( 'Offer signup on the registration form', 'mailhouse' ),
								'type'  => 'checkbox',
							),
							'comment_label'      => array(
								'label'       => __( 'Checkbox wording', 'mailhouse' ),
								'type'        => 'text',
								'placeholder' => __( 'Email me the newsletter', 'mailhouse' ),
							),
						),
					),
				),
			),
			'log'        => array(
				'label'    => __( 'Log & alerts', 'mailhouse' ),
				'sections' => array(
					array(
						'title'  => __( 'Email log', 'mailhouse' ),
						'fields' => array(
							'log_enabled'        => array(
								'label' => __( 'Record every email', 'mailhouse' ),
								'type'  => 'checkbox',
							),
							'log_save_content'   => array(
								'label'       => __( 'Save message content too', 'mailhouse' ),
								'type'        => 'checkbox',
								'description' => __( 'Needed to resend from the log. It also means password reset links and order details sit in the database until the retention window passes -- leave it off unless you are debugging.', 'mailhouse' ),
							),
							'log_retention_days' => array(
								'label'       => __( 'Keep entries for (days)', 'mailhouse' ),
								'type'        => 'number',
								'description' => __( 'Zero keeps them forever, which no site actually wants.', 'mailhouse' ),
							),
						),
					),
					array(
						'title'  => __( 'Failure alerts', 'mailhouse' ),
						'fields' => array(
							'alerts_enabled' => array(
								'label'       => __( 'Email me when delivery fails', 'mailhouse' ),
								'type'        => 'checkbox',
								'description' => __( 'At most one alert an hour, so a broken transport cannot flood you.', 'mailhouse' ),
							),
							'alerts_email'   => array(
								'label' => __( 'Send alerts to', 'mailhouse' ),
								'type'  => 'email',
							),
						),
					),
					array(
						'title'       => __( 'Bounces and complaints', 'mailhouse' ),
						'description' => __( 'A bounce arrives at your provider hours after the send, so they have to tell you about it. Give them this address and Mailhouse retires dead addresses by itself, which is the single most useful thing you can do for your sending reputation.', 'mailhouse' ),
						'fields'      => array(
							'webhook'               => array(
								'label' => __( 'Endpoint', 'mailhouse' ),
								'type'  => 'webhook',
							),
							'soft_bounce_limit'     => array(
								'label'       => __( 'Soft bounces before an address is retired', 'mailhouse' ),
								'type'        => 'number',
								'description' => __( 'A full mailbox is temporary, so these are counted rather than acted on. The count is cleared whenever the provider confirms a delivery. Zero never retires an address on soft bounces alone.', 'mailhouse' ),
							),
							'webhook_mailgun_key'   => array(
								'label'       => __( 'Mailgun webhook signing key', 'mailhouse' ),
								'type'        => 'password',
								'description' => __( 'From Mailgun, under Sending -> Webhooks. With it set, unsigned callbacks are refused.', 'mailhouse' ),
							),
							'webhook_resend_secret' => array(
								'label'       => __( 'Resend signing secret', 'mailhouse' ),
								'type'        => 'password',
								'description' => __( 'The whsec_... value shown when you add the webhook in Resend.', 'mailhouse' ),
							),
							'webhook_sendgrid_key'  => array(
								'label'       => __( 'SendGrid verification key', 'mailhouse' ),
								'type'        => 'text',
								'description' => __( 'The public key SendGrid shows after you turn on signed event webhooks. Not a secret, so it is stored as it is.', 'mailhouse' ),
							),
						),
					),
				),
			),
			'advanced'   => array(
				'label'    => __( 'Advanced', 'mailhouse' ),
				'sections' => array(
					array(
						'title'  => __( 'Advanced', 'mailhouse' ),
						'fields' => array(
							'trust_proxy' => array(
								'label'       => __( 'Trust proxy headers for visitor IPs', 'mailhouse' ),
								'type'        => 'checkbox',
								'description' => __( 'Only turn this on behind a proxy or CDN you control. Anyone can forge these headers, which would defeat the signup rate limit.', 'mailhouse' ),
							),
							'delete_data' => array(
								'label'       => __( 'Delete everything when the plugin is uninstalled', 'mailhouse' ),
								'type'        => 'checkbox',
								'description' => __( 'Off by default. Deleting a plugin should not silently destroy a mailing list.', 'mailhouse' ),
							),
						),
					),
				),
			),
		);

		/**
		 * Filter the settings screen definition.
		 *
		 * @param array $tabs Tabs, sections and fields.
		 */
		return apply_filters( 'mailhouse_settings_tabs', $tabs );
	}

	/**
	 * Print the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! mailhouse_can_manage() ) {
			return;
		}

		$tabs    = $this->tabs();
		$current = $this->current_tab();

		?>
		<div class="wrap mailhouse">
			<h1><?php esc_html_e( 'Mailhouse settings', 'mailhouse' ); ?></h1>

			<?php $this->notice(); ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $tab ) : ?>
					<a href="<?php echo esc_url( mailhouse_admin_url( 'settings', array( 'tab' => $key ) ) ); ?>"
						class="nav-tab <?php echo $key === $current ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mailhouse_save_settings" />
				<input type="hidden" name="tab" value="<?php echo esc_attr( $current ); ?>" />
				<?php wp_nonce_field( 'mailhouse_save_settings' ); ?>

				<?php foreach ( $tabs[ $current ]['sections'] as $section ) : ?>
					<h2><?php echo esc_html( $section['title'] ); ?></h2>

					<?php if ( ! empty( $section['description'] ) ) : ?>
						<p class="description"><?php echo esc_html( $section['description'] ); ?></p>
					<?php endif; ?>

					<table class="form-table" role="presentation">
						<?php foreach ( $section['fields'] as $key => $field ) : ?>
							<?php $this->field_row( $key, $field ); ?>
						<?php endforeach; ?>
					</table>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Print the save notice.
	 *
	 * @return void
	 */
	protected function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['updated'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Settings saved.', 'mailhouse' )
		);
	}

	/**
	 * Print one row of the settings table.
	 *
	 * @param string $key   Setting key.
	 * @param array  $field Field definition.
	 * @return void
	 */
	protected function field_row( $key, $field ) {
		$field = wp_parse_args(
			$field,
			array(
				'label'       => '',
				'type'        => 'text',
				'description' => '',
				'placeholder' => '',
				'options'     => array(),
			)
		);

		echo '<tr>';
		printf( '<th scope="row"><label for="mailhouse-%s">%s</label></th><td>', esc_attr( $key ), esc_html( $field['label'] ) );

		if ( 'mailer' === $field['type'] ) {
			$this->mailer_field();
		} elseif ( 'preview' === $field['type'] ) {
			$this->preview_field();
		} elseif ( 'webhook' === $field['type'] ) {
			$this->webhook_field();
		} else {
			$this->field( $key, $field );
		}

		if ( '' !== $field['description'] ) {
			printf( '<p class="description">%s</p>', esc_html( $field['description'] ) );
		}

		echo '</td></tr>';
	}

	/**
	 * Print one input.
	 *
	 * @param string $key   Setting key.
	 * @param array  $field Field definition.
	 * @return void
	 */
	protected function field( $key, $field ) {
		$id    = 'mailhouse-' . $key;
		$name  = 'mailhouse[' . $key . ']';
		$value = mailhouse_option( $key );

		switch ( $field['type'] ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%s" name="%s" value="1"%s /> %s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					esc_html( $field['label'] )
				);
				break;

			case 'select':
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );

				foreach ( $field['options'] as $option => $label ) {
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $option ),
						selected( (string) $value, (string) $option, false ),
						esc_html( $label )
					);
				}

				echo '</select>';
				break;

			case 'textarea':
				printf(
					'<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( (string) $value )
				);
				break;

			case 'password':
				$is_constant = Mailhouse_Settings::is_constant( $key );
				$has_value   = Mailhouse_Settings::has_secret( $key );

				if ( $is_constant ) {
					printf(
						'<p><code>%s</code> %s</p>',
						esc_html( Mailhouse_Settings::constant_for( $key ) ),
						esc_html__( 'is defined in wp-config.php, so this field is ignored.', 'mailhouse' )
					);
					break;
				}

				printf(
					'<input type="password" id="%s" name="%s" value="" class="regular-text" autocomplete="new-password" placeholder="%s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $has_value ? __( 'Saved. Type a new value to replace it.', 'mailhouse' ) : '' )
				);

				if ( $has_value ) {
					printf(
						' <label><input type="checkbox" name="mailhouse_clear[%s]" value="1" /> %s</label>',
						esc_attr( $key ),
						esc_html__( 'Clear', 'mailhouse' )
					);
				}
				break;

			case 'number':
				printf(
					'<input type="number" id="%s" name="%s" value="%s" class="small-text" min="0" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;

			case 'color':
				printf(
					'<input type="color" id="%s" name="%s" value="%s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;

			case 'url':
				printf(
					'<input type="url" id="%s" name="%s" value="%s" class="regular-text" placeholder="%s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( $field['placeholder'] )
				);
				break;

			case 'image':
				printf(
					'<input type="url" id="%1$s" name="%2$s" value="%3$s" class="regular-text mailhouse-image-url" /> <button type="button" class="button mailhouse-image-pick" data-target="%1$s">%4$s</button>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_html__( 'Choose from Media Library', 'mailhouse' )
				);

				printf(
					'<div class="mailhouse-image-preview" data-for="%s">%s</div>',
					esc_attr( $id ),
					$value ? '<img src="' . esc_url( (string) $value ) . '" alt="" style="max-width:240px;height:auto;margin-top:8px;" />' : ''
				);
				break;

			case 'provider':
				printf( '<select id="%s" name="%s" class="mailhouse-provider-select">', esc_attr( $id ), esc_attr( $name ) );
				printf( '<option value="">%s</option>', esc_html( isset( $field['empty'] ) ? $field['empty'] : '' ) );

				foreach ( Mailhouse_Mailer::providers() as $provider_id => $provider ) {
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $provider_id ),
						selected( (string) $value, (string) $provider_id, false ),
						esc_html( $provider->label() )
					);
				}

				echo '</select>';
				break;

			default:
				printf(
					'<input type="%s" id="%s" name="%s" value="%s" class="regular-text" placeholder="%s" />',
					esc_attr( 'email' === $field['type'] ? 'email' : 'text' ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( $field['placeholder'] )
				);
				break;
		}
	}

	/**
	 * The preview link and the test send on the design tab.
	 *
	 * @return void
	 */
	protected function preview_field() {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=mailhouse_preview_template' ), 'mailhouse_preview_template' );

		printf(
			'<p><a class="button" href="%s" target="_blank" rel="noopener">%s</a> <span class="description">%s</span></p>',
			esc_url( $url ),
			esc_html__( 'Open a preview', 'mailhouse' ),
			esc_html__( 'Save first: the preview shows the saved settings.', 'mailhouse' )
		);

		printf(
			'<p><input type="email" id="mailhouse-test" class="regular-text" value="%s" /> <button type="button" class="button" id="mailhouse-send-test" data-nonce="%s">%s</button></p><div class="mailhouse-test-result"></div>',
			esc_attr( wp_get_current_user()->user_email ),
			esc_attr( wp_create_nonce( 'mailhouse_test_email' ) ),
			esc_html__( 'Send me a test', 'mailhouse' )
		);
	}

	/**
	 * Show a sample email in the current design.
	 *
	 * @return void
	 */
	public function preview() {
		check_admin_referer( 'mailhouse_preview_template' );

		if ( ! mailhouse_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mailhouse' ), '', array( 'response' => 403 ) );
		}

		$content  = '<h1>' . esc_html__( 'A heading looks like this', 'mailhouse' ) . '</h1>';
		$content .= '<p>' . esc_html__( 'This is how your emails will look. The text sits on a white card, with your logo above and your footer below. Links look like this:', 'mailhouse' ) . ' <a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( home_url( '/' ) ) . '</a></p>';
		$content .= Mailhouse_Merge_Tags::button( home_url( '/' ), __( 'A button looks like this', 'mailhouse' ) );
		$content .= '<p>' . esc_html__( 'Newsletters also carry the unsubscribe and preferences links shown in the footer. Password resets and other site emails do not.', 'mailhouse' ) . '</p>';

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: SAMEORIGIN' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by the template.
		echo Mailhouse_Template::wrap(
			$content,
			array(
				'subject'         => __( 'Email preview', 'mailhouse' ),
				'preview'         => __( 'The grey line an inbox shows after the subject.', 'mailhouse' ),
				'unsubscribe_url' => home_url( '/' ),
				'preferences_url' => home_url( '/' ),
			)
		);
		exit;
	}

	/**
	 * Print the transport picker and every transport's own fields.
	 *
	 * All of them are rendered; the script shows the one that matches the
	 * selected transport. That keeps the whole thing working without
	 * JavaScript, where every panel is simply visible.
	 *
	 * @return void
	 */
	protected function mailer_field() {
		$current = (string) mailhouse_option( 'mailer', 'php' );

		echo '<select id="mailhouse-mailer" name="mailhouse[mailer]">';

		foreach ( Mailhouse_Mailer::providers() as $id => $provider ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $id ),
				selected( $current, $id, false ),
				esc_html( $provider->label() )
			);
		}

		echo '</select>';

		foreach ( Mailhouse_Mailer::providers() as $id => $provider ) {
			$fields = $provider->fields();

			printf(
				'<div class="mailhouse-mailer-panel" data-mailer="%s"%s>',
				esc_attr( $id ),
				$id === $current ? '' : ' style="display:none"'
			);

			if ( $provider->description() ) {
				printf( '<p class="description">%s</p>', esc_html( $provider->description() ) );
			}

			if ( $fields ) {
				echo '<table class="form-table" role="presentation">';

				foreach ( $fields as $key => $field ) {
					$this->field_row( $key, $field );
				}

				echo '</table>';
			}

			echo '</div>';
		}

		if ( Mailhouse_Crypto::is_available() && Mailhouse_Crypto::key_in_database() ) {
			printf(
				'<p class="description mailhouse-warn-text">%s</p>',
				esc_html__( 'This site has no security keys in wp-config.php, so the encryption key is stored in the database next to the credentials it protects. Add the WordPress salts, or define MAILHOUSE_ENCRYPTION_KEY, to fix that.', 'mailhouse' )
			);
		}

		if ( ! Mailhouse_Crypto::is_available() ) {
			printf(
				'<p class="description mailhouse-warn-text">%s</p>',
				esc_html__( 'Neither sodium nor OpenSSL is available on this server, so credentials cannot be encrypted. Put them in wp-config.php instead.', 'mailhouse' )
			);
		}
	}

	/**
	 * Print the webhook endpoints and the key controls.
	 *
	 * Site mail and campaigns can go out through different transports, so both
	 * are offered: a bounce reported to one provider says nothing about the
	 * other, and the newsletter stream is the one bounces matter most on.
	 *
	 * @return void
	 */
	protected function webhook_field() {
		$streams   = Mailhouse_Webhooks::active_providers();
		$providers = Mailhouse_Webhooks::providers();

		if ( $streams ) {
			foreach ( $streams as $id => $label ) {
				printf(
					'<p><label for="mailhouse-webhook-%s"><strong>%s</strong></label></p>',
					esc_attr( $id ),
					esc_html(
						sprintf(
							/* translators: %s: provider name. */
							__( 'Paste this into %s', 'mailhouse' ),
							$label
						)
					)
				);

				printf(
					'<input type="text" id="mailhouse-webhook-%s" class="large-text code" readonly onclick="this.select()" value="%s" />',
					esc_attr( $id ),
					esc_attr( Mailhouse_Webhooks::url( $id ) )
				);
			}
		} else {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Neither transport in use can report bounces -- SMTP and PHP mail have no callbacks. Switch to a mail API to get them, or watch for failures in the log instead.', 'mailhouse' )
			);
		}

		echo '<details style="margin-top:12px"><summary>' . esc_html__( 'Endpoints for every provider', 'mailhouse' ) . '</summary><table class="widefat striped" style="margin-top:8px">';

		foreach ( $providers as $id => $label ) {
			printf(
				'<tr><td style="width:22%%"><strong>%s</strong></td><td><input type="text" class="large-text code" readonly onclick="this.select()" value="%s" /></td></tr>',
				esc_html( $label ),
				esc_attr( Mailhouse_Webhooks::url( $id ) )
			);
		}

		echo '</table></details>';

		printf(
			'<p style="margin-top:12px"><a href="%s" class="button" onclick="return confirm(%s)">%s</a></p>',
			esc_url(
				wp_nonce_url(
					add_query_arg( 'action', 'mailhouse_rotate_webhook_key', admin_url( 'admin-post.php' ) ),
					'mailhouse_rotate_webhook_key'
				)
			),
			esc_attr( wp_json_encode( __( 'Replace the key? Every endpoint already saved in a provider dashboard stops working until you paste the new one in.', 'mailhouse' ) ) ),
			esc_html__( 'Replace the key', 'mailhouse' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The key in the URL is what proves a callback is genuine. Where the provider can also sign the body -- Mailgun, Resend, SendGrid -- set the signing key below as well: without one, anyone who learns this URL can mark your subscribers as bounced.', 'mailhouse' )
		);
	}

	/**
	 * Save the submitted tab.
	 *
	 * @return void
	 */
	public function save() {
		check_admin_referer( 'mailhouse_save_settings' );

		if ( ! mailhouse_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mailhouse' ), '', array( 'response' => 403 ) );
		}

		$tabs = $this->tabs();
		$tab  = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'delivery';

		if ( ! isset( $tabs[ $tab ] ) ) {
			wp_safe_redirect( mailhouse_admin_url( 'settings' ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitised by type below.
		$submitted = isset( $_POST['mailhouse'] ) ? (array) wp_unslash( $_POST['mailhouse'] ) : array();
		$clear     = isset( $_POST['mailhouse_clear'] ) ? array_keys( (array) wp_unslash( $_POST['mailhouse_clear'] ) ) : array();
		$fields    = $this->fields_for( $tab );
		$values    = array();

		foreach ( $fields as $key => $field ) {
			$type = isset( $field['type'] ) ? $field['type'] : 'text';
			$raw  = isset( $submitted[ $key ] ) ? $submitted[ $key ] : null;

			if ( 'password' === $type ) {
				// An empty password box means "leave it alone", not "delete
				// it" -- the field is never populated with the stored value.
				if ( in_array( $key, $clear, true ) ) {
					$values[ $key ] = '';
				} elseif ( ! empty( $raw ) ) {
					$values[ $key ] = trim( (string) $raw );
				}

				continue;
			}

			if ( 'preview' === $type ) {
				continue;
			}

			$values[ $key ] = $this->sanitise( $type, $raw, $key, $field );
		}

		Mailhouse_Settings::set( $values );

		wp_safe_redirect( mailhouse_admin_url( 'settings', array( 'tab' => $tab, 'updated' => 1 ) ) );
		exit;
	}

	/**
	 * Every field on one tab, transport fields included.
	 *
	 * @param string $tab Tab key.
	 * @return array
	 */
	protected function fields_for( $tab ) {
		$tabs   = $this->tabs();
		$fields = array();

		foreach ( $tabs[ $tab ]['sections'] as $section ) {
			foreach ( $section['fields'] as $key => $field ) {
				// Display-only: the endpoint panel has nothing to store.
				if ( 'webhook' === $field['type'] ) {
					continue;
				}

				if ( 'mailer' === $field['type'] ) {
					$fields['mailer'] = array( 'type' => 'provider' );

					foreach ( Mailhouse_Mailer::providers() as $provider ) {
						foreach ( $provider->fields() as $provider_key => $provider_field ) {
							$fields[ $provider_key ] = $provider_field;
						}
					}

					continue;
				}

				$fields[ $key ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Sanitise one submitted value.
	 *
	 * Every value is checked against what its field allows -- a select only
	 * takes one of its own options, a transport only one that exists -- so a
	 * crafted form post cannot store anything the screen could not.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Raw value.
	 * @param string $key   Setting key.
	 * @param array  $field Field definition.
	 * @return mixed
	 */
	protected function sanitise( $type, $value, $key = '', $field = array() ) {
		$defaults = Mailhouse_Settings::defaults();
		$default  = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';

		if ( is_array( $value ) ) {
			$value = '';
		}

		switch ( $type ) {
			case 'checkbox':
				return ! empty( $value );

			case 'number':
				return max( 0, (int) $value );

			case 'email':
				return mailhouse_clean_email( $value );

			case 'textarea':
				return wp_kses_post( (string) $value );

			case 'color':
				$colour = sanitize_hex_color( (string) $value );

				return $colour ? $colour : ( $default ? $default : '#2b6cb0' );

			case 'url':
			case 'image':
				return esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );

			case 'provider':
				$value     = sanitize_key( (string) $value );
				$providers = Mailhouse_Mailer::providers();

				if ( 'mailer' === $key ) {
					return isset( $providers[ $value ] ) ? $value : 'php';
				}

				return isset( $providers[ $value ] ) ? $value : '';

			case 'select':
				$value   = sanitize_text_field( (string) $value );
				$options = isset( $field['options'] ) ? array_map( 'strval', array_keys( (array) $field['options'] ) ) : array();

				if ( $options && ! in_array( $value, $options, true ) ) {
					return is_scalar( $default ) ? (string) $default : '';
				}

				return $value;

			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
