<?php
/**
 * Plugin Name: Article Import Wizard
 * Description: Guided article import workflow for non-technical editors with membership-based author attribution.
 * Version: 0.1.1
 * Author: GitHub Copilot and Jens
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: article-import-wizard
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AIW_PLUGIN_VERSION', '0.1.1');
define('AIW_PLUGIN_FILE', __FILE__);
define('AIW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AIW_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once AIW_PLUGIN_DIR . 'includes/class-aiw-admin-menu.php';
require_once AIW_PLUGIN_DIR . 'includes/class-aiw-wizard-controller.php';
require_once AIW_PLUGIN_DIR . 'includes/class-aiw-author-service.php';
require_once AIW_PLUGIN_DIR . 'includes/class-aiw-docx-parser.php';
require_once AIW_PLUGIN_DIR . 'includes/class-aiw-image-mapper.php';
require_once AIW_PLUGIN_DIR . 'includes/class-aiw-link-validator.php';
require_once AIW_PLUGIN_DIR . 'includes/class-aiw-content-builder.php';
require_once AIW_PLUGIN_DIR . 'includes/class-aiw-notifier.php';
require_once AIW_PLUGIN_DIR . 'includes/class-aiw-shortcode-service.php';
require_once AIW_PLUGIN_DIR . 'includes/class-aiw-frontend-author.php';
require_once AIW_PLUGIN_DIR . 'includes/class-aiw-settings.php';
require_once AIW_PLUGIN_DIR . 'includes/class-aiw-admin-i18n.php';

add_action('plugins_loaded', static function () {
    load_plugin_textdomain(
        'article-import-wizard',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );

    $author_service = new AIW_Author_Service();
    $docx_parser = new AIW_DOCX_Parser();
    $image_mapper = new AIW_Image_Mapper();
    $link_validator = new AIW_Link_Validator();
    $content_builder = new AIW_Content_Builder();
    $notifier = new AIW_Notifier();
    $shortcode_service = new AIW_Shortcode_Service();

    $wizard_controller = new AIW_Wizard_Controller(
        $author_service,
        $docx_parser,
        $image_mapper,
        $link_validator,
        $content_builder,
        $notifier,
        $shortcode_service
    );
    $admin_menu = new AIW_Admin_Menu($wizard_controller);

    $admin_menu->register();
    $wizard_controller->register();

    $frontend_author = new AIW_Frontend_Author();
    $frontend_author->register();

    $settings = new AIW_Settings();
    $settings->register();

    $admin_i18n = new AIW_Admin_I18n();
    $admin_i18n->register();
});
