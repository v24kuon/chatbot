<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_Settings {
    const OPTION_KEY = 'chatbot_settings';

    public static function register_menu() {
        add_options_page(
            __('チャットボット設定', 'chatbot'),
            __('チャットボット設定', 'chatbot'),
            'manage_options',
            'chatbot-settings',
            [self::class, 'render_settings_page']
        );
    }

    public static function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [self::class, 'sanitize']);

        add_settings_section('chatbot_section_api', __('API Keys', 'chatbot'), '__return_null', 'chatbot-settings');
        add_settings_field('gemini_api_key', 'Gemini APIキー', [self::class, 'field_gemini'], 'chatbot-settings', 'chatbot_section_api');
        add_settings_field('openai_api_key', 'OpenAI APIキー', [self::class, 'field_openai'], 'chatbot-settings', 'chatbot_section_api');
        add_settings_field('test_connection', __('接続テスト', 'chatbot'), [self::class, 'field_test_button'], 'chatbot-settings', 'chatbot_section_api');
        add_settings_field('pdftotext_path', 'pdftotext パス (任意)', [self::class, 'field_pdftotext'], 'chatbot-settings', 'chatbot_section_api');

        add_settings_section('chatbot_section_embed', __('埋め込み設定', 'chatbot'), '__return_null', 'chatbot-settings');
        add_settings_field('embed_provider', __('埋め込みプロバイダ', 'chatbot'), [self::class, 'field_embed_provider'], 'chatbot-settings', 'chatbot_section_embed');
        add_settings_field('embed_model', __('埋め込みモデル', 'chatbot'), [self::class, 'field_embed_model'], 'chatbot-settings', 'chatbot_section_embed');

        add_settings_section('chatbot_section_limits', __('ファイル上限', 'chatbot'), '__return_null', 'chatbot-settings');
        add_settings_field('max_file_size_mb', __('単一ファイル上限 (MB)', 'chatbot'), [self::class, 'field_max_file'], 'chatbot-settings', 'chatbot_section_limits');
        add_settings_field('max_total_bytes', __('合計容量上限 (bytes)', 'chatbot'), [self::class, 'field_total_bytes'], 'chatbot-settings', 'chatbot_section_limits');

        add_settings_section('chatbot_section_rate', __('レート制限', 'chatbot'), '__return_null', 'chatbot-settings');
        add_settings_field('rate_block_minutes', __('429発生時のブロック時間(分)', 'chatbot'), [self::class, 'field_rate_block'], 'chatbot-settings', 'chatbot_section_rate');
        add_settings_field('rate_notice', __('レート制限時の案内文', 'chatbot'), [self::class, 'field_rate_notice'], 'chatbot-settings', 'chatbot_section_rate');

        add_settings_section('chatbot_section_match', __('マッチング', 'chatbot'), '__return_null', 'chatbot-settings');
        add_settings_field('similarity_threshold', __('類似度閾値', 'chatbot'), [self::class, 'field_threshold'], 'chatbot-settings', 'chatbot_section_match');
        add_settings_field('reranker_enabled', __('軽量リランカーを有効化', 'chatbot'), [self::class, 'field_reranker'], 'chatbot-settings', 'chatbot_section_match');
    }

    public static function get_settings() {
        $default_pdftotext = self::detect_pdftotext();
        $defaults = [
            'gemini_api_key' => '',
            'openai_api_key' => '',
            'max_file_size_mb' => 50,
            'max_total_bytes' => 3221225472, // 3GB
            'rate_block_minutes' => 10,
            'rate_notice' => '現在、アクセスが集中しているためご利用いただけない状況となっております。',
            'similarity_threshold' => 0.8,
            'reranker_enabled' => 0,
            'pdftotext_path' => $default_pdftotext,
            'embed_provider' => 'openai',
            'embed_model' => '',
        ];
        $opts = get_option(self::OPTION_KEY, []);
        return wp_parse_args($opts, $defaults);
    }

    public static function sanitize($input) {
        $out = self::get_settings();
        $out['gemini_api_key'] = self::maybe_encrypt(sanitize_text_field($input['gemini_api_key'] ?? ''));
        $out['openai_api_key'] = self::maybe_encrypt(sanitize_text_field($input['openai_api_key'] ?? ''));
        $out['max_file_size_mb'] = max(1, intval($input['max_file_size_mb'] ?? 50));
        $out['max_total_bytes'] = max(1, intval($input['max_total_bytes'] ?? 3221225472));
        $out['rate_block_minutes'] = max(1, intval($input['rate_block_minutes'] ?? 10));
        $out['rate_notice'] = sanitize_text_field($input['rate_notice'] ?? $out['rate_notice']);
        $out['similarity_threshold'] = floatval($input['similarity_threshold'] ?? 0.8);
        $out['reranker_enabled'] = empty($input['reranker_enabled']) ? 0 : 1;
        $out['pdftotext_path'] = sanitize_text_field($input['pdftotext_path'] ?? $out['pdftotext_path']);
        $provider = sanitize_text_field($input['embed_provider'] ?? $out['embed_provider']);
        $out['embed_provider'] = in_array($provider, ['openai','gemini'], true) ? $provider : 'openai';
        $model_in = trim((string) ($input['embed_model'] ?? $out['embed_model']));
        if ($out['embed_provider'] === 'gemini' && $model_in === '') {
            // 空のまま許容（手動入力を促す）
        } elseif ($out['embed_provider'] === 'openai' && $model_in === '') {
            // 空のまま許容
        }
        $out['embed_model'] = sanitize_text_field($model_in);
        return $out;
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Chatbot Settings', 'chatbot'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_KEY);
                do_settings_sections('chatbot-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public static function field_gemini() {
        $settings = self::get_settings();
        $val = self::mask(self::maybe_decrypt($settings['gemini_api_key']));
        printf('<input type="password" name="%s[gemini_api_key]" value="%s" class="regular-text" autocomplete="new-password" />', esc_attr(self::OPTION_KEY), esc_attr($val));
    }

    public static function field_openai() {
        $settings = self::get_settings();
        $val = self::mask(self::maybe_decrypt($settings['openai_api_key']));
        printf('<input type="password" name="%s[openai_api_key]" value="%s" class="regular-text" autocomplete="new-password" />', esc_attr(self::OPTION_KEY), esc_attr($val));
    }

    public static function field_test_button() {
        wp_nonce_field('chatbot_test_provider', '_chatbot_test_nonce');
        echo '<button type="button" class="button" id="chatbot-test-conn">' . esc_html__('接続テスト（スタブ）', 'chatbot') . '</button>';
        ?>
        <script>
        (function($){
            $('#chatbot-test-conn').on('click', function(){
                const $btn = $(this);
                $btn.prop('disabled', true).text('<?php echo esc_js(__('テスト中...', 'chatbot')); ?>');
                $.post(ajaxurl, {
                    action: 'chatbot_test_provider',
                    _chatbot_test_nonce: $('input[name="_chatbot_test_nonce"]').val()
                }).done(function(resp){
                    alert(resp && resp.data ? resp.data.message : 'OK');
                }).fail(function(){
                    alert('エラーが発生しました');
                }).always(function(){
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('接続テスト（スタブ）', 'chatbot')); ?>');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public static function field_max_file() {
        $settings = self::get_settings();
        printf('<input type="number" min="1" name="%s[max_file_size_mb]" value="%d" />', esc_attr(self::OPTION_KEY), intval($settings['max_file_size_mb']));
    }

    public static function field_total_bytes() {
        $settings = self::get_settings();
        printf('<input type="number" min="1" name="%s[max_total_bytes]" value="%d" /> <span class="description">3GB = 3221225472</span>', esc_attr(self::OPTION_KEY), intval($settings['max_total_bytes']));
    }

    public static function field_rate_block() {
        $settings = self::get_settings();
        printf('<input type="number" min="1" name="%s[rate_block_minutes]" value="%d" />', esc_attr(self::OPTION_KEY), intval($settings['rate_block_minutes']));
    }

    public static function field_rate_notice() {
        $settings = self::get_settings();
        printf('<input type="text" name="%s[rate_notice]" value="%s" class="large-text" />', esc_attr(self::OPTION_KEY), esc_attr($settings['rate_notice']));
    }

    public static function field_threshold() {
        $settings = self::get_settings();
        printf('<input type="number" step="0.01" min="0" max="1" name="%s[similarity_threshold]" value="%s" />', esc_attr(self::OPTION_KEY), esc_attr($settings['similarity_threshold']));
    }

    public static function field_reranker() {
        $settings = self::get_settings();
        printf('<label><input type="checkbox" name="%s[reranker_enabled]" value="1" %s /> %s</label>', esc_attr(self::OPTION_KEY), checked(1, $settings['reranker_enabled'], false), esc_html__('軽量リランカーを有効化（YES/NO判定のみ）', 'chatbot'));
    }

    public static function field_pdftotext() {
        $settings = self::get_settings();
        printf('<input type="text" name="%s[pdftotext_path]" value="%s" class="regular-text" placeholder="/usr/bin/pdftotext" /> <span class="description">共有レンタル環境で置き場所を指定する場合に入力</span>', esc_attr(self::OPTION_KEY), esc_attr($settings['pdftotext_path']));
    }

    public static function field_embed_provider() {
        $settings = self::get_settings();
        $val = $settings['embed_provider'];
        ?>
        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[embed_provider]">
            <option value="openai" <?php selected($val, 'openai'); ?>>OpenAI</option>
            <option value="gemini" <?php selected($val, 'gemini'); ?>>Gemini</option>
        </select>
        <?php
    }

    public static function field_embed_model() {
        $settings = self::get_settings();
        printf('<input type="text" name="%s[embed_model]" value="%s" class="regular-text" placeholder="text-embedding-3-small / text-embedding-004 等" />', esc_attr(self::OPTION_KEY), esc_attr($settings['embed_model']));
    }

    public static function ajax_test_provider() {
        check_ajax_referer('chatbot_test_provider', '_chatbot_test_nonce');
        $settings = self::get_settings();
        if (!self::maybe_decrypt($settings['gemini_api_key']) && !self::maybe_decrypt($settings['openai_api_key'])) {
            wp_send_json_error(['message' => 'APIキーが設定されていません'], 400);
        }
        // 実API呼び出しは行わず、キー存在のみで成功とする（安全のため）
        wp_send_json_success(['message' => 'キーが設定されています（スタブ）。実際の接続は実装後にテストしてください。']);
    }

    private static function cipher_key() {
        return defined('AUTH_KEY') ? AUTH_KEY : (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : 'chatbot-secret');
    }

    private static function maybe_encrypt($value) {
        if (empty($value)) {
            return '';
        }
        $key = substr(hash('sha256', self::cipher_key()), 0, 32);
        $iv = substr(hash('sha256', 'chatbot-iv'), 0, 16);
        $enc = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
        return $enc ?: $value;
    }

    public static function maybe_decrypt($value) {
        if (empty($value)) {
            return '';
        }
        $key = substr(hash('sha256', self::cipher_key()), 0, 32);
        $iv = substr(hash('sha256', 'chatbot-iv'), 0, 16);
        $dec = openssl_decrypt($value, 'AES-256-CBC', $key, 0, $iv);
        return $dec !== false ? $dec : $value;
    }

    public static function get_api_key($provider) {
        $settings = self::get_settings();
        if ($provider === 'gemini') {
            return self::maybe_decrypt($settings['gemini_api_key'] ?? '');
        }
        if ($provider === 'openai') {
            return self::maybe_decrypt($settings['openai_api_key'] ?? '');
        }
        return '';
    }

    private static function mask($value) {
        if (empty($value)) {
            return '';
        }
        if (strlen($value) <= 4) {
            return str_repeat('*', strlen($value));
        }
        return substr($value, 0, 2) . str_repeat('*', max(2, strlen($value) - 4)) . substr($value, -2);
    }

    private static function detect_pdftotext() {
        $candidates = [
            CHATBOT_PLUGIN_DIR . 'bin64/pdftotext',
            CHATBOT_PLUGIN_DIR . 'bin/pdftotext',
        ];
        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        return '';
    }
}

add_action('wp_ajax_chatbot_test_provider', ['Chatbot_Settings', 'ajax_test_provider']);
