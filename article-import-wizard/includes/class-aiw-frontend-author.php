<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIW_Frontend_Author
{
    public function register(): void
    {
        add_filter('the_author', [$this, 'filter_author_name']);
        add_filter('get_the_author_display_name', [$this, 'filter_author_name']);
        add_filter('author_link', [$this, 'filter_author_link']);
    }

    public function filter_author_name(string $display_name): string
    {
        if (is_admin()) {
            return $display_name;
        }

        $author = $this->get_attributed_author_from_current_post();
        if (!$author instanceof WP_User) {
            return $display_name;
        }

        return $author->display_name;
    }

    public function filter_author_link(string $link): string
    {
        if (is_admin()) {
            return $link;
        }

        $author = $this->get_attributed_author_from_current_post();
        if (!$author instanceof WP_User) {
            return $link;
        }

        return get_author_posts_url((int) $author->ID);
    }

    private function get_attributed_author_from_current_post(): ?WP_User
    {
        $post = get_post();
        if (!$post) {
            return null;
        }

        if ((int) get_post_meta($post->ID, '_aiw_imported', true) !== 1) {
            return null;
        }

        $attributed_author_id = (int) get_post_meta($post->ID, '_aiw_attributed_author_user_id', true);
        if ($attributed_author_id <= 0) {
            return null;
        }

        $author = get_user_by('id', $attributed_author_id);

        return ($author instanceof WP_User) ? $author : null;
    }
}
