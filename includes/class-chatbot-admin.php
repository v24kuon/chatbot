<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_Admin {
    private $option_api_key = 'chatbot_gemini_api_key';
    private $option_store   = 'chatbot_gemini_store';
    private $option_files   = 'chatbot_gemini_files';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_chatbot_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_chatbot_upload', [$this, 'handle_upload']);
        add_action('admin_post_chatbot_delete_all', [$this, 'handle_delete_all']);
        add_action('admin_post_chatbot_delete_file', [$this, 'handle_delete_file']);
        add_action('admin_post_chatbot_save_manual', [$this, 'handle_save_manual']);
        add_action('admin_post_chatbot_delete_manual', [$this, 'handle_delete_manual']);
        add_action('admin_notices', [$this, 'render_notices']);
    }

    public function register_menu() {
        add_menu_page(
            'Gemini Chatbot',
            'Gemini Chatbot',
            'manage_options',
            'gemini-chatbot',
            [$this, 'render_page'],
            'dashicons-format-chat',
            80
        );
        add_submenu_page(
            'gemini-chatbot',
            'チャットログ',
            'チャットログ',
            'manage_options',
            'gemini-chatbot-logs',
            [$this, 'render_logs_page']
        );
        add_submenu_page(
            'gemini-chatbot',
            '手動回答',
            '手動回答',
            'manage_options',
            'gemini-chatbot-manual',
            [$this, 'render_manual_page']
        );
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $store = get_option($this->option_store, '');
        $files = get_option($this->option_files, []);
        ?>
        <div class="wrap">
            <h1>Gemini File Search Chatbot</h1>
            <hr />

            <h2>APIキー設定</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('chatbot_save_settings'); ?>
                <input type="hidden" name="action" value="chatbot_save_settings" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="chatbot_api_key">API Key</label></th>
                        <td>
                            <input type="password" id="chatbot_api_key" name="api_key" class="regular-text" placeholder="保存済みキーは非表示" />
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary">保存</button>
                </p>
            </form>

            <h2>ストア管理</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
                <?php wp_nonce_field('chatbot_save_settings'); ?>
                <input type="hidden" name="action" value="chatbot_save_settings" />
                <input type="hidden" name="create_store" value="1" />
                <p>
                    <button type="submit" class="button">ストアを作成/再作成</button>
                    <span style="margin-left:8px;">現在: <?php echo $store ? esc_html($store) : '未作成'; ?></span>
                </p>
            </form>

            <h2>資料アップロード</h2>
            <?php if (!$store): ?>
                <p>ストアを先に作成してください。</p>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field('chatbot_upload'); ?>
                    <input type="hidden" name="action" value="chatbot_upload" />
                    <input type="file" name="chatbot_file" required />
                    <button type="submit" class="button">アップロード</button>
                </form>
                <?php if (!empty($files)): ?>
                    <h3>登録済みファイル</h3>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>表示名</th>
                                <th>Document ID</th>
                                <th>MIME</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($files as $idx => $file): ?>
                            <tr>
                                <td><?php echo esc_html($file['original'] ?? ''); ?></td>
                                <td><?php echo esc_html($file['id'] ?? ''); ?></td>
                                <td><?php echo esc_html($file['mime'] ?? ''); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <?php wp_nonce_field('chatbot_delete_file'); ?>
                                        <input type="hidden" name="action" value="chatbot_delete_file" />
                                        <input type="hidden" name="file_index" value="<?php echo esc_attr((string) $idx); ?>" />
                                        <input type="hidden" name="file_id" value="<?php echo esc_attr($file['id'] ?? ''); ?>" />
                                        <input type="hidden" name="file_original" value="<?php echo esc_attr($file['original'] ?? ''); ?>" />
                                        <button class="button button-secondary" onclick="return confirm('このファイルを削除しますか？');">削除</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>

            <h2>全削除</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('chatbot_delete_all'); ?>
                <input type="hidden" name="action" value="chatbot_delete_all" />
                <p>
                    <button class="button button-danger" onclick="return confirm('ストアとアップロード済みファイルを全て削除します。よろしいですか？');">全削除</button>
                </p>
            </form>
        </div>
        <?php
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options') || !check_admin_referer('chatbot_save_settings')) {
            wp_die('forbidden');
        }
        $api = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        if (!empty($api)) {
            update_option($this->option_api_key, $api, false);
        }

        if (isset($_POST['create_store'])) {
            $client = new Gemini_Client();
            $res = $client->create_store('wp-gemini-store');
            if (is_wp_error($res)) {
                $this->redirect_with_message('error', $res->get_error_message());
            } else {
                update_option($this->option_store, $res['name'] ?? '', false);
                update_option($this->option_files, [], false);
                $this->redirect_with_message('success', 'ストアを作成しました: ' . ($res['name'] ?? ''));
            }
        } else {
            $this->redirect_with_message('success', '設定を保存しました。');
        }
    }

    public function handle_upload() {
        if (!current_user_can('manage_options') || !check_admin_referer('chatbot_upload')) {
            wp_die('forbidden');
        }
        $store = get_option($this->option_store, '');
        if (empty($store)) {
            $this->redirect_with_message('error', '先にストアを作成してください。');
        }
        if (empty($_FILES['chatbot_file'])) {
            $this->redirect_with_message('error', 'ファイルを選択してください。');
        }

        $file = $_FILES['chatbot_file'];
        $upload = wp_handle_upload($file, ['test_form' => false]);
        if (isset($upload['error'])) {
            $this->redirect_with_message('error', 'アップロード失敗: ' . $upload['error']);
        }

        $client = new Gemini_Client();
        $mime = $upload['type'] ?? 'application/octet-stream';

        // Use direct upload to FileSearchStore.
        $upload_res = $client->upload_file_to_store($store, $upload['file'], $file['name'], $mime);

        if (is_wp_error($upload_res)) {
            $this->redirect_with_message('error', 'ストア直接アップロード失敗: ' . $upload_res->get_error_message());
        }

        $op_name = $upload_res['name'] ?? '';
        if (empty($op_name)) {
            $details = wp_json_encode($upload_res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $this->redirect_with_message('error', "ストア直接アップロード応答に operation name がありません。\nUpload response:\n{$details}");
        }

        $op_res = $client->wait_operation($op_name, 180, 3);
        if (is_wp_error($op_res)) {
            $this->redirect_with_message('error', 'アップロード待機失敗: ' . $op_res->get_error_message());
        }

        // Try to extract the remote document resource name from operation response.
        // Expected formats include:
        // - fileSearchStores/{store}/documents/{document}
        // - files/{file} (legacy/other upload flows)
        $candidates = [
            $op_res['response']['name'] ?? null,
            $op_res['response']['document']['name'] ?? null,
            $op_res['response']['documentName'] ?? null,
            $op_res['response']['file']['name'] ?? null,
            $op_res['response']['fileName'] ?? null,
        ];
        $file_name = '';
        foreach ($candidates as $cand) {
            if (is_string($cand)) {
                $cand = trim($cand);
                if ($cand !== '') {
                    $file_name = $cand;
                    break;
                }
            }
        }
        if ($file_name !== '' && strpos($file_name, 'fileSearchStores/') !== 0 && strpos($file_name, 'files/') !== 0) {
            // Avoid storing a local filename as a remote resource id.
            $file_name = '';
        }

        $files = get_option($this->option_files, []);
        $files[] = [
            'id'       => $file_name,
            'original' => $file['name'],
            'mime'     => $mime,
        ];
        update_option($this->option_files, $files, false);

        $this->redirect_with_message('success', 'アップロードが完了しました。');
    }

    public function render_logs_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'chat_messages';
        $store = isset($_GET['store']) ? sanitize_text_field(wp_unslash($_GET['store'])) : '';
        $unanswered = isset($_GET['unanswered']) && $_GET['unanswered'] === '1';
        $page = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        $where = 'WHERE 1=1';
        $params = [];
        if ($store) {
            $where .= ' AND store_name=%s';
            $params[] = $store;
        }
        if ($unanswered) {
            $where .= ' AND unanswered_flag=1';
        }

        $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $total = (int)$wpdb->get_var("SELECT FOUND_ROWS()");
        $total_pages = max(1, ceil($total / $per_page));
        ?>
        <div class="wrap">
            <h1>チャットログ</h1>
            <form method="get" style="margin:12px 0;">
                <input type="hidden" name="page" value="gemini-chatbot-logs" />
                <label>Store: <input type="text" name="store" value="<?php echo esc_attr($store); ?>" /></label>
                <label style="margin-left:12px;"><input type="checkbox" name="unanswered" value="1" <?php checked($unanswered); ?> /> 未回答のみ</label>
                <button class="button">フィルター</button>
            </form>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th>日時</th><th>Store</th><th>質問</th><th>回答（サマリ）</th><th>未回答</th><th>手動回答登録</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row['id']); ?></td>
                        <td><?php echo esc_html($row['created_at']); ?></td>
                        <td><?php echo esc_html($row['store_name']); ?></td>
                        <td style="max-width:220px;"><?php echo esc_html(mb_strimwidth($row['question'], 0, 200, '...')); ?></td>
                        <td style="max-width:220px;"><?php echo esc_html(mb_strimwidth($row['answer'], 0, 200, '...')); ?></td>
                        <td><?php echo $row['unanswered_flag'] ? 'はい' : 'いいえ'; ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;flex-direction:column;gap:4px;max-width:260px;">
                                <?php wp_nonce_field('chatbot_save_manual'); ?>
                                <input type="hidden" name="action" value="chatbot_save_manual" />
                                <input type="hidden" name="store_name" value="<?php echo esc_attr($row['store_name']); ?>" />
                                <input type="hidden" name="from_message_id" value="<?php echo esc_attr($row['id']); ?>" />
                                <label>質問
                                    <textarea name="question_pattern" rows="2" readonly><?php echo esc_textarea($row['question']); ?></textarea>
                                </label>
                                <label>回答
                                    <textarea name="answer_text" rows="2" required></textarea>
                                </label>
                                <label><input type="checkbox" name="enabled" value="1" checked /> 有効</label>
                                <button class="button button-primary">登録</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p>全 <?php echo esc_html($total); ?> 件 / <?php echo esc_html($total_pages); ?> ページ</p>
        </div>
        <?php
    }

    public function render_manual_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'manual_answers';
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A);
        ?>
        <div class="wrap">
            <h1>手動回答</h1>
            <h2>登録済み</h2>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th>Store</th><th>質問</th><th>回答</th><th>有効</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row['id']); ?></td>
                        <td><?php echo esc_html($row['store_name']); ?></td>
                        <td style="max-width:220px;"><?php echo esc_html(mb_strimwidth($row['question_pattern'], 0, 200, '...')); ?></td>
                        <td style="max-width:220px;"><?php echo esc_html(mb_strimwidth($row['answer_text'], 0, 200, '...')); ?></td>
                        <td><?php echo $row['enabled'] ? 'はい' : 'いいえ'; ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <?php wp_nonce_field('chatbot_delete_manual'); ?>
                                <input type="hidden" name="action" value="chatbot_delete_manual" />
                                <input type="hidden" name="id" value="<?php echo esc_attr($row['id']); ?>" />
                                <button class="button button-secondary" onclick="return confirm('削除しますか？');">削除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function handle_save_manual() {
        if (!current_user_can('manage_options') || !check_admin_referer('chatbot_save_manual')) {
            wp_die('forbidden');
        }
        global $wpdb;
        $table = $wpdb->prefix . 'manual_answers';
        $store = isset($_POST['store_name']) ? sanitize_text_field($_POST['store_name']) : '';
        $question = isset($_POST['question_pattern']) ? wp_kses_post($_POST['question_pattern']) : '';
        $answer = isset($_POST['answer_text']) ? wp_kses_post($_POST['answer_text']) : '';
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        if (empty($store) || empty($question) || empty($answer)) {
            $this->redirect_with_message('error', '入力を確認してください。');
        }
        $wpdb->insert(
            $table,
            [
                'store_name' => $store,
                'question_pattern' => $question,
                'answer_text' => $answer,
                'enabled' => $enabled,
            ],
            ['%s','%s','%s','%d']
        );
        $this->redirect_with_message('success', '手動回答を追加しました。');
    }

    public function handle_delete_manual() {
        if (!current_user_can('manage_options') || !check_admin_referer('chatbot_delete_manual')) {
            wp_die('forbidden');
        }
        global $wpdb;
        $table = $wpdb->prefix . 'manual_answers';
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($id > 0) {
            $wpdb->delete($table, ['id' => $id], ['%d']);
        }
        $this->redirect_with_message('success', '手動回答を削除しました。');
    }

    public function handle_delete_file() {
        if (!current_user_can('manage_options') || !check_admin_referer('chatbot_delete_file')) {
            wp_die('forbidden');
        }
        $store = get_option($this->option_store, '');

        $file_index = isset($_POST['file_index']) ? intval($_POST['file_index']) : -1;
        $file_id = isset($_POST['file_id']) ? sanitize_text_field(wp_unslash($_POST['file_id'])) : '';
        $file_original = isset($_POST['file_original']) ? sanitize_text_field(wp_unslash($_POST['file_original'])) : '';

        // Prefer the stored option entry (avoids tampering and supports legacy entries).
        $files = get_option($this->option_files, []);
        if (is_array($files) && $file_index >= 0 && isset($files[$file_index]) && is_array($files[$file_index])) {
            $stored_id = $files[$file_index]['id'] ?? '';
            $stored_original = $files[$file_index]['original'] ?? '';
            // 送信された値と保存値の整合性を検証し、改ざんを防ぐ
            if ((!empty($file_id) && $stored_id !== $file_id) || (!empty($file_original) && $stored_original !== $file_original)) {
                $this->redirect_with_message('error', 'ファイル情報が一致しません。');
            }
            $file_id = $stored_id ?: $file_id;
            $file_original = $stored_original ?: $file_original;
        }

        if (empty($file_id) && empty($file_original)) {
            $this->redirect_with_message('error', 'ファイル情報が不正です。');
        }

        $client = new Gemini_Client();
        // If we have a proper resource name, delete directly.
        if (!empty($file_id) && (strpos($file_id, 'fileSearchStores/') === 0 || strpos($file_id, 'files/') === 0)) {
            $res = $client->delete_file($file_id);
        } else {
            // Legacy data may have stored only the display name. Resolve by displayName;複数一致なら中断。
            if (empty($store)) {
                $this->redirect_with_message('error', 'ストアが未設定のため削除できません。');
            }
            $name_for_lookup = $file_original ?: $file_id;
            $res = $client->delete_document_by_display_name($store, $name_for_lookup);
        }
        if (is_wp_error($res)) {
            $this->redirect_with_message('error', '削除失敗: ' . $res->get_error_message());
        }
        // Remove from local list (by index when possible; fallback by id/original).
        if (is_array($files) && $file_index >= 0 && isset($files[$file_index])) {
            unset($files[$file_index]);
            $files = array_values($files);
        } else {
            // id と original の両方が一致するもののみ削除（片方一致では削除しない）
            $files = array_filter($files, function ($f) use ($file_id, $file_original) {
                if (!is_array($f)) {
                    return true;
                }
                $id_matches = empty($file_id) ? false : (($f['id'] ?? '') === $file_id);
                $original_matches = empty($file_original) ? false : (($f['original'] ?? '') === $file_original);
                // 両方提示されている場合は両方一致で削除。どちらかしか無い場合はその値で一致したもののみ削除。
                if (!empty($file_id) && !empty($file_original)) {
                    return !($id_matches && $original_matches);
                }
                if (!empty($file_id)) {
                    return !$id_matches;
                }
                if (!empty($file_original)) {
                    return !$original_matches;
                }
                return true; // ここには来ない想定
            });
            $files = array_values($files);
        }
        update_option($this->option_files, array_values($files), false);
        $this->redirect_with_message('success', 'ファイルを削除しました。');
    }

    public function handle_delete_all() {
        if (!current_user_can('manage_options') || !check_admin_referer('chatbot_delete_all')) {
            wp_die('forbidden');
        }
        $store = get_option($this->option_store, '');
        $files = get_option($this->option_files, []);
        $client = new Gemini_Client();

        foreach ($files as $file) {
            if (!empty($file['id'])) {
                $client->delete_file($file['id']);
            }
        }
        if (!empty($store)) {
            $client->delete_store($store);
        }

        update_option($this->option_store, '', false);
        update_option($this->option_files, [], false);

        $this->redirect_with_message('success', 'ストアとファイルを削除しました。');
    }

    public function render_notices() {
        if (empty($_GET['chatbot_notice'])) {
            return;
        }
        $data = get_transient($this->notice_key());
        delete_transient($this->notice_key());
        if (empty($data) || !is_array($data)) {
            return;
        }

        $type = isset($data['type']) ? sanitize_text_field($data['type']) : 'success';
        $msg = isset($data['msg']) ? (string) $data['msg'] : '';
        $class = $type === 'error' ? 'notice notice-error' : 'notice notice-success';
        echo '<div class="' . esc_attr($class) . '"><pre style="white-space:pre-wrap;margin:0;">' . esc_html($msg) . '</pre></div>';
    }

    private function redirect_with_message($type, $msg) {
        set_transient($this->notice_key(), [
            'type' => $type,
            'msg'  => $msg,
        ], 60);
        $url = add_query_arg([
            'page'           => 'gemini-chatbot',
            'chatbot_notice' => '1',
        ], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    private function notice_key() {
        return 'chatbot_notice_' . (string) get_current_user_id();
    }
}
