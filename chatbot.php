<?php
/**
 * Plugin Name: Gemini File Search Chatbot
 * Description: Manage Google Gemini File Search store, upload documents, and expose a chat shortcode.
 * Version: 1.0.0
 * Author: Your Name
 * Update URI: false
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CHATBOT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CHATBOT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHATBOT_PLUGIN_VERSION', '1.0.0');

require_once CHATBOT_PLUGIN_PATH . 'includes/class-gemini-client.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-db.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-update-blocker.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-admin.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-frontend.php';

add_action('plugins_loaded', function () {
    new Chatbot_Admin();
    new Chatbot_Frontend();
    new Chatbot_Update_Blocker();
});

register_activation_hook(__FILE__, ['Chatbot_DB', 'install']);
