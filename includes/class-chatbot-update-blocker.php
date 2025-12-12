<?php

if (!defined('ABSPATH')) {
    exit;
}

class Chatbot_Update_Blocker {
    private $plugin_basename = 'chatbot/chatbot.php';

    public function __construct() {
        add_filter('site_transient_update_plugins', [$this, 'block_update_link']);
        add_filter('plugins_api', [$this, 'block_plugin_api'], 10, 3);
    }

    public function block_update_link($transient) {
        if (isset($transient->response[$this->plugin_basename])) {
            unset($transient->response[$this->plugin_basename]);
        }
        return $transient;
    }

    public function block_plugin_api($result, $action, $args) {
        if (isset($args->slug) && $args->slug === 'chatbot') {
            return new WP_Error('no_plugin_info', __('Plugin info not available.', 'chatbot'));
        }
        return $result;
    }
}
