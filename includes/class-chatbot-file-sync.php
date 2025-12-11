<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_File_Sync {
    public static function upload_to_providers($set, $file_path, $file_id) {
        $api_key_g = Chatbot_Settings::get_api_key('gemini');
        $api_key_o = Chatbot_Settings::get_api_key('openai');

        $gemini_ok = false;
        $openai_ok = false;
        $errors = [];

        // Gemini
        if ($api_key_g) {
            $store = Chatbot_Gemini_File::ensure_store($api_key_g, $set);
            if (is_wp_error($store)) {
                $errors[] = 'Geminiストア作成失敗: ' . $store->get_error_message();
                Chatbot_Cron::log_error($file_id, end($errors));
            } else {
                $up = Chatbot_Gemini_File::upload_file($api_key_g, $store, $file_path);
                if (is_wp_error($up)) {
                    $errors[] = 'Geminiアップロード失敗: ' . $up->get_error_message();
                    Chatbot_Cron::log_error($file_id, end($errors));
                } else {
                    Chatbot_Repository::update_store_name($set->id, $store);
                    Chatbot_Repository::update_remote_file_id($file_id, $up);
                    $gemini_ok = true;
                }
            }
        }

        // OpenAI
        if ($api_key_o) {
            $up = Chatbot_OpenAI_File::upload_file($api_key_o, $file_path);
            if (is_wp_error($up)) {
                $errors[] = 'OpenAIアップロード失敗: ' . $up->get_error_message();
                Chatbot_Cron::log_error($file_id, end($errors));
            } else {
                Chatbot_Repository::update_remote_file_id_openai($file_id, $up);
                $openai_ok = true;
            }
        }

        return [
            'gemini_ok' => $gemini_ok,
            'openai_ok' => $openai_ok,
            'errors' => $errors,
        ];
    }

    public static function delete_remote($file) {
        $api_key_g = Chatbot_Settings::get_api_key('gemini');
        $api_key_o = Chatbot_Settings::get_api_key('openai');
        $errors = [];

        if ($api_key_g && !empty($file->remote_file_id)) {
            $res = Chatbot_Gemini_File::delete_file($api_key_g, $file->remote_file_id);
            if (is_wp_error($res)) {
                $errors[] = 'Gemini削除失敗: ' . $res->get_error_message();
                Chatbot_Cron::log_error($file->id, end($errors));
            }
        }

        if ($api_key_o && !empty($file->remote_file_id_openai)) {
            $res = Chatbot_OpenAI_File::delete_file($api_key_o, $file->remote_file_id_openai);
            if (is_wp_error($res)) {
                $errors[] = 'OpenAI削除失敗: ' . $res->get_error_message();
                Chatbot_Cron::log_error($file->id, end($errors));
            }
        }

        return $errors;
    }
}
