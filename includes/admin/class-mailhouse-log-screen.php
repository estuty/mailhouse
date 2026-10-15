<?php
/**
 * The email log screen.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows the log and the detail of one entry.
 */
class Mailhouse_Log_Screen {

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Run row and bulk actions.
	 *
	 * @return void
	 */
	public function handle_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';

		if ( 'mailhouse-log' !== $page || ! mailhouse_can_manage() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( '-1' === $action && isset( $_REQUEST['action2'] ) ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}

		$id = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;

		if ( isset( $_REQUEST['entry'] ) && 'delete' === $action ) {
			check_admin_referer( 'bulk-log_entries' );

			Mailhouse_Log::delete( array_map( 'absint', (array) wp_unslash( $_REQUEST['entry'] ) ) );
			$this->redirect( 'deleted' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'clear' === $action ) {
			check_admin_referer( 'mailhouse_clear_log' );

			Mailhouse_Log::clear();
			$this->redirect( 'cleared' );
		}

		if ( 'resend' === $action && $id ) {
			check_admin_referer( 'mailhouse_log_' . $id );

			$result = Mailhouse_Log::resend( $id );

			$this->redirect( is_wp_error( $result ) ? 'resend_failed' : 'resent' );
		}
	}

	/**
	 * Go back to the log with a notice.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	protected function redirect( $notice ) {
		wp_safe_redirect( mailhouse_admin_url( 'log', array( 'mailhouse_notice' => $notice ) ) );
		exit;
	}

	/**
	 * The log screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! mailhouse_can_manage() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['action'] ) && 'view' === $_GET['action'] ) {
			$this->render_entry( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$table = new Mailhouse_Log_Table();
		$table->prepare_items();

		?>
		<div class="wrap mailhouse">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Email log', 'mailhouse' ); ?></h1>
			<a href="<?php echo esc_url( wp_nonce_url( mailhouse_admin_url( 'log', array( 'action' => 'clear' ) ), 'mailhouse_clear_log' ) ); ?>"
				class="page-title-action"
				onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete every entry in the log?', 'mailhouse' ) ) ); ?>)">
				<?php esc_html_e( 'Clear log', 'mailhouse' ); ?>
			</a>
			<hr class="wp-header-end" />

			<?php $this->notice(); ?>

			<?php if ( ! mailhouse_option( 'log_enabled' ) ) : ?>
				<div class="notice notice-info"><p>
					<?php
					printf(
						/* translators: %s: link to the settings screen. */
						esc_html__( 'Logging is switched off, so nothing new is being recorded. %s', 'mailhouse' ),
						'<a href="' . esc_url( mailhouse_admin_url( 'settings', array( 'tab' => 'log' ) ) ) . '">' . esc_html__( 'Turn it on', 'mailhouse' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<form method="get">
				<input type="hidden" name="page" value="mailhouse-log" />
				<?php
				$table->views();
				$table->search_box( __( 'Search the log', 'mailhouse' ), 'mailhouse-log-search' );
				?>
			</form>

			<form method="post">
				<input type="hidden" name="page" value="mailhouse-log" />
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * One entry in full.
	 *
	 * @param int $id Entry id.
	 * @return void
	 */
	protected function render_entry( $id ) {
		$entry = Mailhouse_Log::get( $id );

		?>
		<div class="wrap mailhouse">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Log entry', 'mailhouse' ); ?></h1>
			<a href="<?php echo esc_url( mailhouse_admin_url( 'log' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Back to the log', 'mailhouse' ); ?></a>
			<hr class="wp-header-end" />

			<?php if ( ! $entry ) : ?>
				<p><?php esc_html_e( 'That entry no longer exists.', 'mailhouse' ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<table class="widefat striped mailhouse-detail">
				<tbody>
					<tr><th><?php esc_html_e( 'When', 'mailhouse' ); ?></th><td><?php echo esc_html( mailhouse_date( $entry->created_at ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Result', 'mailhouse' ); ?></th><td><?php echo esc_html( 'sent' === $entry->status ? __( 'Delivered to the transport', 'mailhouse' ) : __( 'Failed', 'mailhouse' ) ); ?></td></tr>
					<?php if ( '' !== (string) $entry->error ) : ?>
						<tr><th><?php esc_html_e( 'Error', 'mailhouse' ); ?></th><td><code><?php echo esc_html( $entry->error ); ?></code></td></tr>
					<?php endif; ?>
					<tr><th><?php esc_html_e( 'From', 'mailhouse' ); ?></th><td><?php echo esc_html( $entry->from_email ); ?></td></tr>
					<tr><th><?php esc_html_e( 'To', 'mailhouse' ); ?></th><td><?php echo esc_html( $entry->to_email ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Subject', 'mailhouse' ); ?></th><td><?php echo esc_html( $entry->subject ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Transport', 'mailhouse' ); ?></th><td><?php echo esc_html( $entry->mailer ); ?></td></tr>
					<?php if ( '' !== (string) $entry->headers ) : ?>
						<tr><th><?php esc_html_e( 'Headers', 'mailhouse' ); ?></th><td><pre><?php echo esc_html( $entry->headers ); ?></pre></td></tr>
					<?php endif; ?>
					<?php if ( '' !== (string) $entry->attachments ) : ?>
						<tr><th><?php esc_html_e( 'Attachments', 'mailhouse' ); ?></th><td><?php echo esc_html( $entry->attachments ); ?></td></tr>
					<?php endif; ?>
					<?php if ( (int) $entry->campaign_id ) : ?>
						<tr>
							<th><?php esc_html_e( 'Campaign', 'mailhouse' ); ?></th>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $entry->campaign_id ) ); ?>"><?php echo esc_html( get_the_title( (int) $entry->campaign_id ) ); ?></a></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Message', 'mailhouse' ); ?></h2>

			<?php if ( '' === (string) $entry->body ) : ?>
				<p class="description"><?php esc_html_e( 'The body was not saved. Message content is only kept when "Save message content" is on, so that password resets and receipts do not linger in the database.', 'mailhouse' ); ?></p>
			<?php else : ?>
				<iframe class="mailhouse-preview" srcdoc="<?php echo esc_attr( $entry->body ); ?>" sandbox=""></iframe>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Print the notice for whatever just happened.
	 *
	 * @return void
	 */
	protected function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['mailhouse_notice'] ) ? sanitize_key( wp_unslash( $_GET['mailhouse_notice'] ) ) : '';

		$messages = array(
			'deleted'       => array( 'success', __( 'Entries deleted.', 'mailhouse' ) ),
			'cleared'       => array( 'success', __( 'The log has been emptied.', 'mailhouse' ) ),
			'resent'        => array( 'success', __( 'Message sent again.', 'mailhouse' ) ),
			'resend_failed' => array( 'error', __( 'The retry failed. The newest log entry has the reason.', 'mailhouse' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}
}
