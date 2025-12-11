<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * テキスト抽出ヘルパー。
 * - PDF: pdftotext(poppler/Xpdf)が設定されていれば優先。無ければ簡易フィルタ。
 * - txt/md: そのまま読み込み。
 * - その他: 簡易フィルタでプレーン化。
 */
class Chatbot_Extractor {
    public static function extract($path, $mime) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['txt', 'md'], true)) {
            return self::read_text($path);
        }
        if ($ext === 'pdf') {
            $txt = self::extract_pdf($path);
            if ($txt !== '') {
                return $txt;
            }
        }
        return self::fallback_binary($path);
    }

    private static function read_text($path) {
        $data = @file_get_contents($path);
        return $data === false ? '' : $data;
    }

    private static function extract_pdf($path) {
        $settings = Chatbot_Settings::get_settings();
        $bin = trim((string) ($settings['pdftotext_path'] ?? ''));
        if ($bin === '') {
            return '';
        }
        if (!is_executable($bin)) {
            return '';
        }
        // 日本語対応: xpdf-japanese が同梱されていれば cfg を指定する
        $cfg = '';
        $candidate_cfg = CHATBOT_PLUGIN_DIR . 'bin64/xpdf-japanese/add-to-xpdfrc';
        if (file_exists($candidate_cfg)) {
            $cfg = $candidate_cfg;
        }

        // proc_open で標準出力と標準エラーを分けて取得
        // オプションは入力ファイルの前に指定する必要がある
        $cmd = escapeshellcmd($bin);
        if ($cfg) {
            $cmd .= ' -cfg ' . escapeshellarg($cfg);
        }
        $cmd .= ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' -';

        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];
        $process = @proc_open($cmd, $descriptorspec, $pipes);
        if (!is_resource($process)) {
            Chatbot_Cron::log_error(0, 'pdftotext実行失敗: proc_openが失敗しました');
            return '';
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0 || trim($stdout) === '') {
            $error_msg = 'pdftotext実行失敗 (終了コード: ' . $code . ')';
            if ($stderr) {
                $error_msg .= ' - エラー: ' . trim($stderr);
            }
            if (class_exists('Chatbot_Cron')) {
                Chatbot_Cron::log_error(0, $error_msg);
            }
            return '';
        }

        return $stdout;
    }

    private static function fallback_binary($path) {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return '';
        }
        $converted = preg_replace('/[^\P{C}\n\r\t]+/u', ' ', $raw);
        $converted = $converted ?: '';
        return mb_substr($converted, 0, 20000);
    }
}
