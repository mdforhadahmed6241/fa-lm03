<?php
/**
 * All functionality for integrating with WooCommerce Product pages.
 * - Adds custom fields to the Product Data metabox.
 * - Saves the custom field data.
 *
 * @package FA_Licence_Manager
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display custom license fields in the 'General' tab of the Product Data metabox.
 * Hooked to: woocommerce_product_options_general_product_data
 */
function faplm_add_license_product_options() {
	global $wpdb;
	$table_name = $wpdb->prefix . FAPLM_FORMATS_TABLE;

	// 1. Fetch all available license formats
	$formats = $wpdb->get_results( "SELECT id, format_name FROM $table_name" );
	$format_options = array(
		'' => __( 'Select a format...', 'fa-pro-license-manager' ), // Add a default empty option
	);
	if ( $formats ) {
		foreach ( $formats as $format ) {
			$format_options[ $format->id ] = esc_html( $format->format_name );
		}
	}

	echo '<div class="options_group faplm-license-options">';

	// --- 1. Generate License Checkbox ---
	woocommerce_wp_checkbox(
		array(
			'id'          => '_generate_license',
			'label'       => __( 'Generate License', 'fa-pro-license-manager' ),
			'description' => __( 'Check this to automatically generate a license key when this product is purchased.', 'fa-pro-license-manager' ),
			'desc_tip'    => true,
		)
	);

	// --- 2. License Format Dropdown ---
	woocommerce_wp_select(
		array(
			'id'          => '_license_format_id',
			'label'       => __( 'License Format', 'fa-pro-license-manager' ),
			'description' => __( 'Choose the format for the generated license key.', 'fa-pro-license-manager' ),
			'desc_tip'    => true,
			'options'     => $format_options,
		)
	);

	// --- 3. License Duration Input ---
	woocommerce_wp_text_input(
		array(
			'id'                => '_license_duration_days',
			'label'             => __( 'License Duration (days)', 'fa-pro-license-manager' ),
			'description'       => __( 'Enter 0 for a Lifetime license. Otherwise, enter the number of days the license is valid for.', 'fa-pro-license-manager' ),
			'desc_tip'          => true,
			'type'              => 'number',
			'custom_attributes' => array(
				'min'  => '0',
				'step' => '1',
			),
			'value' => get_post_meta( get_the_ID(), '_license_duration_days', true ) ? : '0' // Default to 0
		)
	);

	// --- 4. Activation Limit Input ---
	woocommerce_wp_text_input(
		array(
			'id'                => '_activation_limit',
			'label'             => __( 'Activation Limit', 'fa-pro-license-manager' ),
			'description'       => __( 'Set the default activation limit for generated licenses.', 'fa-pro-license-manager' ),
			'desc_tip'          => true,
			'type'              => 'number',
			'custom_attributes' => array(
				'min'  => '1',
				'step' => '1',
			),
			'value' => get_post_meta( get_the_ID(), '_activation_limit', true ) ? : '1' // Default to 1
		)
	);

	// --- 5. Grant Courier API Access Checkbox ---
	woocommerce_wp_checkbox(
		array(
			'id'          => '_grant_courier_api',
			'label'       => __( 'Grant Courier API Access', 'fa-pro-license-manager' ),
			'description' => __( 'Check this to grant Courier API access to customers who purchase this product.', 'fa-pro-license-manager' ),
			'desc_tip'    => true,
		)
	);

	echo '</div>';
}

/**
 * Save the custom license fields when the product is saved.
 * Hooked to: woocommerce_process_product_meta
 *
 * @param int $post_id The ID of the product being saved.
 */
function faplm_save_license_product_options( $post_id ) {

	// --- 1. Save Generate License ---
	$generate_license = isset( $_POST['_generate_license'] ) ? 'yes' : 'no';
	update_post_meta( $post_id, '_generate_license', $generate_license );

	// --- 2. Save License Format ID ---
	if ( isset( $_POST['_license_format_id'] ) ) {
		$format_id = absint( $_POST['_license_format_id'] );
		update_post_meta( $post_id, '_license_format_id', $format_id );
	}

	// --- 3. Save License Duration ---
	if ( isset( $_POST['_license_duration_days'] ) ) {
		// We use absint() to ensure it's a positive integer or 0
		$duration = absint( $_POST['_license_duration_days'] );
		update_post_meta( $post_id, '_license_duration_days', $duration );
	}

	// --- 4. Save Activation Limit ---
	if ( isset( $_POST['_activation_limit'] ) ) {
		// Use max(1, absint(...)) to ensure the limit is at least 1
		$limit = max( 1, absint( $_POST['_activation_limit'] ) );
		update_post_meta( $post_id, '_activation_limit', $limit );
	}

	// --- 5. Save Grant Courier API Access ---
	$grant_api = isset( $_POST['_grant_courier_api'] ) ? 'yes' : 'no';
	update_post_meta( $post_id, '_grant_courier_api', $grant_api );
}


