<?php
/**
 * Handles all REST API endpoints for the FA License Manager.
 * - /my-license/v1/activate
 * - /my-license/v1/deactivate
 * - /courier-check/v1/status
 *
 * @package FA_Licence_Manager
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register all REST API endpoints for the FA License Manager.
 * Hooks into: rest_api_init
 */
function faplm_register_api_endpoints() {
	
	// --- Step 8: Register the /activate route ---
	register_rest_route(
		'my-license/v1', // Namespace
		'/activate',     // Route
		array(
			'methods'             => 'POST', // Must be a POST request
			'callback'            => 'faplm_handle_activation_request',
			'permission_callback' => '__return_true', // Public endpoint, security is handled by the key
		)
	);

	// --- Step 9: Register the /deactivate route ---
	register_rest_route(
		'my-license/v1', // Namespace
		'/deactivate',   // Route
		array(
			'methods'             => 'POST',
			'callback'            => 'faplm_handle_deactivation_request',
			'permission_callback' => '__return_true', // Public endpoint
		)
	);

	// --- Step 12: Register the /courier-check route ---
	register_rest_route(
		'courier-check/v1', // New Namespace
		'/status',          // Route
		array(
			'methods'             => 'POST',
			'callback'            => 'faplm_handle_courier_check_request', // This is the function we are updating
			'permission_callback' => 'faplm_courier_api_permission_check', // Secure permission check
		)
	);
}
add_action( 'rest_api_init', 'faplm_register_api_endpoints' );


// --- FUNCTIONS FOR /my-license (Steps 8 & 9) ---

/**
 * Main callback function to handle license ACTIVATION requests.
 * (From Step 8)
 *
 * @param WP_REST_Request $request The incoming request object.
 * @return WP_REST_Response|WP_Error
 */
function faplm_handle_activation_request( WP_REST_Request $request ) {
	global $wpdb;
	$table_name = $wpdb->prefix . FAPLM_LICENSES_TABLE;

	// 1. Get parameters from the request body (e.g., JSON)
	$license_key = sanitize_text_field( $request->get_param( 'license_key' ) );
	$domain      = sanitize_text_field( $request->get_param( 'domain' ) );
	
	// 2. Parameter Validation
	if ( empty( $license_key ) || empty( $domain ) ) {
		return new WP_Error(
			'missing_parameters',
			__( 'Missing required parameters: license_key and domain.', 'fa-pro-license-manager' ),
			array( 'status' => 400 ) // 400 Bad Request
		);
	}

	// 3. Core License Validation Logic: Fetch the license
	$license = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM $table_name WHERE license_key = %s",
			$license_key
		)
	);

	// 4. Check 1 (Exists)
	if ( ! $license ) {
		return new WP_Error(
			'invalid_key',
			__( 'The provided license key is invalid.', 'fa-pro-license-manager' ),
			array( 'status' => 403 ) // 403 Forbidden
		);
	}

	// 5. Check 2 (Status)
	if ( 'active' !== $license->status ) {
		$error_code = 'key_not_active';
		$error_msg  = __( 'This license key is not active.', 'fa-pro-license-manager' );

		if ( 'expired' === $license->status ) {
			$error_code = 'key_expired';
			$error_msg  = __( 'This license key has expired.', 'fa-pro-license-manager' );
		}

		return new WP_Error(
			$error_code,
			$error_msg,
			array( 'status' => 403 ) // 403 Forbidden
		);
	}

	// 6. Check 3 (Expiration)
	if ( null !== $license->expires_at ) {
		$current_time = current_time( 'mysql' ); // Use WP local time
		
		if ( $current_time > $license->expires_at ) {
			
			// License has expired, update status in DB
			$wpdb->update(
				$table_name,
				array( 'status' => 'expired' ),
				array( 'id' => $license->id )
			);
			
			return new WP_Error(
				'key_expired',
				__( 'This license key has expired.', 'fa-pro-license-manager' ),
				array( 'status' => 403 ) // 403 Forbidden
			);
		}
	}

	// 7. Check 4 (Already Activated?)
	$activated_domains = json_decode( $license->activated_domains, true );
	
	if ( ! is_array( $activated_domains ) ) {
		$activated_domains = array();
	}

	if ( in_array( $domain, $activated_domains, true ) ) {
		return new WP_REST_Response(
			array(
				'success'    => true,
				'message'    => __( 'License is already activated on this domain.', 'fa-pro-license-manager' ),
				'expires_at' => ( null === $license->expires_at ) ? 'Lifetime' : $license->expires_at,
			),
			200 // 200 OK
		);
	}

	// 8. Check 5 (Limit Reached)
	$current_activations = absint( $license->current_activations );
	$activation_limit    = absint( $license->activation_limit );

	if ( $current_activations >= $activation_limit ) {
		return new WP_Error(
			'limit_reached',
			__( 'This license key has reached its activation limit.', 'fa-pro-license-manager' ),
			array( 'status' => 403 ) // 403 Forbidden
		);
	}

	// 9. Successful Activation Process
	$activated_domains[] = $domain;
	$new_activations_count = $current_activations + 1;

	$data_to_update = array(
		'current_activations' => $new_activations_count,
		'activated_domains'   => wp_json_encode( $activated_domains ),
	);
	$where = array( 'id' => $license->id );

	$updated = $wpdb->update( $table_name, $data_to_update, $where );

	if ( false === $updated ) {
		return new WP_Error(
			'db_error',
			__( 'Could not save activation data to the database.', 'fa-pro-license-manager' ),
			array( 'status' => 500 ) // 500 Internal Server Error
		);
	}

	return new WP_REST_Response(
		array(
			'success'    => true,
			'message'    => __( 'License activated successfully.', 'fa-pro-license-manager' ),
			'expires_at' => ( null === $license->expires_at ) ? 'Lifetime' : $license->expires_at,
		),
		200 // 200 OK
	);
}

