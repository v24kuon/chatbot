<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/class-gemini-client.php';

// Remove remote store and files
$store = get_option('chatbot_gemini_store', '');
$files = get_option('chatbot_gemini_files', []);
$client = new Gemini_Client();

if (!empty($files) && is_array($files)) {
    foreach ($files as $file) {
        if (!empty($file['id'])) {
            $client->delete_file($file['id']);
        }
    }
}

if (!empty($store)) {
    $client->delete_store($store);
}

// Remove options
delete_option('chatbot_gemini_store');
delete_option('chatbot_gemini_files');
delete_option('chatbot_gemini_api_key');

// Drop custom tables
global $wpdb;
$tables = [
    $wpdb->prefix . 'chat_sessions',
    $wpdb->prefix . 'chat_messages',
    $wpdb->prefix . 'manual_answers',
];
foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}
