<?php
/**
 * Handles the "Courier Settings" admin page using the WordPress Settings API.
 * This page will store credentials for the Hybrid model:
 * - Data Source (Hoorin vs. Direct)
 * - Hoorin API Keys
 * - Direct Courier APIs (Pathao, Redex, Steadfast)
 * - Cache Settings
 *
 * @package FA_Licence_Manager
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "Courier Settings" submenu page under "License Manager".
 * Hooked to: admin_menu (from fa-pro-license-manager.php)
 */
function faplm_register_settings_submenu() {
	add_submenu_page(
		'fa-license-manager',               // Parent slug
		__( 'Courier Settings', 'fa-pro-license-manager' ), // Page title
		__( 'Courier Settings', 'fa-pro-license-manager' ), // Menu title
		'manage_options',                   // Capability
		'fa-courier-settings',              // Menu slug
		'faplm_render_courier_settings_page'  // Callback function
	);
}

/**
 * Registers all settings, sections, and fields for the Settings API.
 * Hooked to: admin_init
 */
function faplm_register_api_settings() {
	
	// Register the main settings group
	// All fields will be saved in a single array option named 'faplm_courier_settings'
	register_setting(
		'faplm_courier_settings_group', // Group name (used in <form>)
		'faplm_courier_settings',     // Option name
		'faplm_sanitize_courier_settings' // Sanitization callback
	);

	// --- SECTION 1: DATA SOURCE ---
	add_settings_section(
		'faplm_data_source_section',    // ID
		__( 'Data Source', 'fa-pro-license-manager' ), // Title
		null,  // No description callback needed
		'fa-courier-settings'           // Page slug
	);

	add_settings_field(
		'data_source',
		__( 'Select Data Source', 'fa-pro-license-manager' ),
		'faplm_render_radio_field',
		'fa-courier-settings',
		'faplm_data_source_section',
		array(
			'option_name' => 'faplm_courier_settings',
			'field_key'   => 'data_source',
			'default'     => 'hoorin',
			'options'     => array(
				'hoorin' => __( 'Hoorin API (Recommended)', 'fa-pro-license-manager' ),
				'direct' => __( 'Direct Courier APIs (Advanced)', 'fa-pro-license-manager' ),
			),
		)
	);

	// --- SECTION 2: HOORIN API SETTINGS ---
	add_settings_section(
		'faplm_hoorin_settings_section',
		__( 'Hoorin API Settings', 'fa-pro-license-manager' ),
		'faplm_hoorin_section_callback',
		'fa-courier-settings'
	);

	add_settings_field(
		'hoorin_api_keys',
		__( 'Hoorin API Keys', 'fa-pro-license-manager' ),
		'faplm_render_textarea_field',
		'fa-courier-settings',
		'faplm_hoorin_settings_section',
		array(
			'option_name' => 'faplm_courier_settings',
			'field_key'   => 'hoorin_api_keys',
			'description' => __( 'Enter each API Key on a new line. The plugin will automatically rotate (round-robin) requests.', 'fa-pro-license-manager' ),
		)
	);

	// --- SECTION 3: DIRECT COURIER API SETTINGS ---
	add_settings_section(
		'faplm_direct_api_settings_section',
		__( 'Direct Courier API Settings', 'fa-pro-license-manager' ),
		'faplm_direct_api_section_callback',
		'fa-courier-settings'
	);

	add_settings_field(
		'pathao_bearer_token',
		__( 'Pathao Bearer Token', 'fa-pro-license-manager' ),
		'faplm_render_text_field',
		'fa-courier-settings',
		'faplm_direct_api_settings_section',
		array(
			'option_name' => 'faplm_courier_settings',
			'field_key'   => 'pathao_bearer_token',
		)
	);

	add_settings_field(
		'redex_cookie',
		__( 'RedEx Cookie', 'fa-pro-license-manager' ),
		'faplm_render_textarea_field',
		'fa-courier-settings',
		'faplm_direct_api_settings_section',
		array(
			'option_name' => 'faplm_courier_settings',
			'field_key'   => 'redex_cookie',
			'description' => __( 'Login to RedEx, open Dev Tools (F12), and paste the full Cookie value here. This cookie has a long expiration.', 'fa-pro-license-manager' ),
		)
	);

	add_settings_field(
		'steadfast_email',
		__( 'Steadfast Email', 'fa-pro-license-manager' ),
		'faplm_render_text_field',
		'fa-courier-settings',
		'faplm_direct_api_settings_section',
		array(
			'option_name' => 'faplm_courier_settings',
			'field_key'   => 'steadfast_email',
			'description' => __( 'Email address for the Steadfast login bot.', 'fa-pro-license-manager' ),
		)
	);

	add_settings_field(
		'steadfast_password',
		__( 'Steadfast Password', 'fa-pro-license-manager' ),
		'faplm_render_password_field',
		'fa-courier-settings',
		'faplm_direct_api_settings_section',
		array(
			'option_name' => 'faplm_courier_settings',
			'field_key'   => 'steadfast_password',
			'description' => __( 'Password for the Steadfast login bot.', 'fa-pro-license-manager' ),
		)
	);

	// --- SECTION 4: CACHE SETTINGS ---
	add_settings_section(
		'faplm_cache_settings_section',
		__( 'Cache Settings', 'fa-pro-license-manager' ),
		null,
		'fa-courier-settings'
	);

	add_settings_field(
		'cache_duration',
		__( 'Cache Duration (Hours)', 'fa-pro-license-manager' ),
		'faplm_render_number_field',
		'fa-courier-settings',
		'faplm_cache_settings_section',
		array(
			'option_name' => 'faplm_courier_settings',
			'field_key'   => 'cache_duration',
			'default'     => 6,
			'description' => __( "Hours to store results for the same phone number. '6' is recommended. Set to '0' to disable caching.", 'fa-pro-license-manager' ),
		)
	);
}

