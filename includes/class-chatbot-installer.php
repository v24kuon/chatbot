<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_Installer {
    const DB_VERSION = '0.1.0';

    public static function install() {
        self::create_tables();
        self::ensure_pdftotext_permission();
        add_option('chatbot_db_version', self::DB_VERSION);
    }

    public static function maybe_upgrade() {
        $installed = get_option('chatbot_db_version');
        if ($installed !== self::DB_VERSION) {
            self::create_tables();
            self::ensure_pdftotext_permission();
            update_option('chatbot_db_version', self::DB_VERSION);
        }
    }

    public static function uninstall() {
        // リモートファイルのクリーンアップ
        $settings = Chatbot_Settings::get_settings();
        $files = [];
        try {
            global $wpdb;
            $table_files = Chatbot_Repository::get_table('knowledge_files');
            $table_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_files)) === $table_files);
            if ($table_exists) {
                $files = Chatbot_Repository::list_all_files();
            } else {
                error_log('[chatbot] uninstall: skip list_all_files because table missing: ' . $table_files);
            }
        } catch (\Throwable $e) {
            error_log('[chatbot] uninstall: list_all_files failed: ' . $e->getMessage());
        }
        if (!is_array($files)) {
            error_log('[chatbot] uninstall: list_all_files returned non-array; fallback to empty');
            $files = [];
        }
        foreach ($files as $file) {
            $file_id = (is_object($file) && isset($file->id)) ? $file->id : 'n/a';
            try {
                if (class_exists('Chatbot_File_Sync')) {
                    Chatbot_File_Sync::delete_remote($file);
                }
            } catch (\Throwable $e) {
                error_log('[chatbot] uninstall: delete_remote failed (file_id=' . $file_id . '): ' . $e->getMessage());
            }
            try {
                if (!empty($file->storage_path) && file_exists($file->storage_path)) {
                    $removed = @unlink($file->storage_path);
                    if ($removed === false) {
                        error_log('[chatbot] uninstall: unlink failed (file_id=' . $file_id . ', path=' . $file->storage_path . ')');
                    }
                }
            } catch (\Throwable $e) {
                error_log('[chatbot] uninstall: unlink failed (file_id=' . $file_id . '): ' . $e->getMessage());
            }
        }

        // テーブル削除
        self::drop_tables();

        // オプション削除
        delete_option('chatbot_db_version');
        delete_option('chatbot_settings');
        delete_option('chatbot_cron_errors');

        // Cron解除
        Chatbot_Cron::unschedule();
    }

    private static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $tables = self::get_schema($wpdb->prefix, $charset_collate);

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ($tables as $sql) {
            dbDelta($sql);
        }
    }

    private static function drop_tables() {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $tables = [
            "{$prefix}chatbot_knowledge_sets",
            "{$prefix}chatbot_knowledge_files",
            "{$prefix}chatbot_knowledge_chunks",
            "{$prefix}chatbot_knowledge_embeddings",
            "{$prefix}chatbot_sessions",
            "{$prefix}chatbot_messages",
            "{$prefix}chatbot_manual_answers",
            "{$prefix}chatbot_ai_providers",
            "{$prefix}chatbot_rate_limit_state",
        ];
        foreach ($tables as $t) {
            $wpdb->query("DROP TABLE IF EXISTS {$t}");
        }
    }

    private static function get_schema($prefix, $collate) {
        $ksets = "{$prefix}chatbot_knowledge_sets";
        $kfiles = "{$prefix}chatbot_knowledge_files";
        $kchunks = "{$prefix}chatbot_knowledge_chunks";
        $kemb = "{$prefix}chatbot_knowledge_embeddings";
        $csessions = "{$prefix}chatbot_sessions";
        $cmessages = "{$prefix}chatbot_messages";
        $manual = "{$prefix}chatbot_manual_answers";
        $providers = "{$prefix}chatbot_ai_providers";
        $rlimit = "{$prefix}chatbot_rate_limit_state";

        $schema = [];

        $schema[] = "CREATE TABLE {$ksets} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            slug varchar(190) NOT NULL,
            name varchar(190) NOT NULL,
            description text NULL,
            store_name varchar(190) NULL,
            default_provider_id bigint(20) NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY slug (slug)
        ) {$collate};";

        $schema[] = "CREATE TABLE {$kfiles} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            knowledge_set_id bigint(20) NOT NULL,
            filename varchar(255) NOT NULL,
            storage_path varchar(500) NOT NULL,
            mime_type varchar(100) NOT NULL,
            bytes bigint(20) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            remote_file_id varchar(190) NULL,
            remote_file_id_openai varchar(190) NULL,
            page_count int(11) NULL,
            checksum char(64) NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY knowledge_set_id (knowledge_set_id),
            KEY status (status)
        ) {$collate};";

        $schema[] = "CREATE TABLE {$kchunks} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            knowledge_file_id bigint(20) NOT NULL,
            knowledge_set_id bigint(20) NOT NULL,
            chunk_index int(11) NOT NULL,
            content mediumtext NOT NULL,
            token_count int(11) NULL,
            embedding_status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY knowledge_file_id (knowledge_file_id),
            KEY knowledge_set_id (knowledge_set_id),
            KEY chunk_index (chunk_index)
        ) {$collate};";

        $schema[] = "CREATE TABLE {$kemb} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            knowledge_chunk_id bigint(20) NOT NULL,
            knowledge_set_id bigint(20) NOT NULL,
            dimension smallint(6) NOT NULL,
            vector longblob NOT NULL,
            provider varchar(20) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY knowledge_chunk_id (knowledge_chunk_id),
            KEY knowledge_set_id (knowledge_set_id),
            KEY provider (provider)
        ) {$collate};";

        $schema[] = "CREATE TABLE {$csessions} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_key char(36) NOT NULL,
            wp_user_id bigint(20) NULL,
            knowledge_set_id bigint(20) NOT NULL,
            page_url text NULL,
            user_agent_hash char(64) NULL,
            ip_hash char(64) NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY session_key (session_key),
            KEY knowledge_set_id (knowledge_set_id),
            KEY created_at (created_at)
        ) {$collate};";

        $schema[] = "CREATE TABLE {$cmessages} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            chat_session_id bigint(20) NOT NULL,
            role varchar(20) NOT NULL,
            question mediumtext NULL,
            answer mediumtext NULL,
            knowledge_set_id bigint(20) NOT NULL,
            used_manual_answer_id bigint(20) NULL,
            provider varchar(20) NULL,
            model varchar(190) NULL,
            prompt_tokens int(11) NULL,
            completion_tokens int(11) NULL,
            latency_ms int(11) NULL,
            answered_from_context tinyint(1) NOT NULL DEFAULT 0,
            unanswered_flag tinyint(1) NOT NULL DEFAULT 0,
            rate_limited tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY chat_session_id (chat_session_id),
            KEY knowledge_set_id (knowledge_set_id),
            KEY created_at (created_at),
            KEY unanswered_flag (unanswered_flag)
        ) {$collate};";

        $schema[] = "CREATE TABLE {$manual} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            knowledge_set_id bigint(20) NOT NULL,
            question_pattern text NOT NULL,
            answer_text mediumtext NOT NULL,
            embedding_vector longblob NULL,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY knowledge_set_id (knowledge_set_id),
            KEY enabled (enabled)
        ) {$collate};";

        $schema[] = "CREATE TABLE {$providers} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            type varchar(20) NOT NULL,
            model varchar(190) NULL,
            api_key text NULL,
            temperature decimal(3,2) NULL,
            top_p decimal(3,2) NULL,
            max_output_tokens int(11) NULL,
            timeout_ms int(11) NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY type (type)
        ) {$collate};";

        $schema[] = "CREATE TABLE {$rlimit} (
            provider_type varchar(20) NOT NULL,
            blocked_until datetime NULL,
            last_error text NULL,
            PRIMARY KEY  (provider_type)
        ) {$collate};";

        return $schema;
    }

    private static function ensure_pdftotext_permission() {
        $candidates = [
            CHATBOT_PLUGIN_DIR . 'bin64/pdftotext',
            CHATBOT_PLUGIN_DIR . 'bin/pdftotext',
        ];
        foreach ($candidates as $path) {
            if (file_exists($path)) {
                @chmod($path, 0755);
            }
        }
    }
}
