<?php
/**
 * Removes plugin settings and scheduled tasks when the plugin is deleted.
 *
 * Imported posts, their _aiw_* audit metadata, and authors' stored bios and images are
 * deliberately kept: they are editorial content, not plugin configuration.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$aiw_options = [
    'aiw_restriction_shortcode_start',
    'aiw_restriction_shortcode_end',
    'aiw_inline_image_slots',
    'aiw_notify_email',
    'aiw_link_check_timeout_ms',
    'aiw_replace_broken_links_mode',
    'aiw_back_link_parent_ids',
    'aiw_default_publisher_user_id',
    'aiw_auto_restriction_characters',
    'aiw_auto_restriction_paragraphs',
    'aiw_default_post_status',
    'aiw_default_category_ids',
    'aiw_ambiguous_author_mode',
    'aiw_membership_number_pattern',
    'aiw_author_profile_update',
    'aiw_show_bio_box',
    'aiw_wizard_roles',
    'aiw_simple_admin_menu',
    'aiw_notify_enabled',
    'aiw_indesign_enabled',
    'aiw_indesign_styles',
];

// The uploaded InDesign template is plugin configuration, too.
$aiw_template = get_option('aiw_indesign_template', []);
if (is_array($aiw_template) && !empty($aiw_template['file'])) {
    $aiw_template_path = trailingslashit(wp_upload_dir()['basedir']) . 'aiw-indesign/' . basename((string) $aiw_template['file']);
    if (is_file($aiw_template_path)) {
        wp_delete_file($aiw_template_path);
    }
}
$aiw_options[] = 'aiw_indesign_template';

foreach ($aiw_options as $aiw_option) {
    delete_option($aiw_option);
}

wp_clear_scheduled_hook('aiw_cleanup_preview_uploads');
