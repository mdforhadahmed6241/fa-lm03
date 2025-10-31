<?php
/**
 * All functionality for rendering the admin pages and handling form submissions.
 *
 * @package FA_Licence_Manager
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue admin scripts and styles.
 *
 * @param string $hook_suffix The current admin page.
 */
function faplm_admin_page_scripts( $hook_suffix ) {
	// Only load on our plugin pages
	if ( 'toplevel_page_fa-license-manager' === $hook_suffix || 'license-manager_page_fa-license-formats' === $hook_suffix ) {
		// Add jQuery UI Datepicker
		wp_enqueue_script( 'jquery-ui-datepicker' );
		// Add the default WP jQuery UI styles
		wp_enqueue_style( 'jquery-style', 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.13.2/themes/smoothness/jquery-ui.css' );

		// Custom JS for initializing the datepicker
		add_action( 'admin_footer', 'faplm_admin_footer_js' );

		// Custom styles for status badges
		add_action( 'admin_head', 'faplm_admin_head_css' );
	}
}

/**
 * Add custom CSS to the admin head.
 */
function faplm_admin_head_css() {
	echo '
	<style>
		.faplm-status {
			padding: 4px 8px;
			border-radius: 4px;
			font-weight: bold;
			text-transform: capitalize;
			color: #fff;
		}
		.faplm-status-active { background-color: #28a745; }
		.faplm-status-inactive { background-color: #6c757d; }
		.faplm-status-expired { background-color: #dc3545; }
		.faplm-lifetime { color: #0073aa; font-weight: bold; }
	</style>';
}

/**
 * Add custom JS to the admin footer.
 */
function faplm_admin_footer_js() {
	?>
	<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Initialize datepicker
			$('.faplm-datepicker').datepicker({
				dateFormat: 'yy-mm-dd' // Match MySQL DATETIME format
			});
		});
	</script>
	<?php
}

/**
 * Handle all CRUD actions for licenses.
 * Hooked to 'admin_init'.
 */
function faplm_handle_license_actions() {
	global $wpdb;
	$table_name = $wpdb->prefix . FAPLM_LICENSES_TABLE;

	// Check if we are saving (Add or Edit)
	if ( isset( $_POST['faplm_save_license_nonce'] ) && wp_verify_nonce( $_POST['faplm_save_license_nonce'], 'faplm_save_license_action' ) ) {

		// Common data
		$data = array(
			'product_id'       => isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0,
			'order_id'         => isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : null,
			'status'           => isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : 'inactive',
			'activation_limit' => isset( $_POST['activation_limit'] ) ? absint( $_POST['activation_limit'] ) : 1,
			'allow_courier_api'=> isset( $_POST['allow_courier_api'] ) ? 1 : 0,
		);

		// Handle 'expires_at' - set to NULL if empty
		$data['expires_at'] = ! empty( $_POST['expires_at'] ) ? sanitize_text_field( $_POST['expires_at'] ) : null;

		// Get the ID (if editing)
		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;

		if ( $license_id > 0 ) {
			// --- UPDATE ---
			$where = array( 'id' => $license_id );
			$wpdb->update( $table_name, $data, $where );
			$redirect_url = admin_url( 'admin.php?page=fa-license-manager&message=2' ); // 2 = Updated

		} else {
			// --- CREATE ---
			// Add license_key only on create
			$data['license_key'] = sanitize_text_field( $_POST['license_key'] );
			if ( ! empty( $data['license_key'] ) ) {
				$wpdb->insert( $table_name, $data );
				$redirect_url = admin_url( 'admin.php?page=fa-license-manager&message=1' ); // 1 = Created
			} else {
				// Don't redirect, show error on form
				return;
			}
		}

		wp_redirect( $redirect_url );
		exit;
	}

	// Check if we are deleting
	if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['license_id'] ) && isset( $_GET['_wpnonce'] ) ) {
		if ( wp_verify_nonce( $_GET['_wpnonce'], 'faplm_delete_license' ) ) {
			$license_id = absint( $_GET['license_id'] );
			$wpdb->delete( $table_name, array( 'id' => $license_id ), array( '%d' ) );

			$redirect_url = admin_url( 'admin.php?page=fa-license-manager&message=3' ); // 3 = Deleted
			wp_redirect( $redirect_url );
			exit;
		}
	}
}


/**
 * Main "router" function for the License Manager page.
 */
function faplm_render_main_license_page() {
	$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
	$license_id = isset( $_GET['license_id'] ) ? absint( $_GET['license_id'] ) : 0;

	switch ( $action ) {
		case 'add_new':
			faplm_render_license_form( null );
			break;

		case 'edit':
			global $wpdb;
			$table_name = $wpdb->prefix . FAPLM_LICENSES_TABLE;
			$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $license_id ), ARRAY_A );
			if ( $item ) {
				faplm_render_license_form( $item );
			} else {
				echo '<div class="wrap"><h2>' . esc_html__( 'License not found', 'fa-pro-license-manager' ) . '</h2></div>';
			}
			break;

		case 'list':
		default:
			faplm_render_license_list_table();
			break;
	}
}

