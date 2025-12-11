<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_Shortcode {
    public static function init() {
        add_shortcode('my_chatbot', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function enqueue() {
        wp_register_style('chatbot-style', CHATBOT_PLUGIN_URL . 'assets/chatbot.css', [], CHATBOT_PLUGIN_VERSION);
        wp_register_script('chatbot-script', CHATBOT_PLUGIN_URL . 'assets/chatbot.js', ['wp-i18n'], CHATBOT_PLUGIN_VERSION, true);
        wp_localize_script('chatbot-script', 'ChatbotConfig', [
            'api' => rest_url(Chatbot_REST::NS . '/chat'),
        ]);
    }

    public static function render($atts) {
        $atts = shortcode_atts([
            'layout' => 'floating',
            'dataset' => '',
            'theme' => 'light',
            'initial_message' => '',
            'placeholder' => '質問を入力してください',
        ], $atts);
        if (empty($atts['dataset'])) {
            return '<div class="chatbot-error">dataset属性が必要です</div>';
        }
        wp_enqueue_style('chatbot-style');
        wp_enqueue_script('chatbot-script');

        $id = 'chatbot-' . uniqid();
        ob_start();
        ?>
        <div class="chatbot-container theme-<?php echo esc_attr($atts['theme']); ?> layout-<?php echo esc_attr($atts['layout']); ?>" data-dataset="<?php echo esc_attr($atts['dataset']); ?>" id="<?php echo esc_attr($id); ?>">
            <?php if ($atts['layout'] === 'floating'): ?>
                <button class="chatbot-toggle"><?php echo esc_html__('チャット', 'chatbot'); ?></button>
            <?php endif; ?>
            <div class="chatbot-window">
                <div class="chatbot-messages">
                    <?php if ($atts['initial_message']): ?>
                        <div class="msg bot"><?php echo esc_html($atts['initial_message']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="chatbot-input">
                    <input type="text" placeholder="<?php echo esc_attr($atts['placeholder']); ?>" />
                    <button class="send"><?php echo esc_html__('送信', 'chatbot'); ?></button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
