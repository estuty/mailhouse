<?php
/**
 * Automated emails.
 *
 * Three of them: the double opt-in confirmation, the welcome, and the new post
 * notification. Each is an ordinary email post, so the wording is editable
 * without touching any code.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends the emails nobody presses a button for.
 */
class Mailhouse_Automations {

	/**
	 * Register the hooks.
	 */
	public function __construct() {
		add_action( 'mailhouse_subscriber_confirmed', array( __CLASS__, 'send_welcome' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'post_published' ), 10, 3 );
		add_action( 'mailhouse_notify_post', array( __CLASS__, 'notify_post' ) );
		add_action( 'mailhouse_post_digest', array( __CLASS__, 'send_digests' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ) );
	}

	/**
	 * Send the double opt-in confirmation.
	 *
	 * Sent in the request rather than queued: somebody is sitting there
	 * watching for it, and a minute of cron latency reads as "it did not
	 * work".
	 *
	 * @param object $subscriber Subscriber row.
	 * @return bool
	 */
	public static function send_confirmation( $subscriber ) {
		$email = Mailhouse_Emails::get_automation( 'confirmation' );

		if ( $email ) {
			$message = Mailhouse_Emails::render( $email, $subscriber );
		} else {
			$message = self::fallback_confirmation( $subscriber );
		}

		return Mailhouse_Mailer::send(
			$subscriber->email,
			$message['subject'],
			$message['html'],
			array(
				'text'          => $message['text'],
				'from'          => mailhouse_clean_email( mailhouse_option( 'campaign_from_email' ) ),
				'from_name'     => (string) mailhouse_option( 'campaign_from_name' ),
				'reply_to'      => mailhouse_clean_email( mailhouse_option( 'campaign_reply_to' ) ),
				'campaign_id'   => $email ? (int) $email->ID : 0,
				'subscriber_id' => (int) $subscriber->id,
			)
		);
	}

	/**
	 * The confirmation email used when the editable one has been deleted.
	 *
	 * Losing the opt-in email must not mean losing the ability to take
	 * signups, so there is always something to send.
	 *
	 * @param object $subscriber Subscriber row.
	 * @return array{subject:string,html:string,text:string}
	 */
	protected static function fallback_confirmation( $subscriber ) {
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$url     = Mailhouse_Optin::confirm_url( $subscriber );
		$content = sprintf(
			'<p>%1$s</p>%2$s<p style="font-size:13px;">%3$s<br />%4$s</p>',
			esc_html__( 'Please confirm you want this newsletter by clicking the button below. If you did not sign up, ignore this email.', 'mailhouse' ),
			Mailhouse_Merge_Tags::button( $url, __( 'Confirm my subscription', 'mailhouse' ) ),
			esc_html__( 'If the button does not work, paste this into your browser:', 'mailhouse' ),
			esc_url( $url )
		);

		$subject = sprintf(
			/* translators: %s: site name. */
			__( 'Please confirm your subscription to %s', 'mailhouse' ),
			$site
		);

		$html = Mailhouse_Template::wrap(
			$content,
			array(
				'subject'    => $subject,
				'subscriber' => $subscriber,
			)
		);

		return array(
			'subject' => $subject,
			'html'    => $html,
			'text'    => mailhouse_html_to_text( $html ),
		);
	}

	/**
	 * Queue the welcome email for somebody who just joined.
	 *
	 * @param object $subscriber Subscriber row.
	 * @return void
	 */
	public static function send_welcome( $subscriber ) {
		if ( ! mailhouse_option( 'welcome_enabled' ) || ! $subscriber ) {
			return;
		}

		$email = Mailhouse_Emails::get_automation( 'welcome' );

		if ( ! $email ) {
			return;
		}

		// The unique key on (campaign, subscriber) means somebody who
		// unsubscribes and rejoins is not welcomed twice.
		Mailhouse_Queue::enqueue_one( (int) $email->ID, (int) $subscriber->id, $subscriber->email );
	}

	/**
	 * Note a post that has just gone live, and notify a little later.
	 *
	 * The notification runs two minutes after publishing rather than in the
	 * same request, for two reasons. The block editor saves the post first
	 * and its meta boxes in a second request, so the "don't notify" box is
	 * only stored after this hook has fired. And an author who spots a typo
	 * the moment the post goes live gets to fix it before it reaches every
	 * inbox.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Previous status.
	 * @param WP_Post $post       Post.
	 * @return void
	 */
	public static function post_published( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		if ( ! self::is_notifiable( $post ) ) {
			return;
		}

		// An importer publishing a thousand old posts is not news.
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return;
		}