/**
 * Renders the main WP_List_Table for licenses.
 */
function faplm_render_license_list_table() {
	// Create an instance of our list table class
	$list_table = new FAPLM_Licenses_List_Table();
	$list_table->prepare_items();
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Licenses', 'fa-pro-license-manager' ); ?></h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=fa-license-manager&action=add_new' ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Add New', 'fa-pro-license-manager' ); ?>
		</a>
		<hr class="wp-header-end">

		<?php
		// Display admin notices
		if ( isset( $_GET['message'] ) ) {
			if ( $_GET['message'] === '1' ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'New license added successfully.', 'fa-pro-license-manager' ) . '</p></div>';
			} elseif ( $_GET['message'] === '2' ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'License updated successfully.', 'fa-pro-license-manager' ) . '</p></div>';
			} elseif ( $_GET['message'] === '3' ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'License deleted successfully.', 'fa-pro-license-manager' ) . '</p></div>';
			}
		}
		?>

		<!-- Forms are NOT created automatically, so we need to wrap the table in one to support bulk actions -->
		<form method="post" id="faplm-license-list-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( $_REQUEST['page'] ); ?>" />
			<?php
			$list_table->display();
			?>
		</form>
	</div>
	<?php
}

/**
 * Renders the Add/Edit License form.
 *
 * @param array|null $item The license item to edit, or null to add new.
 */
function faplm_render_license_form( $item ) {
	$is_edit = ( null !== $item );
	$title   = $is_edit ? __( 'Edit License', 'fa-pro-license-manager' ) : __( 'Add New License', 'fa-pro-license-manager' );
	?>
	<div class="wrap">
		<h1><?php echo esc_html( $title ); ?></h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=fa-license-manager' ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Back to List', 'fa-pro-license-manager' ); ?>
		</a>
		<hr class="wp-header-end">

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=fa-license-manager' ) ); ?>">
			<?php wp_nonce_field( 'faplm_save_license_action', 'faplm_save_license_nonce' ); ?>
			
			<?php if ( $is_edit ) : ?>
				<input type="hidden" name="license_id" value="<?php echo absint( $item['id'] ); ?>" />
			<?php endif; ?>

			<table class="form-table">
				<tbody>
					<!-- License Key -->
					<tr>
						<th scope="row">
							<label for="license_key"><?php esc_html_e( 'License Key', 'fa-pro-license-manager' ); ?></label>
						</th>
						<td>
							<input type="text" name="license_key" id="license_key" class="regular-text"
								value="<?php echo $is_edit ? esc_attr( $item['license_key'] ) : ''; ?>"
								<?php echo $is_edit ? 'readonly' : 'required'; ?>>
							<?php if ( $is_edit ) : ?>
								<p class="description"><?php esc_html_e( 'License key cannot be changed.', 'fa-pro-license-manager' ); ?></p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Enter the license key manually.', 'fa-pro-license-manager' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>

					<!-- Product ID -->
					<tr>
						<th scope="row">
							<label for="product_id"><?php esc_html_e( 'Product ID', 'fa-pro-license-manager' ); ?></label>
						</th>
						<td>
							<input type="number" name="product_id" id="product_id" class="regular-text"
								value="<?php echo $is_edit ? absint( $item['product_id'] ) : ''; ?>" required>
							<p class="description"><?php esc_html_e( 'The ID of the product this license is for (e.g., WooCommerce Product ID).', 'fa-pro-license-manager' ); ?></p>
						</td>
					</tr>

					<!-- Order ID -->
					<tr>
						<th scope="row">
							<label for="order_id"><?php esc_html_e( 'Order ID', 'fa-pro-license-manager' ); ?></label>
						</th>
						<td>
							<input type="number" name="order_id" id="order_id" class="regular-text"
								value="<?php echo $is_edit ? absint( $item['order_id'] ) : ''; ?>">
							<p class="description"><?php esc_html_e( 'Optional. The order ID this license is associated with.', 'fa-pro-license-manager' ); ?></p>
						</td>
					</tr>

					<!-- Status -->
					<tr>
						<th scope="row">
							<label for="status"><?php esc_html_e( 'Status', 'fa-pro-license-manager' ); ?></label>
						</th>
						<td>
							<select name="status" id="status">
								<?php
								$statuses = array( 'active', 'inactive', 'expired' );
								$current_status = $is_edit ? $item['status'] : 'inactive';
								foreach ( $statuses as $status ) {
									echo '<option value="' . esc_attr( $status ) . '" ' . selected( $current_status, $status, false ) . '>' . esc_html( ucfirst( $status ) ) . '</option>';
								}
								?>
							</select>
						</td>
					</tr>

					<!-- Expires At -->
					<tr>
						<th scope="row">
							<label for="expires_at"><?php esc_html_e( 'Expires At', 'fa-pro-license-manager' ); ?></label>
						</th>
						<td>
							<input type="text" name="expires_at" id="expires_at" class="faplm-datepicker regular-text"
								value="<?php echo ( $is_edit && null !== $item['expires_at'] ) ? esc_attr( date( 'Y-m-d', strtotime( $item['expires_at'] ) ) ) : ''; ?>">
							<p class="description"><?php esc_html_e( 'Leave blank for a lifetime license (saves as NULL).', 'fa-pro-license-manager' ); ?></p>
						</td>
					</tr>

					<!-- Activation Limit -->
					<tr>
						<th scope="row">
							<label for="activation_limit"><?php esc_html_e( 'Activation Limit', 'fa-pro-license-manager' ); ?></label>
						</th>
						<td>
							<input type="number" name="activation_limit" id="activation_limit" class="regular-text"
								value="<?php echo $is_edit ? absint( $item['activation_limit'] ) : '1'; ?>" min="0" required>
						</td>
					</tr>
					
					<!-- Allow Courier API -->
					<tr>
						<th scope="row">
							<label for="allow_courier_api"><?php esc_html_e( 'Allow Courier API', 'fa-pro-license-manager' ); ?></label>
						</th>
						<td>
							<input type="checkbox" name="allow_courier_api" id="allow_courier_api" value="1"
								<?php checked( $is_edit ? $item['allow_courier_api'] : 0, 1 ); ?>>
							<label for="allow_courier_api"><?php esc_html_e( 'Enable Courier API access for this license.', 'fa-pro-license-manager' ); ?></label>
						</td>
					</tr>

				</tbody>
			</table>
			
			<?php submit_button( $is_edit ? __( 'Update License', 'fa-pro-license-manager' ) : __( 'Add License', 'fa-pro-license-manager' ), 'primary' ); ?>
		</form>
	</div>
	<?php
}

