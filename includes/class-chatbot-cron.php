<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_Cron {
    const HOOK = 'chatbot_process_queue';

    public static function init() {
        add_action(self::HOOK, [self::class, 'process_queue']);
        // 万一スケジュールが外れても再登録
        if (!wp_next_scheduled(self::HOOK)) {
            self::schedule();
        }
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'five_minutes', self::HOOK);
        }
    }

    public static function process_queue() {
        $files = Chatbot_Repository::pending_files(3);
        if (empty($files)) {
            return;
        }
        foreach ($files as $file) {
            try {
                self::process_file($file);
            } catch (\Throwable $e) {
                self::log_error($file->id, '例外: ' . $e->getMessage());
            }
        }
    }

    private static function process_file($file) {
        $path = $file->storage_path;
        if (!file_exists($path)) {
            Chatbot_Repository::update_file_status($file->id, 'error');
            self::log_error($file->id, 'ファイルが存在しません: ' . $path);
            return;
        }
        $content = Chatbot_Extractor::extract($path, $file->mime_type);
        if ($content === '') {
            Chatbot_Repository::update_file_status($file->id, 'error');
            self::log_error($file->id, 'テキスト抽出に失敗しました');
            return;
        }
        $chunks = self::chunk_text($content, 500);
        $index = 0;
        foreach ($chunks as $chunk) {
            $chunk_id = Chatbot_Repository::insert_chunk($file->id, $file->knowledge_set_id, $index++, $chunk, mb_strlen($chunk));
            $vec = Chatbot_Embedder::embed_text($chunk);
            $dim = Chatbot_Embedder::dimension($vec);
            Chatbot_Repository::insert_embedding($chunk_id, $file->knowledge_set_id, $dim, $vec, 'embed');
        }
        Chatbot_Repository::update_file_status($file->id, 'indexed');
    }

    private static function chunk_text($text, $size) {
        $len = mb_strlen($text);
        $chunks = [];
        for ($i = 0; $i < $len; $i += $size) {
            $chunks[] = mb_substr($text, $i, $size);
        }
        return $chunks;
    }

    public static function log_error($file_id, $message) {
        $errors = get_option('chatbot_cron_errors', []);
        if (!is_array($errors)) {
            $errors = [];
        }
        $errors[] = [
            'time' => current_time('mysql'),
            'file_id' => $file_id,
            'message' => $message,
        ];
        // 最新20件に絞る
        if (count($errors) > 20) {
            $errors = array_slice($errors, -20);
        }
        update_option('chatbot_cron_errors', $errors, false);
    }
}

// 5分間隔のスケジュールを追加
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['five_minutes'])) {
        $schedules['five_minutes'] = [
            'interval' => 300,
            'display' => __('Every Five Minutes'),
        ];
    }
    return $schedules;
});
