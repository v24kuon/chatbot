<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_Normalizer {
    public static function normalize($text) {
        $text = (string) $text;
        // 全角→半角、カタカナ→ひらがな（K はカタカナをひらがなに変換。KVで濁点処理）
        $text = mb_convert_kana($text, 'asKVc');
        // 小文字化
        $text = mb_strtolower($text, 'UTF-8');
        // トリムと連続空白の単一化
        $text = preg_replace('/\s+/u', ' ', trim($text));
        // 記号の一部除去
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', trim($text));
        return $text;
    }

    public static function tokenize($text) {
        $norm = self::normalize($text);
        if ($norm === '') {
            return [];
        }
        return preg_split('/\s+/u', $norm);
    }
}
