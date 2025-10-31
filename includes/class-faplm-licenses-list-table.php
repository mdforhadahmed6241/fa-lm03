<?php
/**
 * Custom WP_List_Table class for displaying licenses.
 *
 * @package FA_Licence_Manager
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load WP_List_Table if not already loaded
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class FAPLM_Licenses_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'License', 'fa-pro-license-manager' ),
				'plural'   => __( 'Licenses', 'fa-pro-license-manager' ),
				'ajax'     => false,
			)
		);
	}

	/**
	 * Get the list of columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns = array(
			'cb'          => '<input type="checkbox" />',
			'license_key' => __( 'License Key', 'fa-pro-license-manager' ),
			'status'      => __( 'Status', 'fa-pro-license-manager' ),
			'product_id'  => __( 'Product ID', 'fa-pro-license-manager' ),
			'order_id'    => __( 'Order ID', 'fa-pro-license-manager' ),
			'activations' => __( 'Activations', 'fa-pro-license-manager' ),
			'expires_at'  => __( 'Expires', 'fa-pro-license-manager' ),
		);
		return $columns;
	}

	/**
	 * Get the list of sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		$sortable_columns = array(
			'status'     => array( 'status', false ),
			'product_id' => array( 'product_id', false ),
			'order_id'   => array( 'order_id', false ),
			'expires_at' => array( 'expires_at', false ),
		);
		return $sortable_columns;
	}

	/**
	 * Default column rendering.
	 *
	 * @param object $item
	 * @param string $column_name
	 * @return mixed
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'product_id':
			case 'order_id':
				return $item[ $column_name ] ? absint( $item[ $column_name ] ) : '—';
			case 'status':
				return '<span class="faplm-status faplm-status-' . esc_attr( $item['status'] ) . '">' . esc_html( ucfirst( $item['status'] ) ) . '</span>';
			default:
				return print_r( $item, true ); // Show the whole array for troubleshooting
		}
	}

	/**
	 * Render the "cb" column (checkbox).
	 *
	 * @param object $item
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="license_id[]" value="%s" />',
			$item['id']
		);
	}

	/**
	 * Render the "license_key" column with actions.
	 *
	 * @param object $item
	 * @return string
	 */
	public function column_license_key( $item ) {
		// Build nonce URL for delete action
		$delete_nonce = wp_create_nonce( 'faplm_delete_license' );
		$delete_url   = sprintf(
			'?page=%s&action=%s&license_id=%s&_wpnonce=%s',
			esc_attr( $_REQUEST['page'] ),
			'delete',
			absint( $item['id'] ),
			$delete_nonce
		);

		// Build edit URL
		$edit_url = sprintf(
			'?page=%s&action=%s&license_id=%s',
			esc_attr( $_REQUEST['page'] ),
			'edit',
			absint( $item['id'] )
		);

		// Add actions
		$actions = array(
			'edit'   => '<a href="' . esc_url( $edit_url ) . '">' . __( 'Edit', 'fa-pro-license-manager' ) . '</a>',
			'delete' => '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete this license? This action cannot be undone.', 'fa-pro-license-manager' ) ) . '\')">' . __( 'Delete', 'fa-pro-license-manager' ) . '</a>',
		);

		return '<strong>' . esc_html( $item['license_key'] ) . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * Render the "expires_at" column.
	 *
	 * @param object $item
	 * @return string
	 */
	public function column_expires_at( $item ) {
		if ( null === $item['expires_at'] ) {
			return '<span class="faplm-lifetime">' . __( 'Lifetime', 'fa-pro-license-manager' ) . '</span>';
		}
		// You might want to format this date based on your WordPress settings
		return esc_html( mysql2date( get_option( 'date_format' ), $item['expires_at'] ) );
	}

	/**
	 * Render the "activations" column.
	 *
	 * @param object $item
	 * @return string
	 */
	public function column_activations( $item ) {
		return sprintf(
			'%d / %d',
			absint( $item['current_activations'] ),
			absint( $item['activation_limit'] )
		);
	}

	/**
	 * Prepare the items for the table.
	 */
	public function prepare_items() {
		global $wpdb;
		$table_name = $wpdb->prefix . FAPLM_LICENSES_TABLE;

		$per_page     = $this->get_items_per_page( 'licenses_per_page', 20 );
		$current_page = $this->get_pagenum();
		$total_items  = $wpdb->get_var( "SELECT COUNT(id) FROM $table_name" );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);

		$orderby = ( ! empty( $_REQUEST['orderby'] ) ) ? esc_sql( $_REQUEST['orderby'] ) : 'id';
		$order   = ( ! empty( $_REQUEST['order'] ) ) ? esc_sql( $_REQUEST['order'] ) : 'DESC';

		// Whitelist orderby columns
		$allowed_orderby = array( 'status', 'product_id', 'order_id', 'expires_at', 'id' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'id';
		}

		$offset = ( $current_page - 1 ) * $per_page;

		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name ORDER BY $orderby $order LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A // Return as associative array
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}
}
