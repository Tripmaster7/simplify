<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIW_Admin_Menu
{
    private AIW_Wizard_Controller $wizard_controller;

    public function __construct(AIW_Wizard_Controller $wizard_controller)
    {
        $this->wizard_controller = $wizard_controller;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Article Import Wizard', 'article-import-wizard'),
            __('Article Import', 'article-import-wizard'),
            'edit_posts',
            'aiw-import-wizard',
            [$this->wizard_controller, 'render_page'],
            'dashicons-media-document',
            26
        );
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if ($hook_suffix !== 'toplevel_page_aiw-import-wizard') {
            return;
        }

        wp_enqueue_style(
            'aiw-admin-css',
            AIW_PLUGIN_URL . 'assets/css/admin.css',
            [],
            AIW_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'aiw-admin-js',
            AIW_PLUGIN_URL . 'assets/js/admin.js',
            [],
            AIW_PLUGIN_VERSION,
            true
        );
    }
}
