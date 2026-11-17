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
        add_action('admin_head', [$this, 'render_menu_highlight']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Article Import Wizard', 'article-import-wizard'),
            __('Article Import', 'article-import-wizard'),
            // The wizard requires edit_posts and upload_files; upload_files implies edit_posts for
            // core roles, so this hides the menu from users who would only get a permission error.
            'upload_files',
            'aiw-import-wizard',
            [$this->wizard_controller, 'render_page'],
            'dashicons-media-document',
            26
        );
    }

    public function render_menu_highlight(): void
    {
        ?>
        <style>
            #adminmenu #toplevel_page_aiw-import-wizard > a {
                background: #b32d2e;
                color: #fff;
                font-weight: 700;
            }

            #adminmenu #toplevel_page_aiw-import-wizard > a:hover,
            #adminmenu #toplevel_page_aiw-import-wizard > a:focus {
                background: #8a2424;
                color: #fff;
            }

            #adminmenu #toplevel_page_aiw-import-wizard > a .wp-menu-image::before {
                color: #fff;
            }
        </style>
        <?php
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

        wp_localize_script('aiw-admin-js', 'AIWAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'membershipNonce' => wp_create_nonce('aiw_validate_membership'),
            'membershipFormatMessage' => __('Enter a valid five-digit membership number starting with 0.', 'article-import-wizard'),
            'membershipRequiredMessage' => __('Enter a membership number or author name.', 'article-import-wizard'),
            'membershipCheckingMessage' => __('Checking membership number...', 'article-import-wizard'),
            'membershipAuthorLabel' => __('Author:', 'article-import-wizard'),
            'membershipRequestError' => __('The membership number could not be checked. Please try again.', 'article-import-wizard'),
            'membershipFallbackLabel' => __('Fallback author:', 'article-import-wizard'),
            'categoryNewLabel' => __('New', 'article-import-wizard'),
        ]);
    }
}
