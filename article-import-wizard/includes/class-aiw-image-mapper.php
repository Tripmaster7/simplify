<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIW_Image_Mapper
{
    public function replace_placeholders(string $content, array $image_map, array &$missing_slots = []): string
    {
        if ($content === '') {
            return $content;
        }

        $pattern = '/\[(Bild|Image)\s*(\d+)\s*(?::([^\]]*))?\]/i';

        return (string) preg_replace_callback(
            $pattern,
            static function (array $matches) use ($image_map, &$missing_slots): string {
                $index = isset($matches[2]) ? (int) $matches[2] : 0;
                if ($index <= 0 || !isset($image_map[$index])) {
                    if ($index > 0) {
                        $missing_slots[] = $index;
                    }
                    return '<span class="aiw-missing-image">[MISSING IMAGE ' . esc_html((string) $index) . ']</span>';
                }

                $image_data = $image_map[$index];
                $url = isset($image_data['url']) ? esc_url($image_data['url']) : '';
                $alt = isset($image_data['alt']) ? esc_attr($image_data['alt']) : '';
                $caption = isset($matches[3]) ? trim((string) $matches[3]) : '';
                $caption_html = $caption !== '' ? '<figcaption>' . esc_html($caption) . '</figcaption>' : '';

                return '<figure class="aiw-inline-image"><img src="' . $url . '" alt="' . $alt . '" />' . $caption_html . '</figure>';
            },
            $content
        );
    }
}
