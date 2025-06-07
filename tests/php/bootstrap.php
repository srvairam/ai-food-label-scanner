<?php
// Minimal WordPress stubs for running plugin functions in tests
if (!class_exists('WP_Error')) {
    class WP_Error {
        protected $message;
        public function __construct($code = '', $message = '') {
            $this->message = $message;
        }
        public function get_error_message() {
            return $this->message;
        }
    }
}

function plugin_dir_path($file) { return __DIR__; }
function plugin_dir_url($file) { return 'http://example.com/'; }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function wp_localize_script() {}
function wp_create_nonce($key) { return 'nonce'; }
function admin_url($path='') { return $path; }
function add_action() {}
function add_shortcode() {}
function add_options_page() {}
function register_activation_hook() {}

function get_option($name) { return null; }
function wp_upload_bits($filename, $placeholder, $data) {
    return ['file' => '/tmp/'.$filename, 'url' => 'http://example.com/'.$filename, 'error' => ''];
}
function wp_remote_post($url, $args=array()) { return ['body' => '{}']; }
function wp_remote_get($url, $args=array()) { return ['body' => '{}']; }
function wp_remote_retrieve_body($res) { return $res['body'] ?? ''; }
function wp_json_encode($data) { return json_encode($data); }

require_once __DIR__ . '/../../ai-nutrition-scanner.php';
