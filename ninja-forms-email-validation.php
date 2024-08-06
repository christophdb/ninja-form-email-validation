<?php
/*
Plugin Name: Ninja Forms Email Validation
Plugin URI: https://seatable.io
Description: A plugin to validate email addresses using a remote service when a Ninja Form is submitted.
Version: 1.1
Author: Christoph Dyllick-Brenzinger
Author URI: https://seatable.io
License: GPL2
*/

// Hook the custom function into Ninja Forms submission
add_filter('ninja_forms_submit_data', 'custom_ninja_forms_submit_data');

// Add settings page
add_action('admin_menu', 'nf_email_validation_add_admin_menu');
add_action('admin_init', 'nf_email_validation_settings_init');

function nf_email_validation_add_admin_menu() {
    add_options_page('Ninja Forms Email Validation', 'Ninja Forms Email Validation', 'manage_options', 'ninja_forms_email_validation', 'nf_email_validation_options_page');
}

function nf_email_validation_settings_init() {
    register_setting('nfEmailValidation', 'nf_email_validation_settings');

    add_settings_section(
        'nf_email_validation_section',
        __('Settings', 'wordpress'),
        'nf_email_validation_settings_section_callback',
        'nfEmailValidation'
    );

    add_settings_field(
        'nf_email_validation_key',
        __('Feldschlüssel, der validiert werden soll:', 'wordpress'),
        'nf_email_validation_key_render',
        'nfEmailValidation',
        'nf_email_validation_section'
    );
}

function nf_email_validation_key_render() {
    $options = get_option('nf_email_validation_settings');
    ?>
    <input type='text' name='nf_email_validation_settings[nf_email_validation_key]' value='<?php echo $options['nf_email_validation_key']; ?>'>
    <?php
}

function nf_email_validation_settings_section_callback() {
    echo __('Enter the key of the email field you want to validate.', 'wordpress');
}

function nf_email_validation_options_page() {
    ?>
    <form action='options.php' method='post'>
        <h2>Ninja Forms Email Validation</h2>
        <?php
        settings_fields('nfEmailValidation');
        do_settings_sections('nfEmailValidation');
        submit_button();
        ?>
    </form>
    <?php
}

// Define the custom function
function custom_ninja_forms_submit_data($form_data) {

    // Get the field key from settings
    $options = get_option('nf_email_validation_settings');
    $my_key_from_settings = isset($options['nf_email_validation_key']) ? $options['nf_email_validation_key'] : '';

    // Iterate over all fields
    foreach ($form_data['fields'] as $field_id => $field) {

	// Check if the field key matches the setting
        if ($my_key_from_settings != $field['key']) {
            continue;
        }

        $email = isset($field['value']) ? $field['value'] : '';
        if (empty($email)) {
            $form_data['errors']['fields'][$field_id] = 'Email address is required.';
            continue;
        }

        // Send the email to the validation service
        $response = wp_remote_get('https://get.seatable.io/validate/' . urlencode($email));
        //print_r($response);

	// skip if rate limit reached
        if (isset($response['response']['code']) && $response['response']['code'] == 429) {
		$form_data['errors']['fields'][$field_id] = 'Rate Limit erreicht.';
                continue;
	}

        // Check for WP errors
        if (is_wp_error($response)) {
            $form_data['errors']['fields'][$field_id] = 'There was an error validating your email address. Please try again.';
            custom_log_error('Validation service error: ' . $response->get_error_message());
            continue;
        }

        // Get the response body
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        // Check for JSON errors
        /*if (json_last_error() !== JSON_ERROR_NONE) {
            $form_data['errors']['fields'][$field_id] = 'There was an error processing the validation response. Please try again.';
            custom_log_error('JSON error: ' . json_last_error_msg());
            continue;
        }*/

        // Assuming the service returns a JSON object with a 'valid' key
        if (isset($result['valid']) && $result['valid'] != true) {
            $form_data['errors']['fields'][$field_id] = 'Die E-Mail-Adresse kann nicht automatisch verifiziert werden. Bitte kontaktieren Sie support@seatable.io, wenn Sie ein SeaTable Cloud Konto anlegen möchten.';
            custom_log_error('Email validation failed for email: ' . $email);
        }
    }

    return $form_data;
}

// Custom function to log errors
function custom_log_error($message) {
    if (WP_DEBUG) {
        error_log($message);
    }
}

// Optional: Function to retry validation in case of transient errors
function custom_retry_validation($email, $retry_count = 3) {
    $attempt = 0;
    while ($attempt < $retry_count) {
        $response = wp_remote_get('https://get.seatable.io/validate/' . urlencode($email));
        if (!is_wp_error($response)) {
            return $response;
        }
        $attempt++;
        sleep(1); // Wait a bit before retrying
    }
    return $response;
}
