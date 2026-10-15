<?php
/**
 * Merge tags.
 *
 * Written as {{first_name}}, with an optional fallback after a pipe:
 * {{first_name|there}}. The [[double-bracket]] form other newsletter plugins
 * use is accepted too, so content pasted across from one of them still works.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replaces merge tags with real values.
 */
class Mailhouse_Merge_Tags {

	/**
	 * Every tag, with a one-line description, for the editor's reference list.
	 *
	 * @return array<string,string>
	 */
	public static function catalogue() {
		return array(
			'email'            => __( "The subscriber's email address", 'mailhouse' ),
			'first_name'       => __( 'First name', 'mailhouse' ),
			'last_name'        => __( 'Last name', 'mailhouse' ),
			'name'             => __( 'Full name, falling back to the part before the @', 'mailhouse' ),
			'unsubscribe_url'  => __( 'One-click unsubscribe link', 'mailhouse' ),
			'preferences_url'  => __( 'Link to the preferences page', 'mailhouse' ),
			'confirmation_url' => __( 'Double opt-in confirmation link', 'mailhouse' ),
			'site_name'        => __( 'Site title', 'mailhouse' ),
			'site_url'         => __( 'Site address', 'mailhouse' ),
			'date'             => __( "Today's date", 'mailhouse' ),
			'year'             => __( 'Current year', 'mailhouse' ),
			'post_title'       => __( 'Title of the post that triggered the email', 'mailhouse' ),
			'post_url'         => __( 'Its permalink', 'mailhouse' ),
			'post_excerpt'     => __( 'Its excerpt', 'mailhouse' ),
			'post_image'       => __( 'Its featured image URL', 'mailhouse' ),
			'post_date'        => __( 'Its publication date', 'mailhouse' ),
			'post_author'      => __( "The post author's display name", 'mailhouse' ),
			'post_card'        => __( 'A ready-made card: image, title, excerpt and a Read more button', 'mailhouse' ),
			'post_list'        => __( 'Digests only: a card for every post in the digest', 'mailhouse' ),
			'post_count'       => __( 'Digests only, in the subject: how many posts', 'mailhouse' ),
			'post_button'      => __( 'Just the Read more button', 'mailhouse' ),
		);
	}

	/**
	 * Replace every tag in a string.
	 *
	 * In `html` mode every value is escaped for where it lands: URLs with
	 * esc_url(), everything else with esc_html(). Values come from people
	 * signing up and from post authors, and an email body is no place for
	 * their markup -- or for a link they slipped into their own name.
	 * Subjects and preview text use `text` mode, raw, and are escaped by
	 * whatever prints them.
	 *
	 * @param string $content Text or HTML.
	 * @param array  $context subscriber, post, campaign.
	 * @param string $mode    `html` or `text`.
	 * @return string
	 */
	public static function render( $content, $context = array(), $mode = 'text' ) {
		if ( false === strpos( $content, '{{' ) && false === strpos( $content, '[[' ) ) {
			return $content;
		}

		$values = self::values( $context );
		$html   = 'html' === $mode;
		$raw    = self::html_tags();

		return (string) preg_replace_callback(
			'/(?:\{\{|\[\[)\s*([a-z0-9_]+)\s*(?:\|([^}\]]*))?\s*(?:\}\}|\]\])/i',
			static function ( $matches ) use ( $values, $html, $raw ) {
				$key      = strtolower( $matches[1] );
				$fallback = isset( $matches[2] ) ? trim( $matches[2] ) : '';

				if ( ! array_key_exists( $key, $values ) ) {
					// An unknown tag is left alone rather than blanked, so a
					// typo is visible in the preview instead of silently
					// deleting a line.
					return $matches[0];
				}

				$value = (string) $values[ $key ];
				$value = '' !== $value ? $value : $fallback;

				if ( ! $html ) {
					return in_array( $key, $raw, true ) ? wp_strip_all_tags( $value ) : $value;
				}

				if ( in_array( $key, $raw, true ) ) {
					return $value; // Built by the plugin from escaped parts.
				}

				if ( '_url' === substr( $key, -4 ) || 'post_image' === $key ) {
					return esc_url( $value );
				}

				return esc_html( $value );
			},
			$content
		);
	}

	/**
	 * Tags whose value is HTML the plugin built itself, escaped piece by
	 * piece, and so is inserted as it is.
	 *
	 * @return string[]
	 */
	public static function html_tags() {
		return array( 'post_card', 'post_button' );
	}

