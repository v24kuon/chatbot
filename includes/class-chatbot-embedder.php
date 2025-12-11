<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_Embedder {
    /**
     * テキストをベクタ化する。APIが使えない/失敗した場合は疑似ベクタにフォールバック。
     */
    public static function embed_text($text) {
        $settings = Chatbot_Settings::get_settings();
        $provider = $settings['embed_provider'] ?? 'openai';
        $model = $settings['embed_model'] ?? 'text-embedding-3-small';

        // モデル未指定時のフォールバック
        if ($model === '' || $model === null) {
            $model = ($provider === 'gemini') ? 'gemini-embedding-001' : 'text-embedding-3-small';
        }

        $text = (string) $text;
        if ($text === '') {
            return self::pseudo_embed($text);
        }

        if ($provider === 'openai' && ($key = Chatbot_Settings::maybe_decrypt($settings['openai_api_key'] ?? ''))) {
            $vec = self::embed_openai($text, $model, $key);
            if (!empty($vec)) {
                return self::normalize_vec($vec);
            }
        }
        if ($provider === 'gemini' && ($key = Chatbot_Settings::maybe_decrypt($settings['gemini_api_key'] ?? ''))) {
            $vec = self::embed_gemini($text, $model, $key);
            if (!empty($vec)) {
                return self::normalize_vec($vec);
            }
        }

        // フォールバック（疑似ベクタ）
        return self::pseudo_embed($text);
    }

    /**
     * OpenAI embeddings API
     */
    private static function embed_openai($text, $model, $api_key) {
        $endpoint = 'https://api.openai.com/v1/embeddings';
        $body = wp_json_encode([
            'input' => $text,
            'model' => $model,
        ]);
        $resp = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => $body,
            'timeout' => 20,
        ]);
        if (is_wp_error($resp)) {
            return [];
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            return [];
        }
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($json['data'][0]['embedding'])) {
            return [];
        }
        return $json['data'][0]['embedding'];
    }

    /**
     * Gemini embeddings API (Generative Language)
     */
    private static function embed_gemini($text, $model, $api_key) {
        $endpoint = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:embedContent?key=%s', rawurlencode($model), rawurlencode($api_key));
        $body = wp_json_encode([
            'content' => [
                'parts' => [
                    ['text' => $text],
                ],
            ],
        ]);
        $resp = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
            'timeout' => 20,
        ]);
        if (is_wp_error($resp)) {
            return [];
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            return [];
        }
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($json['embedding']['value'])) {
            return [];
        }
        return $json['embedding']['value'];
    }

    /**
     * 疑似ベクタ（フォールバック）
     */
    private static function pseudo_embed($text) {
        $tokens = Chatbot_Normalizer::tokenize($text);
        $dim = 16;
        $vec = array_fill(0, $dim, 0.0);
        if (empty($tokens)) {
            return $vec;
        }
        foreach ($tokens as $token) {
            $hash = hexdec(substr(hash('sha256', $token), 0, 8));
            $idx = $hash % $dim;
            $sign = ($hash & 1) ? 1 : -1;
            $vec[$idx] += $sign * 1.0;
        }
        return self::normalize_vec($vec);
    }

    public static function similarity(array $a, array $b) {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }
        $dot = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $dot += floatval($a[$i]) * floatval($b[$i]);
        }
        return $dot;
    }

    public static function dimension(array $vec) {
        return count($vec);
    }

    private static function normalize_vec(array $vec) {
        $norm = 0.0;
        foreach ($vec as $v) {
            $norm += $v * $v;
        }
        $norm = sqrt($norm);
        if ($norm > 0) {
            foreach ($vec as $i => $v) {
                $vec[$i] = $v / $norm;
            }
        }
        return $vec;
    }
}
