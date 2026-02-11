<?php
/**
 * Starsender API Handler
 *
 * Handles all communication with Starsender API
 */

if (!defined('ABSPATH')) {
    exit;
}

class SGF_Starsender_API {

    /**
     * API Base URL
     */
    const API_URL = 'https://api.starsender.online/api';

    /**
     * Device API Key
     */
    private $api_key;

    /**
     * Constructor
     */
    public function __construct($api_key = null) {
        if ($api_key === null) {
            $api_key = get_option('sgf_api_key', '');
        }
        $this->api_key = $api_key;
    }

    /**
     * Set API Key
     */
    public function set_api_key($api_key) {
        $this->api_key = $api_key;
        return $this;
    }

    /**
     * Send message via Starsender API
     *
     * @param string $to Destination phone number (with country code, e.g., 628123456789)
     * @param string $message Message content
     * @param string $message_type Message type (text, image, document, etc.)
     * @param string|null $file_url Optional file URL for media messages
     * @param int|null $delay Optional delay in milliseconds
     * @param int|null $schedule Optional schedule timestamp in milliseconds
     * @return array Response with success status
     */
    public function send_message($to, $message, $message_type = 'text', $file_url = null, $delay = null, $schedule = null) {
        // Sanitize inputs
        $to = $this->sanitize_phone_number($to);
        $message = sanitize_text_field($message);
        $message_type = sanitize_text_field($message_type);

        // Validate message type
        $allowed_types = ['text', 'image', 'document', 'video', 'audio'];
        if (!in_array($message_type, $allowed_types)) {
            $message_type = 'text';
        }

        $endpoint = self::API_URL . '/send';

        $body = [
            'messageType' => $message_type,
            'to' => $to,
            'body' => $message,
        ];

        if ($file_url !== null) {
            $body['file'] = esc_url_raw($file_url);
        }

        if ($delay !== null) {
            $body['delay'] = intval($delay);
        }

        if ($schedule !== null) {
            $body['schedule'] = intval($schedule);
        }

        $response = $this->make_request('POST', $endpoint, $body);

        return $response;
    }

    /**
     * Get message details
     *
     * @param string $message_id Message ID from Starsender
     * @return array Message details
     */
    public function get_message($message_id) {
        $endpoint = self::API_URL . '/messages/' . $message_id;

        return $this->make_request('GET', $endpoint);
    }

    /**
     * Make HTTP request to Starsender API
     *
     * @param string $method HTTP method (GET or POST)
     * @param string $endpoint API endpoint
     * @param array $body Request body for POST requests
     * @return array Response with success status
     */
    private function make_request($method, $endpoint, $body = []) {
        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => $this->api_key,
            ],
            'timeout' => 30,
        ];

        if ($method === 'POST') {
            $args['body'] = json_encode($body);
            $response = wp_remote_post($endpoint, $args);
        } else {
            $response = wp_remote_get($endpoint, $args);
        }

        // Check for errors
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => __('Invalid JSON response from API', 'starsender-gravity-forms'),
                'raw_response' => $body,
            ];
        }

        // Check if API returned success
        if (isset($data['success']) && $data['success'] === true) {
            return [
                'success' => true,
                'data' => $data['data'] ?? [],
                'message' => $data['message'] ?? __('Message sent successfully', 'starsender-gravity-forms'),
            ];
        }

        // API returned error
        return [
            'success' => false,
            'message' => $data['message'] ?? __('Unknown error', 'starsender-gravity-forms'),
            'data' => $data,
        ];
    }

    /**
     * Format phone number to remove special characters
     *
     * @param string $phone Phone number
     * @return string Formatted phone number
     */
    private function format_phone_number($phone) {
        return $this->sanitize_phone_number($phone);
    }

    /**
     * Sanitize phone number
     *
     * @param string $phone Phone number
     * @return string Sanitized phone number
     */
    private function sanitize_phone_number($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Remove leading 0 and replace with country code if not present
        // This assumes Indonesian numbers (62)
        if (substr($phone, 0, 1) === '0') {
            $phone = '62' . substr($phone, 1);
        }

        // Validate minimum length (country code + at least 7 digits)
        if (strlen($phone) < 10) {
            return '';
        }

        // Validate maximum length (max 15 digits for international numbers)
        if (strlen($phone) > 15) {
            return substr($phone, 0, 15);
        }

        return $phone;
    }

    /**
     * Validate API key format
     *
     * @param string $api_key API key to validate
     * @return bool True if valid format
     */
    public static function validate_api_key($api_key) {
        // API key should be alphanumeric, 20-100 characters
        return !empty($api_key) &&
               is_string($api_key) &&
               strlen($api_key) >= 20 &&
               strlen($api_key) <= 100 &&
               ctype_alnum($api_key);
    }

    /**
     * Test connection with Starsender API
     *
     * @param string $api_key API key to test
     * @return array Test result
     */
    public static function test_connection($api_key) {
        $api = new self($api_key);

        // Try to get devices to verify connection
        $endpoint = self::API_URL . '/devices';

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => $api_key,
            ],
            'timeout' => 30,
        ];

        $response = wp_remote_get($endpoint, $args);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => __('Invalid JSON response from API', 'starsender-gravity-forms'),
            ];
        }

        // Check if API returned success
        if (isset($data['success']) && $data['success'] === true) {
            return [
                'success' => true,
                'message' => __('Connection successful', 'starsender-gravity-forms'),
                'data' => $data['data'] ?? [],
            ];
        }

        return [
            'success' => false,
            'message' => $data['message'] ?? __('Connection failed', 'starsender-gravity-forms'),
        ];
    }
}
