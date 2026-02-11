<?php
/**
 * Form Handler
 *
 * Handles Gravity Forms submission and sends WhatsApp notifications
 */

if (!defined('ABSPATH')) {
    exit;
}

class SGF_Form_Handler {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('gform_after_submission', [$this, 'handle_form_submission'], 10, 2);
    }

    /**
     * Helper function to get array value recursively (like Gravity Forms rgar)
     */
    private function rgar($array, $key) {
        if (isset($array[$key])) {
            return $array[$key];
        }

        if (strpos($key, '.') === false) {
            return null;
        }

        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $k) {
            if (!is_array($value) || !isset($value[$k])) {
                return null;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Handle form submission
     *
     * @param array $entry The entry object
     * @param array $form The form object
     */
    public function handle_form_submission($entry, $form) {
        // Debug log to check if hook is triggered
        error_log('Starsender GF: Form submission triggered - Form ID: ' . $form['id'] . ', Entry ID: ' . $entry['id']);

        // Check if this form is enabled
        $enabled_forms = get_option('sgf_enable_for_forms', []);

        error_log('Starsender GF: Enabled forms: ' . print_r($enabled_forms, true));
        error_log('Starsender GF: Is form ' . $form['id'] . ' enabled? ' . (in_array($form['id'], $enabled_forms) ? 'YES' : 'NO'));

        if (!in_array($form['id'], $enabled_forms)) {
            error_log('Starsender GF: Form ' . $form['id'] . ' is not enabled, skipping');
            return;
        }

        // Get settings
        $api_key = get_option('sgf_api_key', '');
        $admin_numbers = get_option('sgf_admin_numbers', '');

        error_log('Starsender GF: API Key: ' . (empty($api_key) ? 'EMPTY' : 'SET (' . strlen($api_key) . ' chars)'));
        error_log('Starsender GF: Admin Numbers: ' . (empty($admin_numbers) ? 'EMPTY' : 'SET'));

        if (empty($api_key) || empty($admin_numbers)) {
            error_log('Starsender GF: Missing API key or admin numbers, cannot send notification');
            return;
        }

        // Prepare the message
        $message = $this->prepare_message($entry, $form);
        error_log('Starsender GF: Message prepared: ' . substr($message, 0, 100) . '...');

        // Send to admin numbers
        $numbers = array_filter(array_map('trim', explode("\n", $admin_numbers)));
        error_log('Starsender GF: Sending to ' . count($numbers) . ' admin number(s)');
        $this->send_to_numbers($numbers, $message, $api_key, $entry, $form);

        // Send to customer if enabled
        if (get_option('sgf_send_to_customer', false)) {
            $customer_number = $this->get_customer_phone($entry, $form);
            if ($customer_number) {
                // Format the phone number for WhatsApp
                $formatted_number = $this->format_whatsapp_number($customer_number, $form);
                error_log('Starsender GF: Customer phone - Original: ' . $customer_number . ', Formatted: ' . $formatted_number);
                $customer_message = $this->prepare_customer_message($entry, $form);
                $this->send_to_numbers([$formatted_number], $customer_message, $api_key, $entry, $form);
            } else {
                error_log('Starsender GF: No phone field found in form');
            }
        }
    }

    /**
     * Send message to multiple numbers
     *
     * @param array $numbers Phone numbers
     * @param string $message Message content
     * @param string $api_key API key
     * @param array $entry Entry object
     * @param array $form Form object
     */
    private function send_to_numbers($numbers, $message, $api_key, $entry, $form) {
        $api = new SGF_Starsender_API($api_key);

        foreach ($numbers as $number) {
            error_log('Starsender GF: Sending message to ' . $number);
            $result = $api->send_message($number, $message);

            // Log result
            $this->log_result($number, $result, $entry, $form);

            // Small delay between messages to avoid rate limiting
            if (count($numbers) > 1) {
                usleep(500000); // 0.5 seconds
            }
        }
    }

    /**
     * Prepare message for admin
     *
     * @param array $entry The entry object
     * @param array $form The form object
     * @return string Formatted message
     */
    private function prepare_message($entry, $form) {
        $template = get_option('sgf_message_template', '');

        if (empty($template)) {
            $template = "📝 *New Form Submission*\n\n*Form:* {form_title}\n*Date:* {submission_date}\n\n{fields}\n\n---\n_Sent via Starsender for Gravity Forms_";
        }

        // Build fields output
        $fields_output = $this->build_fields_output($entry, $form);

        // Sanitize form title for message content
        $form_title = sanitize_text_field($form['title']);

        // Replace placeholders
        $replacements = [
            '{form_title}' => $form_title,
            '{submission_date}' => date('Y-m-d H:i:s', strtotime($entry['date_created'])),
            '{fields}' => $fields_output,
        ];

        // Replace custom field placeholders
        foreach ($form['fields'] as $field) {
            $value = $this->get_field_value($field, $entry);
            $label = sanitize_text_field($field['label']); // Sanitize label used as key
            $replacements['{field:' . $label . '}'] = $value;
            $replacements['{field:' . intval($field['id']) . '}'] = $value; // Ensure ID is integer
        }

        // Apply replacements
        $message = str_replace(array_keys($replacements), array_values($replacements), $template);

        // Additional sanitization for message content (remove potentially harmful characters for WhatsApp)
        $message = $this->sanitize_message_content($message);

        return $message;
    }

    /**
     * Prepare message for customer
     *
     * @param array $entry The entry object
     * @param array $form The form object
     * @return string Formatted message
     */
    private function prepare_customer_message($entry, $form) {
        $template = get_option('sgf_customer_message_template', '');

        if (empty($template)) {
            $template = "📋 *Copy of Your Submission*\n\nThank you for submitting the form \"*{form_title}*\".\n\nHere is a copy of your submission:\n\n{fields}\n\n---\n_Sent via Starsender for Gravity Forms_";
        }

        // Build fields output
        $fields_output = $this->build_fields_output($entry, $form);

        // Sanitize form title for message content
        $form_title = sanitize_text_field($form['title']);

        // Replace placeholders
        $replacements = [
            '{form_title}' => $form_title,
            '{submission_date}' => date('Y-m-d H:i:s', strtotime($entry['date_created'])),
            '{fields}' => $fields_output,
        ];

        // Apply replacements
        $message = str_replace(array_keys($replacements), array_values($replacements), $template);

        // Additional sanitization for message content
        $message = $this->sanitize_message_content($message);

        return $message;
    }

    /**
     * Sanitize message content for WhatsApp API
     * Removes potentially harmful characters while preserving WhatsApp formatting
     *
     * @param string $message Message content
     * @return string Sanitized message
     */
    private function sanitize_message_content($message) {
        // Remove control characters except newlines and tabs
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message);

        // Remove null bytes
        $message = str_replace("\0", '', $message);

        return $message;
    }

    /**
     * Build fields output for message
     *
     * @param array $entry The entry object
     * @param array $form The form object
     * @return string Formatted fields
     */
    private function build_fields_output($entry, $form) {
        $output = '';
        $excluded_field_types = ['section', 'page', 'html', 'captcha', 'password'];

        foreach ($form['fields'] as $field) {
            // Skip certain field types
            if (in_array($field['type'], $excluded_field_types)) {
                continue;
            }

            $value = $this->get_field_value($field, $entry);

            // Skip empty values
            if (empty($value) && $value !== '0') {
                continue;
            }

            // Sanitize field label (form configuration could be manipulated)
            $label = sanitize_text_field($field['label']);

            $output .= '*' . $label . ':* ' . $value . "\n\n";
        }

        return rtrim($output);
    }

    /**
     * Get field value from entry
     *
     * @param array $field The field object
     * @param array $entry The entry object
     * @return string Field value
     */
    private function get_field_value($field, $entry) {
        $value = $this->rgar($entry, $field['id']);

        // Handle different field types
        switch ($field['type']) {
            case 'select':
            case 'radio':
            case 'multiselect':
                // For select/radio, get the selected value
                $value = $this->rgar($entry, $field['id']);
                break;

            case 'checkbox':
                // For checkbox, get all checked values
                $value = $this->get_checkbox_values($field, $entry);
                break;

            case 'fileupload':
                // For file upload, get the file URL
                $value = $this->rgar($entry, $field['id']);
                break;

            case 'date':
                // Format date
                $value = $this->rgar($entry, $field['id']);
                if (!empty($value)) {
                    $value = date('Y-m-d', strtotime($value));
                }
                break;

            case 'time':
                // Format time
                $value = $this->rgar($entry, $field['id']);
                break;

            case 'number':
            case 'phone':
            case 'email':
            case 'website':
                $value = $this->rgar($entry, $field['id']);
                break;

            case 'name':
                // Combine name fields
                $value = $this->get_name_value($field, $entry);
                break;

            case 'address':
                // Format address
                $value = $this->get_address_value($field, $entry);
                break;

            default:
                $value = $this->rgar($entry, $field['id']);
        }

        // Convert to string and sanitize
        if (is_array($value)) {
            $value = implode(', ', $value);
        }

        return sanitize_text_field($value);
    }

    /**
     * Get checkbox values
     */
    private function get_checkbox_values($field, $entry) {
        $values = [];
        $inputs = $field['inputs'];

        if (is_array($inputs)) {
            foreach ($inputs as $input) {
                $val = $this->rgar($entry, $input['id']);
                if (!empty($val)) {
                    $values[] = $val;
                }
            }
        }

        return implode(', ', $values);
    }

    /**
     * Get name field value
     */
    private function get_name_value($field, $entry) {
        $name_parts = [];
        $inputs = $field['inputs'];

        if (is_array($inputs)) {
            foreach ($inputs as $input) {
                $val = $this->rgar($entry, $input['id']);
                if (!empty($val)) {
                    $name_parts[] = $val;
                }
            }
        }

        return implode(' ', $name_parts);
    }

    /**
     * Get address field value
     */
    private function get_address_value($field, $entry) {
        $address_parts = [];
        $inputs = $field['inputs'];

        if (is_array($inputs)) {
            foreach ($inputs as $input) {
                $val = $this->rgar($entry, $input['id']);
                if (!empty($val)) {
                    $address_parts[] = $val;
                }
            }
        }

        return implode(', ', $address_parts);
    }

    /**
     * Get customer phone number from entry
     *
     * @param array $entry The entry object
     * @param array $form The form object
     * @return string|null Phone number or null
     */
    private function get_customer_phone($entry, $form) {
        foreach ($form['fields'] as $field) {
            if ($field['type'] === 'phone') {
                return $this->rgar($entry, $field['id']);
            }
        }
        return null;
    }

    /**
     * Format phone number for WhatsApp
     *
     * @param string $phone Phone number from form
     * @param array $form The form object
     * @return string Formatted phone number for WhatsApp
     */
    private function format_whatsapp_number($phone, $form) {
        if (empty($phone)) {
            return '';
        }

        // Get form-specific country code setting
        $form_country_codes = get_option('sgf_form_country_codes', []);
        $default_country_code = '62'; // Default to Indonesia

        // Check if this form has a specific country code setting
        if (isset($form_country_codes[$form['id']]) && !empty($form_country_codes[$form['id']])) {
            $default_country_code = $form_country_codes[$form['id']];
        }

        // Get form-specific require international setting
        $form_require_international = get_option('sgf_form_require_international', []);
        $require_international = isset($form_require_international[$form['id']]) ? $form_require_international[$form['id']] : false;

        // Remove all non-numeric characters except +
        $phone = preg_replace('/[^\d+]/', '', $phone);

        // If starts with +, it's already in international format
        if (strpos($phone, '+') === 0) {
            // Remove the + for Starsender API
            return substr($phone, 1);
        }

        // If this is a multi-country form, log a warning for numbers without +
        if ($require_international) {
            error_log('Starsender GF: Multi-country form detected but number does not start with +. Using default country code ' . $default_country_code);
        }

        // If starts with 0, replace with country code
        if (strpos($phone, '0') === 0) {
            return $default_country_code . substr($phone, 1);
        }

        // Already in international format (without +)
        return $phone;
    }

    /**
     * Log send result
     *
     * @param string $number Phone number
     * @param array $result Send result
     * @param array $entry Entry object
     * @param array $form Form object
     */
    private function log_result($number, $result, $entry, $form) {
        $log_entry = [
            'form_id' => $form['id'],
            'entry_id' => $entry['id'],
            'phone' => $number,
            'success' => $result['success'],
            'message' => $result['message'] ?? '',
            'time' => current_time('mysql'),
        ];

        // Store log as a transient for debugging (expires after 7 days)
        $log_key = 'sgf_log_' . $entry['id'];
        $existing_log = get_transient($log_key);
        $log_data = is_array($existing_log) ? $existing_log : [];
        $log_data[] = $log_entry;

        set_transient($log_key, $log_data, WEEK_IN_SECONDS);

        // Log to WordPress error log
        error_log('Starsender GF: Send result to ' . $number . ' - ' . ($result['success'] ? 'SUCCESS' : 'FAILED') . ': ' . ($result['message'] ?? 'No message'));

        if (!$result['success']) {
            error_log('Starsender GF: Failed to send message - ' . print_r($log_entry, true));
        }
    }
}
