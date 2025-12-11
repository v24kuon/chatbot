<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_Admin_Logs {
    public static function register_menu() {
        add_submenu_page(
            'options-general.php',
            __('チャットボットログ', 'chatbot'),
            __('チャットボットログ', 'chatbot'),
            'manage_options',
            'chatbot-logs',
            [self::class, 'render_page']
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $dataset = sanitize_text_field($_GET['dataset'] ?? '');
        $unanswered_only = !empty($_GET['unanswered']) ? 1 : 0;
        $messages = Chatbot_Repository::get_messages($dataset, $unanswered_only, 50);
        $sets = Chatbot_Repository::list_knowledge_sets();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('チャットボットログ', 'chatbot'); ?></h1>
            <form method="get">
                <input type="hidden" name="page" value="chatbot-logs" />
                <label>資料セット:
                    <select name="dataset">
                        <option value="">(すべて)</option>
                        <?php foreach ($sets as $s): ?>
                            <option value="<?php echo esc_attr($s->slug); ?>" <?php selected($dataset, $s->slug); ?>>
                                <?php echo esc_html($s->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <input type="checkbox" name="unanswered" value="1" <?php checked(1, $unanswered_only); ?> />
                    未回答のみ
                </label>
                <button class="button"><?php esc_html_e('フィルター', 'chatbot'); ?></button>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>資料セット</th>
                        <th>日時</th>
                        <th>質問</th>
                        <th>回答</th>
                        <th>未回答</th>
                        <th>手動回答登録</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($messages)): ?>
                        <tr><td colspan="7">データがありません。</td></tr>
                    <?php else: ?>
                        <?php foreach ($messages as $m): ?>
                            <tr>
                                <td><?php echo esc_html($m->id); ?></td>
                                <td><?php echo esc_html($m->set_name ?: $m->set_slug); ?></td>
                                <td><?php echo esc_html($m->created_at); ?></td>
                                <td><?php echo esc_html(mb_strimwidth($m->question, 0, 200, '...')); ?></td>
                                <td><?php echo esc_html(mb_strimwidth($m->answer, 0, 200, '...')); ?></td>
                                <td><?php echo $m->unanswered_flag ? '✔' : ''; ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;flex-direction:column;gap:4px;">
                                        <?php wp_nonce_field('chatbot_save_manual'); ?>
                                        <input type="hidden" name="action" value="chatbot_save_manual" />
                                        <input type="hidden" name="message_id" value="<?php echo esc_attr($m->id); ?>" />
                                        <input type="hidden" name="knowledge_set_id" value="<?php echo esc_attr($m->knowledge_set_id); ?>" />
                                        <label>質問:
                                            <textarea name="question" rows="2" readonly><?php echo esc_textarea($m->question); ?></textarea>
                                        </label>
                                        <label>回答:
                                            <textarea name="answer_text" rows="2" required></textarea>
                                        </label>
                                        <button class="button button-primary">登録</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

add_action('admin_post_chatbot_save_manual', function () {
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    check_admin_referer('chatbot_save_manual');
    $msg_id = intval($_POST['message_id'] ?? 0);
    $set_id = intval($_POST['knowledge_set_id'] ?? 0);
    $question = sanitize_textarea_field($_POST['question'] ?? '');
    $answer = sanitize_textarea_field($_POST['answer_text'] ?? '');
    if (!$msg_id || !$set_id || $question === '' || $answer === '') {
        wp_redirect(add_query_arg(['page' => 'chatbot-logs', 'error' => 'missing'], admin_url('options-general.php')));
        exit;
    }

    $vec = Chatbot_Embedder::embed_text($answer);
    $dim = Chatbot_Embedder::dimension($vec);
    Chatbot_Repository::insert_manual_answer($set_id, $question, $answer, $vec, $dim);
    wp_redirect(add_query_arg(['page' => 'chatbot-logs', 'saved' => 1], admin_url('options-general.php')));
    exit;
});
