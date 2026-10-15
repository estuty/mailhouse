<?php
/**
 * Emails: broadcasts and automations, stored as posts.
 *
 * A post type rather than a bespoke table, because an email is a title and a
 * body -- exactly what the post editor already does well, revisions and
 * autosave included.
 *
 * The editor is deliberately the classic one. Block markup carries layout CSS
 * that email clients throw away, and half the blocks render as nothing in
 * Outlook; simple HTML wrapped in the plugin's own table template survives the
 * trip.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the email post type and turns a post into a sendable message.
 */
class Mailhouse_Emails {

	/**
	 * Post type name.
	 */
	const POST_TYPE = 'mailhouse_email';

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( $this, 'meta_boxes' ) );
			add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ), 10, 2 );

			add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
			add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
			add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
			add_filter( 'use_block_editor_for_post_type', array( $this, 'classic_editor' ), 10, 2 );
			add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );
		}
	}

	/**
	 * The kinds of email the plugin sends.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	public static function types() {
		return array(
			'newsletter'   => array(
				'label'       => __( 'Broadcast', 'mailhouse' ),
				'description' => __( 'A one-off issue you send yourself.', 'mailhouse' ),
			),
			'new_post'     => array(
				'label'       => __( 'New post notification', 'mailhouse' ),
				'description' => __( 'Sent automatically when a post is published.', 'mailhouse' ),
			),
			'welcome'      => array(
				'label'       => __( 'Welcome email', 'mailhouse' ),
				'description' => __( 'Sent once, when somebody joins the list.', 'mailhouse' ),
			),
			'confirmation' => array(
				'label'       => __( 'Confirmation email', 'mailhouse' ),
				'description' => __( 'The double opt-in email. Must contain {{confirmation_url}}.', 'mailhouse' ),
			),
		);
	}

	/**
	 * Register the post type.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		$cap = mailhouse_capability();

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Emails', 'mailhouse' ),
					'singular_name'      => __( 'Email', 'mailhouse' ),
					'add_new'            => __( 'Add email', 'mailhouse' ),
					'add_new_item'       => __( 'Add email', 'mailhouse' ),
					'edit_item'          => __( 'Edit email', 'mailhouse' ),
					'new_item'           => __( 'New email', 'mailhouse' ),
					'view_item'          => __( 'Preview email', 'mailhouse' ),
					'search_items'       => __( 'Search emails', 'mailhouse' ),
					'not_found'          => __( 'No emails yet.', 'mailhouse' ),
					'not_found_in_trash' => __( 'Nothing in the trash.', 'mailhouse' ),
					'menu_name'          => __( 'Emails', 'mailhouse' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'mailhouse',
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'editor', 'revisions', 'author' ),
				'map_meta_cap'        => false,
				'capabilities'        => array(
					'edit_post'              => $cap,
					'read_post'              => $cap,
					'delete_post'            => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'create_posts'           => $cap,
					'delete_posts'           => $cap,
					'delete_private_posts'   => $cap,
					'delete_published_posts' => $cap,
					'delete_others_posts'    => $cap,
					'edit_private_posts'     => $cap,
					'edit_published_posts'   => $cap,
				),
			)
		);
	}

	/**
	 * Keep the classic editor for emails.
	 *
	 * @param bool   $use       Whether to use the block editor.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function classic_editor( $use, $post_type ) {
		return self::POST_TYPE === $post_type ? false : $use;
	}

	/**
	 * Ask for a subject line rather than a title.
	 *
	 * @param string  $text Placeholder.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function title_placeholder( $text, $post ) {
		return self::POST_TYPE === $post->post_type ? __( 'Internal name for this email', 'mailhouse' ) : $text;
	}

	/**
	 * Add the settings panels to the editor.
	 *
	 * @return void
	 */
	public function meta_boxes() {
		add_meta_box(
			'mailhouse-settings',
			__( 'Email settings', 'mailhouse' ),
			array( $this, 'settings_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'mailhouse-send',
			__( 'Sending', 'mailhouse' ),
			array( $this, 'send_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);

		add_meta_box(
			'mailhouse-tags',
			__( 'Merge tags', 'mailhouse' ),
			array( $this, 'tags_box' ),
			self::POST_TYPE,
			'side',
			'low'
		);
	}

	/**
	 * The subject, preheader and type panel.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function settings_box( $post ) {
		wp_nonce_field( 'mailhouse_save_email', 'mailhouse_email_nonce' );

		$type    = self::type( $post );
		$subject = get_post_meta( $post->ID, '_mailhouse_subject', true );
		$preview = get_post_meta( $post->ID, '_mailhouse_preview', true );
		$types   = self::types();
		$sent    = 'sent' === self::status( $post );

		?>
		<p>
			<label for="mailhouse-subject"><strong><?php esc_html_e( 'Subject line', 'mailhouse' ); ?></strong></label><br />
			<input type="text" class="large-text" id="mailhouse-subject" name="mailhouse_subject" value="<?php echo esc_attr( $subject ); ?>" placeholder="<?php echo esc_attr( get_the_title( $post ) ); ?>" <?php disabled( $sent ); ?> />
		</p>
		<p>
			<label for="mailhouse-preview"><strong><?php esc_html_e( 'Preview text', 'mailhouse' ); ?></strong></label><br />
			<input type="text" class="large-text" id="mailhouse-preview" name="mailhouse_preview" value="<?php echo esc_attr( $preview ); ?>" <?php disabled( $sent ); ?> />
			<span class="description"><?php esc_html_e( 'The grey line the inbox shows after the subject. Leave it empty and the client picks its own, usually badly.', 'mailhouse' ); ?></span>
		</p>
		<p>
			<label for="mailhouse-type"><strong><?php esc_html_e( 'Type', 'mailhouse' ); ?></strong></label><br />
			<select id="mailhouse-type" name="mailhouse_type" <?php disabled( $sent ); ?>>
				<?php foreach ( $types as $key => $info ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $info['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description"><?php echo esc_html( $types[ $type ]['description'] ); ?></span>
		</p>

		<div class="mailhouse-type-panel" data-type="new_post"<?php echo 'new_post' === $type ? '' : ' style="display:none"'; ?>>
			<p><strong><?php esc_html_e( 'Send when one of these is published', 'mailhouse' ); ?></strong></p>
			<?php
			$selected   = (array) get_post_meta( $post->ID, '_mailhouse_post_types', true );
			$selected   = $selected ? $selected : array( 'post' );
			$post_types = get_post_types( array( 'public' => true ), 'objects' );

			foreach ( $post_types as $object ) {
				if ( 'attachment' === $object->name ) {
					continue;
				}

				printf(
					'<label style="margin-right:12px"><input type="checkbox" name="mailhouse_post_types[]" value="%s"%s /> %s</label>',
					esc_attr( $object->name ),
					in_array( $object->name, $selected, true ) ? ' checked="checked"' : '',
					esc_html( $object->labels->singular_name )
				);
			}

			$categories = array_map( 'intval', array_filter( (array) get_post_meta( $post->ID, '_mailhouse_categories', true ) ) );
			$terms      = get_terms(
				array(
					'taxonomy'   => 'category',
					'hide_empty' => false,
				)
			);
			$frequency  = (string) get_post_meta( $post->ID, '_mailhouse_frequency', true );
			$mode       = (string) get_post_meta( $post->ID, '_mailhouse_mode', true );
			$hour_meta  = (string) get_post_meta( $post->ID, '_mailhouse_digest_hour', true );
			$hour       = '' === $hour_meta ? 8 : (int) $hour_meta;
			$day        = (int) get_post_meta( $post->ID, '_mailhouse_digest_day', true );
			$day        = $day ? $day : 1;
			?>

			<?php if ( $terms && ! is_wp_error( $terms ) ) : ?>
				<p><strong><?php esc_html_e( 'Only posts in these categories', 'mailhouse' ); ?></strong>
					<span class="description"><?php esc_html_e( 'None ticked means every category.', 'mailhouse' ); ?></span></p>
				<p>
					<?php foreach ( $terms as $term ) : ?>
						<label style="margin-right:12px"><input type="checkbox" name="mailhouse_categories[]" value="<?php echo esc_attr( $term->term_id ); ?>" <?php checked( in_array( (int) $term->term_id, $categories, true ) ); ?> /> <?php echo esc_html( $term->name ); ?></label>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>

			<p>
				<label for="mailhouse-frequency"><strong><?php esc_html_e( 'How often', 'mailhouse' ); ?></strong></label><br />
				<select id="mailhouse-frequency" name="mailhouse_frequency">
					<option value="instant" <?php selected( $frequency, 'instant' ); ?>><?php esc_html_e( 'One email per post, shortly after it is published', 'mailhouse' ); ?></option>
					<option value="daily" <?php selected( $frequency, 'daily' ); ?>><?php esc_html_e( 'Daily digest of new posts', 'mailhouse' ); ?></option>
					<option value="weekly" <?php selected( $frequency, 'weekly' ); ?>><?php esc_html_e( 'Weekly digest of new posts', 'mailhouse' ); ?></option>
				</select>
			</p>
			<p>
				<label><?php esc_html_e( 'Digests go out at', 'mailhouse' ); ?>
					<select name="mailhouse_digest_hour">
						<?php for ( $h = 0; $h < 24; $h++ ) : ?>
							<option value="<?php echo esc_attr( $h ); ?>" <?php selected( $hour, $h ); ?>><?php echo esc_html( sprintf( '%02d:00', $h ) ); ?></option>
						<?php endfor; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'on (weekly)', 'mailhouse' ); ?>
					<select name="mailhouse_digest_day">
						<?php
						global $wp_locale;
						for ( $d = 1; $d <= 7; $d++ ) :
							?>
							<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $day, $d ); ?>><?php echo esc_html( $wp_locale->get_weekday( $d % 7 ) ); ?></option>
						<?php endfor; ?>
					</select>
				</label>
				<span class="description"><?php esc_html_e( 'Site time.', 'mailhouse' ); ?></span>
			</p>
			<p>
				<strong><?php esc_html_e( 'When it is ready', 'mailhouse' ); ?></strong><br />
				<label><input type="radio" name="mailhouse_mode" value="send" <?php checked( 'review' !== $mode ); ?> /> <?php esc_html_e( 'Send it automatically', 'mailhouse' ); ?></label><br />
				<label><input type="radio" name="mailhouse_mode" value="review" <?php checked( 'review' === $mode ); ?> /> <?php esc_html_e( 'Save it as a draft and email me to review it first', 'mailhouse' ); ?></label>
			</p>
			<p class="description"><?php esc_html_e( 'Use {{post_card}} in the content for a ready-made card with the image, title, excerpt and a Read more button, and {{post_list}} for a digest. Authors can untick a single post in its editor sidebar.', 'mailhouse' ); ?></p>
		</div>
		<?php
	}

	/**
	 * The recipients and send panel.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function send_box( $post ) {
		$type       = self::type( $post );
		$status     = self::status( $post );
		$recipients = get_post_meta( $post->ID, '_mailhouse_tag', true );
		$stats      = Mailhouse_Queue::campaign_stats( $post->ID );
		$is_auto    = 'newsletter' !== $type;

		?>
		<p>
			<label for="mailhouse-tag"><strong><?php esc_html_e( 'Send to', 'mailhouse' ); ?></strong></label><br />
			<select id="mailhouse-tag" name="mailhouse_tag" style="width:100%">
				<option value="0"><?php esc_html_e( 'Everyone subscribed', 'mailhouse' ); ?></option>
				<?php foreach ( Mailhouse_Tags::all() as $tag ) : ?>
					<option value="<?php echo esc_attr( $tag->id ); ?>" <?php selected( (int) $recipients, (int) $tag->id ); ?>>
						<?php
						printf(
							/* translators: 1: tag name, 2: number of subscribers. */
							esc_html__( '%1$s (%2$d)', 'mailhouse' ),
							esc_html( $tag->name ),
							(int) $tag->subscribers
						);
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<?php if ( $is_auto ) : ?>
			<p class="description">
				<?php esc_html_e( 'This email sends itself. Publish it to switch it on, move it to draft to switch it off.', 'mailhouse' ); ?>
			</p>
			<p>
				<strong><?php echo esc_html( number_format_i18n( $stats['sent'] ) ); ?></strong>
				<?php esc_html_e( 'sent so far', 'mailhouse' ); ?>
			</p>
		<?php else : ?>
			<?php if ( 'sending' === $status || 'paused' === $status ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: number sent, 2: total queued. */
						esc_html__( 'Sent %1$s of %2$s.', 'mailhouse' ),
						'<strong>' . esc_html( number_format_i18n( $stats['sent'] ) ) . '</strong>',
						'<strong>' . esc_html( number_format_i18n( $stats['total'] ) ) . '</strong>'
					);
					?>
				</p>
			<?php elseif ( 'sent' === $status ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: number sent, 2: opens, 3: clicks. */
						esc_html__( 'Sent to %1$s. %2$s opens, %3$s clicks.', 'mailhouse' ),
						'<strong>' . esc_html( number_format_i18n( $stats['sent'] ) ) . '</strong>',
						esc_html( number_format_i18n( $stats['opened'] ) ),
						esc_html( number_format_i18n( $stats['clicked'] ) )
					);
					?>
				</p>
			<?php else : ?>
				<p>
					<?php
					$count = Mailhouse_Queue::recipient_count( (int) $recipients );

					printf(
						/* translators: %s: number of subscribers. */
						esc_html__( 'Ready to send to %s subscribers.', 'mailhouse' ),
						'<strong>' . esc_html( number_format_i18n( $count ) ) . '</strong>'
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( 'publish' === $post->post_status && in_array( $status, array( '', 'draft' ), true ) ) : ?>
				<?php
				// A time typed into this box is only saved when the post is
				// updated, and only acted on when the button below is pressed.
				// Saving a draft can never start a send by accident.
				$send_at   = (string) get_post_meta( $post->ID, '_mailhouse_send_at', true );
				$timestamp = $send_at ? strtotime( $send_at . ' UTC' ) : 0;
				$scheduled = $timestamp > time();
				?>
				<p>
					<label for="mailhouse-send-at"><?php esc_html_e( 'Schedule for (site time)', 'mailhouse' ); ?></label>
					<input type="datetime-local" id="mailhouse-send-at" name="mailhouse_send_at" style="width:100%"
						value="<?php echo esc_attr( $timestamp ? wp_date( 'Y-m-d\TH:i', $timestamp ) : '' ); ?>" />
					<span class="description"><?php esc_html_e( 'Update the email to save a time, then use the button below. Leave empty to send straight away.', 'mailhouse' ); ?></span>
				</p>
				<p>
					<?php
					printf(
						'<a href="%s" class="button button-primary" onclick="return confirm(%s)">%s</a>',
						esc_url( self::action_url( 'mailhouse_send_campaign', $post->ID ) ),
						esc_attr(
							wp_json_encode(
								$scheduled
									? __( 'Queue this email for the time saved above?', 'mailhouse' )
									: __( 'Send this email now? It cannot be unsent.', 'mailhouse' )
							)
						),
						$scheduled
							? esc_html__( 'Schedule send', 'mailhouse' )
							: esc_html__( 'Send now', 'mailhouse' )
					);
					?>
				</p>
			<?php elseif ( 'sending' === $status ) : ?>
				<p><a href="<?php echo esc_url( self::action_url( 'mailhouse_pause_campaign', $post->ID ) ); ?>" class="button"><?php esc_html_e( 'Pause', 'mailhouse' ); ?></a></p>
			<?php elseif ( 'paused' === $status ) : ?>
				<p>
					<a href="<?php echo esc_url( self::action_url( 'mailhouse_resume_campaign', $post->ID ) ); ?>" class="button button-primary"><?php esc_html_e( 'Resume', 'mailhouse' ); ?></a>
					<a href="<?php echo esc_url( self::action_url( 'mailhouse_cancel_campaign', $post->ID ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'mailhouse' ); ?></a>
				</p>
			<?php elseif ( 'scheduled' === $status ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: date and time. */
						esc_html__( 'Scheduled for %s.', 'mailhouse' ),
						'<strong>' . esc_html( mailhouse_date( (string) get_post_meta( $post->ID, '_mailhouse_send_at', true ) ) ) . '</strong>'
					);
					?>
				</p>
				<p><a href="<?php echo esc_url( self::action_url( 'mailhouse_cancel_campaign', $post->ID ) ); ?>" class="button"><?php esc_html_e( 'Cancel schedule', 'mailhouse' ); ?></a></p>
			<?php elseif ( 'publish' !== $post->post_status ) : ?>
				<p class="description"><?php esc_html_e( 'Publish the email first, then the send controls appear here.', 'mailhouse' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<hr />
		<p>
			<label for="mailhouse-test"><strong><?php esc_html_e( 'Send a test to', 'mailhouse' ); ?></strong></label>
			<input type="email" id="mailhouse-test" style="width:100%" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
			<button type="button" class="button" id="mailhouse-send-test" data-post="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'mailhouse_test_email' ) ); ?>">
				<?php esc_html_e( 'Send test', 'mailhouse' ); ?>
			</button>
			<span class="mailhouse-test-result"></span>
		</p>
		<?php
	}

	/**
	 * The merge tag reference panel.
	 *
	 * @return void
	 */
	public function tags_box() {
		echo '<p class="description">' . esc_html__( 'Paste any of these into the subject or the body.', 'mailhouse' ) . '</p>';
		echo '<ul class="mailhouse-merge-tags">';

		foreach ( Mailhouse_Merge_Tags::catalogue() as $tag => $description ) {
			printf(
				'<li><code>{{%s}}</code><br /><span class="description">%s</span></li>',
				esc_html( $tag ),
				esc_html( $description )
			);
		}

		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'A fallback goes after a pipe: {{first_name|there}}.', 'mailhouse' ) . '</p>';
	}

	/**
	 * Save the panels.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function save( $post_id, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['mailhouse_email_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mailhouse_email_nonce'] ) ), 'mailhouse_save_email' ) ) {
			return;
		}

		if ( ! mailhouse_can_manage() ) {
			return;
		}

		$type  = isset( $_POST['mailhouse_type'] ) ? sanitize_key( wp_unslash( $_POST['mailhouse_type'] ) ) : 'newsletter';
		$types = self::types();

		update_post_meta( $post_id, '_mailhouse_type', isset( $types[ $type ] ) ? $type : 'newsletter' );

		update_post_meta(
			$post_id,
			'_mailhouse_subject',
			isset( $_POST['mailhouse_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['mailhouse_subject'] ) ) : ''
		);

		update_post_meta(
			$post_id,
			'_mailhouse_preview',
			isset( $_POST['mailhouse_preview'] ) ? sanitize_text_field( wp_unslash( $_POST['mailhouse_preview'] ) ) : ''
		);

		update_post_meta(
			$post_id,
			'_mailhouse_tag',
			isset( $_POST['mailhouse_tag'] ) ? absint( $_POST['mailhouse_tag'] ) : 0
		);

		$post_types = isset( $_POST['mailhouse_post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['mailhouse_post_types'] ) ) : array();

		update_post_meta( $post_id, '_mailhouse_post_types', $post_types );

		$categories = isset( $_POST['mailhouse_categories'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['mailhouse_categories'] ) ) ) ) : array();
		update_post_meta( $post_id, '_mailhouse_categories', $categories );

		$frequency = isset( $_POST['mailhouse_frequency'] ) ? sanitize_key( wp_unslash( $_POST['mailhouse_frequency'] ) ) : 'instant';
		update_post_meta( $post_id, '_mailhouse_frequency', in_array( $frequency, array( 'instant', 'daily', 'weekly' ), true ) ? $frequency : 'instant' );

		$mode = isset( $_POST['mailhouse_mode'] ) ? sanitize_key( wp_unslash( $_POST['mailhouse_mode'] ) ) : 'send';
		update_post_meta( $post_id, '_mailhouse_mode', 'review' === $mode ? 'review' : 'send' );

		if ( isset( $_POST['mailhouse_digest_hour'] ) ) {
			update_post_meta( $post_id, '_mailhouse_digest_hour', min( 23, absint( $_POST['mailhouse_digest_hour'] ) ) );
		}

		if ( isset( $_POST['mailhouse_digest_day'] ) ) {
			$day = absint( $_POST['mailhouse_digest_day'] );
			update_post_meta( $post_id, '_mailhouse_digest_day', $day >= 1 && $day <= 7 ? $day : 1 );
		}

		// A schedule typed into the box is only acted on by the Send button,
		// so saving a draft never starts a send by accident.
		if ( ! empty( $_POST['mailhouse_send_at'] ) ) {
			$local = sanitize_text_field( wp_unslash( $_POST['mailhouse_send_at'] ) );
			$time  = strtotime( $local . ' ' . wp_timezone_string() );

			if ( $time ) {
				update_post_meta( $post_id, '_mailhouse_send_at', gmdate( 'Y-m-d H:i:s', $time ) );
			}
		}
	}

	/**
	 * The list table columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['mailhouse_type']   = __( 'Type', 'mailhouse' );
				$new['mailhouse_status'] = __( 'Status', 'mailhouse' );
				$new['mailhouse_stats']  = __( 'Results', 'mailhouse' );
			}
		}

		return $new;
	}

	/**
	 * Fill in a custom column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post id.
	 * @return void
	 */
	public function column( $column, $post_id ) {
		$post = get_post( $post_id );

		switch ( $column ) {
			case 'mailhouse_type':
				if ( get_post_meta( $post_id, '_mailhouse_generated', true ) ) {
					// An issue the new post automation wrote. It behaves as a
					// broadcast, but saying so would be confusing next to the
					// automation that produced it.
					esc_html_e( 'Post notification', 'mailhouse' );
					break;
				}

				$types = self::types();
				$type  = self::type( $post );

				echo esc_html( isset( $types[ $type ] ) ? $types[ $type ]['label'] : $type );
				break;

			case 'mailhouse_status':
				echo esc_html( self::status_label( $post ) );
				break;

			case 'mailhouse_stats':
				if ( 'newsletter' === self::type( $post ) ) {
					$stats = Mailhouse_Queue::campaign_stats( $post_id );

					if ( $stats['total'] ) {
						printf(
							/* translators: 1: sent, 2: opens, 3: clicks. */
							esc_html__( '%1$s sent, %2$s opens, %3$s clicks', 'mailhouse' ),
							esc_html( number_format_i18n( $stats['sent'] ) ),
							esc_html( number_format_i18n( $stats['opened'] ) ),
							esc_html( number_format_i18n( $stats['clicked'] ) )
						);
					} else {
						echo '&mdash;';
					}
				} else {
					$stats = Mailhouse_Queue::campaign_stats( $post_id );

					printf(
						/* translators: %s: number of emails sent. */
						esc_html__( '%s sent', 'mailhouse' ),
						esc_html( number_format_i18n( $stats['sent'] ) )
					);
				}
				break;
		}
	}

	/**
	 * Add a duplicate link to the row actions.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		$actions['mailhouse_duplicate'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::action_url( 'mailhouse_duplicate_email', $post->ID ) ),
			esc_html__( 'Duplicate', 'mailhouse' )
		);

		return $actions;
	}

	/**
	 * A nonce-protected admin-post URL for one of the send actions.
	 *
	 * @param string $action  Action name.
	 * @param int    $post_id Post id.
	 * @return string
	 */
	public static function action_url( $action, $post_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'email'  => (int) $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $post_id
		);
	}

	/**
	 * An email's type.
	 *
	 * @param WP_Post|int $post Post.
	 * @return string
	 */
	public static function type( $post ) {
		$post = get_post( $post );
		$type = $post ? (string) get_post_meta( $post->ID, '_mailhouse_type', true ) : '';
		$types = self::types();

		return isset( $types[ $type ] ) ? $type : 'newsletter';
	}

	/**
	 * A broadcast's send status.
	 *
	 * @param WP_Post|int $post Post.
	 * @return string One of '', scheduled, sending, paused, sent.
	 */
	public static function status( $post ) {
		$post = get_post( $post );

		return $post ? (string) get_post_meta( $post->ID, '_mailhouse_status', true ) : '';
	}

	/**
	 * A human-readable status.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function status_label( $post ) {
		if ( 'newsletter' !== self::type( $post ) ) {
			return 'publish' === $post->post_status
				? __( 'Active', 'mailhouse' )
				: __( 'Off', 'mailhouse' );
		}

		switch ( self::status( $post ) ) {
			case 'sending':
				return __( 'Sending', 'mailhouse' );

			case 'paused':
				return __( 'Paused', 'mailhouse' );

			case 'sent':
				return __( 'Sent', 'mailhouse' );

			case 'scheduled':
				return __( 'Scheduled', 'mailhouse' );
		}

		return __( 'Draft', 'mailhouse' );
	}

	/**
	 * The published automation of a given type, if there is one.
	 *
	 * @param string $type welcome, confirmation or new_post.
	 * @return WP_Post|null
	 */
	public static function get_automation( $type ) {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_mailhouse_type',
						'value' => $type,
					),
				),
			)
		);

		return $posts ? $posts[0] : null;
	}

	/**
	 * Turn an email into a message ready for one recipient.
	 *
	 * @param WP_Post|int $post       Email.
	 * @param object|null $subscriber Recipient.
	 * @param array       $context    Extra merge tag context, e.g. post.
	 * @return array{subject:string,html:string,text:string}
	 */
	public static function render( $post, $subscriber = null, $context = array() ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return array(
				'subject' => '',
				'html'    => '',
				'text'    => '',
			);
		}

		$context = array_merge( array( 'subscriber' => $subscriber, 'campaign' => $post ), $context );

		// A generated issue remembers the post it was written about, so
		// {{post_title}} and friends still resolve when it is sent days later.
		if ( empty( $context['post'] ) ) {
			$source = (int) get_post_meta( $post->ID, '_mailhouse_source_post', true );

			if ( $source ) {
				$context['post'] = $source;
			}
		}

		$subject = (string) get_post_meta( $post->ID, '_mailhouse_subject', true );
		$subject = '' !== $subject ? $subject : get_the_title( $post );
		$subject = Mailhouse_Merge_Tags::render( $subject, $context );

		$content = (string) $post->post_content;
		$content = do_blocks( $content );
		$content = do_shortcode( $content );
		$content = wpautop( $content );
		$content = Mailhouse_Merge_Tags::render( $content, $context, 'html' );

		$preview = Mailhouse_Merge_Tags::render( (string) get_post_meta( $post->ID, '_mailhouse_preview', true ), $context );

		$html = Mailhouse_Template::wrap(
			$content,
			array(
				'subject'         => $subject,
				'preview'         => $preview,
				'subscriber'      => $subscriber,
				'unsubscribe_url' => $subscriber ? Mailhouse_Optin::unsubscribe_url( $subscriber ) : '',
				'preferences_url' => $subscriber ? Mailhouse_Optin::preferences_url( $subscriber ) : '',
			)
		);

		return array(
			'subject' => wp_specialchars_decode( $subject, ENT_QUOTES ),
			'html'    => $html,
			'text'    => mailhouse_html_to_text( $html ),
		);
	}

	/**
	 * Create a ready-to-use new post notification, switched off.
	 *
	 * @return void
	 */
	public static function create_default_new_post() {
		if ( self::get_automation( 'new_post' ) || get_option( 'mailhouse_new_post_seeded' ) ) {
			return;
		}

		update_option( 'mailhouse_new_post_seeded', 1, false );

		// Left as a draft: a plugin update must never start emailing a list
		// on its own. Publishing it is what switches notifications on.
		$id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => __( 'New post notification', 'mailhouse' ),
				'post_content' => __( "<p>Hello {{first_name|there}},</p>\n\n<p>There is something new on {{site_name}}:</p>\n\n{{post_card}}", 'mailhouse' ),
			)
		);

		if ( $id && ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_mailhouse_type', 'new_post' );
			update_post_meta( $id, '_mailhouse_subject', '{{post_title}}' );
			update_post_meta( $id, '_mailhouse_post_types', array( 'post' ) );
			update_post_meta( $id, '_mailhouse_frequency', 'instant' );
			update_post_meta( $id, '_mailhouse_mode', 'send' );
		}
	}

	/**
	 * Create the two emails a new install cannot work without.
	 *
	 * Both are published straight away: a site with double opt-in on and no
	 * confirmation email would collect addresses that can never confirm.
	 *
	 * @return void
	 */
	public static function create_default_emails() {
		if ( self::get_automation( 'confirmation' ) ) {
			return;
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$confirmation = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => __( 'Confirm your subscription', 'mailhouse' ),
				'post_content' => __(
					"<p>Hello {{first_name|there}},</p>\n\n<p>Please confirm you want the {{site_name}} newsletter by clicking the link below. If you did not sign up, ignore this email and nothing will be sent.</p>\n\n<p><a href=\"{{confirmation_url}}\">Confirm my subscription</a></p>\n\n<p>{{confirmation_url}}</p>",
					'mailhouse'
				),
			)
		);

		if ( $confirmation && ! is_wp_error( $confirmation ) ) {
			update_post_meta( $confirmation, '_mailhouse_type', 'confirmation' );
			update_post_meta(
				$confirmation,
				'_mailhouse_subject',
				sprintf(
					/* translators: %s: site name. */
					__( 'Please confirm your subscription to %s', 'mailhouse' ),
					$site
				)
			);
		}

		$welcome = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => __( 'Welcome', 'mailhouse' ),
				'post_content' => __(
					"<p>Hello {{first_name|there}},</p>\n\n<p>Thanks for subscribing to {{site_name}}. You will hear from us when there is something worth sending.</p>\n\n<p>You can change what you receive, or stop receiving it, at any time using the links at the bottom of every email.</p>",
					'mailhouse'
				),
			)
		);

		if ( $welcome && ! is_wp_error( $welcome ) ) {
			update_post_meta( $welcome, '_mailhouse_type', 'welcome' );
			update_post_meta(
				$welcome,
				'_mailhouse_subject',
				sprintf(
					/* translators: %s: site name. */
					__( 'Welcome to %s', 'mailhouse' ),
					$site
				)
			);
		}
	}
}