		if ( get_post_meta( $post->ID, '_mailhouse_notify_scheduled', true ) ) {
			return;
		}

		update_post_meta( $post->ID, '_mailhouse_notify_scheduled', time() );

		/**
		 * Filter how long after publishing the notification goes out.
		 *
		 * @param int     $delay Seconds.
		 * @param WP_Post $post  Post.
		 */
		$delay = max( 0, (int) apply_filters( 'mailhouse_notify_delay', 2 * MINUTE_IN_SECONDS, $post ) );

		wp_schedule_single_event( time() + $delay, 'mailhouse_notify_post', array( (int) $post->ID ) );
	}

	/**
	 * Whether a post may be emailed about at all.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	protected static function is_notifiable( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( Mailhouse_Emails::POST_TYPE === $post->post_type || wp_is_post_revision( $post ) ) {
			return false;
		}

		if ( 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
			return false;
		}

		// Password-protected content never leaves the site by email.
		if ( '' !== (string) $post->post_password ) {
			return false;
		}

		if ( get_post_meta( $post->ID, '_mailhouse_skip_notify', true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Send, draft or collect the notification for one post.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function notify_post( $post_id ) {
		$post = get_post( (int) $post_id );

		// Checked again: in the two minutes since publishing the post may
		// have been unpublished, protected, or marked "don't notify".
		if ( ! self::is_notifiable( $post ) ) {
			return;
		}

		// Guard against a second run, and against a post that is unpublished
		// and published again.
		if ( get_post_meta( $post->ID, '_mailhouse_notified', true ) ) {
			return;
		}

		$issues = array();

		foreach ( self::automations_for( $post ) as $automation ) {
			$frequency = self::frequency( $automation );

			if ( 'instant' !== $frequency ) {
				$pending   = array_map( 'intval', (array) get_post_meta( $automation->ID, '_mailhouse_digest_queue', true ) );
				$pending[] = (int) $post->ID;

				update_post_meta( $automation->ID, '_mailhouse_digest_queue', array_values( array_unique( array_filter( $pending ) ) ) );
				$issues[] = 'digest';
				continue;
			}

			$campaign = self::create_issue( $automation, array( $post ) );

			if ( $campaign ) {
				$issues[] = $campaign;
				self::deliver( $automation, $campaign );
			}
		}

		if ( $issues ) {
			update_post_meta( $post->ID, '_mailhouse_notified', is_int( $issues[0] ) ? $issues[0] : 'digest' );
		}
	}

	/**
	 * Every published new post automation that wants this post.
	 *
	 * @param WP_Post $post Post.
	 * @return WP_Post[]
	 */
	protected static function automations_for( $post ) {
		$automations = get_posts(
			array(
				'post_type'      => Mailhouse_Emails::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_mailhouse_type',
						'value' => 'new_post',
					),
				),
			)
		);

		$out = array();

		foreach ( $automations as $automation ) {
			$post_types = array_filter( (array) get_post_meta( $automation->ID, '_mailhouse_post_types', true ) );
			$post_types = $post_types ? $post_types : array( 'post' );

			if ( ! in_array( $post->post_type, $post_types, true ) ) {
				continue;
			}

			$categories = array_filter( array_map( 'intval', (array) get_post_meta( $automation->ID, '_mailhouse_categories', true ) ) );

			if ( $categories && is_object_in_taxonomy( $post->post_type, 'category' ) ) {
				$post_categories = wp_get_post_categories( $post->ID );

				if ( ! array_intersect( $categories, array_map( 'intval', $post_categories ) ) ) {
					continue;
				}
			}

			$out[] = $automation;
		}

		return $out;
	}

	/**
	 * How often an automation sends.
	 *
	 * @param WP_Post $automation Automation.
	 * @return string instant, daily or weekly.
	 */
	public static function frequency( $automation ) {
		$frequency = (string) get_post_meta( $automation->ID, '_mailhouse_frequency', true );

		return in_array( $frequency, array( 'daily', 'weekly' ), true ) ? $frequency : 'instant';
	}

	/**
	 * Queue an issue, or leave it as a draft for somebody to approve.
	 *
	 * @param WP_Post $automation Automation.
	 * @param int     $campaign   Issue id.
	 * @return void
	 */
	protected static function deliver( $automation, $campaign ) {
		if ( 'review' !== get_post_meta( $automation->ID, '_mailhouse_mode', true ) ) {
			Mailhouse_Queue::enqueue_campaign( $campaign );
			return;
		}

		$to = mailhouse_clean_email( mailhouse_option( 'alerts_email' ) );
		$to = $to ? $to : get_option( 'admin_email' );

		Mailhouse_Mailer::send(
			$to,
			sprintf(
				/* translators: %s: email subject. */
				__( 'Ready to review: %s', 'mailhouse' ),
				get_the_title( $campaign )
			),
			Mailhouse_Template::wrap(
				'<p>' . esc_html__( 'A new post notification has been written and is waiting for you. Nothing has been sent yet.', 'mailhouse' ) . '</p>' .
				Mailhouse_Merge_Tags::button( admin_url( 'post.php?post=' . (int) $campaign . '&action=edit' ), __( 'Review and send', 'mailhouse' ) ),
				array( 'subject' => __( 'Ready to review', 'mailhouse' ) )
			)
		);
	}

	/**
	 * Send the daily and weekly digests that are due.
	 *
	 * Runs hourly. A digest goes out at the hour set on the automation
	 * (08:00 by default, site time); a weekly one on its chosen weekday.
	 *
	 * @return void
	 */
	public static function send_digests() {
		$automations = get_posts(
			array(
				'post_type'      => Mailhouse_Emails::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_mailhouse_type',
						'value' => 'new_post',
					),
				),
			)
		);

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		foreach ( $automations as $automation ) {
			$frequency = self::frequency( $automation );

			if ( 'instant' === $frequency ) {
				continue;
			}

			$hour    = min( 23, max( 0, (int) get_post_meta( $automation->ID, '_mailhouse_digest_hour', true ) ) );
			$hour    = '' === (string) get_post_meta( $automation->ID, '_mailhouse_digest_hour', true ) ? 8 : $hour;
			$weekday = (int) get_post_meta( $automation->ID, '_mailhouse_digest_day', true );
			$weekday = $weekday >= 1 && $weekday <= 7 ? $weekday : 1;

			if ( (int) $now->format( 'G' ) < $hour ) {
				continue;
			}

			if ( 'weekly' === $frequency && (int) $now->format( 'N' ) !== $weekday ) {
				continue;
			}

			$period = 'weekly' === $frequency ? $now->format( 'o-W' ) : $now->format( 'Y-m-d' );

			if ( get_post_meta( $automation->ID, '_mailhouse_digest_last', true ) === $period ) {
				continue;
			}

			$ids   = array_map( 'intval', (array) get_post_meta( $automation->ID, '_mailhouse_digest_queue', true ) );
			$posts = array();

			foreach ( $ids as $id ) {
				$post = get_post( $id );

				if ( self::is_notifiable( $post ) ) {
					$posts[] = $post;
				}
			}

			update_post_meta( $automation->ID, '_mailhouse_digest_last', $period );

			if ( ! $posts ) {
				delete_post_meta( $automation->ID, '_mailhouse_digest_queue' );
				continue;
			}

			$campaign = self::create_issue( $automation, $posts );

			if ( $campaign ) {
				delete_post_meta( $automation->ID, '_mailhouse_digest_queue' );
				self::deliver( $automation, $campaign );
			}
		}
	}

	/**
	 * Copy an automation into a one-off issue about one or more posts.
	 *
	 * A copy rather than a send from the automation itself, for three
	 * reasons: the queue's one-row-per-recipient key would otherwise let a
	 * subscriber be notified about the first post and no others; the wording
	 * that actually went out stays on record; and later edits to the
	 * automation do not rewrite history.
	 *
	 * A digest's posts are written into the issue as cards where the
	 * automation says {{post_list}} (or at the end, when it does not); the
	 * single-post tags resolve to the newest post.
	 *
	 * @param WP_Post   $automation Automation post.
	 * @param WP_Post[] $posts      Posts the issue is about, newest last.
	 * @return int New campaign id, or 0 on failure.
	 */
	protected static function create_issue( $automation, $posts ) {
		$posts   = array_values( $posts );
		$lead    = end( $posts );
		$count   = count( $posts );
		$content = (string) $automation->post_content;

		if ( '' === trim( wp_strip_all_tags( $content, true ) ) && false === strpos( $content, '{{' ) ) {
			$content = $count > 1 ? '{{post_list}}' : '{{post_card}}';
		}

		if ( $count > 1 || false !== strpos( $content, 'post_list' ) ) {
			$cards = array();

			foreach ( array_reverse( $posts ) as $post ) {
				$cards[] = Mailhouse_Merge_Tags::card_for( $post );
			}

			$list = implode( '<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0;" />', $cards );

			if ( preg_match( '/(?:\{\{|\[\[)\s*post_list\s*(?:\}\}|\]\])/i', $content ) ) {
				$content = (string) preg_replace( '/(?:\{\{|\[\[)\s*post_list\s*(?:\}\}|\]\])/i', $list, $content );
			} elseif ( $count > 1 ) {
				// A single-post template used for a digest: drop the
				// single-post card and list every post instead.
				$content = str_replace( array( '{{post_card}}', '[[post_card]]' ), '', $content ) . $list;
			}
		}

		$title = $count > 1
			? sprintf(
				/* translators: %d: number of posts. */
				_n( 'Digest: %d new post', 'Digest: %d new posts', $count, 'mailhouse' ),
				$count
			)
			: sprintf(
				/* translators: %s: post title. */
				__( 'New post: %s', 'mailhouse' ),
				wp_strip_all_tags( get_the_title( $lead ) )
			);

		$review   = 'review' === get_post_meta( $automation->ID, '_mailhouse_mode', true );
		$campaign = wp_insert_post(
			array(
				'post_type'    => Mailhouse_Emails::POST_TYPE,
				'post_status'  => $review ? 'draft' : 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $campaign ) ) {
			mailhouse_log_debug( 'Could not create the notification issue', $campaign->get_error_message() );

			return 0;
		}

		$subject = (string) get_post_meta( $automation->ID, '_mailhouse_subject', true );
		$subject = str_replace( array( '{{post_count}}', '[[post_count]]' ), (string) $count, $subject );

		update_post_meta( $campaign, '_mailhouse_type', 'newsletter' );
		update_post_meta( $campaign, '_mailhouse_generated', 1 );
		update_post_meta( $campaign, '_mailhouse_source_post', (int) $lead->ID );
		update_post_meta( $campaign, '_mailhouse_subject', '' !== $subject ? $subject : ( $count > 1 ? $title : '{{post_title}}' ) );
		update_post_meta( $campaign, '_mailhouse_preview', (string) get_post_meta( $automation->ID, '_mailhouse_preview', true ) );
		update_post_meta( $campaign, '_mailhouse_tag', (int) get_post_meta( $automation->ID, '_mailhouse_tag', true ) );

		return (int) $campaign;
	}

	/**
	 * The "don't notify" box on the post editor.
	 *
	 * @param string $post_type Post type being edited.
	 * @return void
	 */
	public static function add_meta_box( $post_type ) {
		if ( Mailhouse_Emails::POST_TYPE === $post_type || ! is_post_type_viewable( $post_type ) || 'attachment' === $post_type ) {
			return;
		}

		if ( ! current_user_can( 'publish_posts' ) ) {
			return;
		}

		add_meta_box( 'mailhouse-notify', __( 'Newsletter', 'mailhouse' ), array( __CLASS__, 'render_meta_box' ), $post_type, 'side', 'low' );
	}

	/**
	 * Print the box.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'mailhouse_skip_notify', 'mailhouse_skip_notify_nonce' );

		$notified = get_post_meta( $post->ID, '_mailhouse_notified', true );

		if ( $notified ) {
			echo '<p>' . esc_html__( 'Subscribers have already been told about this post.', 'mailhouse' ) . '</p>';
			return;
		}

		printf(
			'<label><input type="checkbox" name="mailhouse_skip_notify" value="1"%s /> %s</label>',
			checked( (bool) get_post_meta( $post->ID, '_mailhouse_skip_notify', true ), true, false ),
			esc_html__( "Don't email subscribers about this post", 'mailhouse' )
		);
	}

	/**
	 * Save the box.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['mailhouse_skip_notify_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mailhouse_skip_notify_nonce'] ) ), 'mailhouse_skip_notify' ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! empty( $_POST['mailhouse_skip_notify'] ) ) {
			update_post_meta( $post_id, '_mailhouse_skip_notify', 1 );
		} else {
			delete_post_meta( $post_id, '_mailhouse_skip_notify' );
		}
	}
}
