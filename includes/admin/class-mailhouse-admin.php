<?php
/**
 * The admin area: menus, assets, the dashboard.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the admin screens.
 */
class Mailhouse_Admin {

	/**
	 * The settings screen.
	 *
	 * @var Mailhouse_Admin_Settings
	 */
	protected $settings;

	/**
	 * The tools screen.
	 *
	 * @var Mailhouse_Tools
	 */
	protected $tools;

	/**
	 * The subscribers and tags screens.
	 *
	 * @var Mailhouse_Subscribers_Screen
	 */
	protected $subscribers;

	/**
	 * The email log screen.
	 *
	 * @var Mailhouse_Log_Screen
	 */
	protected $log;

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		// Built here rather than at render time: each screen acts on its own
		// row and bulk actions during admin_init, which is the last chance to
		// redirect before output starts.
		$this->settings    = new Mailhouse_Admin_Settings();
		$this->tools       = new Mailhouse_Tools();
		$this->subscribers = new Mailhouse_Subscribers_Screen();
		$this->log         = new Mailhouse_Log_Screen();

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . MAILHOUSE_BASENAME, array( $this, 'action_links' ) );

		add_action( 'wp_ajax_mailhouse_test_email', array( $this, 'ajax_test_email' ) );
		add_action( 'admin_post_mailhouse_duplicate_email', array( $this, 'duplicate_email' ) );
	}

	/**
	 * Register the menu.
	 *
	 * @return void
	 */
	public function menu() {
		$cap = mailhouse_capability();

		add_menu_page(
			__( 'Mailhouse', 'mailhouse' ),
			__( 'Mailhouse', 'mailhouse' ),
			$cap,
			'mailhouse',
			array( $this, 'dashboard' ),
			'dashicons-email-alt',
			26
		);

		add_submenu_page( 'mailhouse', __( 'Dashboard', 'mailhouse' ), __( 'Dashboard', 'mailhouse' ), $cap, 'mailhouse', array( $this, 'dashboard' ) );
		add_submenu_page( 'mailhouse', __( 'Subscribers', 'mailhouse' ), __( 'Subscribers', 'mailhouse' ), $cap, 'mailhouse-subscribers', array( $this, 'subscribers' ) );
		add_submenu_page( 'mailhouse', __( 'Tags', 'mailhouse' ), __( 'Tags', 'mailhouse' ), $cap, 'mailhouse-tags', array( $this, 'tags' ) );
		add_submenu_page( 'mailhouse', __( 'Email log', 'mailhouse' ), __( 'Email log', 'mailhouse' ), $cap, 'mailhouse-log', array( $this, 'log' ) );
		add_submenu_page( 'mailhouse', __( 'Settings', 'mailhouse' ), __( 'Settings', 'mailhouse' ), $cap, 'mailhouse-settings', array( $this->settings, 'render' ) );
		add_submenu_page( 'mailhouse', __( 'Tools', 'mailhouse' ), __( 'Tools', 'mailhouse' ), $cap, 'mailhouse-tools', array( $this->tools, 'render' ) );
	}

	/**
	 * Load the admin stylesheet and script on our screens.
	 *
	 * @param string $hook Current screen hook.
	 * @return void
	 */
	public function assets( $hook ) {
		$screen = get_current_screen();
		$ours   = ( $screen && Mailhouse_Emails::POST_TYPE === $screen->post_type ) || false !== strpos( $hook, 'mailhouse' );

		if ( ! $ours ) {
			return;
		}

		// The logo picker on the design tab uses the Media Library.
		if ( false !== strpos( $hook, 'mailhouse-settings' ) && current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}

		wp_enqueue_style( 'mailhouse-admin', MAILHOUSE_URL . 'assets/admin/admin.css', array(), MAILHOUSE_VERSION );
		wp_enqueue_script( 'mailhouse-admin', MAILHOUSE_URL . 'assets/admin/admin.js', array(), MAILHOUSE_VERSION, true );

		wp_localize_script(
			'mailhouse-admin',
			'mailhouseAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'sending' => __( 'Sending...', 'mailhouse' ),
				'failed'  => __( 'Could not reach the site. Try again.', 'mailhouse' ),
				'logo'    => __( 'Choose a logo', 'mailhouse' ),
				'useLogo' => __( 'Use this image', 'mailhouse' ),
			)
		);
	}

	/**
	 * Add a settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( mailhouse_admin_url( 'settings' ) ), esc_html__( 'Settings', 'mailhouse' ) )
		);

		return $links;
	}

	/**
	 * Warn about the things that silently break mail.
	 *
	 * @return void
	 */
	public function notices() {
		if ( ! mailhouse_can_manage() ) {
			return;
		}

		$screen = get_current_screen();
		$ours   = $screen && ( false !== strpos( $screen->id, 'mailhouse' ) || Mailhouse_Emails::POST_TYPE === $screen->post_type );

		// Conflicts are worth saying anywhere in the admin: two plugins both
		// reconfiguring PHPMailer is a coin toss over which one wins.
		foreach ( self::conflicts() as $name ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				sprintf(
					/* translators: %s: plugin name. */
					esc_html__( 'Mailhouse and %s both take over how WordPress sends email. Deactivate one of them, or delivery will depend on which loads last.', 'mailhouse' ),
					'<strong>' . esc_html( $name ) . '</strong>'
				)
			);
		}

		if ( ! $ours ) {
			return;
		}

		if ( ! Mailhouse_Mailer::is_ready() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Mail is still going out through PHP mail(), which most inboxes distrust.', 'mailhouse' ),
				esc_url( mailhouse_admin_url( 'settings' ) ),
				esc_html__( 'Set up a transport', 'mailhouse' )
			);
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && ! wp_next_scheduled( 'mailhouse_process_queue' ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'WP-Cron is disabled on this site. Campaigns will only send while a real cron job is calling wp-cron.php.', 'mailhouse' )
			);
		}
	}

	/**
	 * Other active plugins that also intercept wp_mail().
	 *
	 * @return string[]
	 */
	public static function conflicts() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$known = array(
			'wp-mail-smtp/wp_mail_smtp.php'            => 'WP Mail SMTP',
			'easy-wp-smtp/easy-wp-smtp.php'            => 'Easy WP SMTP',
			'post-smtp/postman-smtp.php'               => 'Post SMTP',
			'wp-smtp/wp-smtp.php'                      => 'WP SMTP',
			'fluent-smtp/fluent-smtp.php'              => 'FluentSMTP',
			'gmail-smtp/main.php'                      => 'Gmail SMTP',
			'newsletter/plugin.php'                    => 'Newsletter',
			'newsletter-optin-box/noptin.php'          => 'Noptin',
			'mailpoet/mailpoet.php'                    => 'MailPoet',
		);

		$found = array();

		foreach ( $known as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$found[] = $name;
			}
		}

		return $found;
	}

	/**
	 * The dashboard screen.
	 *
	 * @return void
	 */
	public function dashboard() {
		$counts   = Mailhouse_Subscribers::counts();
		$log      = Mailhouse_Log::stats( 7 );
		$provider = Mailhouse_Mailer::provider();
		$pending  = Mailhouse_Queue::pending_count();

		$recent = get_posts(
			array(
				'post_type'      => Mailhouse_Emails::POST_TYPE,
				'posts_per_page' => 5,
				'post_status'    => array( 'publish', 'draft' ),
			)
		);

		?>
		<div class="wrap mailhouse">
			<h1><?php esc_html_e( 'Mailhouse', 'mailhouse' ); ?></h1>

			<div class="mailhouse-cards">
				<div class="mailhouse-card">
					<h2><?php esc_html_e( 'Subscribers', 'mailhouse' ); ?></h2>
					<p class="mailhouse-figure"><?php echo esc_html( number_format_i18n( $counts['subscribed'] ) ); ?></p>
					<p class="description">
						<?php
						printf(
							/* translators: 1: pending count, 2: unsubscribed count. */
							esc_html__( '%1$s pending, %2$s unsubscribed', 'mailhouse' ),
							esc_html( number_format_i18n( $counts['pending'] ) ),
							esc_html( number_format_i18n( $counts['unsubscribed'] ) )
						);
						?>
					</p>
					<p><a class="button" href="<?php echo esc_url( mailhouse_admin_url( 'subscribers' ) ); ?>"><?php esc_html_e( 'Manage the list', 'mailhouse' ); ?></a></p>
				</div>

				<div class="mailhouse-card">
					<h2><?php esc_html_e( 'Delivery', 'mailhouse' ); ?></h2>
					<p class="mailhouse-figure"><?php echo esc_html( $provider ? $provider->label() : __( 'Not set', 'mailhouse' ) ); ?></p>
					<p class="description">
						<?php if ( Mailhouse_Mailer::is_ready() && 'php' !== mailhouse_option( 'mailer' ) ) : ?>
							<span class="mailhouse-ok">&#10003;</span> <?php esc_html_e( 'Configured', 'mailhouse' ); ?>
						<?php else : ?>
							<span class="mailhouse-warn">!</span> <?php esc_html_e( 'Needs setting up', 'mailhouse' ); ?>
						<?php endif; ?>
					</p>
					<p><a class="button" href="<?php echo esc_url( mailhouse_admin_url( 'settings' ) ); ?>"><?php esc_html_e( 'Delivery settings', 'mailhouse' ); ?></a></p>
				</div>

				<div class="mailhouse-card">
					<h2><?php esc_html_e( 'Last 7 days', 'mailhouse' ); ?></h2>
					<p class="mailhouse-figure"><?php echo esc_html( number_format_i18n( $log['sent'] ) ); ?></p>
					<p class="description">
						<?php
						printf(
							/* translators: %s: number of failures. */
							esc_html__( '%s failed', 'mailhouse' ),
							esc_html( number_format_i18n( $log['failed'] ) )
						);
						?>
					</p>
					<p><a class="button" href="<?php echo esc_url( mailhouse_admin_url( 'log' ) ); ?>"><?php esc_html_e( 'Open the log', 'mailhouse' ); ?></a></p>
				</div>

				<div class="mailhouse-card">
					<h2><?php esc_html_e( 'Queue', 'mailhouse' ); ?></h2>
					<p class="mailhouse-figure"><?php echo esc_html( number_format_i18n( $pending ) ); ?></p>
					<p class="description">
						<?php
						$next = wp_next_scheduled( 'mailhouse_process_queue' );

						if ( $next ) {
							printf(
								/* translators: %s: relative time. */
								esc_html__( 'Next run in %s', 'mailhouse' ),
								esc_html( human_time_diff( time(), $next ) )
							);
						} else {
							esc_html_e( 'No run scheduled', 'mailhouse' );
						}
						?>
					</p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Mailhouse_Emails::POST_TYPE ) ); ?>"><?php esc_html_e( 'Emails', 'mailhouse' ); ?></a></p>
				</div>
			</div>

			<div class="mailhouse-columns">
				<div class="mailhouse-panel">
					<h2><?php esc_html_e( 'Recent emails', 'mailhouse' ); ?></h2>
					<?php if ( $recent ) : ?>
						<table class="widefat striped">
							<tbody>
								<?php foreach ( $recent as $email ) : ?>
									<tr>
										<td><a href="<?php echo esc_url( (string) get_edit_post_link( $email->ID ) ); ?>"><?php echo esc_html( get_the_title( $email ) ); ?></a></td>
										<td><?php echo esc_html( Mailhouse_Emails::status_label( $email ) ); ?></td>
										<td><?php echo esc_html( mailhouse_date( get_post_time( 'Y-m-d H:i:s', true, $email ) ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e( 'Nothing yet.', 'mailhouse' ); ?></p>
					<?php endif; ?>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Mailhouse_Emails::POST_TYPE ) ); ?>">
							<?php esc_html_e( 'Write an email', 'mailhouse' ); ?>
						</a>
					</p>
				</div>

				<div class="mailhouse-panel">
					<h2><?php esc_html_e( 'Send a test', 'mailhouse' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Sends a plain message through the configured transport and reports exactly what came back.', 'mailhouse' ); ?></p>
					<p>
						<input type="email" id="mailhouse-test" class="regular-text" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
						<button type="button" class="button" id="mailhouse-send-test" data-nonce="<?php echo esc_attr( wp_create_nonce( 'mailhouse_test_email' ) ); ?>">
							<?php esc_html_e( 'Send test', 'mailhouse' ); ?>
						</button>
					</p>
					<div class="mailhouse-test-result"></div>

					<h2><?php esc_html_e( 'Add a form', 'mailhouse' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Paste this shortcode into any post, page or widget. There is a Newsletter form block too.', 'mailhouse' ); ?></p>
					<p><code>[mailhouse_form]</code></p>
					<p><code>[mailhouse_form title="Stay in touch" name="first" tags="weekly"]</code></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The subscribers screen.
	 *
	 * @return void
	 */
	public function subscribers() {
		$this->subscribers->render();
	}

	/**
	 * The tags screen.
	 *
	 * @return void
	 */
	public function tags() {
		$this->subscribers->render_tags();
	}

	/**
	 * The email log screen.
	 *
	 * @return void
	 */
	public function log() {
		$this->log->render();
	}

	/**
	 * Send a test message and report what happened.
	 *
	 * @return void
	 */
	public function ajax_test_email() {
		check_ajax_referer( 'mailhouse_test_email', 'nonce' );

		if ( ! mailhouse_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'mailhouse' ) ) );
		}

		$to      = isset( $_POST['email'] ) ? mailhouse_clean_email( wp_unslash( $_POST['email'] ) ) : '';
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;

		if ( ! $to ) {
			wp_send_json_error( array( 'message' => __( 'That is not a valid email address.', 'mailhouse' ) ) );
		}

		// Only Mailhouse emails can be previewed. Any other post id would
		// let this mail out drafts, private posts or protected content.
		if ( $post_id && Mailhouse_Emails::POST_TYPE !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'That is not a Mailhouse email.', 'mailhouse' ) ) );
		}

		// Catch the reason rather than only the fact of a failure.
		$error = '';

		$capture = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error->get_error_message();
		};

		add_action( 'wp_mail_failed', $capture );

		if ( $post_id ) {
			$sent = $this->send_preview( $post_id, $to );
		} else {
			$sent = Mailhouse_Mailer::send(
				$to,
				sprintf(
					/* translators: %s: site name. */
					__( 'Mailhouse test from %s', 'mailhouse' ),
					get_bloginfo( 'name' )
				),
				Mailhouse_Template::wrap(
					'<p>' . esc_html__( 'If you are reading this, mail is leaving the site correctly.', 'mailhouse' ) . '</p>' .
					'<p>' . sprintf(
						/* translators: %s: transport name. */
						esc_html__( 'Sent through: %s', 'mailhouse' ),
						esc_html( Mailhouse_Mailer::provider() ? Mailhouse_Mailer::provider()->label() : '-' )
					) . '</p>',
					array( 'subject' => __( 'Mailhouse test', 'mailhouse' ) )
				)
			);
		}

		remove_action( 'wp_mail_failed', $capture );

		if ( $sent ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %s: email address. */
						__( 'Sent to %s. If it does not arrive, check the spam folder and the domain records.', 'mailhouse' ),
						$to
					),
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => $error ? $error : __( 'The transport refused the message and gave no reason.', 'mailhouse' ),
			)
		);
	}

	/**
	 * Send one campaign to a single address as a preview.
	 *
	 * @param int    $post_id Email id.
	 * @param string $to      Address.
	 * @return bool
	 */
	protected function send_preview( $post_id, $to ) {
		// A real subscriber if the tester is on the list, so the merge tags
		// and the unsubscribe link resolve to something real.
		$subscriber = Mailhouse_Subscribers::get_by_email( $to );

		if ( ! $subscriber ) {
			$subscriber = (object) array(
				'id'         => 0,
				'email'      => $to,
				'first_name' => wp_get_current_user()->first_name,
				'last_name'  => wp_get_current_user()->last_name,
				'status'     => 'subscribed',
				'fields'     => '',
				'confirm_key' => '',
				'manage_key' => '',
				'sent'       => 0,
			);
		}

		$message = Mailhouse_Emails::render( $post_id, $subscriber );

		return Mailhouse_Mailer::send(
			$to,
			/* translators: %s: subject line. */
			sprintf( __( '[Test] %s', 'mailhouse' ), $message['subject'] ),
			$message['html'],
			array(
				'text'        => $message['text'],
				'from'        => mailhouse_clean_email( mailhouse_option( 'campaign_from_email' ) ),
				'from_name'   => (string) mailhouse_option( 'campaign_from_name' ),
				'reply_to'    => mailhouse_clean_email( mailhouse_option( 'campaign_reply_to' ) ),
				'campaign_id' => $post_id,
			)
		);
	}

	/**
	 * Copy an email, so last month's issue can be this month's starting point.
	 *
	 * @return void
	 */
	public function duplicate_email() {
		$post_id = isset( $_GET['email'] ) ? absint( $_GET['email'] ) : 0;

		check_admin_referer( 'mailhouse_duplicate_email_' . $post_id );

		if ( ! mailhouse_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mailhouse' ), '', array( 'response' => 403 ) );
		}

		$original = get_post( $post_id );

		if ( ! $original || Mailhouse_Emails::POST_TYPE !== $original->post_type ) {
			wp_die( esc_html__( 'That email does not exist.', 'mailhouse' ) );
		}

		$copy = wp_insert_post(
			array(
				'post_type'    => Mailhouse_Emails::POST_TYPE,
				'post_status'  => 'draft',
				/* translators: %s: original title. */
				'post_title'   => sprintf( __( '%s (copy)', 'mailhouse' ), $original->post_title ),
				'post_content' => $original->post_content,
			)
		);

		if ( $copy && ! is_wp_error( $copy ) ) {
			foreach ( array( '_mailhouse_type', '_mailhouse_subject', '_mailhouse_preview', '_mailhouse_tag', '_mailhouse_post_types' ) as $key ) {
				$value = get_post_meta( $post_id, $key, true );

				if ( '' !== $value ) {
					update_post_meta( $copy, $key, $value );
				}
			}

			wp_safe_redirect( (string) get_edit_post_link( $copy, 'raw' ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Mailhouse_Emails::POST_TYPE ) );
		exit;
	}
}
