<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_Admin_Upload {
    public static function register_menu() {
        add_submenu_page(
            'options-general.php',
            __('チャットボット資料アップロード', 'chatbot'),
            __('チャットボット資料アップロード', 'chatbot'),
            'manage_options',
            'chatbot-upload',
            [self::class, 'render_page']
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $sets = Chatbot_Repository::list_knowledge_sets();
        $notices = [];
        $uploaded = !empty($_GET['uploaded']);
        $deleted = !empty($_GET['deleted']);
        $error_msg = sanitize_text_field($_GET['error'] ?? '');
        $warning_msg = sanitize_text_field($_GET['warning'] ?? '');
        if ($uploaded) {
            $notices[] = [
                'class' => 'notice-info',
                'text' => __('アップロードを受け付けました（インデックス待ち）', 'chatbot'),
            ];
        }
        if ($deleted) {
            $notices[] = [
                'class' => 'notice-info',
                'text' => __('ファイルを削除しました', 'chatbot'),
            ];
        }
        if ($error_msg) {
            $notices[] = [
                'class' => 'notice-error',
                'text' => sprintf(__('エラー: %s', 'chatbot'), $error_msg),
            ];
        }
        if ($warning_msg) {
            $notices[] = [
                'class' => 'notice-warning',
                'text' => sprintf(__('警告: %s', 'chatbot'), $warning_msg),
            ];
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('チャットボット資料アップロード', 'chatbot'); ?></h1>
            <?php foreach ($notices as $notice): ?>
                <div class="notice <?php echo esc_attr($notice['class']); ?>"><p><?php echo esc_html($notice['text']); ?></p></div>
            <?php endforeach; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('chatbot_upload_file'); ?>
                <input type="hidden" name="action" value="chatbot_upload_file" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">資料セット</th>
                        <td>
                            <label>
                                <input type="radio" name="set_mode" value="existing" checked />
                                既存の資料セットを選択
                            </label><br/>
                            <select name="dataset_existing">
                                <option value=""><?php esc_html_e('選択してください', 'chatbot'); ?></option>
                                <?php foreach ($sets as $s): ?>
                                    <option value="<?php echo esc_attr($s->slug); ?>">
                                        <?php echo esc_html($s->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p style="margin:8px 0 4px 0;">
                                <label>
                                    <input type="radio" name="set_mode" value="new" />
                                    新しい資料セットを作成
                                </label>
                            </p>
                            <div style="border:1px solid #ddd; padding:8px; max-width:480px;">
                                <p><label>スラッグ（必須）<br/>
                                    <input type="text" name="dataset_new_slug" value="" />
                                </label></p>
                                <p><label>名前（必須）<br/>
                                    <input type="text" name="dataset_new_name" value="" />
                                </label></p>
                                <p><label>説明（任意）<br/>
                                    <textarea name="dataset_new_desc" rows="2"></textarea>
                                </label></p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ファイル</th>
                        <td>
                            <input type="file" name="file" accept=".pdf,.txt,.md" required />
                            <p class="description">対応拡張子: pdf, txt, md</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('アップロード', 'chatbot'); ?></button>
                </p>
            </form>

            <h2><?php esc_html_e('アップロード済みファイル一覧', 'chatbot'); ?></h2>
            <?php
            $set_filter = sanitize_text_field($_GET['dataset'] ?? '');
            $files = Chatbot_Repository::list_files($set_filter, 100);
            ?>
            <form method="get" style="margin-bottom:12px;">
                <input type="hidden" name="page" value="chatbot-upload" />
                <label>資料セット:
                    <select name="dataset">
                        <option value=""><?php esc_html_e('すべて', 'chatbot'); ?></option>
                        <?php foreach ($sets as $s): ?>
                            <option value="<?php echo esc_attr($s->slug); ?>" <?php selected($set_filter, $s->slug); ?>>
                                <?php echo esc_html($s->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button"><?php esc_html_e('フィルター', 'chatbot'); ?></button>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>資料セット</th>
                        <th>ファイル名</th>
                        <th>サイズ</th>
                        <th>状態</th>
                        <th>更新日時</th>
                        <th>削除</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($files)): ?>
                        <tr><td colspan="7">データがありません。</td></tr>
                    <?php else: ?>
                        <?php foreach ($files as $f): ?>
                            <tr>
                                <td><?php echo esc_html($f->id); ?></td>
                                <td><?php echo esc_html($f->set_name ?: $f->set_slug); ?></td>
                                <td><?php echo esc_html($f->filename); ?></td>
                                <td><?php echo esc_html(size_format($f->bytes)); ?></td>
                                <td><?php echo esc_html($f->status); ?></td>
                                <td><?php echo esc_html($f->updated_at); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('chatbot_delete_file'); ?>
                                        <input type="hidden" name="action" value="chatbot_delete_file" />
                                        <input type="hidden" name="file_id" value="<?php echo esc_attr($f->id); ?>" />
                                        <button class="button button-small" onclick="return confirm('削除しますか？');">削除</button>
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

add_action('admin_post_chatbot_upload_file', function () {
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    check_admin_referer('chatbot_upload_file');

    $set_mode = sanitize_text_field($_POST['set_mode'] ?? 'existing');
    $dataset = '';
    if ($set_mode === 'new') {
        $slug = sanitize_title($_POST['dataset_new_slug'] ?? '');
        $name = sanitize_text_field($_POST['dataset_new_name'] ?? '');
        $desc = wp_kses_post($_POST['dataset_new_desc'] ?? '');
        if (!$slug || !$name) {
            wp_redirect(add_query_arg(['page' => 'chatbot-upload', 'error' => '新規セット: スラッグと名前は必須です'], admin_url('options-general.php')));
            exit;
        }
        $exists = Chatbot_Repository::get_knowledge_set_by_slug($slug);
        if ($exists) {
            wp_redirect(add_query_arg(['page' => 'chatbot-upload', 'error' => '同じスラッグの資料セットが存在します'], admin_url('options-general.php')));
            exit;
        }
        $new_id = Chatbot_Repository::create_knowledge_set($slug, $name, $desc);
        $dataset = $slug;
    } else {
        $dataset = sanitize_title($_POST['dataset_existing'] ?? '');
        if (!$dataset) {
            wp_redirect(add_query_arg(['page' => 'chatbot-upload', 'error' => '資料セットを選択してください'], admin_url('options-general.php')));
            exit;
        }
    }

    if (empty($_FILES['file'])) {
        wp_redirect(add_query_arg(['page' => 'chatbot-upload', 'error' => 'file required'], admin_url('options-general.php')));
        exit;
    }

    $set = Chatbot_Repository::get_knowledge_set_by_slug($dataset);
    if (!$set) {
        wp_redirect(add_query_arg(['page' => 'chatbot-upload', 'error' => 'dataset not found'], admin_url('options-general.php')));
        exit;
    }

    $settings = Chatbot_Settings::get_settings();
    $file = $_FILES['file'];
    $allowed = ['pdf','txt','md']; // Office除外
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        wp_redirect(add_query_arg(['page' => 'chatbot-upload', 'error' => '拡張子が許可されていません'], admin_url('options-general.php')));
        exit;
    }
    $max_single = intval($settings['max_file_size_mb']) * 1024 * 1024;
    if ($file['size'] > $max_single) {
        wp_redirect(add_query_arg(['page' => 'chatbot-upload', 'error' => '単一ファイルの上限を超えています'], admin_url('options-general.php')));
        exit;
    }

    global $wpdb;
    $table = Chatbot_Repository::get_table('knowledge_files');
    $total = (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(bytes) FROM {$table} WHERE knowledge_set_id = %d", $set->id));
    if ($total + $file['size'] > intval($settings['max_total_bytes'])) {
        wp_redirect(add_query_arg(['page' => 'chatbot-upload', 'error' => '合計容量が上限を超えています'], admin_url('options-general.php')));
        exit;
    }

    $upload = wp_handle_upload($file, ['test_form' => false, 'unique_filename_callback' => null]);
    if (isset($upload['error'])) {
        $error_param = sanitize_text_field($upload['error']);
        wp_redirect(add_query_arg(['page' => 'chatbot-upload', 'error' => $error_param], admin_url('options-general.php')));
        exit;
    }
    $checksum = hash_file('sha256', $upload['file']);
    $file_id = Chatbot_Repository::insert_file($set->id, basename($upload['file']), $upload['file'], $upload['type'], filesize($upload['file']), $checksum);

    $upload_result = Chatbot_File_Sync::upload_to_providers($set, $upload['file'], $file_id);
    $has_success = ($upload_result['gemini_ok'] || $upload_result['openai_ok']);
    $has_errors = !empty($upload_result['errors']);
    if ($has_success) {
        Chatbot_Repository::update_file_status($file_id, 'indexed');
        $args = ['page' => 'chatbot-upload', 'uploaded' => 1];
        if ($has_errors) {
            $args['warning'] = implode(' / ', $upload_result['errors']);
        }
        wp_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    }

    Chatbot_Repository::update_file_status($file_id, 'error');
    $msg = $upload_result['errors'][0] ?? 'Gemini/OpenAI アップロードに失敗しました（APIキー未設定の可能性があります）';
    $msg = sanitize_text_field($msg);
    wp_redirect(add_query_arg(['page' => 'chatbot-upload', 'error' => $msg], admin_url('options-general.php')));
    exit;
});

add_action('admin_post_chatbot_delete_file', function () {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission.'));
    }
    check_admin_referer('chatbot_delete_file');

    $file_id = intval($_POST['file_id'] ?? 0);
    if (!$file_id) {
        wp_redirect(add_query_arg(['page' => 'chatbot-upload', 'error' => 'invalid file id'], admin_url('options-general.php')));
        exit;
    }

    $file = Chatbot_Repository::get_file($file_id);
    if (!$file) {
        wp_redirect(add_query_arg(['page' => 'chatbot-upload', 'error' => 'file not found'], admin_url('options-general.php')));
        exit;
    }

    $remote_errors = Chatbot_File_Sync::delete_remote($file);

    if (!empty($file->storage_path) && file_exists($file->storage_path)) {
        @unlink($file->storage_path);
    }

    Chatbot_Repository::delete_file_row($file_id);

    $redirect_args = ['page' => 'chatbot-upload', 'deleted' => 1];
    if (!empty($remote_errors)) {
        $redirect_args['warning'] = rawurlencode(implode(' / ', $remote_errors));
    }
    wp_redirect(add_query_arg($redirect_args, admin_url('options-general.php')));
    exit;
});