/**
 * Renders the main settings page wrapper.
 */
function faplm_render_courier_settings_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Courier API Settings', 'fa-pro-license-manager' ); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'faplm_courier_settings_group' ); // Links to our register_setting() group
			do_settings_sections( 'fa-courier-settings' );  // Renders all sections and fields for this page
			submit_button();                                 // Adds the "Save Changes" button
			?>
		</form>
	</div>
	<?php
}

/**
 * =================================================================
 * SECTION CALLBACKS (Descriptions)
 * =================================================================
 */

function faplm_hoorin_section_callback() {
	echo '<p>' . esc_html__( 'These settings are used only if "Hoorin API" is selected as the data source.', 'fa-pro-license-manager' ) . '</p>';
}

function faplm_direct_api_section_callback() {
	echo '<p>' . esc_html__( 'These settings are used only if "Direct Courier APIs" is selected. This is an advanced feature.', 'fa-pro-license-manager' ) . '</p>';
}


/**
 * =================================================================
 * FIELD RENDERER CALLBACKS
 * =================================================================
 */

// Helper function to get the current saved options
function faplm_get_setting( $field_key, $default = '' ) {
	$options = get_option( 'faplm_courier_settings' );
	return isset( $options[ $field_key ] ) ? $options[ $field_key ] : $default;
}

// Renders Radio Buttons
function faplm_render_radio_field( $args ) {
	$value = faplm_get_setting( $args['field_key'], $args['default'] );
	
	foreach ( $args['options'] as $radio_value => $label ) {
		echo '<label style="margin-right: 20px;">';
		echo '<input type="radio" name="faplm_courier_settings[' . esc_attr( $args['field_key'] ) . ']" value="' . esc_attr( $radio_value ) . '" ' . checked( $value, $radio_value, false ) . '>';
		echo ' ' . esc_html( $label );
		echo '</label><br>';
	}
}

// Renders a simple Text Input
function faplm_render_text_field( $args ) {
	$value = faplm_get_setting( $args['field_key'], '' );
	?>
	<input type="text" name="faplm_courier_settings[<?php echo esc_attr( $args['field_key'] ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="regular-text">
	<?php if ( ! empty( $args['description'] ) ) : ?>
		<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
	<?php endif; ?>
	<?php
}

// Renders a Password Input
function faplm_render_password_field( $args ) {
	$value = faplm_get_setting( $args['field_key'], '' );
	?>
	<input type="password" name="faplm_courier_settings[<?php echo esc_attr( $args['field_key'] ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="regular-text">
	<?php if ( ! empty( $args['description'] ) ) : ?>
		<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
	<?php endif; ?>
	<?php
}

// Renders a Textarea
function faplm_render_textarea_field( $args ) {
	$value = faplm_get_setting( $args['field_key'], '' );
	?>
	<textarea name="faplm_courier_settings[<?php echo esc_attr( $args['field_key'] ); ?>]" rows="5" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
	<?php if ( ! empty( $args['description'] ) ) : ?>
		<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
	<?php endif; ?>
	<?php
}

// Renders a Number Input
function faplm_render_number_field( $args ) {
	$value = faplm_get_setting( $args['field_key'], $args['default'] );
	?>
	<input type="number" name="faplm_courier_settings[<?php echo esc_attr( $args['field_key'] ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="small-text" min="0">
	<?php if ( ! empty( $args['description'] ) ) : ?>
		<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
	<?php endif; ?>
	<?php
}


/**
 * =================================================================
 * SANITIZATION CALLBACK
 * =================================================================
 */

/**
 * Sanitizes the entire settings array before saving.
 *
 * @param array $input The raw input from the form.
 * @return array The sanitized input.
 */
function faplm_sanitize_courier_settings( $input ) {
	$output = array();

	if ( isset( $input['data_source'] ) ) {
		$output['data_source'] = in_array( $input['data_source'], array( 'hoorin', 'direct' ) ) ? $input['data_source'] : 'hoorin';
	}

	if ( isset( $input['hoorin_api_keys'] ) ) {
		// Allow newlines, but sanitize the content
		$output['hoorin_api_keys'] = sanitize_textarea_field( $input['hoorin_api_keys'] );
	}

	if ( isset( $input['pathao_bearer_token'] ) ) {
		$output['pathao_bearer_token'] = sanitize_text_field( $input['pathao_bearer_token'] );
	}

	if ( isset( $input['redex_cookie'] ) ) {
		// Cookies can contain special chars, but we still sanitize for safety
		$output['redex_cookie'] = sanitize_textarea_field( $input['redex_cookie'] );
	}

	if ( isset( $input['steadfast_email'] ) ) {
		$output['steadfast_email'] = sanitize_email( $input['steadfast_email'] );
	}

	if ( isset( $input['steadfast_password'] ) ) {
		// Passwords should not be stripped of special chars, so just trim
		$output['steadfast_password'] = trim( $input['steadfast_password'] );
	}
	
	if ( isset( $input['cache_duration'] ) ) {
		$output['cache_duration'] = absint( $input['cache_duration'] ); // Ensure positive integer
	}

	return $output;
}

