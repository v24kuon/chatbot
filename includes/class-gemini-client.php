<?php

if (!defined('ABSPATH')) {
    exit;
}

class Gemini_Client {
    const BASE_URL = 'https://generativelanguage.googleapis.com';
    const MODEL_ID = 'gemini-2.5-flash';

    private $api_key;

    public function __construct() {
        $this->api_key = get_option('chatbot_gemini_api_key', '');
    }

    private function auth_headers($extra = []) {
        if (empty($this->api_key)) {
            return new WP_Error('missing_api_key', 'API Key is not set.');
        }
        $headers = array_merge([
            'x-goog-api-key' => $this->api_key,
        ], $extra);
        return $headers;
    }

    private function safe_url($url) {
        // Never expose API key in UI/logs.
        return preg_replace('/([?&]key=)[^&]+/i', '$1***', $url);
    }

    private function request($method, $url, $body = null, $headers = [], $is_json = true) {
        $auth_headers = $this->auth_headers($headers);
        if (is_wp_error($auth_headers)) {
            return $auth_headers;
        }

        if ($is_json && empty($headers['Content-Type'])) {
            $auth_headers['Content-Type'] = 'application/json';
        }
        $args = [
            'method'  => $method,
            'headers' => $auth_headers,
            'timeout' => 45,
        ];

        if (!is_null($body)) {
            $args['body'] = $is_json ? wp_json_encode($body) : $body;
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $res_body = wp_remote_retrieve_body($response);
        if ($code >= 300) {
            $safe_url = $this->safe_url($url);
            $snippet = (string) $res_body;
            if (strlen($snippet) > 3000) {
                $snippet = substr($snippet, 0, 3000) . "\n...(truncated)";
            }
            $body_dump = '';
            if (!is_null($body)) {
                if ($is_json) {
                    $body_dump = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                } else {
                    $body_dump = '[binary or non-json body]';
                }
            }
            $msg = "Gemini API error: {$code}\nURL: {$safe_url}\nMethod: {$method}\nResponse: {$snippet}";
            if ($body_dump !== '') {
                $msg .= "\nRequestBody: {$body_dump}";
            }
            return new WP_Error('api_error', $msg, [
                'status' => $code,
                'url'    => $safe_url,
            ]);
        }

        if ($is_json) {
            $decoded = json_decode($res_body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error('json_error', 'Failed to decode response JSON.');
            }
            return $decoded;
        }

        return $res_body;
    }

    public function create_store($display_name) {
        $url = self::BASE_URL . '/v1beta/fileSearchStores';
        return $this->request('POST', $url, [
            'displayName' => $display_name,
        ]);
    }

    public function delete_store($store_name) {
        $url = self::BASE_URL . '/v1beta/' . $store_name . '?force=true';
        return $this->request('DELETE', $url, null, [], false);
    }

    public function upload_file($file_path, $display_name, $mime_type) {
        $query = http_build_query([
            'uploadType' => 'media',
            'key'        => $this->api_key,
        ]);
        $url = self::BASE_URL . '/upload/v1beta/files?' . $query;

        $size = @filesize($file_path);
        $headers = [
            'Content-Type' => $mime_type ?: 'application/octet-stream',
        ];
        if ($size !== false) {
            $headers['Content-Length'] = (string) $size;
        }

        $body = file_get_contents($file_path);
        if ($body === false) {
            return new WP_Error('file_read_error', 'Failed to read the uploaded file.');
        }
        $res = $this->request('POST', $url, $body, $headers, false);
        if (is_wp_error($res)) {
            return $res;
        }
        $decoded = json_decode($res, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Failed to decode upload response.');
        }
        return $decoded;
    }

    public function upload_file_to_store($store_name, $file_path, $display_name, $mime_type) {
        // Direct upload into FileSearchStore using media upload.
        // POST https://generativelanguage.googleapis.com/upload/v1beta/{fileSearchStore}:uploadToFileSearchStore?uploadType=media&key=API_KEY
        $query = http_build_query([
            'uploadType' => 'media',
            'key'        => $this->api_key,
        ]);
        $url = self::BASE_URL . '/upload/v1beta/' . $store_name . ':uploadToFileSearchStore?' . $query;

        $size = @filesize($file_path);
        $headers = [
            'Content-Type' => $mime_type ?: 'application/octet-stream',
        ];
        if ($size !== false) {
            $headers['Content-Length'] = (string) $size;
        }

        $body = file_get_contents($file_path);
        if ($body === false) {
            return new WP_Error('file_read_error', 'Failed to read the uploaded file.');
        }
        $res = $this->request('POST', $url, $body, $headers, false);
        if (is_wp_error($res)) {
            return $res;
        }
        $decoded = json_decode($res, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Failed to decode upload-to-store response.');
        }
        return $decoded;
    }

    public function select_manual_answer($question, $candidates) {
        $url = self::BASE_URL . '/v1beta/models/' . self::MODEL_ID . ':generateContent';
        $candidate_texts = [];
        foreach ($candidates as $idx => $cand) {
            $candidate_texts[] = "ID:" . $cand['id'] . " | 質問パターン: " . $cand['question_pattern'] . " | 回答: " . $cand['answer_text'];
        }
        $instruction = "あなたはFAQの回答選択エージェントです。以下の候補リストの中から、ユーザーの質問に対し**完全に意味が一致するか、極めて高い関連性を持つ**回答を1つだけ選んでください。\n\n"
            . "候補リスト:\n" . implode("\n", $candidate_texts) . "\n\n"
            . "ルール:\n"
            . "1. 質問の意味が候補の「質問パターン」と合致する場合のみ、そのIDを選んでください。\n"
            . "2. 単にキーワードが含まれている程度や、曖昧な場合は選ばないでください。\n"
            . "3. 適切なものがなければ 'NO_MATCH' と答えてください。\n"
            . "4. 回答は 'ID:<数字>' または 'NO_MATCH' の形式のみで出力してください。";

        $body = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $instruction],
                        ['text' => 'ユーザー質問: ' . $question],
                    ],
                ],
            ],
            'generation_config' => [
                'temperature' => 0,
                'max_output_tokens' => 32,
            ],
        ];
        $res = $this->request('POST', $url, $body);
        if (is_wp_error($res)) {
            return $res;
        }
        $text = $res['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $latency = null; // Latency tracking logic can be added if needed
        if (preg_match('/ID:\\s*(\\d+)/', $text, $m)) {
            return ['id' => (int)$m[1], 'latency_ms' => $latency];
        }
        return [];
    }

    public function import_file($store_name, $file_name) {
        // REST requires API key on query in many endpoints; align with docs.
        $query = http_build_query(['key' => $this->api_key]);
        $url = self::BASE_URL . '/v1beta/' . $store_name . ':importFile?' . $query;
        return $this->request('POST', $url, [
            'fileName' => $file_name,
        ]);
    }

    public function delete_file($file_name) {
        $url = self::BASE_URL . '/v1beta/' . $file_name;
        return $this->request('DELETE', $url, null, [], false);
    }

    public function get_file($file_name) {
        $url = self::BASE_URL . '/v1beta/' . $file_name;
        return $this->request('GET', $url, null, [], true);
    }

    public function wait_file_active($file_name, $timeout_seconds = 120, $interval_seconds = 3) {
        $start = time();
        while (true) {
            $file = $this->get_file($file_name);
            if (is_wp_error($file)) {
                return $file;
            }
            $state = $file['state'] ?? 'STATE_UNSPECIFIED';
            if ($state === 'ACTIVE') {
                return $file;
            }
            if ($state === 'FAILED') {
                $details = wp_json_encode($file, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                return new WP_Error('file_failed', "File processing failed.\n{$details}");
            }
            if (time() - $start > $timeout_seconds) {
                $details = wp_json_encode($file, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                return new WP_Error('file_timeout', "File is not ACTIVE yet (state={$state}). Please wait and retry.\n{$details}");
            }
            sleep($interval_seconds);
        }
    }

    public function get_operation($operation_name) {
        $url = self::BASE_URL . '/v1beta/' . $operation_name;
        return $this->request('GET', $url, null, [], true);
    }

    public function wait_operation($operation_name, $timeout_seconds = 60, $interval_seconds = 3) {
        $start = time();
        while (true) {
            $op = $this->get_operation($operation_name);
            if (is_wp_error($op)) {
                return $op;
            }
            if (!empty($op['done'])) {
                return $op;
            }
            if (time() - $start > $timeout_seconds) {
                return new WP_Error('operation_timeout', 'Import operation timed out.');
            }
            sleep($interval_seconds);
        }
    }

    public function generate_content($store_name, $prompt, $ground_rules) {
        $url = self::BASE_URL . '/v1beta/models/' . self::MODEL_ID . ':generateContent';
        $body = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [
                        ['text' => $ground_rules],
                        ['text' => $prompt],
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
        ];
        return $this->request('POST', $url, $body);
    }
}