/**
 * Automatically generate a license key when an order is marked as processing or completed.
 * Hooked to: woocommerce_order_status_processing
 * Hooked to: woocommerce_order_status_completed
 *
 * @param int $order_id The ID of the order.
 */
function faplm_generate_license_on_order_completion( $order_id ) {
	global $wpdb;
	$licenses_table = $wpdb->prefix . FAPLM_LICENSES_TABLE;

	// --- FIX 1: Ensure key generation functions are loaded ---
	// This is a safeguard in case this function is called by a hook
	// before the main plugin file has included our key functions.
	if ( ! function_exists( 'faplm_generate_license_key_by_format' ) ) {
		// Use the constant defined in our main plugin file
		$functions_file = FAPLM_PLUGIN_DIR . 'includes/faplm-key-functions.php';

		if ( file_exists( $functions_file ) ) {
			require_once $functions_file;
		} else {
			// If the file is missing, we must stop to prevent a fatal error.
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( __( 'CRITICAL ERROR: License generation functions file is missing. Could not generate licenses.', 'fa-pro-license-manager' ) );
			}
			return; // Exit function immediately
		}
	}
	// --- END FIX 1 ---


	// 1. Get the order object
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	// 2. Loop through each item in the order
	foreach ( $order->get_items() as $item_id => $item ) {
		$product_id   = $item->get_product_id();
		$variation_id = $item->get_variation_id();
		
		// Use variation ID if it exists, otherwise main product ID.
		// License rules are often set on the variation.
		$checked_product_id = $variation_id ? $variation_id : $product_id;

		// 3. Check for duplicates: Has a license *ever* been generated for this product + order?
		// This prevents hooks running multiple times (e.g., processing -> completed) from creating duplicates.
		$existing_license_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(id) FROM $licenses_table WHERE order_id = %d AND product_id = %d",
				$order_id,
				$checked_product_id
			)
		);

		if ( $existing_license_count > 0 ) {
			continue; // Skip this item, license(s) already exist
		}

		// 4. Check if this product is set to generate a license
		$generate_license = get_post_meta( $checked_product_id, '_generate_license', true );

		if ( 'yes' !== $generate_license ) {
			continue; // Skip, this product doesn't generate licenses
		}

		// 5. Retrieve all our license rules from product meta
		$format_id     = get_post_meta( $checked_product_id, '_license_format_id', true );
		$duration_days = get_post_meta( $checked_product_id, '_license_duration_days', true );
		$limit         = get_post_meta( $checked_product_id, '_activation_limit', true );
		$grant_api     = get_post_meta( $checked_product_id, '_grant_courier_api', true );

		// Check for valid format ID
		if ( empty( $format_id ) ) {
			// Log an error for the admin
			$order->add_order_note( sprintf( __( 'Failed to generate license for Product ID %d. No license format was selected.', 'fa-pro-license-manager' ), $checked_product_id ) );
			continue;
		}

		// 6. Calculate Expiration Date
		$expires_at    = null; // Default to NULL (Lifetime)
		$duration_days = absint( $duration_days );
		if ( $duration_days > 0 ) {
			$expires_at = date( 'Y-m-d H:i:s', strtotime( "+{$duration_days} days" ) );
		}

		// 7. Get quantity and generate one key per item purchased
		$quantity = $item->get_quantity();
		$keys_generated_count = 0;

		for ( $i = 0; $i < $quantity; $i++ ) {

			// 8. Generate the unique license key
			// This calls the function from Step 3
			$new_key = faplm_generate_license_key_by_format( $format_id );

			if ( is_wp_error( $new_key ) ) {
				// Log the error
				$order->add_order_note( sprintf( __( 'License generation failed for item %d of %d (Product ID %d): %s', 'fa-pro-license-manager' ), ( $i + 1 ), $quantity, $checked_product_id, $new_key->get_error_message() ) );
				
				// --- FIX 2: Break this 'for' loop, not 'continue'. ---
				// If the format is invalid, it will fail for all quantities,
				// so we should stop trying for this product.
				break;
				// --- END FIX 2 ---
			}

			// 9. Prepare data and insert into the database
			$data_to_insert = array(
				'license_key'         => $new_key,
				'product_id'          => $checked_product_id,
				'order_id'            => $order_id,
				'status'              => 'active', // Automatically active on purchase
				'expires_at'          => $expires_at,
				'activation_limit'    => absint( $limit ) > 0 ? absint( $limit ) : 1, // Default to 1
				'current_activations' => 0,
				'activated_domains'   => null,
				'allow_courier_api'   => ( 'yes' === $grant_api ) ? 1 : 0,
			);
			
			$result = $wpdb->insert( $licenses_table, $data_to_insert );

			if ( $result ) {
				$keys_generated_count++;
			}
		} // end for quantity loop
		
		// Add a summary note to the order
		if ( $keys_generated_count > 0 ) {
			$order->add_order_note( sprintf( __( '%d license(s) successfully generated for Product: %s.', 'fa-pro-license-manager' ), $keys_generated_count, $item->get_name() ) );
		}

	} // end foreach order item loop
}


