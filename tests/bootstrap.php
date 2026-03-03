<?php

// Mock WordPress ABSPATH if not defined.
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// Mock WP_Error.
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code;
        public $message;
        public $data;

        public function __construct($code = '', $message = '', $data = '') {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
}

// Mock WordPress global functions.
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return ($thing instanceof WP_Error);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('wp_remote_request')) {
    $mock_responses = [];

    function set_mock_response($url, $response) {
        global $mock_responses;
        $mock_responses[$url] = $response;
    }

    function wp_remote_request($url, $args = []) {
        global $mock_responses;
        foreach ($mock_responses as $mock_url => $response) {
            if (strpos($url, $mock_url) !== false) {
                return $response;
            }
        }
        return new WP_Error('http_request_failed', 'A generic error message');
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        if (is_wp_error($response)) {
            return 0;
        }
        return $response['response']['code'] ?? 200;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        if (is_wp_error($response)) {
            return '';
        }
        return $response['body'] ?? '';
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        if ($option === 'chatbot_gemini_api_key') {
            return 'test-api-key';
        }
        return $default;
    }
}

// Mock PHPUnit's PHPUnit\Framework\TestCase if it doesn't exist.
if (!class_exists('PHPUnit\Framework\TestCase')) {
    eval('namespace PHPUnit\Framework; class TestCase {
        public function setUp(): void {}
        public function tearDown(): void {}
        protected function assertSame($expected, $actual, $message = "") {
            if ($expected !== $actual) {
                $expected_str = var_export($expected, true);
                $actual_str = var_export($actual, true);
                throw new \Exception($message ?: "Expected {$expected_str}, but got {$actual_str}");
            }
        }
        protected function assertTrue($actual, $message = "") {
            if ($actual !== true) {
                throw new \Exception($message ?: "Expected true, but got " . var_export($actual, true));
            }
        }
    }');
}

require_once dirname(__DIR__) . '/includes/class-gemini-client.php';
