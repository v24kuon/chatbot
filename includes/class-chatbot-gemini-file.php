<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gemini File Search 連携ヘルパー（簡易実装）。
 * - ストア作成: POST /v1beta/fileSearchStores?key=API_KEY
 * - ストアへのアップロード: POST /upload/v1beta/fileSearchStores/{store}/:uploadToFileSearchStore?key=API_KEY
 * - ファイル削除: DELETE /v1beta/fileSearchStores/{store}/documents/{doc}?key=API_KEY
 *
 * 注意: エンドポイント仕様は公式ドキュメントに準拠。失敗時はエラーメッセージを返すのみ。
 */
class Chatbot_Gemini_File {
    const BASE = 'https://generativelanguage.googleapis.com';

    public static function ensure_store($api_key, $set) {
        if (!empty($set->store_name)) {
            return $set->store_name;
        }

        $name = 'store-' . sanitize_title($set->slug);
        $url = self::BASE . '/v1beta/fileSearchStores';
        $url = add_query_arg('key', $api_key, $url);
        $payload = [
            'display_name' => $name,
        ];

        $resp = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($resp)) {
            return new WP_Error('gemini_store', $resp->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code >= 200 && $code < 300 && !empty($body['name'])) {
            $store_name = $body['name'];
            Chatbot_Repository::update_store_name($set->id, $store_name);
            return $store_name;
        }

        $msg = $body['error']['message'] ?? 'store create failed';
        return new WP_Error('gemini_store', $msg);
    }

    public static function upload_file($api_key, $store_name, $file_path) {
        if (!file_exists($file_path)) {
            return new WP_Error('upload', 'file not found');
        }

        // リソース名のセグメントごとにエンコードし、スラッシュを維持
        $resource = ltrim($store_name, '/');
        $resource = implode('/', array_map('rawurlencode', explode('/', $resource)));
        $url = self::BASE . '/upload/v1beta/' . $resource . ':uploadToFileSearchStore';
        $url = add_query_arg('key', $api_key, $url);

        $boundary = wp_generate_uuid4();
        $file_contents = file_get_contents($file_path);
        if ($file_contents === false) {
            return new WP_Error('gemini_upload', 'failed to read file');
        }
        $filename = basename($file_path);

        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $file_contents . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $resp = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
            'timeout' => 60,
        ]);

        if (is_wp_error($resp)) {
            return new WP_Error('gemini_upload', $resp->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code >= 200 && $code < 300 && !empty($data['file']['name'])) {
            return $data['file']['name'];
        }

        $msg = $data['error']['message'] ?? 'upload failed';
        return new WP_Error('gemini_upload', $msg);
    }

    /**
     * Delete a file by full resource name (fileSearchStores/{store_id}/documents/{doc_id}).
     *
     * @param string $api_key API key for Gemini File Search.
     * @param string $file_id Full resource name: fileSearchStores/{store}/documents/{doc}.
     * @return true|WP_Error
     */
    public static function delete_file($api_key, $file_id) {
        // Google Generative Language API は DELETE メソッドを要求
        $resource = ltrim($file_id, '/');
        $resource = implode('/', array_map('rawurlencode', explode('/', $resource)));
        $url = self::BASE . '/v1beta/' . $resource;
        $url = add_query_arg('key', $api_key, $url);

        $resp = wp_remote_request($url, ['method' => 'DELETE', 'timeout' => 20]);
        if (is_wp_error($resp)) {
            return $resp;
        }

        $code = wp_remote_retrieve_response_code($resp);
        if ($code >= 200 && $code < 300) {
            return true;
        }

        $data = json_decode(wp_remote_retrieve_body($resp), true);
        $msg = $data['error']['message'] ?? 'delete failed';
        return new WP_Error('gemini_delete', $msg);
    }
}
