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

        register_setting('sgf_settings', 'sgf_enable_for_forms', [
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
            if (!empty($number)) {
                $sanitized[] = $number;
            }
        }

        return implode("\n", array_unique($sanitized));
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

        return $phone;
    }

    /**
     * Get default message template
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
     * Render settings page
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $api_key = get_option('sgf_api_key', '');
        $admin_numbers = get_option('sgf_admin_numbers', '');
        $message_template = get_option('sgf_message_template', $this->get_default_template());
        $send_to_customer = get_option('sgf_send_to_customer', false);

        // Get all Gravity Forms
        $forms = $this->get_gravity_forms();
        $enabled_forms = get_option('sgf_enable_for_forms', []);

        // Show settings errors if any
        settings_errors('sgf_settings');
        ?>
        <div class="wrap sgf-settings-wrap">
            <h1>
                <span class="dashicons dashicons-whatsapp" style="font-size: 1.5em; vertical-align: middle; margin-right: 10px;"></span>
                <?php _e('Starsender for Gravity Forms', 'starsender-gravity-forms'); ?>
            </h1>

            <div class="sgf-settings-container">
                <!-- Main Settings Form -->
                <div class="sgf-card">
                    <h2><?php _e('API Configuration', 'starsender-gravity-forms'); ?></h2>

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
                                        <?php _e('Message Template', 'starsender-gravity-forms'); ?>
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
                        </table>

                        <?php submit_button(__('Save Settings', 'starsender-gravity-forms'), 'primary'); ?>
                    </form>
                </div>

                <!-- Test Connection Card -->
                <div class="sgf-card">
                    <h2><?php _e('Test Connection', 'starsender-gravity-forms'); ?></h2>

                    <p><?php _e('Test your Starsender API connection by sending a test message to your admin numbers.', 'starsender-gravity-forms'); ?></p>

                    <div id="sgf-test-connection">
                        <button type="button" class="button button-primary button-large" id="sgf-test-btn">
                            <span class="dashicons dashicons-controls-play"></span>
                            <?php _e('Test Connection', 'starsender-gravity-forms'); ?>
                        </button>

                        <div id="sgf-test-result" style="margin-top: 15px; display: none;">
                            <div class="notice" style="padding: 10px;"></div>
                        </div>
                    </div>
                </div>

                <!-- Enabled Forms Card -->
                <div class="sgf-card">
                    <h2><?php _e('Enable for Forms', 'starsender-gravity-forms'); ?></h2>

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
                                        <th style="width: 100px;"><?php _e('Status', 'starsender-gravity-forms'); ?></th>
                                        <th style="width: 100px;"><?php _e('Entries', 'starsender-gravity-forms'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($forms as $form) :
                                        $is_enabled = in_array($form['id'], $enabled_forms);
                                        $status_class = $is_enabled ? 'sgf-status-enabled' : 'sgf-status-disabled';
                                        $status_text = $is_enabled ? __('Enabled', 'starsender-gravity-forms') : __('Disabled', 'starsender-gravity-forms');
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

                <!-- Help Card -->
                <div class="sgf-card">
                    <h2><?php _e('Need Help?', 'starsender-gravity-forms'); ?></h2>

                    <h3><?php _e('Documentation', 'starsender-gravity-forms'); ?></h3>
                    <ul>
                        <li><a href="https://docs.starsender.online/" target="_blank"><?php _e('Starsender API Documentation', 'starsender-gravity-forms'); ?> &rarr;</a></li>
                        <li><a href="https://docs.gravityforms.com/" target="_blank"><?php _e('Gravity Forms Documentation', 'starsender-gravity-forms'); ?> &rarr;</a></li>
                    </ul>

                    <h3><?php _e('Support', 'starsender-gravity-forms'); ?></h3>
                    <p><?php _e('If you need help, please visit our GitHub repository.', 'starsender-gravity-forms'); ?></p>
                    <p>
                        <a href="https://github.com/yayasanvitka/starsender-gravity-forms/issues" target="_blank" class="button button-secondary">
                            <?php _e('Get Support', 'starsender-gravity-forms'); ?> &rarr;
                        </a>
                    </p>
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

        // Save enabled forms
        $enabled_forms = isset($_POST['sgf_enabled_forms']) ? array_map('intval', $_POST['sgf_enabled_forms']) : [];
        update_option('sgf_enable_for_forms', $enabled_forms);

        add_settings_error('sgf_settings', 'form_settings_saved', __('Form settings saved successfully. ' . count($enabled_forms) . ' form(s) enabled.', 'starsender-gravity-forms'), 'updated');

        // Redirect back to settings page
        wp_redirect(admin_url('admin.php?page=starsender-gravity-forms&settings-updated=true'));
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
