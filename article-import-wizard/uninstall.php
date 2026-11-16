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
];

foreach ($aiw_options as $aiw_option) {
    delete_option($aiw_option);
}

wp_clear_scheduled_hook('aiw_cleanup_preview_uploads');
