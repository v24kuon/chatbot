<?php
/**
 * Plugin Name: Chatbot RAG Plugin
 * Description: 資料セットベースのチャットボット（RAG）プラグイン。ショートコード設置、ベクタ検索、管理画面、レート上限制御などを提供します。
 * Version: 0.1.0
 * Author: Project Team
 * Update URI: https://local/chatbot-rag-plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CHATBOT_PLUGIN_VERSION', '0.1.0');
define('CHATBOT_PLUGIN_FILE', __FILE__);
define('CHATBOT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHATBOT_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-plugin.php';

Chatbot_Plugin::instance();