/**
 * Handles the logic (add/delete) and UI for the License Formats page.
 * (Moved from main plugin file for organization)
 */
function faplm_render_license_formats_page() {
	global $wpdb;
	$table_name = $wpdb->prefix . FAPLM_FORMATS_TABLE;

	// Handle Add New Format submission
	if ( isset( $_POST['faplm_add_format_nonce'] ) && wp_verify_nonce( $_POST['faplm_add_format_nonce'], 'faplm_add_format_action' ) ) {
		
		$format_name = sanitize_text_field( $_POST['format_name'] );
		$prefix = sanitize_text_field( $_POST['prefix'] );
		$suffix = sanitize_text_field( $_POST['suffix'] );
		$chunk_length = absint( $_POST['chunk_length'] );
		$total_chunks = absint( $_POST['total_chunks'] );

		if ( ! empty( $format_name ) && $chunk_length > 0 && $total_chunks > 0 ) {
			$wpdb->insert(
				$table_name,
				array(
					'format_name'    => $format_name,
					'prefix'         => $prefix,
					'suffix'         => $suffix,
					'chunk_length'   => $chunk_length,
					'total_chunks'   => $total_chunks,
				)
			);
			// Redirect to avoid form resubmission
			wp_redirect( admin_url( 'admin.php?page=fa-license-formats&message=1' ) );
			exit;
		}
	}

	// Handle Delete Format action
	if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['format_id'] ) && isset( $_GET['_wpnonce'] ) ) {
		$format_id = absint( $_GET['format_id'] );
		if ( wp_verify_nonce( $_GET['_wpnonce'], 'faplm_delete_format_' . $format_id ) ) {
			$wpdb->delete( $table_name, array( 'id' => $format_id ), array( '%d' ) );
			
			// Redirect to clean the URL
			wp_redirect( admin_url( 'admin.php?page=fa-license-formats&message=2' ) );
			exit;
		}
	}

	// Fetch existing formats to display
	$formats = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY id DESC" );

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'License Formats', 'fa-pro-license-manager' ); ?></h1>

		<?php
		// Display admin notices
		if ( isset( $_GET['message'] ) ) {
			if ( $_GET['message'] === '1' ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'New format added successfully.', 'fa-pro-license-manager' ) . '</p></div>';
			} elseif ( $_GET['message'] === '2' ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Format deleted successfully.', 'fa-pro-license-manager' ) . '</p></div>';
			}
		}
		?>

		<div id="col-container" class="wp-clearfix">
			<div id="col-left">
				<div class="col-wrap">
					<h2><?php esc_html_e( 'Add New Format', 'fa-pro-license-manager' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=fa-license-formats' ) ); ?>">
						<?php wp_nonce_field( 'faplm_add_format_action', 'faplm_add_format_nonce' ); ?>

						<div class="form-field">
							<label for="format_name"><?php esc_html_e( 'Format Name', 'fa-pro-license-manager' ); ?></label>
							<input type="text" name="format_name" id="format_name" required>
							<p><?php esc_html_e( 'A name for this format (e.g., "Standard Plugin", "Lifetime Deal").', 'fa-pro-license-manager' ); ?></p>
						</div>

						<div class="form-field">
							<label for="prefix"><?php esc_html_e( 'Prefix', 'fa-pro-license-manager' ); ?></label>
							<input type="text" name="prefix" id="prefix">
							<p><?php esc_html_e( 'Optional. Text to add before the key (e.g., "MYPLUGIN-").', 'fa-pro-license-manager' ); ?></p>
						</div>

						<div class="form-field">
							<label for="suffix"><?php esc_html_e( 'Suffix', 'fa-pro-license-manager' ); ?></label>
							<input type="text" name="suffix" id="suffix">
							<p><?php esc_html_e( 'Optional. Text to add after the key (e.g., "-LIFETIME").', 'fa-pro-license-manager' ); ?></p>
						</div>

						<div class="form-field">
							<label for="chunk_length"><?php esc_html_e( 'Chunk Length', 'fa-pro-license-manager' ); ?></label>
							<input type="number" name="chunk_length" id="chunk_length" value="4" min="1" required>
							<p><?php esc_html_e( 'Length of each random character block.', 'fa-pro-license-manager' ); ?></p>
						</div>

						<div class="form-field">
							<label for="total_chunks"><?php esc_html_e( 'Number of Chunks', 'fa-pro-license-manager' ); ?></label>
							<input type="number" name="total_chunks" id="total_chunks" value="4" min="1" required>
							<p><?php esc_html_e( 'How many blocks the key should have.', 'fa-pro-license-manager' ); ?></p>
						</div>

						<?php submit_button( __( 'Add New Format', 'fa-pro-license-manager' ), 'primary', 'faplm_add_format' ); ?>
					</form>
				</div>
			</div>
			<div id="col-right">
				<div class="col-wrap">
					<h2><?php esc_html_e( 'Existing Formats', 'fa-pro-license-manager' ); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Name', 'fa-pro-license-manager' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Example', 'fa-pro-license-manager' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Layout', 'fa-pro-license-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $formats ) ) : ?>
								<tr>
									<td colspan="3"><?php esc_html_e( 'No formats found.', 'fa-pro-license-manager' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $formats as $format ) : ?>
									<tr>
										<td>
											<strong><?php echo esc_html( $format->format_name ); ?></strong>
											<div classs="row-actions">
												<span class="trash">
													<?php
													// Create a nonce'd delete URL
													$delete_url = wp_nonce_url(
														admin_url( 'admin.php?page=fa-license-formats&action=delete&format_id=' . $format->id ),
														'faplm_delete_format_' . $format->id
													);
													?>
													<a href="<?php echo esc_url( $delete_url ); ?>" class="submitdelete" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this format?', 'fa-pro-license-manager' ); ?>');">
														<?php esc_html_e( 'Delete', 'fa-pro-license-manager' ); ?>
													</a>
												</span>
											</div>
										</td>
										<td>
											<code>
												<?php
												// Generate a simple visual example
												$example = esc_html( $format->prefix );
												for ( $i = 0; $i < $format->total_chunks; $i++ ) {
													$example .= str_repeat( 'X', $format->chunk_length );
													if ( $i < $format->total_chunks - 1 ) {
														$example .= '-';
													}
												}
												$example .= esc_html( $format->suffix );
												echo $example;
												?>
											</code>
										</td>
										<td>
											<?php
											printf(
												esc_html__( '%1$d chunks of %2$d chars', 'fa-pro-license-manager' ),
												absint( $format->total_chunks ),
												absint( $format->chunk_length )
											);
											?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
	<?php
}
