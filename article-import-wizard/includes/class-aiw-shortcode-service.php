<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIW_Shortcode_Service
{
    public function inject_restriction_shortcodes(string $content, string $start_shortcode, string $end_shortcode): string
    {
        $start_shortcode = trim($start_shortcode);
        $end_shortcode = trim($end_shortcode);

        if ($start_shortcode === '' || $end_shortcode === '') {
            return $content;
        }

        return $start_shortcode . "\n" . $content . "\n" . $end_shortcode;
    }
}
