<?php
/**
 * Plugin Name:       FA Licence Manager
 * Plugin URI:        https://your-plugin-website.com/
 * Description:       A professional license manager for handling software activations, expirations, and updates.
 * Version:           1.0.0
 * Author:            Your Name
 * Author URI:        https://your-website.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fa-pro-license-manager
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define constants.
 */
define( 'FAPLM_VERSION', '1.0.0' );
define( 'FAPLM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FAPLM_LICENSES_TABLE', 'plugin_licenses' ); // We will prepend the $wpdb->prefix
define( 'FAPLM_FORMATS_TABLE', 'license_formats' ); // Table for license formats

/**
 * Include core functions.
 */
// Functions for generating license keys
require_once FAPLM_PLUGIN_DIR . 'includes/faplm-key-functions.php'; 
// The WP_List_Table class for displaying licenses
require_once FAPLM_PLUGIN_DIR . 'includes/class-faplm-licenses-list-table.php';
// Functions for rendering admin pages and handling license/format CRUD
require_once FAPLM_PLUGIN_DIR . 'includes/faplm-admin-pages.php';
// Functions for integrating with WooCommerce products and orders
require_once FAPLM_PLUGIN_DIR . 'includes/faplm-woocommerce-integration.php';
// Functions for handling REST API endpoints (e.g., /activate)
require_once FAPLM_PLUGIN_DIR . 'includes/faplm-api-endpoints.php';
// Functions for the "Courier Settings" admin page
require_once FAPLM_PLUGIN_DIR . 'includes/faplm-admin-settings-page.php';
// --- ADD THIS NEW LINE ---
// Helper functions for Direct Courier API logic (Normalization, Bot)
require_once FAPLM_PLUGIN_DIR . 'includes/faplm-direct-courier-helpers.php';


/**
 * The code that runs during plugin activation.
 * This function is called when the plugin is 'Activated' from the plugins screen.
 */
function faplm_activate_plugin() {
	// We need to include the upgrade.php file to use dbDelta().
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	global $wpdb;

	// Get the correct table name with the WordPress prefix.
	$table_name_licenses = $wpdb->prefix . FAPLM_LICENSES_TABLE;
	$table_name_formats  = $wpdb->prefix . FAPLM_FORMATS_TABLE;
	
	// Get the character set and collation.
	$charset_collate = $wpdb->get_charset_collate();

	// SQL statement to create our custom licenses table.
	$sql_licenses = "CREATE TABLE $table_name_licenses (
		id BIGINT(20) unsigned NOT NULL AUTO_INCREMENT,
		license_key VARCHAR(255) NOT NULL,
		product_id BIGINT(20) unsigned NOT NULL,
		order_id BIGINT(20) unsigned NOT NULL,
		status VARCHAR(100) NOT NULL DEFAULT 'inactive',
		expires_at DATETIME DEFAULT NULL,
		activation_limit INT(11) NOT NULL DEFAULT 1,
		current_activations INT(11) NOT NULL DEFAULT 0,
		activated_domains TEXT DEFAULT NULL,
		allow_courier_api TINYINT(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY license_key (license_key)
	) $charset_collate;";

	// Run the SQL query for the licenses table.
	dbDelta( $sql_licenses );

	// SQL statement to create our custom license formats table.
	$sql_formats = "CREATE TABLE $table_name_formats (
		id BIGINT(20) unsigned NOT NULL AUTO_INCREMENT,
		format_name VARCHAR(255) NOT NULL,
		prefix VARCHAR(100) DEFAULT NULL,
		suffix VARCHAR(100) DEFAULT NULL,
		chunk_length INT(11) NOT NULL DEFAULT 4,
		total_chunks INT(11) NOT NULL DEFAULT 4,
		PRIMARY KEY  (id)
	) $charset_collate;";

	// Run the SQL query for the formats table.
	dbDelta( $sql_formats );

    // --- REMOVE THIS LINE ---
    // add_option( 'faplm_hoorin_cache_duration', 6 );
}

// Register the activation hook.
register_activation_hook( __FILE__, 'faplm_activate_plugin' );

/**
 * Register Admin Menu Pages.
 */
function faplm_register_admin_menu() {
	// Create main menu page
	add_menu_page(
		__( 'License Manager', 'fa-pro-license-manager' ), // Page Title
		__( 'License Manager', 'fa-pro-license-manager' ), // Menu Title
		'manage_options', // Capability
		'fa-license-manager', // Menu Slug
		'faplm_render_main_license_page', // Callback function (from new admin-pages.php)
		'dashicons-admin-network', // Icon
		25 // Position
	);

	// Create sub-menu page for License Formats
	add_submenu_page(
		'fa-license-manager', // Parent Slug
		__( 'License Formats', 'fa-pro-license-manager' ), // Page Title
		__( 'License Formats', 'fa-pro-license-manager' ), // Menu Title
		'manage_options', // Capability
		'fa-license-formats', // Menu Slug
		'faplm_render_license_formats_page' // Callback function (from new admin-pages.php)
	);

    // --- ADD THIS HOOK ---
    // This calls the function from our new file
    faplm_register_settings_submenu();
}
add_action( 'admin_menu', 'faplm_register_admin_menu' );

// --- ADD THIS HOOK ---
/**
 * Register all settings for the Settings API.
 */
add_action( 'admin_init', 'faplm_register_api_settings' );

/**
 * Hook to process CRUD actions for licenses (add, edit, delete).
 * This is hooked to admin_init and handled in faplm-admin-pages.php
 */
add_action( 'admin_init', 'faplm_handle_license_actions' );

/**
 * Enqueue scripts and styles for admin pages.
 */
add_action( 'admin_enqueue_scripts', 'faplm_admin_page_scripts' );

/**
 * Initialize WooCommerce integration hooks.
 * We run this on 'plugins_loaded' to ensure WooCommerce has been loaded first.
 */
function faplm_init_woocommerce_integration() {
	// Check if WooCommerce is active
	if ( class_exists( 'WooCommerce' ) ) {
		// Add fields to product page
		add_action( 'woocommerce_product_options_general_product_data', 'faplm_add_license_product_options' );
		// Save the custom fields
		add_action( 'woocommerce_process_product_meta', 'faplm_save_license_product_options' );

		// Generate licenses when an order is processing or completed
		add_action( 'woocommerce_order_status_processing', 'faplm_generate_license_on_order_completion', 10, 1 );
		add_action( 'woocommerce_order_status_completed', 'faplm_generate_license_on_order_completion', 10, 1 );

		// Display license keys on customer-facing order pages
		add_action( 'woocommerce_thankyou', 'faplm_display_license_keys_on_thankyou', 10, 1 );
		add_action( 'woocommerce_order_details_after_order_table', 'faplm_display_license_keys_on_view_order', 10, 1 );
	}
}
add_action( 'plugins_loaded', 'faplm_init_woocommerce_integration' );



