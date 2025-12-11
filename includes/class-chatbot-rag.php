<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_RAG {
    const TOP_K = 8;

    public static function answer($question, $set_slug, $page_url = '') {
        $settings = Chatbot_Settings::get_settings();
        $set = Chatbot_Repository::get_knowledge_set_by_slug($set_slug);
        if (!$set) {
            return ['error' => '資料セットが見つかりません'];
        }

        $session_key = self::get_session_key();
        $session_id = Chatbot_Repository::get_or_create_session($session_key, $set->id, $page_url);

        // Rate limit check (both providers)
        $rl_gemini = Chatbot_Repository::get_rate_limit('gemini');
        $rl_openai = Chatbot_Repository::get_rate_limit('openai');
        $blocked = false;
        if ($rl_gemini && $rl_gemini->blocked_until && strtotime($rl_gemini->blocked_until) > time()) {
            $blocked = true;
        }
        if ($rl_openai && $rl_openai->blocked_until && strtotime($rl_openai->blocked_until) > time()) {
            $blocked = true;
        }
        if ($blocked) {
            return ['rate_limited' => true, 'message' => $settings['rate_notice']];
        }

        // LLM回答生成（Gemini File Search経由）
        $result = self::synthesize_answer($question, $set);
        $answer = is_array($result) ? $result['answer'] : $result;
        $provider = is_array($result) ? ($result['provider'] ?? 'local') : 'local';
        $model = is_array($result) ? ($result['model'] ?? null) : null;
        $unanswered = stripos($answer, '記載がない') !== false;

        Chatbot_Repository::log_message($session_id, 'assistant', $question, $answer, $set->id, null, $provider, $model, true, $unanswered, false);
        return ['answer' => $answer, 'unanswered' => $unanswered, 'context_used' => 0];
    }

    private static function get_chunk($chunk_id) {
        global $wpdb;
        $table = Chatbot_Repository::get_table('knowledge_chunks');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $chunk_id));
    }

    private static function synthesize_answer($question, $set) {
        $key_g = Chatbot_Settings::get_api_key('gemini');
        $key_o = Chatbot_Settings::get_api_key('openai');

        // 優先: Gemini File Search (store_name & remote_file_id)
        if ($key_g && !empty($set->store_name)) {
            $system_msg = "あなたは資料ベースの回答エンジンです。渡したファイル検索ストアから取得した内容のみで回答してください。記載がない場合のみ「資料に記載がないためお答えできません」と答えてください。";
            $user_msg = "質問: {$question}";
            $res = self::call_gemini_file_search($key_g, $system_msg, $user_msg, $set->store_name);
            if (($res['provider'] ?? '') === 'gemini') {
                return $res;
            }
        }

        // 次点: OpenAI Responses API + attachments (file_id)
        if ($key_o && !empty($set->id)) {
            // ファイルIDの中から最新のOpenAI file_idを拾う
            $file = Chatbot_Repository::get_latest_openai_file($set->id);
            if ($file && !empty($file->remote_file_id_openai)) {
                $system_msg = "You are a retrieval QA bot. Answer only from the attached file. If the answer is not in the file, reply: 資料に記載がないためお答えできません。";
                $user_msg = $question;
                $res = self::call_openai_responses($key_o, $system_msg, $user_msg, $file->remote_file_id_openai);
                if (($res['provider'] ?? '') === 'openai') {
                    return $res;
                }
            }
        }

        $fallback = self::fallback_answer($question, []);
        return ['answer' => $fallback, 'provider' => 'local', 'model' => null];
    }

    private static function call_gemini_file_search($api_key, $system_msg, $user_msg, $store_name) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . urlencode($api_key);
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $system_msg],
                        ['text' => $user_msg],
                    ],
                ],
            ],
            'tools' => [
                [
                    'file_search' => [
                        'file_search_store_names' => [$store_name],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 1024,
            ],
        ];

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return ['answer' => self::fallback_answer('', []), 'provider' => 'local', 'model' => null];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            $text = trim($data['candidates'][0]['content']['parts'][0]['text']);
            return ['answer' => $text, 'provider' => 'gemini', 'model' => 'gemini-2.5-flash'];
        }

        if (isset($data['error']['code']) && $data['error']['code'] === 429) {
            $blocked_until = date('Y-m-d H:i:s', time() + 600);
            Chatbot_Repository::upsert_rate_limit('gemini', $blocked_until, $data['error']['message'] ?? 'Rate limit exceeded');
        }

        return ['answer' => self::fallback_answer('', []), 'provider' => 'local', 'model' => null];
    }

    private static function call_openai_responses($api_key, $system_msg, $user_msg, $file_id) {
        $url = 'https://api.openai.com/v1/responses';
        $payload = [
            'model' => 'gpt-4o-mini',
            'input' => $user_msg,
            'system' => $system_msg,
            'attachments' => [
                [
                    'file_id' => $file_id,
                    'tools' => [
                        ['type' => 'file_search'],
                    ],
                ],
            ],
            'max_output_tokens' => 1024,
            'temperature' => 0.3,
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return ['answer' => self::fallback_answer('', []), 'provider' => 'local', 'model' => null];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['output_text'])) {
            $text = trim($data['output_text']);
            return ['answer' => $text, 'provider' => 'openai', 'model' => 'gpt-4o-mini'];
        }

        if (isset($data['error']['type']) && $data['error']['type'] === 'rate_limit_exceeded') {
            $blocked_until = date('Y-m-d H:i:s', time() + 600);
            Chatbot_Repository::upsert_rate_limit('openai', $blocked_until, $data['error']['message'] ?? 'Rate limit exceeded');
        }

        return ['answer' => self::fallback_answer('', []), 'provider' => 'local', 'model' => null];
    }

    private static function fallback_answer($question, array $chunks) {
        // APIキーがない場合やAPI呼び出し失敗時のフォールバック
        if (empty($chunks)) {
            return '資料に記載がないためお答えできません。';
        }
        $context = implode("\n---\n", array_slice($chunks, 0, 2));
        return mb_substr($context, 0, 400) . '...';
    }

    private static function get_session_key() {
        if (!isset($_COOKIE['chatbot_sid'])) {
            $sid = wp_generate_uuid4();
            setcookie('chatbot_sid', $sid, time() + DAY_IN_SECONDS * 30, COOKIEPATH, COOKIE_DOMAIN);
            return $sid;
        }
        return sanitize_text_field(wp_unslash($_COOKIE['chatbot_sid']));
    }
}
