<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds a "back to overview" link to the top and bottom of sub-pages whose parent page is
 * selected in the settings (for example the chapters of a multi-page guide).
 *
 * The link is added while the page is rendered and never stored in the post content, so it
 * cannot be lost when a page is edited or replaced, and it always follows the parent's current
 * title and permalink.
 */
class AIW_Section_Navigation
{
    public function register(): void
    {
        // After do_blocks (9), wpautop (10) and do_shortcode (11), so the link markup is final.
        add_filter('the_content', [$this, 'add_back_links'], 20);
    }

    public function add_back_links($content)
    {
        if (!is_string($content) || is_admin() || is_feed() || !is_singular('page') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        // the_content also runs for other posts rendered on the page; only touch the queried page.
        $post = get_post();
        if (!$post instanceof WP_Post || (int) $post->ID !== get_queried_object_id()) {
            return $content;
        }

        $parent_id = (int) $post->post_parent;
        if ($parent_id <= 0 || !in_array($parent_id, AIW_Settings::get_back_link_parent_ids(), true)) {
            return $content;
        }

        // Never link visitors to an overview they cannot open.
        if (get_post_status($parent_id) !== 'publish' || post_password_required($parent_id)) {
            return $content;
        }

        $parent_title = html_entity_decode(wp_strip_all_tags(get_the_title($parent_id)), ENT_QUOTES, 'UTF-8');
        $label = sprintf(
            /* translators: %s: title of the overview (parent) page */
            __('Back to overview: %s', 'article-import-wizard'),
            $parent_title
        );

        $link = sprintf(
            '<p class="aiw-back-link"><a href="%s">&larr; %s</a></p>',
            esc_url(get_permalink($parent_id)),
            esc_html($label)
        );

        return $link . "\n" . $content . "\n" . $link;
    }
}
