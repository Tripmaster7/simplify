<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIW_Content_Builder
{
    public function append_bio_box(string $content, int $author_user_id): string
    {
        $author = get_user_by('id', $author_user_id);
        if (!$author instanceof WP_User) {
            return $content;
        }

        $bio = (string) get_user_meta($author_user_id, 'aiw_author_bio', true);
        $image_id = (int) get_user_meta($author_user_id, 'aiw_author_image_id', true);
        $image_html = '';

        if ($image_id > 0) {
            $image_html = wp_get_attachment_image($image_id, 'thumbnail', false, ['class' => 'aiw-bio-box__image']);
            if (!is_string($image_html)) {
                $image_html = '';
            }
        }

        $bio_html = '<div class="aiw-bio-box">';
        if ($image_html !== '') {
            $bio_html .= '<div class="aiw-bio-box__media">' . $image_html . '</div>';
        }

        $bio_html .= '<div class="aiw-bio-box__body">';
        $bio_html .= '<h3 class="aiw-bio-box__name">' . esc_html($author->display_name) . '</h3>';
        if ($bio !== '') {
            $bio_html .= '<p class="aiw-bio-box__text">' . esc_html($bio) . '</p>';
        }
        $bio_html .= '</div></div>';

        if ($content === '') {
            return $bio_html;
        }

        return $content . "\n\n" . $bio_html;
    }
}
