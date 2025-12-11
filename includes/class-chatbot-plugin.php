<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-extractor.php';
require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-installer.php';
require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-settings.php';
require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-cron.php';
require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-normalizer.php';
require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-embedder.php';
require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-repository.php';
require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-manual.php';
require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-rag.php';
require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-rest.php';
require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-shortcode.php';
require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-admin-logs.php';
require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-admin-upload.php';
require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-gemini-file.php';
require_once CHATBOT_PLUGIN_DIR . 'includes/class-chatbot-openai-file.php';

class Chatbot_Plugin {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(CHATBOT_PLUGIN_FILE, [$this, 'activate']);
        register_uninstall_hook(CHATBOT_PLUGIN_FILE, [self::class, 'uninstall']);
        add_action('plugins_loaded', [$this, 'maybe_upgrade']);
        add_action('init', [$this, 'init']);
        add_action('admin_init', [$this, 'admin_init']);
        add_action('admin_menu', [$this, 'admin_menu']);

        Chatbot_Cron::init();
    }

    public function activate() {
        Chatbot_Installer::install();
        Chatbot_Cron::schedule();
    }

    public function maybe_upgrade() {
        Chatbot_Installer::maybe_upgrade();
    }

    public function init() {
        Chatbot_REST::init();
        Chatbot_Shortcode::init();
    }

    public function admin_init() {
        Chatbot_Settings::register_settings();
    }

    public function admin_menu() {
        Chatbot_Settings::register_menu();
        Chatbot_Admin_Logs::register_menu();
        Chatbot_Admin_Upload::register_menu();
    }

    public static function uninstall() {
        Chatbot_Installer::uninstall();
    }
}