	/**
	 * The value of every tag for one recipient.
	 *
	 * @param array $context subscriber, post, campaign.
	 * @return array<string,string>
	 */
	public static function values( $context ) {
		$subscriber = isset( $context['subscriber'] ) ? $context['subscriber'] : null;
		$post       = isset( $context['post'] ) ? get_post( $context['post'] ) : null;

		$values = array(
			'email'            => '',
			'first_name'       => '',
			'last_name'        => '',
			'name'             => '',
			'unsubscribe_url'  => '',
			'preferences_url'  => '',
			'confirmation_url' => '',
			'site_name'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'         => home_url( '/' ),
			'date'             => wp_date( get_option( 'date_format' ) ),
			'year'             => wp_date( 'Y' ),
			'post_title'       => '',
			'post_url'         => '',
			'post_excerpt'     => '',
			'post_image'       => '',
			'post_date'        => '',
			'post_author'      => '',
			'post_card'        => '',
			'post_button'      => '',
		);

		if ( $subscriber ) {
			$values['email']            = $subscriber->email;
			$values['first_name']       = $subscriber->first_name;
			$values['last_name']        = $subscriber->last_name;
			$values['name']             = Mailhouse_Subscribers::name( $subscriber );
			$values['unsubscribe_url']  = Mailhouse_Optin::unsubscribe_url( $subscriber );
			$values['preferences_url']  = Mailhouse_Optin::preferences_url( $subscriber );
			$values['confirmation_url'] = Mailhouse_Optin::confirm_url( $subscriber );

			foreach ( Mailhouse_Subscribers::fields( $subscriber ) as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$values[ strtolower( (string) $key ) ] = (string) $value;
				}
			}
		}

		if ( $post ) {
			$values['post_title']   = get_the_title( $post );
			$values['post_url']     = get_permalink( $post );
			$values['post_excerpt'] = self::excerpt( $post );
			$values['post_image']   = (string) get_the_post_thumbnail_url( $post, 'large' );
			$values['post_date']    = get_the_date( '', $post );
			$values['post_author']  = get_the_author_meta( 'display_name', (int) $post->post_author );
			$values['post_button']  = self::button( $values['post_url'], __( 'Read more', 'mailhouse' ) );
			$values['post_card']    = self::card( $post, $values );
		}

		/**
		 * Filter the merge tag values for one recipient.
		 *
		 * @param array $values  Tag values.
		 * @param array $context Rendering context.
		 */
		return apply_filters( 'mailhouse_merge_tag_values', $values, $context );
	}

	/**
	 * A button that survives Outlook: a table cell with a background.
	 *
	 * @param string $url   Link.
	 * @param string $label Text.
	 * @return string
	 */
	public static function button( $url, $label ) {
		$accent = sanitize_hex_color( (string) mailhouse_option( 'template_button', mailhouse_option( 'template_accent', '#2b6cb0' ) ) );
		$accent = $accent ? $accent : '#2b6cb0';
		$text   = mailhouse_contrast_colour( $accent );

		return sprintf(
			'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 16px;"><tr><td bgcolor="%1$s" style="background-color:%1$s;border-radius:6px;"><a class="mh-button" href="%2$s" style="display:inline-block;padding:12px 22px;font-weight:600;font-size:15px;color:%3$s;text-decoration:none;border-radius:6px;">%4$s</a></td></tr></table>',
			esc_attr( $accent ),
			esc_url( $url ),
			esc_attr( $text ),
			esc_html( $label )
		);
	}

	/**
	 * The card for one post, as {{post_card}} renders it.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function card_for( $post ) {
		$values = self::values( array( 'post' => $post ) );

		return $values['post_card'];
	}

	/**
	 * The post card used by new post notifications.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $values Already-computed tag values.
	 * @return string
	 */
	protected static function card( $post, $values ) {
		$html = '';

		if ( $values['post_image'] ) {
			$html .= sprintf(
				'<a href="%s" style="text-decoration:none;"><img src="%s" alt="%s" width="536" style="display:block;width:100%%;max-width:536px;height:auto;border:0;border-radius:6px;margin:0 0 16px;" /></a>',
				esc_url( $values['post_url'] ),
				esc_url( $values['post_image'] ),
				esc_attr( wp_strip_all_tags( $values['post_title'] ) )
			);
		}

		$html .= sprintf(
			'<h2 style="margin:0 0 8px;font-size:22px;line-height:1.3;"><a href="%s" style="color:inherit;text-decoration:none;">%s</a></h2>',
			esc_url( $values['post_url'] ),
			esc_html( wp_strip_all_tags( $values['post_title'] ) )
		);

		$html .= sprintf(
			'<p style="margin:0 0 12px;font-size:13px;color:#6b7280;">%s</p>',
			esc_html( trim( $values['post_date'] . ( $values['post_author'] ? ' · ' . $values['post_author'] : '' ) ) )
		);

		if ( '' !== $values['post_excerpt'] ) {
			$html .= '<p style="margin:0 0 16px;">' . esc_html( wp_strip_all_tags( html_entity_decode( $values['post_excerpt'], ENT_QUOTES, 'UTF-8' ) ) ) . '</p>';
		}

		return $html . $values['post_button'];
	}

	/**
	 * A post's excerpt, generated from the content when there is none.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	protected static function excerpt( $post ) {
		// Nothing of a password-protected post leaves the site by email.
		if ( '' !== (string) $post->post_password ) {
			return '';
		}

		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			return $post->post_excerpt;
		}

		$content = strip_shortcodes( (string) $post->post_content );
		$content = excerpt_remove_blocks( $content );

		return wp_trim_words( wp_strip_all_tags( $content ), 55, '&hellip;' );
	}
}