/**
 * Main callback function to handle license DEACTIVATION requests.
 * (From Step 9)
 *
 * @param WP_REST_Request $request The incoming request object.
 * @return WP_REST_Response|WP_Error
 */
function faplm_handle_deactivation_request( WP_REST_Request $request ) {
	global $wpdb;
	$table_name = $wpdb->prefix . FAPLM_LICENSES_TABLE;

	// 1. Get parameters
	$license_key = sanitize_text_field( $request->get_param( 'license_key' ) );
	$domain      = sanitize_text_field( $request->get_param( 'domain' ) );
	
	// 2. Parameter Validation
	if ( empty( $license_key ) || empty( $domain ) ) {
		return new WP_Error(
			'missing_parameters',
			__( 'Missing required parameters: license_key and domain.', 'fa-pro-license-manager' ),
			array( 'status' => 400 ) // 400 Bad Request
		);
	}

	// 3. Core Logic: Fetch the license
	$license = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM $table_name WHERE license_key = %s",
			$license_key
		)
	);

	// 4. Check 1 (Exists)
	if ( ! $license ) {
		return new WP_Error(
			'invalid_key',
			__( 'The provided license key is invalid.', 'fa-pro-license-manager' ),
			array( 'status' => 403 ) // 403 Forbidden
		);
	}

	// 5. Check 2 (Is it activated on this domain?)
	$activated_domains = json_decode( $license->activated_domains, true );
	
	if ( ! is_array( $activated_domains ) ) {
		$activated_domains = array();
	}

	// Search for the domain in the array
	$key = array_search( $domain, $activated_domains, true );

	if ( false === $key ) {
		// Domain was NOT found in the array
		return new WP_Error(
			'not_activated_here',
			__( 'This license is not activated on the specified domain.', 'fa-pro-license-manager' ),
			array( 'status' => 400 ) // 400 Bad Request
		);
	}

	// 6. Successful Deactivation Process
	
	// Domain was found. Remove it using its key.
	unset( $activated_domains[ $key ] );

	// Re-index the array to ensure it saves as a JSON array, not an object.
	$updated_domains_array = array_values( $activated_domains );

	// Decrement the activation count, ensuring it doesn't go below zero.
	$new_activations_count = max( 0, absint( $license->current_activations ) - 1 );
	
	// Prepare data for the database update
	$data_to_update = array(
		'current_activations' => $new_activations_count,
		'activated_domains'   => wp_json_encode( $updated_domains_array ), // Save the re-indexed array
	);
	$where = array( 'id' => $license->id );

	$updated = $wpdb->update( $table_name, $data_to_update, $where );

	if ( false === $updated ) {
		// Handle potential database error
		return new WP_Error(
			'db_error',
			__( 'Could not save deactivation data to the database.', 'fa-pro-license-manager' ),
			array( 'status' => 500 ) // 500 Internal Server Error
		);
	}

	// 7. Return the final success response
	return new WP_REST_Response(
		array(
			'success' => true,
			'message' => __( 'License deactivated successfully.', 'fa-pro-license-manager' ),
		),
		200 // 200 OK
	);
}


