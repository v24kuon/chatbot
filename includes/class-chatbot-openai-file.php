<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_OpenAI_File {
    const BASE = 'https://api.openai.com';

    public static function upload_file($api_key, $file_path) {
        if (!file_exists($file_path)) {
            return new WP_Error('openai_upload', 'file not found');
        }
        $url = self::BASE . '/v1/files';

        $boundary = wp_generate_uuid4();
        $filename = basename($file_path);
        $file_contents = file_get_contents($file_path);

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"purpose\"\r\n\r\n";
        $body .= "assistants\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $file_contents . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $resp = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
            'timeout' => 60,
        ]);

        if (is_wp_error($resp)) {
            return new WP_Error('openai_upload', $resp->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code >= 200 && $code < 300 && !empty($data['id'])) {
            return $data['id'];
        }
        $msg = $data['error']['message'] ?? 'upload failed';
        return new WP_Error('openai_upload', $msg);
    }

    public static function delete_file($api_key, $file_id) {
        $url = self::BASE . '/v1/files/' . rawurlencode($file_id);
        $resp = wp_remote_request($url, [
            'method' => 'DELETE',
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'timeout' => 20,
        ]);
        if (is_wp_error($resp)) {
            return $resp;
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code >= 200 && $code < 300) {
            return true;
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        $msg = $data['error']['message'] ?? 'delete failed';
        return new WP_Error('openai_delete', $msg);
    }
}
