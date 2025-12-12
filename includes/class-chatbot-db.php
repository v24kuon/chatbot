<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_DB {
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        $sessions = "{$prefix}chat_sessions";
        $messages = "{$prefix}chat_messages";
        $manuals  = "{$prefix}manual_answers";

        $sql_sessions = "CREATE TABLE {$sessions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_key VARCHAR(64) NOT NULL,
            wp_user_id BIGINT UNSIGNED NULL,
            store_name VARCHAR(191) DEFAULT '' NOT NULL,
            page_url TEXT NULL,
            user_agent_hash CHAR(64) DEFAULT '' NOT NULL,
            ip_hash CHAR(64) DEFAULT '' NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY session_key (session_key),
            KEY store_name (store_name),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $sql_messages = "CREATE TABLE {$messages} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_session_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(20) DEFAULT '' NOT NULL,
            question LONGTEXT NULL,
            answer LONGTEXT NULL,
            store_name VARCHAR(191) DEFAULT '' NOT NULL,
            used_manual_answer_id BIGINT UNSIGNED NULL,
            provider VARCHAR(20) DEFAULT '' NOT NULL,
            model VARCHAR(50) DEFAULT '' NOT NULL,
            prompt_tokens INT NULL,
            completion_tokens INT NULL,
            latency_ms INT NULL,
            answered_from_context TINYINT(1) DEFAULT 0,
            unanswered_flag TINYINT(1) DEFAULT 0,
            rate_limited TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY chat_session_id (chat_session_id),
            KEY store_name (store_name),
            KEY created_at (created_at),
            KEY unanswered_flag (unanswered_flag)
        ) {$charset_collate};";

        $sql_manuals = "CREATE TABLE {$manuals} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_name VARCHAR(191) DEFAULT '' NOT NULL,
            question_pattern TEXT NOT NULL,
            answer_text LONGTEXT NOT NULL,
            enabled TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY store_name (store_name),
            KEY enabled (enabled)
        ) {$charset_collate};";

        dbDelta($sql_sessions);
        dbDelta($sql_messages);
        dbDelta($sql_manuals);
    }

    public static function maybe_install() {
        // Lightweight check: ensure sessions table exists; if not, install.
        global $wpdb;
        $table = $wpdb->prefix . 'chat_sessions';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            self::install();
        }
    }
}
