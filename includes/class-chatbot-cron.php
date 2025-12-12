<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_Cron {
    const HOOK = 'chatbot_process_queue';

    public static function init() {
        // バックグラウンド処理は外部APIに委譲するため、スケジューリングは行わない
        // 既存のスケジュールが残っていれば解除のみ行う
        self::unschedule();
    }

    public static function schedule() {
        // no-op: 外部委譲運用のためスケジュールしない
    }

    public static function unschedule() {
        // 全スケジュールを確実に解除
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function process_queue() {
        // no-op: Cron バッチは利用しない（外部APIに委譲）
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
