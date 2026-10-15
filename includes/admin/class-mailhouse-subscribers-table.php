<?php
/**
 * The subscribers list table.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists subscribers, using the same table WordPress uses everywhere else.
 */
class Mailhouse_Subscribers_Table extends WP_List_Table {

	/**
	 * Status counts, for the view links.
	 *
	 * @var array
	 */
	protected $counts = array();

	/**
	 * Set up the table.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'subscriber',
				'plural'   => 'subscribers',
				'ajax'     => false,
			)
		);

		$this->counts = Mailhouse_Subscribers::counts();
	}

	/**
	 * The columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'email'      => __( 'Email', 'mailhouse' ),
			'name'       => __( 'Name', 'mailhouse' ),
			'status'     => __( 'Status', 'mailhouse' ),
			'tags'       => __( 'Tags', 'mailhouse' ),
			'engagement' => __( 'Sent / opens', 'mailhouse' ),
			'source'     => __( 'Source', 'mailhouse' ),
			'created_at' => __( 'Signed up', 'mailhouse' ),
		);
	}

	/**
	 * The sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'email'      => array( 'email', false ),
			'status'     => array( 'status', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * The status filter links.
	 *
	 * @return array
	 */
	protected function get_views() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$views   = array();

		$labels = array_merge(
			array( 'all' => __( 'All', 'mailhouse' ) ),
			Mailhouse_Subscribers::statuses()
		);

		foreach ( $labels as $status => $label ) {
			$count = isset( $this->counts[ $status ] ) ? $this->counts[ $status ] : 0;

			if ( 'all' !== $status && ! $count ) {
				continue;
			}

			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( mailhouse_admin_url( 'subscribers', array( 'status' => $status ) ) ),
				$current === $status ? ' class="current"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	/**
	 * The bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'subscribe'   => __( 'Mark as subscribed', 'mailhouse' ),
			'unsubscribe' => __( 'Mark as unsubscribed', 'mailhouse' ),
			'resend'      => __( 'Resend confirmation', 'mailhouse' ),
			'delete'      => __( 'Delete permanently', 'mailhouse' ),
		);
	}

	/**
	 * The tag filter.
	 *
	 * @param string $which Top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$tags = Mailhouse_Tags::all();

		if ( ! $tags ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['tag'] ) ? absint( $_GET['tag'] ) : 0;

		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="mailhouse-filter-tag">' . esc_html__( 'Filter by tag', 'mailhouse' ) . '</label>';
		echo '<select name="tag" id="mailhouse-filter-tag">';
		echo '<option value="0">' . esc_html__( 'All tags', 'mailhouse' ) . '</option>';

		foreach ( $tags as $tag ) {
			printf(
				'<option value="%d"%s>%s (%d)</option>',
				(int) $tag->id,
				selected( $current, (int) $tag->id, false ),
				esc_html( $tag->name ),
				(int) $tag->subscribers
			);
		}

		echo '</select>';
		submit_button( __( 'Filter', 'mailhouse' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Load the rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 25;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$args = array(
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'tag'      => isset( $_GET['tag'] ) ? absint( $_GET['tag'] ) : 0,
			'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at',
			'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'DESC',
			'per_page' => $per_page,
			'page'     => $this->get_pagenum(),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->items = Mailhouse_Subscribers::query( $args );
		$total       = Mailhouse_Subscribers::count( $args );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * The checkbox column.
	 *
	 * @param object $item Subscriber.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="subscriber[]" value="%d" />', (int) $item->id );
	}

	/**
	 * The email column, with the row actions.
	 *
	 * @param object $item Subscriber.
	 * @return string
	 */
	public function column_email( $item ) {
		$edit = mailhouse_admin_url( 'subscribers', array( 'action' => 'edit', 'id' => (int) $item->id ) );

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html__( 'Edit', 'mailhouse' ) ),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(%s)">%s</a>',
				esc_url(
					wp_nonce_url(
						mailhouse_admin_url( 'subscribers', array( 'action' => 'delete', 'id' => (int) $item->id ) ),
						'mailhouse_subscriber_' . $item->id
					)
				),
				esc_attr( wp_json_encode( __( 'Delete this subscriber permanently?', 'mailhouse' ) ) ),
				esc_html__( 'Delete', 'mailhouse' )
			),
		);

		if ( 'pending' === $item->status ) {
			$actions['resend'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					wp_nonce_url(
						mailhouse_admin_url( 'subscribers', array( 'action' => 'resend', 'id' => (int) $item->id ) ),
						'mailhouse_subscriber_' . $item->id
					)
				),
				esc_html__( 'Resend confirmation', 'mailhouse' )
			);
		}

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s',
			esc_url( $edit ),
			esc_html( $item->email ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * The name column.
	 *
	 * @param object $item Subscriber.
	 * @return string
	 */
	public function column_name( $item ) {
		$name = trim( $item->first_name . ' ' . $item->last_name );

		return $name ? esc_html( $name ) : '&mdash;';
	}

	/**
	 * The status column.
	 *
	 * @param object $item Subscriber.
	 * @return string
	 */
	public function column_status( $item ) {
		$statuses = Mailhouse_Subscribers::statuses();
		$label    = isset( $statuses[ $item->status ] ) ? $statuses[ $item->status ] : $item->status;

		return sprintf(
			'<span class="mailhouse-status mailhouse-status--%s">%s</span>',
			esc_attr( $item->status ),
			esc_html( $label )
		);
	}

	/**
	 * The tags column.
	 *
	 * @param object $item Subscriber.
	 * @return string
	 */
	public function column_tags( $item ) {
		$tags = Mailhouse_Tags::for_subscriber( (int) $item->id );

		if ( ! $tags ) {
			return '&mdash;';
		}

		$links = array();

		foreach ( $tags as $tag ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( mailhouse_admin_url( 'subscribers', array( 'tag' => (int) $tag->id ) ) ),
				esc_html( $tag->name )
			);
		}

		return implode( ', ', $links );
	}

	/**
	 * The engagement column.
	 *
	 * @param object $item Subscriber.
	 * @return string
	 */
	public function column_engagement( $item ) {
		return sprintf(
			'%s / %s',
			esc_html( number_format_i18n( (int) $item->sent ) ),
			esc_html( number_format_i18n( (int) $item->opens ) )
		);
	}

	/**
	 * The signup date column.
	 *
	 * @param object $item Subscriber.
	 * @return string
	 */
	public function column_created_at( $item ) {
		return esc_html( mailhouse_date( $item->created_at ) );
	}

	/**
	 * Any column without its own method.
	 *
	 * @param object $item   Subscriber.
	 * @param string $column Column key.
	 * @return string
	 */
	public function column_default( $item, $column ) {
		return isset( $item->$column ) && '' !== $item->$column ? esc_html( $item->$column ) : '&mdash;';
	}

	/**
	 * What to say when the list is empty.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No subscribers yet. Add a form to a page, or import a list under Tools.', 'mailhouse' );
	}
}
