<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIW_Shortcode_Service
{
    public function register(): void
    {
        add_filter('pre_do_shortcode_tag', [$this, 'bypass_restriction_shortcode_in_editor'], 10, 4);
    }

    /**
     * @param false|string $return
     * @param array|string $attr   WordPress passes '' for shortcodes without attributes.
     * @param array        $m
     */
    public function bypass_restriction_shortcode_in_editor($return, $tag, $attr, $m)
    {
        if (!$this->should_bypass_shortcode_rendering()) {
            return $return;
        }

        $start_shortcode = AIW_Settings::get_option_string(AIW_Settings::OPTION_RESTRICTION_START, AIW_Settings::DEFAULT_RESTRICTION_START);
        $configured_tag = $this->extract_shortcode_tag($start_shortcode);
        if ($configured_tag === '' || strtolower($configured_tag) !== strtolower((string) $tag)) {
            return $return;
        }

        if (isset($m[0]) && is_string($m[0])) {
            return $m[0];
        }

        return '[' . $tag . ']';
    }

    /**
     * Only show the raw restriction shortcode to editors working in wp-admin or the block editor.
     *
     * is_admin() is also true for admin-ajax.php requests, including anonymous ones from the
     * front end (infinite scroll, live search, ...). Bypassing there would print the restricted
     * content unprotected, so AJAX is excluded and an editing capability is always required.
     */
    private function should_bypass_shortcode_rendering(): bool
    {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return false;
        }

        if (wp_doing_ajax()) {
            return false;
        }

        if (is_admin()) {
            return true;
        }

        return defined('REST_REQUEST') && REST_REQUEST;
    }

    private function extract_shortcode_tag(string $shortcode): string
    {
        $shortcode = trim($shortcode);
        if ($shortcode === '') {
            return '';
        }

        if (!preg_match('/^\[\s*\/?\s*([A-Za-z0-9_-]+)/', $shortcode, $matches)) {
            return '';
        }

        return isset($matches[1]) ? (string) $matches[1] : '';
    }
}
