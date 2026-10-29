<?php
/**
 * The email log list table.
 *
 * @package Mailhouse
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists logged messages.
 */
class Mailhouse_Log_Table extends WP_List_Table {

	/**
	 * Set up the table.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'log_entry',
				'plural'   => 'log_entries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'created_at' => __( 'When', 'mailhouse' ),
			'status'     => __( 'Result', 'mailhouse' ),
			'to_email'   => __( 'To', 'mailhouse' ),
			'subject'    => __( 'Subject', 'mailhouse' ),
			'mailer'     => __( 'Transport', 'mailhouse' ),
		);
	}

	/**
	 * The sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ),
			'status'     => array( 'status', false ),
		);
	}

	/**
	 * The result filter links.
	 *
	 * @return array
	 */
	protected function get_views() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		$labels = array(
			''          => __( 'All', 'mailhouse' ),
			'sent'      => __( 'Accepted', 'mailhouse' ),
			'failed'    => __( 'Failed', 'mailhouse' ),
			'bounced'   => __( 'Bounced', 'mailhouse' ),
			'complaint' => __( 'Complaints', 'mailhouse' ),
		);

		$views = array();

		foreach ( $labels as $status => $label ) {
			$count = Mailhouse_Log::count( array( 'status' => $status ) );

			// The bounce views only appear once there is something in them; a
			// site with no webhook configured should not be shown two
			// permanently empty links.
			if ( in_array( $status, array( 'bounced', 'complaint' ), true ) && ! $count ) {
				continue;
			}

			$views[ $status ? $status : 'all' ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( mailhouse_admin_url( 'log', $status ? array( 'status' => $status ) : array() ) ),
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
		return array( 'delete' => __( 'Delete', 'mailhouse' ) );
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
			'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at',
			'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'DESC',
			'per_page' => $per_page,
			'page'     => $this->get_pagenum(),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->items = Mailhouse_Log::query( $args );
		$total       = Mailhouse_Log::count( $args );

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
	 * @param object $item Log row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="entry[]" value="%d" />', (int) $item->id );
	}

	/**
	 * The date column, with the row actions.
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	public function column_created_at( $item ) {
		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( mailhouse_admin_url( 'log', array( 'action' => 'view', 'id' => (int) $item->id ) ) ),
				esc_html__( 'Details', 'mailhouse' )
			),
		);

		if ( '' !== (string) $item->body ) {
			$actions['resend'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					wp_nonce_url(
						mailhouse_admin_url( 'log', array( 'action' => 'resend', 'id' => (int) $item->id ) ),
						'mailhouse_log_' . $item->id
					)
				),
				esc_html__( 'Resend', 'mailhouse' )
			);
		}

		return sprintf(
			'%s%s',
			esc_html( mailhouse_date( $item->created_at ) ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * The result column.
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	public function column_status( $item ) {
		// "Accepted" rather than "Delivered": at this point the transport took
		// the message, which is not the same as an inbox having it. A bounce
		// row arriving later for the same address is exactly that difference.
		if ( 'sent' === $item->status ) {
			return '<span class="mailhouse-status mailhouse-status--subscribed">' . esc_html__( 'Accepted', 'mailhouse' ) . '</span>';
		}

		$labels = array(
			'failed'       => array( 'bounced', __( 'Failed', 'mailhouse' ) ),
			'bounced'      => array( 'bounced', __( 'Bounced', 'mailhouse' ) ),
			'complaint'    => array( 'bounced', __( 'Spam complaint', 'mailhouse' ) ),
			'unsubscribed' => array( 'unsubscribed', __( 'Unsubscribed', 'mailhouse' ) ),
		);

		$label = isset( $labels[ $item->status ] ) ? $labels[ $item->status ] : array( 'pending', $item->status );

		return sprintf(
			'<span class="mailhouse-status mailhouse-status--%s">%s</span>%s',
			esc_attr( $label[0] ),
			esc_html( $label[1] ),
			'' !== (string) $item->error
				? '<br /><span class="description">' . esc_html( wp_trim_words( (string) $item->error, 12 ) ) . '</span>'
				: ''
		);
	}

	/**
	 * The transport column.
	 *
	 * @param object $item Log row.
	 * @return string
	 */
	public function column_mailer( $item ) {
		$provider = Mailhouse_Mailer::provider( $item->mailer );

		return esc_html( $provider ? $provider->label() : $item->mailer );
	}

	/**
	 * Any column without its own method.
	 *
	 * @param object $item   Log row.
	 * @param string $column Column key.
	 * @return string
	 */
	public function column_default( $item, $column ) {
		return isset( $item->$column ) && '' !== $item->$column ? esc_html( $item->$column ) : '&mdash;';
	}

	/**
	 * What to say when the log is empty.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Nothing logged yet.', 'mailhouse' );
	}
}