// --- NEW FUNCTIONS FOR STEP 7 ---

/**
 * Display license key info on the "Thank You" (Order Received) page.
 * Hooked to: woocommerce_thankyou
 *
 * @param int $order_id The ID of the order.
 */
function faplm_display_license_keys_on_thankyou( $order_id ) {
	faplm_render_customer_license_details( $order_id );
}

/**
 * Display license key info on the "View Order" page in My Account.
 * Hooked to: woocommerce_order_details_after_order_table
 *
 * @param WC_Order $order The order object.
 */
function faplm_display_license_keys_on_view_order( $order ) {
	// The hook passes the full order object, so we get the ID from it.
	$order_id = $order->get_id();
	faplm_render_customer_license_details( $order_id );
}

/**
 * Shared function to query and render license key details for an order.
 *
 * @param int $order_id The ID of the order to display licenses for.
 */
function faplm_render_customer_license_details( $order_id ) {
	global $wpdb;
	$licenses_table = $wpdb->prefix . FAPLM_LICENSES_TABLE;

	// 1. Query the database for all licenses associated with this order
	$licenses = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM $licenses_table WHERE order_id = %d",
			$order_id
		)
	);

	// 2. If no licenses are found, do nothing.
	if ( empty( $licenses ) ) {
		return;
	}

	// 3. Display the licenses in a clean table format
	?>
	<section class="faplm-license-details">
		<h2><?php esc_html_e( 'Your License Keys', 'fa-pro-license-manager' ); ?></h2>
		<table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
			<thead>
				<tr>
					<th class="woocommerce-table__product-name product-name"><?php esc_html_e( 'Product', 'fa-pro-license-manager' ); ?></th>
					<th class="woocommerce-table__product-table product-license"><?php esc_html_e( 'License Key', 'fa-pro-license-manager' ); ?></th>
					<th class="woocommerce-table__product-table product-expires"><?php esc_html_e( 'Expires', 'fa-pro-license-manager' ); ?></th>
					<th class="woocommerce-table__product-table product-activations"><?php esc_html_e( 'Activations', 'fa-pro-license-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $licenses as $license ) : ?>
					<?php
					// Get product details
					$product = wc_get_product( $license->product_id );
					$product_name = $product ? $product->get_name() : __( 'Product not found', 'fa-pro-license-manager' );

					// Format expiration date
					if ( null === $license->expires_at ) {
						$expires_text = __( 'Lifetime', 'fa-pro-license-manager' );
					} else {
						// Format the date based on WordPress settings
						$expires_text = date_i18n( get_option( 'date_format' ), strtotime( $license->expires_at ) );
					}

					// Format activations
					$activations_text = sprintf(
						'%d / %d',
						absint( $license->current_activations ),
						absint( $license->activation_limit )
					);
					?>
					<tr class="woocommerce-table__line-item order_item">
						<td class="woocommerce-table__product-name product-name">
							<?php echo esc_html( $product_name ); ?>
						</td>
						<td class="woocommerce-table__product-info product-license">
							<code><?php echo esc_html( $license->license_key ); ?></code>
						</td>
						<td class="woocommerce-table__product-info product-expires">
							<?php echo esc_html( $expires_text ); ?>
						</td>
						<td class="woocommerce-table__product-info product-activations">
							<?php echo esc_html( $activations_text ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<?php
}


