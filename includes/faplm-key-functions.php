<?php
/**
 * Core functions for FA License Manager
 * - License Key Generation
 *
 * @package FA_Licence_Manager
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates a single random, uppercase, alphanumeric chunk.
 *
 * @param int $length The desired length of the chunk.
 * @return string The random chunk.
 */
function faplm_generate_random_chunk( $length = 4 ) {
	$chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
	$chunk = '';
	for ( $i = 0; $i < $length; $i++ ) {
		$chunk .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
	}
	return $chunk;
}

/**
 * Checks if a license key already exists in the database.
 *
 * @param string $key The license key to check.
 * @return bool True if the key is unique (does not exist), false otherwise.
 */
function faplm_is_license_key_unique( $key ) {
	global $wpdb;
	$licenses_table = $wpdb->prefix . FAPLM_LICENSES_TABLE;

	$count = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM $licenses_table WHERE license_key = %s",
			$key
		)
	);

	return absint( $count ) === 0;
}

/**
 * Generates a unique license key based on a specific format ID.
 *
 * This function will fetch the format rules, generate a key, and
 * loop until a unique key is found.
 *
 * @param int $format_id The ID of the format from the wp_license_formats table.
 * @return string|WP_Error The new, unique license key on success, or WP_Error on failure.
 */
function faplm_generate_license_key_by_format( $format_id ) {
	global $wpdb;
	$formats_table = $wpdb->prefix . FAPLM_FORMATS_TABLE;

	// 1. Fetch Format Rules
	$format = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM $formats_table WHERE id = %d",
			$format_id
		)
	);

	if ( ! $format ) {
		return new WP_Error( 'invalid_format_id', __( 'The specified license format ID does not exist.', 'fa-pro-license-manager' ) );
	}

	$max_attempts = 10; // Safety break to prevent infinite loops
	$attempts     = 0;

	do {
		// 2. Generate Random Chunks
		$chunks = array();
		for ( $i = 0; $i < $format->total_chunks; $i++ ) {
			$chunks[] = faplm_generate_random_chunk( $format->chunk_length );
		}

		// 3. Assemble the Key
		$key_body = implode( '-', $chunks );
		$new_key  = $format->prefix . $key_body . $format->suffix;

		$attempts++;
		if ( $attempts > $max_attempts ) {
			return new WP_Error( 'generation_failed', __( 'Could not generate a unique key after 10 attempts. Check format rules.', 'fa-pro-license-manager' ) );
		}

		// 4. Check for Uniqueness (Loop repeats if faplm_is_license_key_unique returns false)
	} while ( ! faplm_is_license_key_unique( $new_key ) );

	// 5. Return the guaranteed unique key
	return $new_key;
}
