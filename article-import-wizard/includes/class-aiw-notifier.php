<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIW_Notifier
{
    public function send_import_complete_email(int $post_id, string $recipient, array $context = []): bool
    {
        if (!is_email($recipient)) {
            return false;
        }

        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return false;
        }

        $is_update = !empty($context['updated']);

        $subject = sprintf(
            $is_update
                ? __('Article Updated by Import: %s', 'article-import-wizard')
                : __('Article Import Completed: %s', 'article-import-wizard'),
            $post->post_title
        );

        $lines = [];
        $lines[] = $is_update
            ? __('An existing article was updated by a new import. The previous version is kept as a revision.', 'article-import-wizard')
            : __('The article import process has completed.', 'article-import-wizard');
        $lines[] = '';
        $lines[] = sprintf(__('Post ID: %d', 'article-import-wizard'), $post_id);
        $lines[] = sprintf(__('Title: %s', 'article-import-wizard'), $post->post_title);
        $lines[] = sprintf(__('Edit URL: %s', 'article-import-wizard'), get_edit_post_link($post_id, ''));

        if (isset($context['membership_number'])) {
            $lines[] = sprintf(__('Membership Number: %s', 'article-import-wizard'), (string) $context['membership_number']);
        }

        if (isset($context['attributed_author'])) {
            $lines[] = sprintf(__('Attributed Author: %s', 'article-import-wizard'), (string) $context['attributed_author']);
        }

        if (isset($context['invalid_links'])) {
            $lines[] = sprintf(__('Invalid Links: %d', 'article-import-wizard'), (int) $context['invalid_links']);
        }

        if (isset($context['operator_user_id'])) {
            $lines[] = sprintf(__('Imported By (Internal User ID): %d', 'article-import-wizard'), (int) $context['operator_user_id']);
        }

        $message = implode("\n", $lines);

        return (bool) wp_mail($recipient, $subject, $message);
    }
}
