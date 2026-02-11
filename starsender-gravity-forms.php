<?php
/**
 * Plugin Name: Starsender for Gravity Forms
 * Plugin URI: https://github.com/stprasst/Starsender-Gravityform
 * Description: Send Gravity Forms submissions to WhatsApp admin using Starsender API
 * Version: 1.2.0
 * Author: Stefanus Eko Prasetyo
 * Author URI: https://github.com/stprasst
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: starsender-gravity-forms
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SGF_VERSION', '1.2.0');
define('SGF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SGF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SGF_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Starsender Gravity Forms Plugin Class
 */
class Starsender_Gravity_Forms_Plugin {

    /**
     * Single instance of the class
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
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once SGF_PLUGIN_DIR . 'includes/class-sgf-starsender-api.php';
        require_once SGF_PLUGIN_DIR . 'includes/class-sgf-settings.php';
        require_once SGF_PLUGIN_DIR . 'includes/class-sgf-form-handler.php';
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('plugins_loaded', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_sgf_test_connection', [$this, 'ajax_test_connection']);
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Check if Gravity Forms is active
        if (!class_exists('GFAPI') && !class_exists('GFForms')) {
            add_action('admin_notices', [$this, 'gravity_forms_not_active_notice']);
            return;
        }

        // Initialize classes
        SGF_Settings::get_instance();
        SGF_Form_Handler::get_instance();

        // Load plugin text domain
        load_plugin_textdomain('starsender-gravity-forms', false, dirname(SGF_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Main menu - Settings
        add_menu_page(
            __('Starsender for Gravity Forms', 'starsender-gravity-forms'),
            __('Starsender GF', 'starsender-gravity-forms'),
            'manage_options',
            'starsender-gravity-forms',
            [$this, 'render_settings_page'],
            'dashicons-whatsapp',
            30
        );

        // Submenu - Settings (same as main)
        add_submenu_page(
            'starsender-gravity-forms',
            __('Settings', 'starsender-gravity-forms'),
            __('Settings', 'starsender-gravity-forms'),
            'manage_options',
            'starsender-gravity-forms',
            [$this, 'render_settings_page']
        );

        // Submenu - Enable for Forms
        add_submenu_page(
            'starsender-gravity-forms',
            __('Enable for Forms', 'starsender-gravity-forms'),
            __('Enable for Forms', 'starsender-gravity-forms'),
            'manage_options',
            'starsender-enable-forms',
            [$this, 'render_enable_forms_page']
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ('toplevel_page_starsender-gravity-forms' !== $hook && 'starsender-gravity-forms_page_starsender-enable-forms' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'sgf-admin-style',
            SGF_PLUGIN_URL . 'assets/css/admin-style.css',
            [],
            SGF_VERSION
        );

        wp_enqueue_script(
            'sgf-admin-script',
            SGF_PLUGIN_URL . 'assets/js/admin-script.js',
            ['jquery'],
            SGF_VERSION,
            true
        );

        wp_localize_script('sgf-admin-script', 'sgfAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sgf_nonce'),
            'strings' => [
                'testing' => __('Testing connection...', 'starsender-gravity-forms'),
                'success' => __('Connection successful!', 'starsender-gravity-forms'),
                'error' => __('Connection failed!', 'starsender-gravity-forms'),
                'apiKeyRequired' => __('API Key is required', 'starsender-gravity-forms'),
                'adminNumberRequired' => __('At least one admin number is required', 'starsender-gravity-forms'),
                'testConnection' => __('Test Connection', 'starsender-gravity-forms'),
                'restoreTemplate' => __('Are you sure you want to restore the default message template? Your current template will be replaced.', 'starsender-gravity-forms'),
            ]
        ]);
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        SGF_Settings::get_instance()->render_main_settings_page();
    }

    /**
     * Render enable forms page
     */
    public function render_enable_forms_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        SGF_Settings::get_instance()->render_enable_forms_page();
    }

    /**
     * AJAX test connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('sgf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starsender-gravity-forms')]);
        }

        // Rate limiting: max 5 tests per minute per user
        $user_id = get_current_user_id();
        $rate_limit_key = 'sgf_test_rate_limit_' . $user_id;
        $test_count = get_transient($rate_limit_key);

        if ($test_count !== false && $test_count >= 5) {
            wp_send_json_error(['message' => __('Too many test attempts. Please wait a minute before trying again.', 'starsender-gravity-forms')]);
        }

        // Increment rate limit counter
        if ($test_count === false) {
            set_transient($rate_limit_key, 1, 60); // 1 test, expires in 60 seconds
        } else {
            set_transient($rate_limit_key, $test_count + 1, 60);
        }

        // Sanitize and validate inputs
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $admin_numbers = isset($_POST['admin_numbers']) ? sanitize_textarea_field($_POST['admin_numbers']) : '';

        if (empty($api_key)) {
            wp_send_json_error(['message' => __('API Key is required', 'starsender-gravity-forms')]);
        }

        // Validate API key format
        if (!SGF_Starsender_API::validate_api_key($api_key)) {
            wp_send_json_error(['message' => __('Invalid API Key format', 'starsender-gravity-forms')]);
        }

        $numbers = array_filter(array_map('trim', explode("\n", $admin_numbers)));
        if (empty($numbers)) {
            wp_send_json_error(['message' => __('At least one admin number is required', 'starsender-gravity-forms')]);
        }

        // Validate phone numbers
        $valid_numbers = [];
        foreach ($numbers as $number) {
            // Remove all non-numeric characters for validation
            $clean_number = preg_replace('/[^0-9]/', '', $number);
            if (strlen($clean_number) >= 10 && strlen($clean_number) <= 15) {
                $valid_numbers[] = $number;
            }
        }

        if (empty($valid_numbers)) {
            wp_send_json_error(['message' => __('No valid phone numbers provided', 'starsender-gravity-forms')]);
        }

        $api = new SGF_Starsender_API($api_key);

        $site_name = sanitize_text_field(get_bloginfo('name'));
        $timestamp = sanitize_text_field(current_time('Y-m-d H:i:s'));

        $test_message = sprintf(
            __("Connectivity test passed. %s is now successfully integrated with WhatsApp. The Starsender global config is enabled, and messages are being delivered without issues.\n\nRef Id: %s", 'starsender-gravity-forms'),
            $site_name,
            $timestamp
        );

        $results = [];
        $all_success = true;

        foreach ($valid_numbers as $number) {
            $result = $api->send_message($number, $test_message);
            $results[$number] = $result;

            if (!$result['success']) {
                $all_success = false;
            }
        }

        if ($all_success) {
            wp_send_json_success([
                'message' => __('Test message sent to all admin numbers', 'starsender-gravity-forms'),
                'details' => $results
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Some messages failed to send', 'starsender-gravity-forms'),
                'details' => $results
            ]);
        }
    }

    /**
     * Show notice if Gravity Forms is not active
     */
    public function gravity_forms_not_active_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('<strong>Starsender for Gravity Forms</strong> requires Gravity Forms to be installed and activated.', 'starsender-gravity-forms'); ?></p>
        </div>
        <?php
    }
}

/**
 * Initialize the plugin
 */
function sgf_init() {
    return Starsender_Gravity_Forms_Plugin::get_instance();
}

// Start the plugin
sgf_init();