// --- FUNCTIONS FOR /courier-check (Steps 12 & 13) ---

/**
 * Security check for the Courier API endpoint.
 * This function is hooked as the 'permission_callback'
 * (From Step 12)
 *
 * @param WP_REST_Request $request The incoming request object.
 * @return bool|WP_Error True if permission is granted, WP_Error otherwise.
 */
function faplm_courier_api_permission_check( WP_REST_Request $request ) {
	global $wpdb;
	$table_name = $wpdb->prefix . FAPLM_LICENSES_TABLE;

	// 1. Get the Authorization header
	$auth_header = $request->get_header( 'authorization' );

	if ( empty( $auth_header ) ) {
		return new WP_Error(
			'401_unauthorized',
			'Authorization header is missing.',
			array( 'status' => 401 )
		);
	}

	// 2. Parse the "Bearer <LICENSE_KEY>" format
	$license_key = '';
	if ( sscanf( $auth_header, 'Bearer %s', $license_key ) !== 1 ) {
		return new WP_Error(
			'401_unauthorized',
			'Authorization header is malformed. Expected "Bearer <KEY>".',
			array( 'status' => 401 )
		);
	}

	if ( empty( $license_key ) ) {
		return new WP_Error(
			'401_unauthorized',
			'No license key provided in Authorization header.',
			array( 'status' => 401 )
		);
	}

	// 3. Query the database for this license key
	$license = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM $table_name WHERE license_key = %s",
			$license_key
		)
	);

	$error_msg = __( 'Invalid or unauthorized license.', 'fa-pro-license-manager' );

	// 4. Perform Security Checks
	
	// Check 1: Key exists and status is 'active'
	if ( ! $license || 'active' !== $license->status ) {
		return new WP_Error( '403_forbidden', $error_msg, array( 'status' => 403 ) );
	}

	// Check 2: Expiration date (if it's not NULL)
	if ( null !== $license->expires_at ) {
		$current_time = current_time( 'mysql' ); // Use WP local time for consistency
		
		if ( $current_time > $license->expires_at ) {
			// As a courtesy, update the status to 'expired' in the DB
			$wpdb->update( $table_name, array( 'status' => 'expired' ), array( 'id' => $license->id ) );
			return new WP_Error( '403_forbidden', 'This license has expired.', array( 'status' => 403 ) );
		}
	}

	// Check 3: 'allow_courier_api' column must be 1
	if ( 1 !== (int) $license->allow_courier_api ) {
		return new WP_Error( '403_forbidden', 'This license does not have permission to access the courier API.', array( 'status' => 403 ) );
	}

	// 5. All checks passed!
	return true;
}

/**
 * Main callback for the courier-check/v1/status endpoint.
 * This is the DEBUG version for measuring performance.
 *
 * @param WP_REST_Request $request The incoming request object.
 * @return WP_REST_Response|WP_Error
 */
