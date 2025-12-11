<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_Repository {
    public static function get_table($suffix) {
        global $wpdb;
        return $wpdb->prefix . 'chatbot_' . $suffix;
    }

    public static function get_settings() {
        return Chatbot_Settings::get_settings();
    }

    public static function get_knowledge_set_by_slug($slug) {
        global $wpdb;
        $table = self::get_table('knowledge_sets');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s", $slug));
    }

    public static function create_knowledge_set($slug, $name, $desc = '') {
        global $wpdb;
        $table = self::get_table('knowledge_sets');
        $wpdb->insert($table, [
            'slug' => sanitize_title($slug),
            'name' => sanitize_text_field($name),
            'description' => wp_kses_post($desc),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        return $wpdb->insert_id;
    }

    public static function insert_file($set_id, $filename, $path, $mime, $bytes, $checksum) {
        global $wpdb;
        $table = self::get_table('knowledge_files');
        $wpdb->insert($table, [
            'knowledge_set_id' => $set_id,
            'filename' => $filename,
            'storage_path' => $path,
            'mime_type' => $mime,
            'bytes' => $bytes,
            'status' => 'pending',
            'checksum' => $checksum,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        return $wpdb->insert_id;
    }

    public static function pending_files($limit = 3) {
        global $wpdb;
        $table = self::get_table('knowledge_files');
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status = %s ORDER BY created_at ASC LIMIT %d", 'pending', intval($limit)));
    }

    public static function update_file_status($id, $status) {
        global $wpdb;
        $table = self::get_table('knowledge_files');
        $wpdb->update($table, ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id]);
    }

    public static function insert_chunk($file_id, $set_id, $index, $content, $token_count) {
        global $wpdb;
        $table = self::get_table('knowledge_chunks');
        $wpdb->insert($table, [
            'knowledge_file_id' => $file_id,
            'knowledge_set_id' => $set_id,
            'chunk_index' => $index,
            'content' => $content,
            'token_count' => $token_count,
            'embedding_status' => 'pending',
            'created_at' => current_time('mysql'),
        ]);
        return $wpdb->insert_id;
    }

    public static function insert_embedding($chunk_id, $set_id, $dimension, array $vector, $provider = 'local') {
        global $wpdb;
        $table = self::get_table('knowledge_embeddings');
        $wpdb->insert($table, [
            'knowledge_chunk_id' => $chunk_id,
            'knowledge_set_id' => $set_id,
            'dimension' => $dimension,
            'vector' => wp_json_encode($vector),
            'provider' => $provider,
            'created_at' => current_time('mysql'),
        ]);
        return $wpdb->insert_id;
    }

    public static function get_embeddings_by_set($set_id, $limit = 200) {
        global $wpdb;
        $table = self::get_table('knowledge_embeddings');
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE knowledge_set_id = %d ORDER BY id DESC LIMIT %d", $set_id, intval($limit)));
    }

    public static function get_messages($set_slug = '', $unanswered_only = 0, $limit = 50) {
        global $wpdb;
        $m = self::get_table('chatbot_messages');
        $s = self::get_table('chatbot_sessions');
        $ks = self::get_table('knowledge_sets');

        $where = [];
        $params = [];
        if ($set_slug !== '') {
            $where[] = "ks.slug = %s";
            $params[] = $set_slug;
        }
        if ($unanswered_only) {
            $where[] = "m.unanswered_flag = 1";
        }
        $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "
            SELECT m.*, ks.name as set_name, ks.slug as set_slug
            FROM {$m} m
            INNER JOIN {$s} s ON s.id = m.chat_session_id
            INNER JOIN {$ks} ks ON ks.id = m.knowledge_set_id
            {$where_sql}
            ORDER BY m.id DESC
            LIMIT %d
        ";
        $params[] = intval($limit);
        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    public static function log_message($session_id, $role, $question, $answer, $set_id, $manual_id, $provider, $model, $answered_from_context, $unanswered_flag, $rate_limited, $prompt_tokens = null, $completion_tokens = null, $latency_ms = null) {
        global $wpdb;
        $table = self::get_table('chatbot_messages');
        $wpdb->insert($table, [
            'chat_session_id' => $session_id,
            'role' => $role,
            'question' => $question,
            'answer' => $answer,
            'knowledge_set_id' => $set_id,
            'used_manual_answer_id' => $manual_id,
            'provider' => $provider,
            'model' => $model,
            'prompt_tokens' => $prompt_tokens,
            'completion_tokens' => $completion_tokens,
            'latency_ms' => $latency_ms,
            'answered_from_context' => $answered_from_context ? 1 : 0,
            'unanswered_flag' => $unanswered_flag ? 1 : 0,
            'rate_limited' => $rate_limited ? 1 : 0,
            'created_at' => current_time('mysql'),
        ]);
        return $wpdb->insert_id;
    }

    public static function insert_manual_answer($set_id, $question_pattern, $answer_text, array $vec, $dimension) {
        global $wpdb;
        $table = self::get_table('manual_answers');
        $wpdb->insert($table, [
            'knowledge_set_id' => $set_id,
            'question_pattern' => $question_pattern,
            'answer_text' => $answer_text,
            'embedding_vector' => wp_json_encode($vec),
            'enabled' => 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        return $wpdb->insert_id;
    }

    public static function list_knowledge_sets() {
        global $wpdb;
        $table = self::get_table('knowledge_sets');
        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC");
    }

    public static function list_files($set_slug = '', $limit = 100) {
        global $wpdb;
        $f = self::get_table('knowledge_files');
        $ks = self::get_table('knowledge_sets');
        $where = '';
        $params = [];
        if ($set_slug !== '') {
            $where = "WHERE ks.slug = %s";
            $params[] = $set_slug;
        }
        $params[] = intval($limit);
        $sql = "
            SELECT f.*, ks.name as set_name, ks.slug as set_slug
            FROM {$f} f
            INNER JOIN {$ks} ks ON ks.id = f.knowledge_set_id
            {$where}
            ORDER BY f.id DESC
            LIMIT %d
        ";
        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    public static function update_store_name($set_id, $store_name) {
        global $wpdb;
        $table = self::get_table('knowledge_sets');
        $wpdb->update($table, ['store_name' => $store_name, 'updated_at' => current_time('mysql')], ['id' => $set_id]);
    }

    public static function update_remote_file_id($file_id, $remote_id) {
        global $wpdb;
        $table = self::get_table('knowledge_files');
        $wpdb->update($table, ['remote_file_id' => $remote_id, 'updated_at' => current_time('mysql')], ['id' => $file_id]);
    }

    public static function update_remote_file_id_openai($file_id, $remote_id) {
        global $wpdb;
        $table = self::get_table('knowledge_files');
        $wpdb->update($table, ['remote_file_id_openai' => $remote_id, 'updated_at' => current_time('mysql')], ['id' => $file_id]);
    }

    public static function get_file($file_id) {
        global $wpdb;
        $f = self::get_table('knowledge_files');
        $ks = self::get_table('knowledge_sets');
        return $wpdb->get_row($wpdb->prepare("
            SELECT f.*, ks.store_name, ks.slug as set_slug
            FROM {$f} f
            INNER JOIN {$ks} ks ON ks.id = f.knowledge_set_id
            WHERE f.id = %d
        ", $file_id));
    }

    public static function delete_file_row($file_id) {
        global $wpdb;
        $table = self::get_table('knowledge_files');
        $wpdb->delete($table, ['id' => $file_id]);
    }

    public static function list_all_files() {
        global $wpdb;
        $table = self::get_table('knowledge_files');
        return $wpdb->get_results("SELECT * FROM {$table}");
    }

    public static function get_latest_openai_file($set_id) {
        global $wpdb;
        $files = self::get_table('knowledge_files');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$files} WHERE knowledge_set_id = %d AND remote_file_id_openai IS NOT NULL ORDER BY id DESC LIMIT 1", $set_id));
    }

    public static function list_pending_files($limit = 20) {
        global $wpdb;
        $f = self::get_table('knowledge_files');
        $ks = self::get_table('knowledge_sets');
        $sql = "
            SELECT f.*, ks.name as set_name, ks.slug as set_slug
            FROM {$f} f
            INNER JOIN {$ks} ks ON ks.id = f.knowledge_set_id
            WHERE f.status = %s
            ORDER BY f.id DESC
            LIMIT %d
        ";
        return $wpdb->get_results($wpdb->prepare($sql, 'pending', intval($limit)));
    }

    public static function count_files_by_status() {
        global $wpdb;
        $f = self::get_table('knowledge_files');
        $sql = "SELECT status, COUNT(*) as cnt FROM {$f} GROUP BY status";
        $rows = $wpdb->get_results($sql);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->status] = intval($r->cnt);
        }
        return $out;
    }

    public static function get_or_create_session($session_key, $set_id, $page_url) {
        global $wpdb;
        $table = self::get_table('chatbot_sessions');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE session_key = %s", $session_key));
        if ($row) {
            return $row->id;
        }
        $wpdb->insert($table, [
            'session_key' => $session_key,
            'wp_user_id' => get_current_user_id() ?: null,
            'knowledge_set_id' => $set_id,
            'page_url' => $page_url,
            'user_agent_hash' => self::safe_hash($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'ip_hash' => self::safe_hash($_SERVER['REMOTE_ADDR'] ?? ''),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        return $wpdb->insert_id;
    }

    public static function find_manual_answers($set_id, $limit = 50) {
        global $wpdb;
        $table = self::get_table('manual_answers');
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE knowledge_set_id = %d AND enabled = 1 ORDER BY id DESC LIMIT %d", $set_id, intval($limit)));
    }

    public static function upsert_rate_limit($provider_type, $blocked_until, $last_error) {
        global $wpdb;
        $table = self::get_table('rate_limit_state');
        $wpdb->replace($table, [
            'provider_type' => $provider_type,
            'blocked_until' => $blocked_until,
            'last_error' => $last_error,
        ]);
    }

    public static function get_rate_limit($provider_type) {
        global $wpdb;
        $table = self::get_table('rate_limit_state');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE provider_type = %s", $provider_type));
    }

    private static function safe_hash($value) {
        return hash('sha256', $value);
    }
}
