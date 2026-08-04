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

    public function inject_restriction_shortcodes(string $content, string $start_shortcode, string $end_shortcode): string
    {
        $start_shortcode = trim($start_shortcode);
        $end_shortcode = trim($end_shortcode);

        if ($start_shortcode === '' || $end_shortcode === '') {
            return $content;
        }

        return $start_shortcode . "\n" . $content . "\n" . $end_shortcode;
    }

    public function bypass_restriction_shortcode_in_editor($return, string $tag, array $attr, array $m)
    {
        if (!$this->should_bypass_shortcode_rendering()) {
            return $return;
        }

        $start_shortcode = AIW_Settings::get_option_string(AIW_Settings::OPTION_RESTRICTION_START, '[restrict]');
        $configured_tag = $this->extract_shortcode_tag($start_shortcode);
        if ($configured_tag === '' || strtolower($configured_tag) !== strtolower($tag)) {
            return $return;
        }

        if (isset($m[0]) && is_string($m[0])) {
            return $m[0];
        }

        return '[' . $tag . ']';
    }

    private function should_bypass_shortcode_rendering(): bool
    {
        if (is_admin()) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST && is_user_logged_in()) {
            return current_user_can('edit_posts');
        }

        return false;
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