function faplm_handle_courier_check_request( WP_REST_Request $request ) {
	
	// 1. Get searchTerm from the JSON body
	$params      = $request->get_json_params();
	$search_term = isset( $params['searchTerm'] ) ? sanitize_text_field( $params['searchTerm'] ) : '';

	if ( empty( $search_term ) ) {
		return new WP_Error(
			'400_bad_request',
			'searchTerm is required in the JSON body.',
			array( 'status' => 400 )
		);
	}

	// 2. Implement Caching (Cache-Check)
	$transient_key = 'courier_data_' . md5( $search_term );
	$cached_data   = get_transient( $transient_key );

	// 3. (Cache Hit) If data is found, return it immediately.
	if ( false !== $cached_data ) {
		// The cached data is a raw JSON string.
		// We return it with the correct content-type.
		$response = new WP_REST_Response( $cached_data, 200 );
		$response->header( 'Content-Type', 'application/json' );
		$response->header( 'X-Cache-Status', 'HIT' ); // Custom header to show it came from cache
		return $response;
	}

	// 4. (Cache Miss) No data found in cache. Proceed to API call.
	
	// a. Fetch API Keys from settings
	$api_keys_string = get_option( 'faplm_hoorin_api_keys', '' );
	
	// b. Prepare Key Array
	$api_keys = preg_split( '/\r\n|\r|\n/', $api_keys_string ); // Split by any newline
	$api_keys = array_map( 'trim', $api_keys );               // Trim whitespace from each key
	$api_keys = array_filter( $api_keys );                    // Remove any empty lines

	if ( empty( $api_keys ) ) {
		return new WP_Error(
			'no_api_keys',
			'No Hoorin API keys are configured.',
			array( 'status' => 500 ) // 500 Internal Server Error
		);
	}

	// c. Implement Round-Robin (Get Index)
	$current_index = absint( get_option( 'hoorin_key_index', 0 ) );

	// d. Select Key
	// Ensure index is not out of bounds (e.g., if keys were removed)
	if ( $current_index >= count( $api_keys ) ) {
		$current_index = 0;
	}
	$key_to_use = $api_keys[ $current_index ];

	// e. Implement Round-Robin (Update Index for next request)
	$next_index = ( $current_index + 1 ) % count( $api_keys );
	update_option( 'hoorin_key_index', $next_index );

	// 5. Call the External Hoorin API
	$api_url  = 'https://dash.hoorin.com/api/courier/news.php';
	$full_url = add_query_arg(
		array(
			'apiKey'     => $key_to_use,
			'searchTerm' => $search_term,
		),
		$api_url
	);

	// --- START DEBUG TIMER ---
	$start_time = microtime( true );
	
	$response = wp_remote_get( $full_url, array( 'timeout' => 15 ) ); // 15 second timeout

	$end_time = microtime( true );
	$call_duration = $end_time - $start_time;
	// --- END DEBUG TIMER ---


	// 6. Error Handling (WP_Error, e.g., cURL error, DNS failure)
	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'api_call_failed',
			'The external API call failed. ' . $response->get_error_message(),
			array( 'status' => 500 ) // 500 Internal Server Error
		);
	}

	// 7. Response Handling
	$response_body = wp_remote_retrieve_body( $response );
	$response_code = wp_remote_retrieve_response_code( $response );

	// 8. Process and Return Response
	if ( 200 === $response_code ) {
		// (Successful Call)
		
		// a. Save to Cache
		// We save the ORIGINAL, raw $response_body to the cache.
		$cache_duration_hours = absint( get_option( 'faplm_hoorin_cache_duration', 6 ) );
		$cache_in_seconds     = $cache_duration_hours * HOUR_IN_SECONDS;

		if ( $cache_in_seconds > 0 ) {
			// Save the raw JSON string to the transient
			set_transient( $transient_key, $response_body, $cache_in_seconds );
		}

		// --- START LOGGING CODE (STEP 14) ---
		// We still log the event even in debug mode.
		global $wpdb;
		// --- THE FIX IS ON THIS LINE ---
		// Use the actual string, as the constant may not be loaded during an API call.
		$logs_table_name = $wpdb->prefix . 'courier_api_logs'; 
		
		$auth_header = $request->get_header( 'authorization' );
		$license_key = 'Unknown';
		if ( sscanf( $auth_header, 'Bearer %s', $license_key ) !== 1 ) {
			$license_key = 'Error Parsing';
		}
		
		$wpdb->insert(
			$logs_table_name,
			array(
				'license_key_used' => $license_key,
				'hoorin_key_used'  => '...' . substr( $key_to_use, -6 ), // Save last 6 chars for security
				'search_term'      => $search_term,
				'timestamp'        => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		// --- END LOGGING CODE ---

		// --- START DEBUG RESPONSE ---
		// b. Return to User
		// We decode the original response to ADD our debug info.
		$data = json_decode( $response_body, true ); // true for associative array

		// Handle bad JSON from Hoorin
		if ( ! is_array( $data ) ) {
			$data = array(
				'original_response' => $response_body,
				'json_decode_error' => true,
			);
		}
		
		// Add our new debug object
		$data['_debug_info'] = array(
			'status'                   => 'CACHE_MISS',
			'hoorin_call_time_seconds' => round( $call_duration, 4 ), // Rounded for readability
		);

		// Return the MODIFIED data
		$rest_response = new WP_REST_Response( $data, 200 );
		$rest_response->header( 'X-Cache-Status', 'MISS' ); // Custom header
		return $rest_response;
		// --- END DEBUG RESPONSE ---

	} else {
		// (Failed Call - e.g., 401, 403, 500 from Hoorin API)
		return new WP_Error(
			'external_api_error',
			'The external API returned an error.',
			array(
				'status'        => 502, // 502 Bad Gateway
				'upstream_code' => $response_code,
				'upstream_body' => json_decode( $response_body ), // Attempt to decode body for debugging
			)
		);
	}
}


