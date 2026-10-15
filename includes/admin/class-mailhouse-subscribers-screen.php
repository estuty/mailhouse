<?php
/**
 * The Subscribers and Tags screens.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists, edits and removes subscribers.
 */
class Mailhouse_Subscribers_Screen {

	/**
	 * Register the hooks.
	 *
	 * Actions run on admin_init, before any output, because most of them
	 * finish with a redirect.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_post_mailhouse_save_subscriber', array( $this, 'save_subscriber' ) );
		add_action( 'admin_post_mailhouse_save_tag', array( $this, 'save_tag' ) );
	}

	/**
	 * Run row and bulk actions.
	 *
	 * @return void
	 */
	public function handle_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';

		if ( 'mailhouse-subscribers' !== $page && 'mailhouse-tags' !== $page ) {
			return;
		}

		if ( ! mailhouse_can_manage() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '-1' === $action && isset( $_REQUEST['action2'] ) ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;

		// A bulk action and a row action can share a name -- "delete" is both
		// -- so the presence of checkboxes is what tells them apart.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['subscriber'] ) ) {
			$this->bulk( $action );
			return;
		}

		if ( ! $id ) {
			return;
		}

		switch ( $action ) {
			case 'delete':
				check_admin_referer( 'mailhouse_subscriber_' . $id );
				Mailhouse_Subscribers::delete( array( $id ) );
				$this->redirect( 'deleted' );
				break;

			case 'resend':
				check_admin_referer( 'mailhouse_subscriber_' . $id );

				$subscriber = Mailhouse_Subscribers::get( $id );

				if ( $subscriber ) {
					delete_transient( 'mailhouse_confirm_' . md5( $subscriber->email ) );
					Mailhouse_Subscribers::send_confirmation( $subscriber );
				}

				$this->redirect( 'resent' );
				break;

			case 'delete_tag':
				check_admin_referer( 'mailhouse_tag_' . $id );
				Mailhouse_Tags::delete( $id );

				wp_safe_redirect( mailhouse_admin_url( 'tags', array( 'mailhouse_notice' => 'tag_deleted' ) ) );
				exit;
		}
	}

	/**
	 * Apply a bulk action.
	 *
	 * @param string $action Action key.
	 * @return void
	 */
	protected function bulk( $action ) {
		check_admin_referer( 'bulk-subscribers' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ids = isset( $_REQUEST['subscriber'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['subscriber'] ) ) : array();
		$ids = array_filter( $ids );

		if ( ! $ids ) {
			$this->redirect( 'none' );
		}

		switch ( $action ) {
			case 'delete':
				Mailhouse_Subscribers::delete( $ids );
				$this->redirect( 'deleted' );
				break;

			case 'subscribe':
				foreach ( $ids as $id ) {
					Mailhouse_Subscribers::update(
						$id,
						array(
							'status'       => 'subscribed',
							'confirmed_at' => mailhouse_now(),
							'confirm_key'  => null,
						)
					);
				}

				$this->redirect( 'updated' );
				break;

			case 'unsubscribe':
				foreach ( $ids as $id ) {
					Mailhouse_Subscribers::unsubscribe( Mailhouse_Subscribers::get( $id ) );
				}

				$this->redirect( 'updated' );
				break;

			case 'resend':
				foreach ( $ids as $id ) {
					$subscriber = Mailhouse_Subscribers::get( $id );

					if ( $subscriber && 'pending' === $subscriber->status ) {
						delete_transient( 'mailhouse_confirm_' . md5( $subscriber->email ) );
						Mailhouse_Subscribers::send_confirmation( $subscriber );
					}
				}

				$this->redirect( 'resent' );
				break;
		}
	}

	/**
	 * Go back to the list with a notice.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	protected function redirect( $notice ) {
		wp_safe_redirect( mailhouse_admin_url( 'subscribers', array( 'mailhouse_notice' => $notice ) ) );
		exit;
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
			'deleted'     => __( 'Subscribers deleted.', 'mailhouse' ),
			'updated'     => __( 'Subscribers updated.', 'mailhouse' ),
			'resent'      => __( 'Confirmation emails sent.', 'mailhouse' ),
			'saved'       => __( 'Subscriber saved.', 'mailhouse' ),
			'added'       => __( 'Subscriber added.', 'mailhouse' ),
			'exists'      => __( 'That address is already on the list.', 'mailhouse' ),
			'invalid'     => __( 'That is not a valid email address.', 'mailhouse' ),
			'none'        => __( 'Nothing was selected.', 'mailhouse' ),
			'tag_saved'   => __( 'Tag saved.', 'mailhouse' ),
			'tag_deleted' => __( 'Tag deleted.', 'mailhouse' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$type = in_array( $notice, array( 'invalid', 'none', 'exists' ), true ) ? 'warning' : 'success';

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $messages[ $notice ] )
		);
	}

	/**
	 * The Subscribers screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! mailhouse_can_manage() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'edit' === $action ) {
			$this->render_edit();
			return;
		}

		$table = new Mailhouse_Subscribers_Table();
		$table->prepare_items();

		?>
		<div class="wrap mailhouse">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Subscribers', 'mailhouse' ); ?></h1>
			<a href="<?php echo esc_url( mailhouse_admin_url( 'subscribers', array( 'action' => 'edit' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add subscriber', 'mailhouse' ); ?></a>
			<a href="<?php echo esc_url( mailhouse_admin_url( 'tools' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Import / export', 'mailhouse' ); ?></a>
			<hr class="wp-header-end" />

			<?php $this->notice(); ?>

			<form method="get">
				<input type="hidden" name="page" value="mailhouse-subscribers" />
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( isset( $_GET['status'] ) ) {
					printf( '<input type="hidden" name="status" value="%s" />', esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ) );
				}

				$table->views();
				$table->search_box( __( 'Search subscribers', 'mailhouse' ), 'mailhouse-search' );
				?>
			</form>

			<form method="post">
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * The add/edit subscriber form.
	 *
	 * @return void
	 */
	protected function render_edit() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id         = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$subscriber = $id ? Mailhouse_Subscribers::get( $id ) : null;
		$tags       = Mailhouse_Tags::all();
		$current    = $subscriber ? array_map( 'intval', wp_list_pluck( Mailhouse_Tags::for_subscriber( $id ), 'id' ) ) : array();
		$statuses   = Mailhouse_Subscribers::statuses();

		?>
		<div class="wrap mailhouse">
			<h1><?php echo $subscriber ? esc_html__( 'Edit subscriber', 'mailhouse' ) : esc_html__( 'Add subscriber', 'mailhouse' ); ?></h1>

			<?php $this->notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mailhouse_save_subscriber" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<?php wp_nonce_field( 'mailhouse_save_subscriber' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mailhouse-email"><?php esc_html_e( 'Email', 'mailhouse' ); ?></label></th>
						<td><input type="email" id="mailhouse-email" name="email" class="regular-text" required value="<?php echo esc_attr( $subscriber ? $subscriber->email : '' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="mailhouse-first"><?php esc_html_e( 'First name', 'mailhouse' ); ?></label></th>
						<td><input type="text" id="mailhouse-first" name="first_name" class="regular-text" value="<?php echo esc_attr( $subscriber ? $subscriber->first_name : '' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="mailhouse-last"><?php esc_html_e( 'Last name', 'mailhouse' ); ?></label></th>
						<td><input type="text" id="mailhouse-last" name="last_name" class="regular-text" value="<?php echo esc_attr( $subscriber ? $subscriber->last_name : '' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="mailhouse-status"><?php esc_html_e( 'Status', 'mailhouse' ); ?></label></th>
						<td>
							<select id="mailhouse-status" name="status">
								<?php foreach ( $statuses as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $subscriber ? $subscriber->status : 'subscribed', $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Adding someone by hand skips the confirmation email. Only do it for people who genuinely asked.', 'mailhouse' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tags', 'mailhouse' ); ?></th>
						<td>
							<?php if ( $tags ) : ?>
								<?php foreach ( $tags as $tag ) : ?>
									<label style="margin-right:12px">
										<input type="checkbox" name="tags[]" value="<?php echo esc_attr( $tag->id ); ?>" <?php checked( in_array( (int) $tag->id, $current, true ) ); ?> />
										<?php echo esc_html( $tag->name ); ?>
									</label>
								<?php endforeach; ?>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'No tags yet.', 'mailhouse' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $subscriber ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'History', 'mailhouse' ); ?></th>
							<td>
								<p>
									<?php
									printf(
										/* translators: 1: signup date, 2: source. */
										esc_html__( 'Signed up %1$s from %2$s.', 'mailhouse' ),
										esc_html( mailhouse_date( $subscriber->created_at ) ),
										esc_html( $subscriber->source ? $subscriber->source : __( 'unknown', 'mailhouse' ) )
									);
									?>
								</p>
								<p>
									<?php
									printf(
										/* translators: 1: emails sent, 2: opens, 3: clicks. */
										esc_html__( '%1$s emails sent, %2$s opens, %3$s clicks.', 'mailhouse' ),
										esc_html( number_format_i18n( (int) $subscriber->sent ) ),
										esc_html( number_format_i18n( (int) $subscriber->opens ) ),
										esc_html( number_format_i18n( (int) $subscriber->clicks ) )
									);
									?>
								</p>
								<p><a href="<?php echo esc_url( mailhouse_admin_url( 'log', array( 's' => $subscriber->email ) ) ); ?>"><?php esc_html_e( 'See their email log', 'mailhouse' ); ?></a></p>
							</td>
						</tr>
					<?php endif; ?>
				</table>

				<?php submit_button( $subscriber ? __( 'Save subscriber', 'mailhouse' ) : __( 'Add subscriber', 'mailhouse' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save the add/edit form.
	 *
	 * @return void
	 */
	public function save_subscriber() {
		check_admin_referer( 'mailhouse_save_subscriber' );

		if ( ! mailhouse_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mailhouse' ), '', array( 'response' => 403 ) );
		}

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$email  = isset( $_POST['email'] ) ? mailhouse_clean_email( wp_unslash( $_POST['email'] ) ) : '';
		$first  = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last   = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'subscribed';
		$tags   = isset( $_POST['tags'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['tags'] ) ) : array();

		if ( ! $email ) {
			$this->redirect( 'invalid' );
		}

		if ( ! array_key_exists( $status, Mailhouse_Subscribers::statuses() ) ) {
			$status = 'subscribed';
		}

		if ( $id ) {
			$existing = Mailhouse_Subscribers::get_by_email( $email );

			if ( $existing && (int) $existing->id !== $id ) {
				$this->redirect( 'exists' );
			}

			Mailhouse_Subscribers::update(
				$id,
				array(
					'email'      => $email,
					'first_name' => $first,
					'last_name'  => $last,
					'status'     => $status,
				)
			);

			Mailhouse_Tags::set( $id, $tags );

			$this->redirect( 'saved' );
		}

		$result = Mailhouse_Subscribers::add(
			array(
				'email'       => $email,
				'first_name'  => $first,
				'last_name'   => $last,
				'status'      => $status,
				'tags'        => $tags,
				'source'            => 'admin',
				'send_emails'       => 'pending' === $status,
				'overwrite_names'   => true,
				'allow_resubscribe' => true,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'invalid' );
		}

		$this->redirect( $result['is_new'] ? 'added' : 'exists' );
	}

	/**
	 * The Tags screen.
	 *
	 * @return void
	 */
	public function render_tags() {
		if ( ! mailhouse_can_manage() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing = $edit_id ? Mailhouse_Tags::get( $edit_id ) : null;

		?>
		<div class="wrap mailhouse">
			<h1><?php esc_html_e( 'Tags', 'mailhouse' ); ?></h1>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->notice();
			?>

			<p class="description"><?php esc_html_e( 'Tags are how a campaign is aimed at part of the list rather than all of it. A subscriber can carry as many as you like.', 'mailhouse' ); ?></p>

			<div class="mailhouse-columns">
				<div class="mailhouse-panel mailhouse-panel--narrow">
					<h2><?php echo $editing ? esc_html__( 'Edit tag', 'mailhouse' ) : esc_html__( 'Add tag', 'mailhouse' ); ?></h2>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="mailhouse_save_tag" />
						<input type="hidden" name="id" value="<?php echo esc_attr( (string) $edit_id ); ?>" />
						<?php wp_nonce_field( 'mailhouse_save_tag' ); ?>

						<p>
							<label for="mailhouse-tag-name"><?php esc_html_e( 'Name', 'mailhouse' ); ?></label><br />
							<input type="text" id="mailhouse-tag-name" name="name" class="regular-text" required value="<?php echo esc_attr( $editing ? $editing->name : '' ); ?>" />
						</p>
						<p>
							<label for="mailhouse-tag-description"><?php esc_html_e( 'Description', 'mailhouse' ); ?></label><br />
							<textarea id="mailhouse-tag-description" name="description" rows="3" class="large-text"><?php echo esc_textarea( $editing ? (string) $editing->description : '' ); ?></textarea>
						</p>

						<?php submit_button( $editing ? __( 'Save tag', 'mailhouse' ) : __( 'Add tag', 'mailhouse' ) ); ?>
					</form>
				</div>

				<div class="mailhouse-panel">
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'mailhouse' ); ?></th>
								<th><?php esc_html_e( 'Slug', 'mailhouse' ); ?></th>
								<th><?php esc_html_e( 'Subscribers', 'mailhouse' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( Mailhouse_Tags::all() as $tag ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $tag->name ); ?></strong></td>
									<td><code><?php echo esc_html( $tag->slug ); ?></code></td>
									<td>
										<a href="<?php echo esc_url( mailhouse_admin_url( 'subscribers', array( 'tag' => (int) $tag->id ) ) ); ?>">
											<?php echo esc_html( number_format_i18n( (int) $tag->subscribers ) ); ?>
										</a>
									</td>
									<td>
										<a href="<?php echo esc_url( mailhouse_admin_url( 'tags', array( 'edit' => (int) $tag->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'mailhouse' ); ?></a>
										|
										<a class="submitdelete"
											href="<?php echo esc_url( wp_nonce_url( mailhouse_admin_url( 'tags', array( 'action' => 'delete_tag', 'id' => (int) $tag->id ) ), 'mailhouse_tag_' . $tag->id ) ); ?>"
											onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this tag? Subscribers keep their subscription.', 'mailhouse' ) ) ); ?>)">
											<?php esc_html_e( 'Delete', 'mailhouse' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save a tag.
	 *
	 * @return void
	 */
	public function save_tag() {
		check_admin_referer( 'mailhouse_save_tag' );

		if ( ! mailhouse_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mailhouse' ), '', array( 'response' => 403 ) );
		}

		$id          = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( $id ) {
			Mailhouse_Tags::update( $id, $name, $description );
		} else {
			Mailhouse_Tags::create( $name, $description );
		}

		wp_safe_redirect( mailhouse_admin_url( 'tags', array( 'mailhouse_notice' => 'tag_saved' ) ) );
		exit;
	}
}
