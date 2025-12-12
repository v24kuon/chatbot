<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_Frontend {
    private $ground_rules = '与えられた資料を基に、ユーザーの質問に直接、簡潔に答えてください。「資料によると」などの前置きや、質問と無関係な例示は省いてください。質問の状況に合わせて資料のルールを適用した結論のみを回答してください。資料から判断できない場合のみ「その内容についてはお問い合わせフォームよりお問い合わせください」と答えてください。親しみやすい丁寧な日本語でお願いします。';

    public function __construct() {
        add_shortcode('gemini_chatbot', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('wp_ajax_chatbot_ask', [$this, 'handle_chat']);
        add_action('wp_ajax_nopriv_chatbot_ask', [$this, 'handle_chat']);
        add_action('init', ['Chatbot_DB', 'maybe_install']);
    }

    public function register_assets() {
        $css_ver = CHATBOT_PLUGIN_VERSION;
        $js_ver  = CHATBOT_PLUGIN_VERSION;
        $css_path = CHATBOT_PLUGIN_PATH . 'assets/chatbot.css';
        $js_path  = CHATBOT_PLUGIN_PATH . 'assets/chatbot.js';
        if (file_exists($css_path)) {
            $css_ver = (string) filemtime($css_path);
        }
        if (file_exists($js_path)) {
            $js_ver = (string) filemtime($js_path);
        }

        wp_register_style('gemini-chatbot', CHATBOT_PLUGIN_URL . 'assets/chatbot.css', [], $css_ver);
        // jQuery を前提としているテーマ/他プラグインや、旧JSのキャッシュ残りに備えて依存に含める
        wp_register_script('gemini-chatbot', CHATBOT_PLUGIN_URL . 'assets/chatbot.js', ['jquery'], $js_ver, true);
        wp_localize_script('gemini-chatbot', 'GeminiChatbot', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('chatbot_ask'),
        ]);
    }

    public function render_shortcode($atts) {
        wp_enqueue_style('gemini-chatbot');
        wp_enqueue_script('gemini-chatbot');

        if (get_transient('chatbot_gemini_unavailable')) {
            return '<div class="gemini-chatbot-unavailable" style="background:#e8eef3; padding:16px; border-radius:24px; color:#3d4f5f; font-size:13px; text-align:center;">現在混雑中のためご利用できません。申し訳ありませんが時間をおいてお試しください。</div>';
        }

        ob_start();
        ?>
        <div class="gemini-chatbot">
            <div class="chat-log"></div>
            <div class="chat-input">
                <input type="text" class="chat-question" placeholder="メッセージを入力..." />
                <button type="button" class="chat-send" aria-label="送信">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                    </svg>
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_chat() {
        check_ajax_referer('chatbot_ask', 'nonce');
        $prompt = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        if (empty($prompt)) {
            wp_send_json_error(['message' => '質問を入力してください。']);
        }
        $store = get_option('chatbot_gemini_store', '');
        if (empty($store)) {
            wp_send_json_error(['message' => 'ストアが設定されていません。']);
        }

        // Check availability
        if (get_transient('chatbot_gemini_unavailable')) {
            wp_send_json_error(['message' => '現在混雑中です。時間をおいてお試しください。']);
        }

        $session = $this->ensure_session();
        $manual = $this->try_manual_answer($store, $prompt);
        if ($manual && isset($manual['answer'])) {
            $this->log_message($session['id'], [
                'question' => $prompt,
                'answer' => $manual['answer'],
                'store_name' => $store,
                'used_manual_answer_id' => $manual['id'],
                'provider' => 'manual',
                'model' => '',
                'latency_ms' => $manual['latency'] ?? null,
                'answered_from_context' => 1,
                'unanswered_flag' => 0,
            ]);
            wp_send_json_success(['answer' => $manual['answer']]);
        }

        $client = new Gemini_Client();
        $response = $client->generate_content($store, $prompt, $this->ground_rules);
        if (is_wp_error($response)) {
            $data = $response->get_error_data();
            $code = $data['status'] ?? 0;
            // 429: Too Many Requests, 503: Service Unavailable, or any API error
            if ($code == 429 || $code == 503 || $response->get_error_code() === 'api_error') {
                set_transient('chatbot_gemini_unavailable', true, 60); // Block for 60 seconds
                wp_send_json_error(['message' => '現在混雑中です。時間をおいてお試しください。']);
            }
            // For other errors, also show friendly message
            wp_send_json_error(['message' => '現在混雑中です。時間をおいてお試しください。']);
        }
        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $unanswered = (strpos($text, 'お問い合わせフォームよりお問い合わせください') !== false);

        $this->log_message($session['id'], [
            'question' => $prompt,
            'answer' => $text,
            'store_name' => $store,
            'used_manual_answer_id' => null,
            'provider' => 'gemini',
            'model' => Gemini_Client::MODEL_ID,
            'latency_ms' => null,
            'answered_from_context' => $unanswered ? 0 : 1,
            'unanswered_flag' => $unanswered ? 1 : 0,
        ]);

        wp_send_json_success(['answer' => $text]);
    }

    private function ensure_session() {
        global $wpdb;
        $session_key = isset($_COOKIE['chatbot_session']) ? sanitize_text_field(wp_unslash($_COOKIE['chatbot_session'])) : '';
        if (empty($session_key)) {
            $session_key = wp_generate_uuid4();
            setcookie('chatbot_session', $session_key, time() + MONTH_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        }
        $table = $wpdb->prefix . 'chat_sessions';
        $store = get_option('chatbot_gemini_store', '');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE session_key=%s LIMIT 1", $session_key), ARRAY_A);
        if ($existing) {
            return $existing;
        }
        $page_url = wp_get_referer();
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $wpdb->insert(
            $table,
            [
                'session_key' => $session_key,
                'wp_user_id' => get_current_user_id() ?: null,
                'store_name' => $store,
                'page_url' => $page_url,
                'user_agent_hash' => $ua ? md5($ua) : '',
                'ip_hash' => $ip ? md5($ip) : '',
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s']
        );
        $id = $wpdb->insert_id;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
    }

    private function log_message($session_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'chat_messages';
        $wpdb->insert(
            $table,
            [
                'chat_session_id' => $session_id,
                'role' => 'assistant',
                'question' => $data['question'] ?? '',
                'answer' => $data['answer'] ?? '',
                'store_name' => $data['store_name'] ?? '',
                'used_manual_answer_id' => $data['used_manual_answer_id'],
                'provider' => $data['provider'] ?? '',
                'model' => $data['model'] ?? '',
                'prompt_tokens' => $data['prompt_tokens'] ?? null,
                'completion_tokens' => $data['completion_tokens'] ?? null,
                'latency_ms' => $data['latency_ms'] ?? null,
                'answered_from_context' => $data['answered_from_context'] ?? 0,
                'unanswered_flag' => $data['unanswered_flag'] ?? 0,
                'rate_limited' => $data['rate_limited'] ?? 0,
            ],
            ['%d','%s','%s','%s','%s','%d','%s','%s','%d','%d','%d','%d','%d','%d']
        );
    }

    private function try_manual_answer($store, $question) {
        global $wpdb;
        $table = $wpdb->prefix . 'manual_answers';
        $candidates = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, question_pattern, answer_text FROM {$table} WHERE enabled=1 AND store_name=%s ORDER BY id DESC LIMIT 50",
                $store
            ),
            ARRAY_A
        );
        if (empty($candidates)) {
            return null;
        }
        $normalized_q = $this->normalize_question($question);
        $client = new Gemini_Client();
        $selection = $client->select_manual_answer($normalized_q, $candidates);
        if (is_wp_error($selection)) {
            return null;
        }
        if (!empty($selection['id'])) {
            foreach ($candidates as $cand) {
                if ((int)$cand['id'] === (int)$selection['id']) {
                    return [
                        'id' => (int)$cand['id'],
                        'answer' => $cand['answer_text'],
                        'latency' => $selection['latency_ms'] ?? null,
                    ];
                }
            }
        }
        return null;
    }

    private function normalize_question($text) {
        $text = trim($text);
        $text = mb_convert_kana($text, 'KVas');
        return $text;
    }
}
