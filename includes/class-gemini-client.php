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
                // Keep the original (untruncated) response body for downstream parsing.
                'response_body' => $res_body,
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
        $instruction = "あなたはFAQの回答選択エージェントです。以下の候補リストの中から、ユーザーの質問に対して**意味的に同じ、または同じ意図を持つ**ものを1つ選んでください。\n\n"
            . "候補リスト:\n" . implode("\n", $candidate_texts) . "\n\n"
            . "ルール:\n"
            . "1. 表現が異なっていても、質問の**意図や聞きたい内容が同じ**であれば選んでください。\n"
            . "   例: 「終了時間は？」と「いつ終わるの？」「何時に終わる？」「終わりは何時？」は同じ意図です。\n"
            . "2. 質問の意図が候補のどれとも一致しない場合のみ 'NO_MATCH' と答えてください。\n"
            . "3. 回答は 'ID:<数字>' または 'NO_MATCH' の形式のみで出力してください。";

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

    private function normalize_display_name($text) {
        $text = is_string($text) ? $text : '';
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        return mb_convert_kana($text, 'KVas');
    }

    public function list_documents($store_name, $page_size = 20, $page_token = '') {
        $page_size = (int) $page_size;
        if ($page_size < 1) {
            $page_size = 1;
        }
        if ($page_size > 20) {
            $page_size = 20;
        }

        $query = [
            'pageSize' => $page_size,
        ];
        if (!empty($page_token)) {
            $query['pageToken'] = (string) $page_token;
        }
        $url = self::BASE_URL . '/v1beta/' . $store_name . '/documents?' . http_build_query($query);
        return $this->request('GET', $url, null, [], true);
    }

    public function delete_document_by_display_name($store_name, $display_name) {
        $target = $this->normalize_display_name($display_name);
        if ($target === '') {
            return new WP_Error('invalid_display_name', 'ファイル名が不正です。');
        }

        $token = '';
        $matches = [];
        for ($i = 0; $i < 50; $i++) { // up to 1000 docs (20 * 50)
            $res = $this->list_documents($store_name, 20, $token);
            if (is_wp_error($res)) {
                return $res;
            }
            $docs = $res['documents'] ?? [];
            if (is_array($docs)) {
                foreach ($docs as $doc) {
                    if (!is_array($doc)) {
                        continue;
                    }
                    $dn = $this->normalize_display_name($doc['displayName'] ?? '');
                    if ($dn !== '' && $dn === $target) {
                        $name = isset($doc['name']) ? (string) $doc['name'] : '';
                        if ($name === '') {
                            continue;
                        }
                        $matches[] = $name;
                    }
                }
            }

            $token = isset($res['nextPageToken']) ? (string) $res['nextPageToken'] : '';
            if ($token === '') {
                break;
            }
        }

        if (count($matches) === 1) {
            return $this->delete_file($matches[0]);
        }
        if (count($matches) === 0) {
            return new WP_Error(
                'document_not_found',
                'Gemini上のドキュメントを特定できませんでした。全削除、または再アップロードをお試しください。'
            );
        }

        return new WP_Error(
            'ambiguous_document',
            '同名のドキュメントが複数存在するため削除を中断しました。全削除、または対象を特定したうえで再度お試しください。'
        );
    }

    public function delete_file($file_name) {
        // Use force=true so that related chunks are also removed per Gemini docs.
        $url = self::BASE_URL . '/v1beta/' . $file_name . '?force=true';
        $res = $this->request('DELETE', $url, null, [], false);

        // Check for specific error: "Cannot delete non-empty Document"
        if (is_wp_error($res)) {
            $error_data    = $res->get_error_data();
            $status        = isset($error_data['status']) ? (int) $error_data['status'] : 0;
            $error_message = $res->get_error_message();
            $raw_body      = isset($error_data['response_body']) && is_string($error_data['response_body'])
                ? $error_data['response_body']
                : '';
            $document_not_empty_message = 'このドキュメントは空でないため削除できません。ストア全体を削除するか、Geminiの管理画面から削除してください（force=true で再試行すると解消する可能性があります）。';

            // Check if this is a 400 error with "Cannot delete non-empty Document"
            if ($status === 400) {
                // Prefer parsing the raw response body (untruncated) if available.
                if ($raw_body !== '') {
                    $decoded = json_decode($raw_body, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $api_error_message = $decoded['error']['message'] ?? '';
                        if (is_string($api_error_message) && strpos($api_error_message, 'Cannot delete non-empty Document') !== false) {
                            return new WP_Error(
                                'document_not_empty',
                                $document_not_empty_message
                            );
                        }
                    } elseif (preg_match('/"message"\s*:\s*"([^"]+)"/', $raw_body, $matches)) {
                        $api_error_message = $matches[1];
                        if (strpos($api_error_message, 'Cannot delete non-empty Document') !== false) {
                            return new WP_Error(
                                'document_not_empty',
                                $document_not_empty_message
                            );
                        }
                    }
                }

                // Fallback: check the formatted error message (may be truncated)
                if (strpos($error_message, 'Cannot delete non-empty Document') !== false) {
                    return new WP_Error(
                        'document_not_empty',
                        $document_not_empty_message
                    );
                }
            }
        }

        return $res;
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
