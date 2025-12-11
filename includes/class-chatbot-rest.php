<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_REST {
    const NS = 'chatbot/v1';

    public static function init() {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/knowledge-sets', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_set'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route(self::NS, '/upload', [
            'methods' => 'POST',
            'callback' => [self::class, 'upload_file'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => [
                'dataset' => ['required' => true],
            ],
        ]);

        register_rest_route(self::NS, '/chat', [
            'methods' => 'POST',
            'callback' => [self::class, 'chat'],
            'permission_callback' => '__return_true',
            'args' => [
                'dataset' => ['required' => true],
                'question' => ['required' => true],
                'page_url' => ['required' => false],
            ],
        ]);
    }

    public static function create_set(\WP_REST_Request $req) {
        $slug = sanitize_title($req->get_param('slug'));
        $name = sanitize_text_field($req->get_param('name'));
        $desc = wp_kses_post($req->get_param('description'));
        if (!$slug || !$name) {
            return new WP_Error('invalid', 'slugとnameは必須です', ['status' => 400]);
        }
        $exists = Chatbot_Repository::get_knowledge_set_by_slug($slug);
        if ($exists) {
            return new WP_Error('exists', '既に存在します', ['status' => 409]);
        }
        $id = Chatbot_Repository::create_knowledge_set($slug, $name, $desc);
        return ['id' => $id, 'slug' => $slug, 'name' => $name];
    }

    public static function upload_file(\WP_REST_Request $req) {
        $dataset = sanitize_title($req->get_param('dataset'));
        $set = Chatbot_Repository::get_knowledge_set_by_slug($dataset);
        if (!$set) {
            return new WP_Error('not_found', '資料セットがありません', ['status' => 404]);
        }
        if (empty($_FILES['file'])) {
            return new WP_Error('no_file', 'fileが必要です', ['status' => 400]);
        }
        $settings = Chatbot_Settings::get_settings();
        $file = $_FILES['file'];
        $allowed = ['pdf','txt','md']; // Office除外
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            return new WP_Error('bad_ext', '許可されていない拡張子です', ['status' => 400]);
        }
        $max_single = intval($settings['max_file_size_mb']) * 1024 * 1024;
        if ($file['size'] > $max_single) {
            return new WP_Error('too_large', '単一ファイルの上限を超えています', ['status' => 400]);
        }

        // 合計サイズチェック
        global $wpdb;
        $table = Chatbot_Repository::get_table('knowledge_files');
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(bytes) FROM {$table} WHERE knowledge_set_id = %d", $set->id));
        if ($total + $file['size'] > intval($settings['max_total_bytes'])) {
            return new WP_Error('total_limit', '合計容量が上限（3GB）を超えています', ['status' => 400]);
        }

        $upload = wp_handle_upload($file, ['test_form' => false, 'unique_filename_callback' => null]);
        if (isset($upload['error'])) {
            return new WP_Error('upload_error', $upload['error'], ['status' => 500]);
        }
        $checksum = hash_file('sha256', $upload['file']);
        $file_id = Chatbot_Repository::insert_file($set->id, basename($upload['file']), $upload['file'], $upload['type'], filesize($upload['file']), $checksum);

        $gemini_ok = false;
        $openai_ok = false;

        $settings = Chatbot_Settings::get_settings();

        // Gemini File Searchへアップロード
        $api_key_g = Chatbot_Settings::maybe_decrypt($settings['gemini_api_key'] ?? '');
        if ($api_key_g) {
            $store = Chatbot_Gemini_File::ensure_store($api_key_g, $set);
            if (is_wp_error($store)) {
                Chatbot_Cron::log_error($file_id, 'Geminiストア作成失敗: ' . $store->get_error_message());
            } else {
                $up = Chatbot_Gemini_File::upload_file($api_key_g, $store, $upload['file']);
                if (is_wp_error($up)) {
                    Chatbot_Cron::log_error($file_id, 'Geminiアップロード失敗: ' . $up->get_error_message());
                } else {
                    Chatbot_Repository::update_store_name($set->id, $store);
                    Chatbot_Repository::update_remote_file_id($file_id, $up);
                    $gemini_ok = true;
                }
            }
        }

        // OpenAI Files APIへアップロード
        $api_key_o = Chatbot_Settings::maybe_decrypt($settings['openai_api_key'] ?? '');
        if ($api_key_o) {
            $up = Chatbot_OpenAI_File::upload_file($api_key_o, $upload['file']);
            if (is_wp_error($up)) {
                Chatbot_Cron::log_error($file_id, 'OpenAIアップロード失敗: ' . $up->get_error_message());
            } else {
                Chatbot_Repository::update_remote_file_id_openai($file_id, $up);
                $openai_ok = true;
            }
        }

        if ($gemini_ok || $openai_ok) {
            Chatbot_Repository::update_file_status($file_id, 'indexed');
            return ['id' => $file_id, 'status' => 'indexed'];
        }

        Chatbot_Repository::update_file_status($file_id, 'error');
        return new WP_Error('upload_failed', 'Gemini/OpenAI アップロードに失敗しました', ['status' => 500]);
    }

    public static function chat(\WP_REST_Request $req) {
        $dataset = sanitize_title($req->get_param('dataset'));
        $question = sanitize_text_field($req->get_param('question'));
        $page_url = esc_url_raw($req->get_param('page_url'));
        if (!$question) {
            return new WP_Error('invalid', 'questionは必須です', ['status' => 400]);
        }
        $result = Chatbot_RAG::answer($question, $dataset, $page_url);
        if (!empty($result['error'])) {
            return new WP_Error('rag_error', $result['error'], ['status' => 400]);
        }
        return $result;
    }
}
