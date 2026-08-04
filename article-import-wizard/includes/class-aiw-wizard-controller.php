<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIW_Wizard_Controller
{
    private AIW_Author_Service $author_service;
    private AIW_DOCX_Parser $docx_parser;
    private AIW_Image_Mapper $image_mapper;
    private AIW_Link_Validator $link_validator;
    private AIW_Content_Builder $content_builder;
    private AIW_Notifier $notifier;
    private AIW_Shortcode_Service $shortcode_service;

    public function __construct(
        AIW_Author_Service $author_service,
        AIW_DOCX_Parser $docx_parser,
        AIW_Image_Mapper $image_mapper,
        AIW_Link_Validator $link_validator,
        AIW_Content_Builder $content_builder,
        AIW_Notifier $notifier,
        AIW_Shortcode_Service $shortcode_service
    )
    {
        $this->author_service = $author_service;
        $this->docx_parser = $docx_parser;
        $this->image_mapper = $image_mapper;
        $this->link_validator = $link_validator;
        $this->content_builder = $content_builder;
        $this->notifier = $notifier;
        $this->shortcode_service = $shortcode_service;
    }

    public function register(): void
    {
        add_action('admin_post_aiw_create_draft', [$this, 'handle_create_draft']);
    }

    public function render_page(): void
    {
        if (!$this->can_access_wizard()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'article-import-wizard'));
        }

        $message = isset($_GET['aiw_message']) ? sanitize_text_field(wp_unslash($_GET['aiw_message'])) : '';
        $error = isset($_GET['aiw_error']) ? sanitize_text_field(wp_unslash($_GET['aiw_error'])) : '';
        $default_restriction_start = AIW_Settings::get_option_string(AIW_Settings::OPTION_RESTRICTION_START, '[restrict]');
        $default_restriction_end = AIW_Settings::get_option_string(AIW_Settings::OPTION_RESTRICTION_END, '[/restrict]');
        $default_inline_slots = AIW_Settings::get_option_int(AIW_Settings::OPTION_INLINE_IMAGE_SLOTS, 3);
        $default_broken_link_mode = AIW_Settings::get_option_string(AIW_Settings::OPTION_REPLACE_BROKEN_LINKS_MODE, 'replace');

        include AIW_PLUGIN_DIR . 'templates/wizard-step-upload.php';
    }

    public function handle_create_draft(): void
    {
        if (!$this->can_access_wizard()) {
            wp_die(esc_html__('Unauthorized.', 'article-import-wizard'));
        }

        check_admin_referer('aiw_create_draft_nonce');

        $membership_number = isset($_POST['aiw_membership_number'])
            ? sanitize_text_field(wp_unslash($_POST['aiw_membership_number']))
            : '';

        $post_title = isset($_POST['aiw_post_title'])
            ? sanitize_text_field(wp_unslash($_POST['aiw_post_title']))
            : '';

        $post_content = isset($_POST['aiw_post_content'])
            ? wp_kses_post(wp_unslash($_POST['aiw_post_content']))
            : '';

        $restriction_start = isset($_POST['aiw_restriction_start'])
            ? sanitize_text_field(wp_unslash($_POST['aiw_restriction_start']))
            : AIW_Settings::get_option_string(AIW_Settings::OPTION_RESTRICTION_START, '[restrict]');

        $restriction_end = isset($_POST['aiw_restriction_end'])
            ? sanitize_text_field(wp_unslash($_POST['aiw_restriction_end']))
            : AIW_Settings::get_option_string(AIW_Settings::OPTION_RESTRICTION_END, '[/restrict]');

        $inline_image_count = isset($_POST['aiw_inline_image_count'])
            ? max(0, (int) wp_unslash($_POST['aiw_inline_image_count']))
            : AIW_Settings::get_option_int(AIW_Settings::OPTION_INLINE_IMAGE_SLOTS, 3);

        $broken_link_mode = isset($_POST['aiw_broken_link_mode'])
            ? sanitize_text_field(wp_unslash($_POST['aiw_broken_link_mode']))
            : AIW_Settings::get_option_string(AIW_Settings::OPTION_REPLACE_BROKEN_LINKS_MODE, 'replace');

        $author_bio = isset($_POST['aiw_author_bio'])
            ? sanitize_textarea_field(wp_unslash($_POST['aiw_author_bio']))
            : '';

        if ($membership_number === '') {
            $this->redirect_with_error(__('Membership number is required.', 'article-import-wizard'));
        }

        $attributed_author = $this->author_service->find_by_membership_number($membership_number);
        if (!$attributed_author) {
            $this->redirect_with_error(__('No user found for that membership number.', 'article-import-wizard'));
        }

        $doc_parse = $this->parse_uploaded_docx();
        if (!empty($doc_parse['error'])) {
            $this->redirect_with_error((string) $doc_parse['error']);
        }

        if ($post_title === '' && !empty($doc_parse['title'])) {
            $post_title = sanitize_text_field((string) $doc_parse['title']);
        }

        if ($post_content === '' && !empty($doc_parse['content'])) {
            $post_content = wp_kses_post((string) $doc_parse['content']);

            if (!empty($doc_parse['subtitle'])) {
                $post_content = '<h2>' . esc_html((string) $doc_parse['subtitle']) . '</h2>' . "\n" . $post_content;
            }
        }

        if ($post_title === '' || $post_content === '') {
            $this->redirect_with_error(__('Title and content are required (either manually or from DOCX).', 'article-import-wizard'));
        }

        $operator_user_id = get_current_user_id();

        $working_content = $this->shortcode_service->inject_restriction_shortcodes(
            $post_content,
            $restriction_start,
            $restriction_end
        );

        $inline_image_ids = $this->upload_multiple_images('aiw_inline_images', $inline_image_count, 0);
        $image_map = $this->build_inline_image_map($inline_image_ids);
        $missing_image_slots = [];
        $working_content = $this->image_mapper->replace_placeholders($working_content, $image_map, $missing_image_slots);

        if ($author_bio !== '') {
            update_user_meta($attributed_author->ID, 'aiw_author_bio', $author_bio);
        }

        $author_image_id = $this->upload_single_image('aiw_author_image', 0);
        if ($author_image_id > 0) {
            update_user_meta($attributed_author->ID, 'aiw_author_image_id', $author_image_id);
        }

        $working_content = $this->content_builder->append_bio_box($working_content, (int) $attributed_author->ID);

        $links = $this->extract_links_from_content($working_content);
        if (isset($doc_parse['links']) && is_array($doc_parse['links'])) {
            $links = array_values(array_unique(array_merge($links, $doc_parse['links'])));
        }

        $timeout_ms = AIW_Settings::get_option_int(AIW_Settings::OPTION_LINK_CHECK_TIMEOUT, 6000);
        $link_validation = $this->link_validator->validate_links($links, $timeout_ms);

        $invalid_urls = [];
        foreach ($link_validation['invalid'] as $invalid_entry) {
            if (isset($invalid_entry['url']) && is_string($invalid_entry['url'])) {
                $invalid_urls[] = $invalid_entry['url'];
            }
        }

        if ($broken_link_mode === 'replace' && !empty($invalid_urls)) {
            $working_content = $this->replace_invalid_links_in_content($working_content, $invalid_urls);
        }

        $post_id = wp_insert_post([
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_title' => $post_title,
            'post_content' => $working_content,
            // Keep operator as actual post owner for permission safety.
            'post_author' => $operator_user_id,
        ], true);

        if (is_wp_error($post_id)) {
            $this->redirect_with_error($post_id->get_error_message());
        }

        foreach ($inline_image_ids as $inline_image_id) {
            wp_update_post([
                'ID' => (int) $inline_image_id,
                'post_parent' => (int) $post_id,
            ]);
        }

        if ($author_image_id > 0) {
            wp_update_post([
                'ID' => (int) $author_image_id,
                'post_parent' => (int) $post_id,
            ]);
        }

        $source_filename = isset($_FILES['aiw_docx_file']['name'])
            ? sanitize_file_name(wp_unslash($_FILES['aiw_docx_file']['name']))
            : '';

        $headline_image_id = $this->upload_single_image('aiw_headline_image', $post_id);
        if ($headline_image_id > 0) {
            set_post_thumbnail($post_id, $headline_image_id);
        }

        $validation_report = [
            'invalid_links' => $link_validation['invalid'],
            'missing_image_slots' => array_values(array_unique($missing_image_slots)),
            'broken_link_mode' => $broken_link_mode,
            'writing_date' => isset($doc_parse['writing_date']) ? (string) $doc_parse['writing_date'] : '',
            'doc_author' => isset($doc_parse['doc_author']) ? (string) $doc_parse['doc_author'] : '',
        ];

        update_post_meta($post_id, '_aiw_imported', 1);
        update_post_meta($post_id, '_aiw_import_status', 'completed');
        update_post_meta($post_id, '_aiw_attributed_author_user_id', (int) $attributed_author->ID);
        update_post_meta($post_id, '_aiw_imported_by_user_id', (int) $operator_user_id);
        update_post_meta($post_id, '_aiw_imported_at', gmdate('c'));
        update_post_meta($post_id, '_aiw_doc_source_filename', $source_filename);
        update_post_meta($post_id, '_aiw_validation_report', wp_json_encode($validation_report));
        update_post_meta($post_id, '_aiw_restriction_shortcode_start', $restriction_start);
        update_post_meta($post_id, '_aiw_restriction_shortcode_end', $restriction_end);

        $notify_email = AIW_Settings::get_option_string(AIW_Settings::OPTION_NOTIFY_EMAIL, get_option('admin_email', ''));
        $this->notifier->send_import_complete_email($post_id, $notify_email, [
            'membership_number' => $membership_number,
            'attributed_author' => $attributed_author->display_name,
            'invalid_links' => count($link_validation['invalid']),
            'operator_user_id' => $operator_user_id,
        ]);

        $edit_url = get_edit_post_link($post_id, '');
        $message = sprintf(
            /* translators: %1$s: post id, %2$s: attributed author login */
            __('Draft #%1$s created. Attributed author: %2$s', 'article-import-wizard'),
            $post_id,
            $attributed_author->user_login
        );

        $this->redirect_with_message($message, $edit_url);
    }

    private function can_access_wizard(): bool
    {
        return current_user_can('edit_posts') && current_user_can('upload_files');
    }

    private function parse_uploaded_docx(): array
    {
        if (!isset($_FILES['aiw_docx_file']['name']) || !is_string($_FILES['aiw_docx_file']['name'])) {
            return [
                'title' => '',
                'subtitle' => '',
                'content' => '',
                'writing_date' => '',
                'doc_author' => '',
                'links' => [],
                'placeholders' => [],
                'error' => '',
            ];
        }

        $filename = sanitize_file_name(wp_unslash($_FILES['aiw_docx_file']['name']));
        if ($filename === '') {
            return [
                'title' => '',
                'subtitle' => '',
                'content' => '',
                'writing_date' => '',
                'doc_author' => '',
                'links' => [],
                'placeholders' => [],
                'error' => '',
            ];
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension !== 'docx') {
            return [
                'title' => '',
                'subtitle' => '',
                'content' => '',
                'writing_date' => '',
                'doc_author' => '',
                'links' => [],
                'placeholders' => [],
                'error' => __('Only DOCX files are allowed.', 'article-import-wizard'),
            ];
        }

        if (!isset($_FILES['aiw_docx_file']['tmp_name']) || !is_string($_FILES['aiw_docx_file']['tmp_name'])) {
            return [
                'title' => '',
                'subtitle' => '',
                'content' => '',
                'writing_date' => '',
                'doc_author' => '',
                'links' => [],
                'placeholders' => [],
                'error' => __('Uploaded DOCX temp file is missing.', 'article-import-wizard'),
            ];
        }

        $tmp_name = $_FILES['aiw_docx_file']['tmp_name'];
        if ($tmp_name === '' || !file_exists($tmp_name)) {
            return [
                'title' => '',
                'subtitle' => '',
                'content' => '',
                'writing_date' => '',
                'doc_author' => '',
                'links' => [],
                'placeholders' => [],
                'error' => __('Uploaded DOCX temp file is not accessible.', 'article-import-wizard'),
            ];
        }

        return $this->docx_parser->parse($tmp_name);
    }

    private function build_inline_image_map(array $attachment_ids): array
    {
        $map = [];
        $index = 1;

        foreach ($attachment_ids as $attachment_id) {
            $url = wp_get_attachment_url($attachment_id);
            if (!is_string($url) || $url === '') {
                continue;
            }

            $alt = (string) get_post_meta((int) $attachment_id, '_wp_attachment_image_alt', true);
            $map[$index] = [
                'id' => (int) $attachment_id,
                'url' => $url,
                'alt' => $alt,
            ];
            $index++;
        }

        return $map;
    }

    private function upload_single_image(string $field_name, int $post_id): int
    {
        if (!isset($_FILES[$field_name]['name']) || !is_string($_FILES[$field_name]['name']) || $_FILES[$field_name]['name'] === '') {
            return 0;
        }

        $this->include_media_admin_files();
        $attachment_id = media_handle_upload($field_name, $post_id);

        if (is_wp_error($attachment_id)) {
            return 0;
        }

        return (int) $attachment_id;
    }

    private function upload_multiple_images(string $field_name, int $limit, int $post_id): array
    {
        if ($limit <= 0 || !isset($_FILES[$field_name]) || !is_array($_FILES[$field_name]['name'])) {
            return [];
        }

        $this->include_media_admin_files();

        $attachment_ids = [];
        $count = count($_FILES[$field_name]['name']);

        for ($i = 0; $i < $count && count($attachment_ids) < $limit; $i++) {
            $name = isset($_FILES[$field_name]['name'][$i]) ? (string) $_FILES[$field_name]['name'][$i] : '';
            if ($name === '') {
                continue;
            }

            $error = isset($_FILES[$field_name]['error'][$i]) ? (int) $_FILES[$field_name]['error'][$i] : UPLOAD_ERR_NO_FILE;
            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }

            $tmp_key = $field_name . '_tmp_' . $i;
            $_FILES[$tmp_key] = [
                'name' => $name,
                'type' => isset($_FILES[$field_name]['type'][$i]) ? (string) $_FILES[$field_name]['type'][$i] : '',
                'tmp_name' => isset($_FILES[$field_name]['tmp_name'][$i]) ? (string) $_FILES[$field_name]['tmp_name'][$i] : '',
                'error' => $error,
                'size' => isset($_FILES[$field_name]['size'][$i]) ? (int) $_FILES[$field_name]['size'][$i] : 0,
            ];

            $attachment_id = media_handle_upload($tmp_key, $post_id);
            unset($_FILES[$tmp_key]);

            if (!is_wp_error($attachment_id)) {
                $attachment_ids[] = (int) $attachment_id;
            }
        }

        return $attachment_ids;
    }

    private function include_media_admin_files(): void
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }

    private function extract_links_from_content(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $links = [];
        preg_match_all('/href=["\']([^"\']+)["\']/i', $content, $href_matches);
        if (isset($href_matches[1]) && is_array($href_matches[1])) {
            foreach ($href_matches[1] as $href) {
                $href = trim((string) $href);
                if ($href !== '') {
                    $links[] = $href;
                }
            }
        }

        preg_match_all('~https?://[^\s<]+~i', wp_strip_all_tags($content), $url_matches);
        if (isset($url_matches[0]) && is_array($url_matches[0])) {
            foreach ($url_matches[0] as $url) {
                $url = trim((string) $url);
                if ($url !== '') {
                    $links[] = $url;
                }
            }
        }

        return array_values(array_unique($links));
    }

    private function replace_invalid_links_in_content(string $content, array $invalid_urls): string
    {
        if ($content === '' || empty($invalid_urls)) {
            return $content;
        }

        foreach ($invalid_urls as $invalid_url) {
            $quoted = preg_quote($invalid_url, '/');

            $content = (string) preg_replace(
                '/<a\b[^>]*href=["\']' . $quoted . '["\'][^>]*>.*?<\/a>/is',
                '<span class="aiw-check-link" style="color:#cc0000;font-weight:700;">CHECK LINK</span>',
                $content
            );

            $content = (string) preg_replace(
                '/(?<!["\'])' . $quoted . '(?!["\'])/i',
                '<span class="aiw-check-link" style="color:#cc0000;font-weight:700;">CHECK LINK</span>',
                $content
            );
        }

        return $content;
    }

    private function redirect_with_error(string $error): void
    {
        $url = add_query_arg(
            ['page' => 'aiw-import-wizard', 'aiw_error' => rawurlencode($error)],
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    private function redirect_with_message(string $message, string $edit_url = ''): void
    {
        $args = ['page' => 'aiw-import-wizard', 'aiw_message' => rawurlencode($message)];

        if ($edit_url !== '') {
            $args['aiw_edit_url'] = rawurlencode($edit_url);
        }

        $url = add_query_arg($args, admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }
}
