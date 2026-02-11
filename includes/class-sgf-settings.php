<?php
/**
 * Settings Page Handler
 *
 * Handles the plugin settings page and options
 */

if (!defined('ABSPATH')) {
    exit;
}

class SGF_Settings {

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
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_sgf_save_settings', [$this, 'save_main_settings']);
        add_action('admin_post_sgf_save_form_settings', [$this, 'save_form_settings']);
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('sgf_settings', 'sgf_api_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('sgf_settings', 'sgf_admin_numbers', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_admin_numbers'],
            'default' => '',
        ]);

        register_setting('sgf_settings', 'sgf_message_template', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => $this->get_default_template(),
        ]);

        register_setting('sgf_settings', 'sgf_customer_message_template', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => $this->get_default_customer_template(),
        ]);

        register_setting('sgf_settings', 'sgf_enable_for_forms', [
            'type' => 'array',
            'default' => [],
        ]);

        register_setting('sgf_settings', 'sgf_form_country_codes', [
            'type' => 'array',
            'default' => [],
        ]);

        register_setting('sgf_settings', 'sgf_form_require_international', [
            'type' => 'array',
            'default' => [],
        ]);

        register_setting('sgf_settings', 'sgf_send_to_customer', [
            'type' => 'boolean',
            'default' => false,
        ]);
    }

    /**
     * Sanitize admin numbers
     */
    public function sanitize_admin_numbers($input) {
        $lines = explode("\n", $input);
        $sanitized = [];

        foreach ($lines as $line) {
            $number = $this->format_phone_number(trim($line));
            // Validate minimum length after formatting
            if (!empty($number) && strlen($number) >= 10) {
                // Prevent duplicate numbers
                if (!in_array($number, $sanitized)) {
                    $sanitized[] = $number;
                }
            }
        }

        return implode("\n", $sanitized);
    }

    /**
     * Format phone number
     */
    private function format_phone_number($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Remove leading 0 and replace with 62 (Indonesia)
        if (substr($phone, 0, 1) === '0') {
            $phone = '62' . substr($phone, 1);
        }

        // Validate length
        if (strlen($phone) < 10 || strlen($phone) > 15) {
            return '';
        }

        return $phone;
    }

    /**
     * Get default message template for admin
     */
    private function get_default_template() {
        return "📝 *New Form Submission*

*Form:* {form_title}
*Date:* {submission_date}

{fields}

---
_Sent via Starsender for Gravity Forms_";
    }

    /**
     * Get default message template for customer
     */
    private function get_default_customer_template() {
        return "📋 *Copy of Your Submission*

Thank you for submitting the form \"*{form_title}*\".

Here is a copy of your submission:

{fields}

---
_Sent via Starsender for Gravity Forms_";
    }

    /**
     * Render main settings page
     */
    public function render_main_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $api_key = get_option('sgf_api_key', '');
        $admin_numbers = get_option('sgf_admin_numbers', '');
        $message_template = get_option('sgf_message_template', $this->get_default_template());
        $customer_message_template = get_option('sgf_customer_message_template', $this->get_default_customer_template());
        $send_to_customer = get_option('sgf_send_to_customer', false);

        // Show settings errors if any
        settings_errors('sgf_settings');
        ?>
        <div class="wrap sgf-settings-wrap">
            <h1>
                <?php _e('Starsender for Gravity Forms', 'starsender-gravity-forms'); ?>
            </h1>

            <div class="sgf-settings-container">
                <!-- Main Settings Form -->
                <div class="sgf-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 10px; border-bottom: 1px solid #ddd;">
                        <h2 style="margin: 0; padding: 0; border: none; font-size: 1.3em;"><?php _e('API Configuration', 'starsender-gravity-forms'); ?></h2>
                        <button type="button" class="button button-secondary" id="sgf-test-btn">
                            <span class="dashicons dashicons-controls-play" style="vertical-align: middle;"></span>
                            <?php _e('Test Connection', 'starsender-gravity-forms'); ?>
                        </button>
                    </div>

                    <div id="sgf-test-result" style="margin: 15px 0; display: none;">
                    </div>

                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="sgf_save_settings">
                        <?php wp_nonce_field('sgf_save_settings_nonce', 'sgf_save_settings_nonce'); ?>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="sgf_api_key">
                                        <?php _e('API Key', 'starsender-gravity-forms'); ?>
                                        <span class="required">*</span>
                                    </label>
                                </th>
                                <td>
                                    <input type="text"
                                           id="sgf_api_key"
                                           name="sgf_api_key"
                                           value="<?php echo esc_attr($api_key); ?>"
                                           class="regular-text"
                                           placeholder="<?php _e('Enter your Starsender API Key', 'starsender-gravity-forms'); ?>"
                                           required>
                                    <p class="description">
                                        <?php _e('Enter your Starsender Device API Key. You can get it from your Starsender dashboard.', 'starsender-gravity-forms'); ?>
                                        <a href="https://app.starsender.online/" target="_blank"><?php _e('Get API Key', 'starsender-gravity-forms'); ?> &rarr;</a>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="sgf_admin_numbers">
                                        <?php _e('Admin WhatsApp Numbers', 'starsender-gravity-forms'); ?>
                                        <span class="required">*</span>
                                    </label>
                                </th>
                                <td>
                                    <textarea id="sgf_admin_numbers"
                                              name="sgf_admin_numbers"
                                              rows="5"
                                              class="large-text"
                                              placeholder="<?php echo esc_attr("628123456789\n628987654321"); ?>"
                                              required><?php echo esc_textarea($admin_numbers); ?></textarea>
                                    <p class="description">
                                        <?php _e('Enter WhatsApp numbers (one per line) that will receive form submission notifications. Use international format without + or 0 at the beginning (e.g., 628123456789 for Indonesia).', 'starsender-gravity-forms'); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="sgf_message_template">
                                        <?php _e('Admin Message Template', 'starsender-gravity-forms'); ?>
                                    </label>
                                </th>
                                <td>
                                    <textarea id="sgf_message_template"
                                              name="sgf_message_template"
                                              rows="10"
                                              class="large-text code"><?php echo esc_textarea($message_template); ?></textarea>
                                    <p class="description">
                                        <?php _e('Available placeholders:', 'starsender-gravity-forms'); ?>
                                        <code>{form_title}</code>,
                                        <code>{submission_date}</code>,
                                        <code>{fields}</code>,
                                        <code>{field:label}</code> (e.g., <code>{field:Email}</code>)
                                    </p>
                                    <button type="button" class="button button-secondary" onclick="sgfRestoreDefaultTemplate()">
                                        <?php _e('Restore Default Template', 'starsender-gravity-forms'); ?>
                                    </button>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="sgf_send_to_customer">
                                        <?php _e('Send Copy to Customer', 'starsender-gravity-forms'); ?>
                                    </label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                               id="sgf_send_to_customer"
                                               name="sgf_send_to_customer"
                                               value="1"
                                               <?php checked($send_to_customer, true); ?>>
                                        <?php _e('Send a copy of the form submission to the customer\'s WhatsApp number', 'starsender-gravity-forms'); ?>
                                    </label>
                                    <p class="description">
                                        <?php _e('If enabled, the customer will also receive a WhatsApp message with their form submission details. You need to have a phone field in your form for this to work.', 'starsender-gravity-forms'); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="sgf_customer_message_template">
                                        <?php _e('Customer Message Template', 'starsender-gravity-forms'); ?>
                                    </label>
                                </th>
                                <td>
                                    <textarea id="sgf_customer_message_template"
                                              name="sgf_customer_message_template"
                                              rows="10"
                                              class="large-text code"><?php echo esc_textarea($customer_message_template); ?></textarea>
                                    <p class="description">
                                        <?php _e('Available placeholders:', 'starsender-gravity-forms'); ?>
                                        <code>{form_title}</code>,
                                        <code>{submission_date}</code>,
                                        <code>{fields}</code>,
                                        <code>{field:label}</code> (e.g., <code>{field:Email}</code>)
                                    </p>
                                    <button type="button" class="button button-secondary" onclick="sgfRestoreDefaultCustomerTemplate()">
                                        <?php _e('Restore Default Template', 'starsender-gravity-forms'); ?>
                                    </button>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(__('Save Settings', 'starsender-gravity-forms'), 'primary'); ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render enable forms page
     */
    public function render_enable_forms_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get all Gravity Forms
        $forms = $this->get_gravity_forms();
        $enabled_forms = get_option('sgf_enable_for_forms', []);
        $form_country_codes = get_option('sgf_form_country_codes', []);
        $form_require_international = get_option('sgf_form_require_international', []);

        // Show settings errors if any
        settings_errors('sgf_settings');
        ?>
        <div class="wrap sgf-settings-wrap">
            <h1>
                <?php _e('Enable for Forms', 'starsender-gravity-forms'); ?>
            </h1>

            <div class="sgf-settings-container">
                <!-- Enabled Forms Card -->
                <div class="sgf-card">
                    <h2><?php _e('Select Forms to Enable WhatsApp Notifications', 'starsender-gravity-forms'); ?></h2>

                    <p class="description">
                        <?php _e('Enable forms to send WhatsApp notifications. Configure country code and international format requirement for customer phone numbers.', 'starsender-gravity-forms'); ?>
                    </p>

                    <?php if (empty($forms)) : ?>
                        <p><?php _e('No Gravity Forms found. Create a form first to enable WhatsApp notifications.', 'starsender-gravity-forms'); ?></p>
                        <p>
                            <a href="<?php echo admin_url('admin.php?page=gf_new_form'); ?>" class="button button-primary">
                                <?php _e('Create New Form', 'starsender-gravity-forms'); ?>
                            </a>
                        </p>
                    <?php else : ?>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <input type="hidden" name="action" value="sgf_save_form_settings">
                            <?php wp_nonce_field('sgf_save_form_settings_nonce', 'sgf_save_form_settings_nonce'); ?>

                            <table class="wp-list-table widefat fixed striped sgf-forms-table">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;"><?php _e('Enabled', 'starsender-gravity-forms'); ?></th>
                                        <th><?php _e('Form Title', 'starsender-gravity-forms'); ?></th>
                                        <th style="width: 100px;"><?php _e('Form ID', 'starsender-gravity-forms'); ?></th>
                                        <th style="width: 200px;"><?php _e('Phone Settings', 'starsender-gravity-forms'); ?></th>
                                        <th style="width: 100px;"><?php _e('Status', 'starsender-gravity-forms'); ?></th>
                                        <th style="width: 100px;"><?php _e('Entries', 'starsender-gravity-forms'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($forms as $form) :
                                        $is_enabled = in_array($form['id'], $enabled_forms);
                                        $status_class = $is_enabled ? 'sgf-status-enabled' : 'sgf-status-disabled';
                                        $status_text = $is_enabled ? __('Enabled', 'starsender-gravity-forms') : __('Disabled', 'starsender-gravity-forms');
                                        $country_code = isset($form_country_codes[$form['id']]) ? $form_country_codes[$form['id']] : '62';
                                        $require_international = isset($form_require_international[$form['id']]) ? $form_require_international[$form['id']] : false;
                                        ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox"
                                                       name="sgf_enabled_forms[]"
                                                       value="<?php echo esc_attr($form['id']); ?>"
                                                       <?php checked($is_enabled); ?>>
                                            </td>
                                            <td>
                                                <strong><?php echo esc_html($form['title']); ?></strong>
                                            </td>
                                            <td><?php echo esc_html($form['id']); ?></td>
                                            <td>
                                                <div style="margin-bottom: 10px;">
                                                    <label style="font-size: 12px; font-weight: 600;">
                                                        <input type="checkbox"
                                                               name="sgf_require_international[<?php echo esc_attr($form['id']); ?>]"
                                                               value="1"
                                                               <?php checked($require_international); ?>>
                                                        <?php _e('Multi-Country Form', 'starsender-gravity-forms'); ?>
                                                    </label>
                                                    <p class="description" style="margin: 0 0 5px 0;">
                                                        <?php _e('Require customers to enter number with + (e.g., +628xxx)', 'starsender-gravity-forms'); ?>
                                                    </p>
                                                </div>
                                                <div style="<?php echo $require_international ? 'opacity: 0.5;' : ''; ?>">
                                                    <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 3px;">
                                                        <?php _e('Default Country Code:', 'starsender-gravity-forms'); ?>
                                                    </label>
                                                    <input type="text"
                                                           name="sgf_country_codes[<?php echo esc_attr($form['id']); ?>]"
                                                           value="<?php echo esc_attr($country_code); ?>"
                                                           class="small-text"
                                                           placeholder="62"
                                                           <?php disabled($require_international); ?>>
                                                    <p class="description" style="margin: 0;">
                                                        <?php _e('Used when customer enters without +', 'starsender-gravity-forms'); ?>
                                                    </p>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="sgf-status-badge <?php echo $status_class; ?>">
                                                    <?php echo esc_html($status_text); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="<?php echo admin_url('admin.php?page=gf_entries&id=' . $form['id']); ?>">
                                                    <?php echo esc_html($form['entries'] ?? 0); ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <?php submit_button(__('Save Form Settings', 'starsender-gravity-forms'), 'primary'); ?>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Phone Format Info Card -->
                <div class="sgf-card">
                    <h2><?php _e('Phone Number Format Information', 'starsender-gravity-forms'); ?></h2>

                    <h3><?php _e('Single Country vs Multi-Country Forms:', 'starsender-gravity-forms'); ?></h3>

                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Form Type', 'starsender-gravity-forms'); ?></th>
                                <th><?php _e('Customer Input', 'starsender-gravity-forms'); ?></th>
                                <th><?php _e('Result', 'starsender-gravity-forms'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td rowspan="3"><strong><?php _e('Single Country', 'starsender-gravity-forms'); ?></strong><br><small>Default Country Code: 62</small></td>
                                <td><code>085312345678</code></td>
                                <td><code>6285312345678</code> <span class="dashicons dashicons-yes" style="color: green;"></span></td>
                            </tr>
                            <tr>
                                <td><code>6285312345678</code></td>
                                <td><code>6285312345678</code> <span class="dashicons dashicons-yes" style="color: green;"></span></td>
                            </tr>
                            <tr>
                                <td><code>+6285312345678</code></td>
                                <td><code>6285312345678</code> <span class="dashicons dashicons-yes" style="color: green;"></span></td>
                            </tr>
                            <tr>
                                <td rowspan="3"><strong><?php _e('Multi-Country', 'starsender-gravity-forms'); ?></strong><br><small>Require International Format: ON</small></td>
                                <td><code>085312345678</code></td>
                                <td><code>6285312345678</code> <span class="dashicons dashicons-warning" style="color: orange;"></span></td>
                            </tr>
                            <tr>
                                <td><code>+6285312345678</code></td>
                                <td><code>6285312345678</code> <span class="dashicons dashicons-yes" style="color: green;"></span></td>
                            </tr>
                            <tr>
                                <td><code>+60123456789</code></td>
                                <td><code>60123456789</code> <span class="dashicons dashicons-yes" style="color: green;"></span></td>
                            </tr>
                            <tr>
                                <td rowspan="2"><strong><?php _e('International', 'starsender-gravity-forms'); ?></strong></td>
                                <td><code>+441234567890</code></td>
                                <td><code>441234567890</code> <span class="dashicons dashicons-yes" style="color: green;"></span></td>
                            </tr>
                            <tr>
                                <td><code>+11234567890</code></td>
                                <td><code>11234567890</code> <span class="dashicons dashicons-yes" style="color: green;"></span></td>
                            </tr>
                        </tbody>
                    </table>

                    <div style="background: #fffbe0; border-left: 4px solid #dba617; padding: 12px; margin-top: 20px;">
                        <h4 style="margin-top: 0;"><?php _e('Recommendation for Multi-Country Forms:', 'starsender-gravity-forms'); ?></h4>
                        <p style="margin-bottom: 0;">
                            <?php _e('<strong>Enable "Multi-Country Form"</strong> checkbox and add instructions in your phone field placeholder like: "Enter with country code (e.g., +628xxx for Indonesia, +60xxx for Malaysia)"', 'starsender-gravity-forms'); ?>
                        </p>
                    </div>

                    <h3 style="margin-top: 20px;"><?php _e('Common Country Codes:', 'starsender-gravity-forms'); ?></h3>
                    <ul style="list-style: none; padding: 0; display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">
                        <li><code>62</code> - Indonesia</li>
                        <li><code>60</code> - Malaysia</li>
                        <li><code>65</code> - Singapore</li>
                        <li><code>61</code> - Australia</li>
                        <li><code>44</code> - United Kingdom</li>
                        <li><code>1</code> - USA/Canada</li>
                        <li><code>91</code> - India</li>
                        <li><code>86</code> - China</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Save main settings
     */
    public function save_main_settings() {
        if (!check_admin_referer('sgf_save_settings_nonce', 'sgf_save_settings_nonce')) {
            wp_die(__('Security check failed', 'starsender-gravity-forms'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'starsender-gravity-forms'));
        }

        // Save settings
        if (isset($_POST['sgf_api_key'])) {
            update_option('sgf_api_key', sanitize_text_field($_POST['sgf_api_key']));
        }

        if (isset($_POST['sgf_admin_numbers'])) {
            update_option('sgf_admin_numbers', $this->sanitize_admin_numbers($_POST['sgf_admin_numbers']));
        }

        if (isset($_POST['sgf_message_template'])) {
            update_option('sgf_message_template', sanitize_textarea_field($_POST['sgf_message_template']));
        }

        if (isset($_POST['sgf_customer_message_template'])) {
            update_option('sgf_customer_message_template', sanitize_textarea_field($_POST['sgf_customer_message_template']));
        }

        if (isset($_POST['sgf_send_to_customer'])) {
            update_option('sgf_send_to_customer', true);
        } else {
            update_option('sgf_send_to_customer', false);
        }

        add_settings_error('sgf_settings', 'settings_saved', __('Settings saved successfully.', 'starsender-gravity-forms'), 'updated');

        // Redirect back to settings page
        wp_redirect(admin_url('admin.php?page=starsender-gravity-forms&settings-updated=true'));
        exit;
    }

    /**
     * Save form settings
     */
    public function save_form_settings() {
        if (!check_admin_referer('sgf_save_form_settings_nonce', 'sgf_save_form_settings_nonce')) {
            wp_die(__('Security check failed', 'starsender-gravity-forms'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'starsender-gravity-forms'));
        }

        // Save enabled forms - validate all form IDs
        $enabled_forms = isset($_POST['sgf_enabled_forms']) ? $_POST['sgf_enabled_forms'] : [];
        $sanitized_enabled_forms = [];
        if (is_array($enabled_forms)) {
            foreach ($enabled_forms as $form_id) {
                $form_id = intval($form_id);
                if ($form_id > 0) {
                    $sanitized_enabled_forms[] = $form_id;
                }
            }
        }
        update_option('sgf_enable_for_forms', $sanitized_enabled_forms);

        // Save country codes per form
        $form_country_codes = isset($_POST['sgf_country_codes']) ? $_POST['sgf_country_codes'] : [];
        $sanitized_country_codes = [];

        if (is_array($form_country_codes)) {
            foreach ($form_country_codes as $form_id => $country_code) {
                // Validate form ID
                $form_id = intval($form_id);
                if ($form_id > 0) {
                    // Sanitize: only numbers allowed, max 3 digits (country codes)
                    $country_code = preg_replace('/[^0-9]/', '', $country_code);
                    $country_code = substr($country_code, 0, 3); // Max 3 digits
                    if (!empty($country_code)) {
                        $sanitized_country_codes[$form_id] = $country_code;
                    }
                }
            }
        }

        update_option('sgf_form_country_codes', $sanitized_country_codes);

        // Save require international format per form
        $form_require_international = isset($_POST['sgf_require_international']) ? $_POST['sgf_require_international'] : [];
        $sanitized_require_international = [];

        if (is_array($form_require_international)) {
            foreach ($form_require_international as $form_id => $value) {
                $form_id = intval($form_id);
                if ($form_id > 0) {
                    $sanitized_require_international[$form_id] = true;
                }
            }
        }

        update_option('sgf_form_require_international', $sanitized_require_international);

        add_settings_error('sgf_settings', 'form_settings_saved', __('Form settings saved successfully. ' . count($sanitized_enabled_forms) . ' form(s) enabled.', 'starsender-gravity-forms'), 'updated');

        // Redirect back to enable forms page
        wp_redirect(admin_url('admin.php?page=starsender-enable-forms&settings-updated=true'));
        exit;
    }

    /**
     * Get all Gravity Forms
     */
    private function get_gravity_forms() {
        if (!class_exists('GFAPI')) {
            return [];
        }

        $forms = \GFAPI::get_forms();
        $result = [];

        foreach ($forms as $form) {
            $result[] = [
                'id' => $form['id'],
                'title' => $form['title'],
                'entries' => \GFAPI::count_entries($form['id']),
            ];
        }

        return $result;
    }
}
