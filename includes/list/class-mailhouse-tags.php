<?php
/**
 * Tags.
 *
 * One flat set of labels rather than lists-within-lists. A subscriber can
 * carry any number of them, and a campaign is addressed to a tag or to
 * everybody. That covers segmenting without the bookkeeping of separate lists,
 * where the same person ends up stored three times.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates tags and attaches them to subscribers.
 */
class Mailhouse_Tags {

	/**
	 * The tags table.
	 *
	 * @return string
	 */
	public static function table() {
		return Mailhouse_Install::table( 'tags' );
	}

	/**
	 * The subscriber/tag pivot table.
	 *
	 * @return string
	 */
	public static function pivot() {
		return Mailhouse_Install::table( 'subscriber_tags' );
	}

	/**
	 * Every tag, with its subscriber count.
	 *
	 * @return object[]
	 */
	public static function all() {
		global $wpdb;

		$tags  = self::table();
		$pivot = self::pivot();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results(
			"SELECT t.*, COUNT(p.subscriber_id) AS subscribers
			 FROM {$tags} t
			 LEFT JOIN {$pivot} p ON p.tag_id = t.id
			 GROUP BY t.id
			 ORDER BY t.name ASC"
		);
	}

	/**
	 * One tag.
	 *
	 * @param int $id Tag id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Find a tag by slug.
	 *
	 * @param string $slug Slug.
	 * @return object|null
	 */
	public static function get_by_slug( $slug ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ) );
	}

	/**
	 * Create a tag, or return the existing one with the same slug.
	 *
	 * @param string $name        Display name.
	 * @param string $description Optional description.
	 * @return int Tag id, or 0 when the name was empty.
	 */
	public static function create( $name, $description = '' ) {
		global $wpdb;

		$name = trim( wp_strip_all_tags( (string) $name ) );

		if ( '' === $name ) {
			return 0;
		}

		$slug     = sanitize_title( $name );
		$existing = self::get_by_slug( $slug );

		if ( $existing ) {
			return (int) $existing->id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			self::table(),
			array(
				'name'        => $name,
				'slug'        => $slug,
				'description' => $description,
				'created_at'  => mailhouse_now(),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Rename a tag.
	 *
	 * @param int    $id          Tag id.
	 * @param string $name        New name.
	 * @param string $description New description.
	 * @return bool
	 */
	public static function update( $id, $name, $description = '' ) {
		global $wpdb;

		$name = trim( wp_strip_all_tags( (string) $name ) );

		if ( '' === $name ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update(
			self::table(),
			array(
				'name'        => $name,
				'slug'        => sanitize_title( $name ),
				'description' => $description,
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Delete a tag and detach it from every subscriber.
	 *
	 * @param int $id Tag id.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = (int) $id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::pivot(), array( 'tag_id' => $id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), array( 'id' => $id ) );
	}

	/**
	 * Turn a mixed list of ids, slugs or names into tag ids.
	 *
	 * Names that do not exist yet are created, so a form can name its tag in
	 * a shortcode attribute without anyone setting it up first.
	 *
	 * @param mixed $tags     Array or comma-separated string.
	 * @param bool  $create   Create missing tags.
	 * @return int[]
	 */
	public static function resolve( $tags, $create = true ) {
		if ( is_string( $tags ) ) {
			$tags = explode( ',', $tags );
		}

		$ids = array();

		foreach ( (array) $tags as $tag ) {
			if ( is_numeric( $tag ) ) {
				$found = self::get( (int) $tag );

				if ( $found ) {
					$ids[] = (int) $found->id;
				}

				continue;
			}

			$tag = trim( (string) $tag );

			if ( '' === $tag ) {
				continue;
			}

			$found = self::get_by_slug( sanitize_title( $tag ) );

			if ( $found ) {
				$ids[] = (int) $found->id;
			} elseif ( $create ) {
				$id = self::create( $tag );

				if ( $id ) {
					$ids[] = $id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Attach tags to a subscriber.
	 *
	 * @param int   $subscriber_id Subscriber id.
	 * @param array $tag_ids       Tag ids.
	 * @return void
	 */
	public static function attach( $subscriber_id, $tag_ids ) {
		global $wpdb;

		foreach ( array_filter( array_map( 'absint', (array) $tag_ids ) ) as $tag_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'INSERT IGNORE INTO ' . self::pivot() . ' (subscriber_id, tag_id, added_at) VALUES (%d, %d, %s)',
					(int) $subscriber_id,
					$tag_id,
					mailhouse_now()
				)
			);
		}
	}

	/**
	 * Detach tags from a subscriber.
	 *
	 * @param int   $subscriber_id Subscriber id.
	 * @param array $tag_ids       Tag ids; empty removes all of them.
	 * @return void
	 */
	public static function detach( $subscriber_id, $tag_ids = array() ) {
		global $wpdb;

		$pivot         = self::pivot();
		$subscriber_id = (int) $subscriber_id;
		$tag_ids       = array_filter( array_map( 'absint', (array) $tag_ids ) );

		if ( ! $tag_ids ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $pivot, array( 'subscriber_id' => $subscriber_id ) );
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $tag_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$pivot} WHERE subscriber_id = %d AND tag_id IN ({$placeholders})",
				array_merge( array( $subscriber_id ), $tag_ids )
			)
		);
	}

	/**
	 * Replace a subscriber's tags with exactly this set.
	 *
	 * @param int   $subscriber_id Subscriber id.
	 * @param array $tag_ids       Tag ids.
	 * @return void
	 */
	public static function set( $subscriber_id, $tag_ids ) {
		self::detach( $subscriber_id );
		self::attach( $subscriber_id, $tag_ids );
	}

	/**
	 * The tags on a subscriber.
	 *
	 * @param int $subscriber_id Subscriber id.
	 * @return object[]
	 */
	public static function for_subscriber( $subscriber_id ) {
		global $wpdb;

		$tags  = self::table();
		$pivot = self::pivot();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.* FROM {$tags} t
				 INNER JOIN {$pivot} p ON p.tag_id = t.id
				 WHERE p.subscriber_id = %d
				 ORDER BY t.name ASC",
				(int) $subscriber_id
			)
		);
	}
}
