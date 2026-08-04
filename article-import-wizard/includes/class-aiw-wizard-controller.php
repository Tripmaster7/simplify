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
    ) {
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
        add_action('admin_post_aiw_preview_import', [$this, 'handle_preview_import']);
        add_action('admin_post_aiw_create_draft', [$this, 'handle_create_draft']);
    }

    public function render_page(): void
    {
        if (!$this->can_access_wizard()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'article-import-wizard'));
        }

        $message = isset($_GET['aiw_message']) ? sanitize_text_field(wp_unslash($_GET['aiw_message'])) : '';
        $error = isset($_GET['aiw_error']) ? sanitize_text_field(wp_unslash($_GET['aiw_error'])) : '';
        $preview_token = isset($_GET['aiw_preview_token']) ? sanitize_text_field(wp_unslash($_GET['aiw_preview_token'])) : '';
        $preview_data = $preview_token !== '' ? $this->get_preview_data($preview_token) : null;

        $default_inline_slots = AIW_Settings::get_option_int(AIW_Settings::OPTION_INLINE_IMAGE_SLOTS, 3);
        $default_broken_link_mode = AIW_Settings::get_option_string(AIW_Settings::OPTION_REPLACE_BROKEN_LINKS_MODE, 'replace');
        $default_restriction_start = AIW_Settings::get_option_string(AIW_Settings::OPTION_RESTRICTION_START, '[restrict]');
        $default_restriction_end = AIW_Settings::get_option_string(AIW_Settings::OPTION_RESTRICTION_END, '[/restrict]');

        include AIW_PLUGIN_DIR . 'templates/wizard-step-upload.php';
    }

    public function handle_preview_import(): void
    {
        if (!$this->can_access_wizard()) {
            wp_die(esc_html__('Unauthorized.', 'article-import-wizard'));
        }

        check_admin_referer('aiw_preview_import_nonce');

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

        $submitted_author_bio = isset($_POST['aiw_author_bio'])
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

        $doc_metadata = isset($doc_parse['metadata']) && is_array($doc_parse['metadata']) ? $doc_parse['metadata'] : [];
        $metadata_warnings = [];

        if (!empty($doc_metadata['author_membership']) && (string) $doc_metadata['author_membership'] !== $membership_number) {
            $metadata_warnings[] = __('DOCX membership number does not match the wizard membership number.', 'article-import-wizard');
        }

        if (empty($doc_metadata['restriction_anchor'])) {
            $metadata_warnings[] = __('DOCX is missing the [RESTRICT] anchor.', 'article-import-wizard');
        }

        if ($post_title === '' && !empty($doc_parse['title'])) {
            $post_title = $this->normalize_title_text((string) $doc_parse['title']);
        }

        if ($post_content === '' && !empty($doc_parse['content'])) {
            $post_content = wp_kses_post((string) $doc_parse['content']);
            if (!empty($doc_parse['subtitle'])) {
                $post_content = '<h2>' . esc_html($this->normalize_title_text((string) $doc_parse['subtitle'])) . '</h2>' . "\n" . $post_content;
            }
        }

        if ($post_title === '' || $post_content === '') {
            $this->redirect_with_error(__('Title and content are required (either manually or from DOCX).', 'article-import-wizard'));
        }

        $operator_user_id = get_current_user_id();

        $headline_image_id = $this->upload_single_image('aiw_headline_image', 0);
        $inline_image_ids = $this->upload_multiple_images('aiw_inline_images', $inline_image_count, 0);
        $author_image_id = $this->upload_single_image('aiw_author_image', 0);

        $image_map = $this->build_inline_image_map($inline_image_ids);
        $missing_image_slots = [];
        $content_with_images = $this->image_mapper->replace_placeholders($post_content, $image_map, $missing_image_slots);

        $working_content = $this->build_restricted_article_body($content_with_images, $restriction_start, $metadata_warnings);

        $stored_author_bio = (string) get_user_meta((int) $attributed_author->ID, 'aiw_author_bio', true);
        $final_author_bio = $submitted_author_bio !== '' ? $submitted_author_bio : $stored_author_bio;

        $stored_author_image_id = (int) get_user_meta((int) $attributed_author->ID, 'aiw_author_image_id', true);
        $final_author_image_id = $author_image_id > 0 ? $author_image_id : $stored_author_image_id;

        $working_content = $this->append_bio_box_from_data(
            $working_content,
            (string) $attributed_author->display_name,
            $final_author_bio,
            $final_author_image_id,
            $restriction_end
        );

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

        $source_filename = isset($_FILES['aiw_docx_file']['name'])
            ? sanitize_file_name(wp_unslash($_FILES['aiw_docx_file']['name']))
            : '';

        $validation_report = [
            'invalid_links' => $link_validation['invalid'],
            'missing_image_slots' => array_values(array_unique($missing_image_slots)),
            'broken_link_mode' => $broken_link_mode,
            'writing_date' => isset($doc_parse['writing_date']) ? (string) $doc_parse['writing_date'] : '',
            'doc_author' => isset($doc_parse['doc_author']) ? (string) $doc_parse['doc_author'] : '',
            'metadata_warnings' => $metadata_warnings,
        ];

        $preview_payload = [
            'membership_number' => $membership_number,
            'attributed_author_user_id' => (int) $attributed_author->ID,
            'attributed_author_name' => (string) $attributed_author->display_name,
            'operator_user_id' => (int) $operator_user_id,
            'post_title' => $post_title,
            'working_content' => $working_content,
            'restriction_start' => $restriction_start,
            'restriction_end' => $restriction_end,
            'source_filename' => $source_filename,
            'broken_link_mode' => $broken_link_mode,
            'headline_image_id' => (int) $headline_image_id,
            'inline_image_ids' => array_map('intval', $inline_image_ids),
            'author_image_id' => (int) $author_image_id,
            'final_author_image_id' => (int) $final_author_image_id,
            'submitted_author_bio' => $submitted_author_bio,
            'final_author_bio' => $final_author_bio,
            'validation_report' => $validation_report,
            'invalid_links_count' => count($link_validation['invalid']),
            'metadata_warnings' => $metadata_warnings,
            'created_at' => gmdate('c'),
        ];

        $token = wp_generate_password(20, false, false);
        $this->store_preview_data($token, $preview_payload);

        $this->redirect_to_preview($token, __('Preview generated. Review and confirm draft creation.', 'article-import-wizard'));
    }

    public function handle_create_draft(): void
    {
        if (!$this->can_access_wizard()) {
            wp_die(esc_html__('Unauthorized.', 'article-import-wizard'));
        }

        check_admin_referer('aiw_create_draft_nonce');

        $preview_token = isset($_POST['aiw_preview_token'])
            ? sanitize_text_field(wp_unslash($_POST['aiw_preview_token']))
            : '';

        if ($preview_token === '') {
            $this->redirect_with_error(__('Preview token missing. Please run preview first.', 'article-import-wizard'));
        }

        $preview = $this->get_preview_data($preview_token);
        if (!is_array($preview)) {
            $this->redirect_with_error(__('Preview data expired. Please run preview again.', 'article-import-wizard'));
        }

        $operator_user_id = get_current_user_id();

        $attributed_author_user_id = isset($preview['attributed_author_user_id']) ? (int) $preview['attributed_author_user_id'] : 0;

        $post_id = wp_insert_post([
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_title' => (string) $preview['post_title'],
            'post_content' => (string) $preview['working_content'],
            'post_author' => $attributed_author_user_id > 0 ? $attributed_author_user_id : $operator_user_id,
        ], true);

        if (is_wp_error($post_id)) {
            $this->redirect_with_error($post_id->get_error_message());
        }

        $headline_image_id = isset($preview['headline_image_id']) ? (int) $preview['headline_image_id'] : 0;
        if ($headline_image_id > 0) {
            wp_update_post([
                'ID' => $headline_image_id,
                'post_parent' => (int) $post_id,
            ]);
            set_post_thumbnail($post_id, $headline_image_id);
        }

        $inline_image_ids = isset($preview['inline_image_ids']) && is_array($preview['inline_image_ids'])
            ? $preview['inline_image_ids']
            : [];
        foreach ($inline_image_ids as $inline_image_id) {
            wp_update_post([
                'ID' => (int) $inline_image_id,
                'post_parent' => (int) $post_id,
            ]);
        }

        $attributed_author_user_id = isset($preview['attributed_author_user_id']) ? (int) $preview['attributed_author_user_id'] : 0;
        if ($attributed_author_user_id <= 0) {
            $this->redirect_with_error(__('Attributed author is missing in preview payload.', 'article-import-wizard'));
        }

        $submitted_author_bio = isset($preview['submitted_author_bio']) ? (string) $preview['submitted_author_bio'] : '';
        if ($submitted_author_bio !== '') {
            update_user_meta($attributed_author_user_id, 'aiw_author_bio', $submitted_author_bio);
        }

        $author_image_id = isset($preview['author_image_id']) ? (int) $preview['author_image_id'] : 0;
        if ($author_image_id > 0) {
            wp_update_post([
                'ID' => $author_image_id,
                'post_parent' => (int) $post_id,
            ]);
            update_user_meta($attributed_author_user_id, 'aiw_author_image_id', $author_image_id);
        }

        $validation_report = isset($preview['validation_report']) && is_array($preview['validation_report'])
            ? $preview['validation_report']
            : [];

        update_post_meta($post_id, '_aiw_imported', 1);
        update_post_meta($post_id, '_aiw_import_status', 'completed');
        update_post_meta($post_id, '_aiw_attributed_author_user_id', $attributed_author_user_id);
        update_post_meta($post_id, '_aiw_imported_by_user_id', (int) $operator_user_id);
        update_post_meta($post_id, '_aiw_imported_at', gmdate('c'));
        update_post_meta($post_id, '_aiw_doc_source_filename', isset($preview['source_filename']) ? (string) $preview['source_filename'] : '');
        update_post_meta($post_id, '_aiw_validation_report', wp_json_encode($validation_report));
        update_post_meta($post_id, '_aiw_restriction_shortcode_start', isset($preview['restriction_start']) ? (string) $preview['restriction_start'] : '');
        update_post_meta($post_id, '_aiw_restriction_shortcode_end', isset($preview['restriction_end']) ? (string) $preview['restriction_end'] : '');

        $notify_email = AIW_Settings::get_option_string(AIW_Settings::OPTION_NOTIFY_EMAIL, get_option('admin_email', ''));
        $this->notifier->send_import_complete_email($post_id, $notify_email, [
            'membership_number' => isset($preview['membership_number']) ? (string) $preview['membership_number'] : '',
            'attributed_author' => isset($preview['attributed_author_name']) ? (string) $preview['attributed_author_name'] : '',
            'invalid_links' => isset($preview['invalid_links_count']) ? (int) $preview['invalid_links_count'] : 0,
            'operator_user_id' => (int) $operator_user_id,
        ]);

        $this->delete_preview_data($preview_token);

        $edit_url = get_edit_post_link($post_id, '');
        $message = sprintf(
            __('Draft #%1$s created. Attributed author: %2$s', 'article-import-wizard'),
            $post_id,
            isset($preview['attributed_author_name']) ? (string) $preview['attributed_author_name'] : ''
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
            return $this->empty_doc_parse_payload('');
        }

        $filename = sanitize_file_name(wp_unslash($_FILES['aiw_docx_file']['name']));
        if ($filename === '') {
            return $this->empty_doc_parse_payload('');
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension !== 'docx') {
            return $this->empty_doc_parse_payload(__('Only DOCX files are allowed.', 'article-import-wizard'));
        }

        if (!isset($_FILES['aiw_docx_file']['tmp_name']) || !is_string($_FILES['aiw_docx_file']['tmp_name'])) {
            return $this->empty_doc_parse_payload(__('Uploaded DOCX temp file is missing.', 'article-import-wizard'));
        }

        $tmp_name = $_FILES['aiw_docx_file']['tmp_name'];
        if ($tmp_name === '' || !file_exists($tmp_name)) {
            return $this->empty_doc_parse_payload(__('Uploaded DOCX temp file is not accessible.', 'article-import-wizard'));
        }

        return $this->docx_parser->parse($tmp_name);
    }

    private function empty_doc_parse_payload(string $error): array
    {
        return [
            'title' => '',
            'subtitle' => '',
            'content' => '',
            'writing_date' => '',
            'doc_author' => '',
            'links' => [],
            'placeholders' => [],
            'error' => $error,
        ];
    }

    private function append_bio_box_from_data(string $content, string $display_name, string $bio, int $image_id, string $restriction_end): string
    {
        $restriction_end = trim($restriction_end);
        $image_html = '';
        if ($image_id > 0) {
            $candidate = wp_get_attachment_image($image_id, 'thumbnail', false, ['class' => 'aiw-bio-box__image']);
            if (is_string($candidate)) {
                $image_html = $candidate;
            }
        }

        $bio_html = '<div class="aiw-bio-box">';
        if ($image_html !== '') {
            $bio_html .= '<div class="aiw-bio-box__media">' . $image_html . '</div>';
        }

        $bio_html .= '<div class="aiw-bio-box__body">';
        $bio_html .= '<h3 class="aiw-bio-box__name">' . esc_html($display_name) . '</h3>';
        if ($bio !== '') {
            $bio_html .= '<p class="aiw-bio-box__text">' . esc_html($bio) . '</p>';
        }
        $bio_html .= '</div></div>';

        $bio_block = $this->wrap_raw_html_block($bio_html);

        $restriction_end_markup = '';
        if ($restriction_end !== '') {
            $restriction_end_markup = $this->wrap_shortcode_block($restriction_end);
        }

        if ($content === '') {
            if ($restriction_end_markup !== '') {
                return $restriction_end_markup . "\n\n" . $bio_block;
            }

            return $bio_block;
        }

        if ($restriction_end_markup !== '') {
            return $content . "\n\n" . $restriction_end_markup . "\n\n" . $bio_block;
        }

        return $content . "\n\n" . $bio_block;
    }

    private function apply_restriction_anchor(string $content, string $restriction_start): string
    {
        $restriction_start = trim($restriction_start);

        if ($restriction_start === '') {
            return $content;
        }

        if (stripos($content, '[RESTRICT]') !== false) {
            $replacement = "\n\n<!-- wp:shortcode -->\n" . $restriction_start . "\n<!-- /wp:shortcode -->\n\n";
            return str_ireplace('[RESTRICT]', $replacement, $content);
        }

        return $content;
    }

    private function build_restricted_article_body(string $content, string $restriction_start, array &$metadata_warnings): string
    {
        $restriction_start = trim($restriction_start);
        $content = $this->normalize_restriction_markers($content);

        if (!$this->contains_restriction_anchor($content)) {
            $metadata_warnings[] = __('DOCX is missing the [RESTRICT] anchor.', 'article-import-wizard');
            if ($restriction_start === '') {
                return $this->convert_to_block_markup($content);
            }

            return $this->wrap_shortcode_block($restriction_start) . "\n\n" . $this->convert_to_block_markup($content);
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="aiw-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $document->getElementById('aiw-root');
        if (!$root instanceof DOMElement) {
            return $this->convert_to_block_markup($content);
        }

        $blocks = [];
        $anchor_inserted = false;

        foreach (iterator_to_array($root->childNodes) as $node) {
            if ($node instanceof DOMText && trim($node->wholeText) === '') {
                continue;
            }

            if ($node instanceof DOMElement) {
                $segments = $this->convert_dom_element_to_blocks_with_restriction($node, $restriction_start, $anchor_inserted);
                $blocks = array_merge($blocks, $segments);
                continue;
            }

            if ($node instanceof DOMComment) {
                $comment = trim($node->nodeValue);
                if ($comment !== '') {
                    $blocks[] = '<!-- ' . $comment . ' -->';
                }
            }
        }

        if (!$anchor_inserted) {
            if ($restriction_start !== '') {
                array_unshift($blocks, $this->wrap_shortcode_block($restriction_start));
            }
        }

        return implode("\n\n", $blocks);
    }

    private function convert_dom_element_to_blocks_with_restriction(DOMElement $node, string $restriction_start, bool &$anchor_inserted): array
    {
        $inner_html = $this->dom_node_inner_html($node);
        if (!$anchor_inserted && $this->contains_restriction_anchor($inner_html)) {
            $parts = preg_split('/\[\s*RESTRICT(?:_START)?\s*\]/iu', $inner_html, 2);
            $before_html = isset($parts[0]) ? trim((string) $parts[0]) : '';
            $after_html = isset($parts[1]) ? trim((string) $parts[1]) : '';

            $blocks = [];
            if ($before_html !== '') {
                $blocks = array_merge($blocks, $this->convert_dom_element_to_blocks_from_html($node->tagName, $before_html, $node));
            }

            if ($restriction_start !== '') {
                $blocks[] = $this->wrap_shortcode_block($restriction_start);
            }

            if ($after_html !== '') {
                $blocks = array_merge($blocks, $this->convert_dom_element_to_blocks_from_html($node->tagName, $after_html, $node));
            }

            $anchor_inserted = true;

            return $blocks;
        }

        return $this->convert_dom_element_to_blocks($node);
    }

    private function convert_dom_element_to_blocks_from_html(string $tag_name, string $inner_html, DOMElement $template_node): array
    {
        $tag = strtolower($tag_name);
        $inner_html = trim($inner_html);
        if ($inner_html === '') {
            return [];
        }

        if ($tag === 'p') {
            return [$this->wrap_paragraph_block($inner_html)];
        }

        if ($tag === 'h1' || $tag === 'h2' || $tag === 'h3' || $tag === 'h4' || $tag === 'h5' || $tag === 'h6') {
            return [$this->wrap_heading_block((int) substr($tag, 1), $inner_html)];
        }

        if ($tag === 'figure' || strpos((string) $template_node->getAttribute('class'), 'wp-block-image') !== false) {
            return [$this->wrap_image_block($template_node)];
        }

        return [$this->wrap_raw_html_block($inner_html)];
    }

    private function wrap_shortcode_block(string $shortcode): string
    {
        $shortcode = trim($shortcode);
        if ($shortcode === '') {
            return '';
        }

        return "<!-- wp:shortcode -->\n" . $shortcode . "\n<!-- /wp:shortcode -->";
    }

    private function convert_to_block_markup(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return $content;
        }

        $segments = $this->split_block_segments($content);
        $blocks = [];

        foreach ($segments as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^<!--\s*wp:[a-z0-9_-]+\s*-->.*<!--\s*\/wp:[a-z0-9_-]+\s*-->$/is', $segment)) {
                $blocks[] = $segment;
                continue;
            }

            if (preg_match('/^<!--\s*wp:shortcode\s*-->.*<!--\s*\/wp:shortcode\s*-->$/is', $segment)) {
                $blocks[] = $segment;
                continue;
            }

            $blocks = array_merge($blocks, $this->html_fragment_to_blocks($segment));
        }

        return implode("\n\n", $blocks);
    }

    private function split_block_segments(string $content): array
    {
        $pattern = '/(<!--\s*wp:shortcode\s*-->.*?<!--\s*\/wp:shortcode\s*-->)/is';
        $parts = preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? $parts : [$content];
    }

    private function html_fragment_to_blocks(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="aiw-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $document->getElementById('aiw-root');
        if (!$root instanceof DOMElement) {
            return ["<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->"];
        }

        $blocks = [];
        foreach (iterator_to_array($root->childNodes) as $node) {
            if ($node instanceof DOMText && trim($node->wholeText) === '') {
                continue;
            }

            if ($node instanceof DOMElement) {
                $blocks = array_merge($blocks, $this->convert_dom_element_to_blocks($node));
                continue;
            }

            if ($node instanceof DOMComment) {
                $comment = trim($node->nodeValue);
                if ($comment !== '') {
                    $blocks[] = '<!-- ' . $comment . ' -->';
                }
            }
        }

        return $blocks;
    }

    private function convert_dom_element_to_blocks(DOMElement $node): array
    {
        $tag = strtolower($node->tagName);

        if ($tag === 'p') {
            return [$this->wrap_paragraph_block($this->dom_node_inner_html($node))];
        }

        if ($tag === 'h1' || $tag === 'h2' || $tag === 'h3' || $tag === 'h4' || $tag === 'h5' || $tag === 'h6') {
            return [$this->wrap_heading_block((int) substr($tag, 1), $this->dom_node_inner_html($node))];
        }

        if ($tag === 'figure' || strpos((string) $node->getAttribute('class'), 'wp-block-image') !== false) {
            return [$this->wrap_image_block($node)];
        }

        if (strpos((string) $node->getAttribute('class'), 'aiw-bio-box') !== false) {
            return ["<!-- wp:html -->\n" . $this->dom_node_outer_html($node) . "\n<!-- /wp:html -->"];
        }

        return ["<!-- wp:html -->\n" . $this->dom_node_outer_html($node) . "\n<!-- /wp:html -->"];
    }

    private function wrap_paragraph_block(string $inner_html): string
    {
        return "<!-- wp:paragraph -->\n<p>" . trim($inner_html) . "</p>\n<!-- /wp:paragraph -->";
    }

    private function wrap_heading_block(int $level, string $inner_html): string
    {
        $level = max(1, min(6, $level));

        return "<!-- wp:heading {\"level\":{$level}} -->\n<h{$level}>" . trim($inner_html) . "</h{$level}>\n<!-- /wp:heading -->";
    }

    private function wrap_image_block(DOMElement $node): string
    {
        $img = null;
        foreach ($node->getElementsByTagName('img') as $candidate) {
            $img = $candidate;
            break;
        }

        if (!$img instanceof DOMElement) {
            return "<!-- wp:html -->\n" . $this->dom_node_outer_html($node) . "\n<!-- /wp:html -->";
        }

        $src = (string) $img->getAttribute('src');
        $alt = (string) $img->getAttribute('alt');
        $class = (string) $img->getAttribute('class');
        $id = 0;

        if (preg_match('/wp-image-(\d+)/', $class, $matches)) {
            $id = (int) $matches[1];
        }

        $caption = '';
        foreach ($node->getElementsByTagName('figcaption') as $candidate) {
            $caption = trim($candidate->textContent);
            break;
        }

        $block = '<!-- wp:image ' . wp_json_encode(array_filter([
            'id' => $id > 0 ? $id : null,
            'sizeSlug' => 'full',
            'linkDestination' => 'none',
        ]), JSON_UNESCAPED_SLASHES) . ' -->';
        $block .= '<figure class="wp-block-image size-full">';
        $block .= '<img src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '"' . ($id > 0 ? ' class="wp-image-' . esc_attr((string) $id) . '"' : '') . ' />';
        if ($caption !== '') {
            $block .= '<figcaption>' . esc_html($caption) . '</figcaption>';
        }
        $block .= '</figure><!-- /wp:image -->';

        return $block;
    }

    private function wrap_raw_html_block(string $html): string
    {
        return "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
    }

    private function contains_restriction_anchor(string $text): bool
    {
        return preg_match('/\[\s*RESTRICT(?:_START)?\s*\]/iu', $text) === 1;
    }

    private function normalize_restriction_markers(string $text): string
    {
        $text = preg_replace('/\[\s*RESTRICT_START\s*\]/iu', '[RESTRICT]', $text);
        $text = preg_replace('/\[\s*RESTRICT_END\s*\]/iu', '', (string) $text);

        return (string) $text;
    }

    private function dom_node_inner_html(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }

        return $html;
    }

    private function dom_node_outer_html(DOMNode $node): string
    {
        return $node->ownerDocument->saveHTML($node) ?: '';
    }

    private function normalize_title_text(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^\[(TITLE|HEADLINE|H1|SUBTITLE|SUBHEADER|H2|AUTHOR_MEMBERSHIP|MEMBERSHIP|MEMBER_ID|AUTHOR_NAME|WRITING_DATE|DATE|BIO)\s*:\s*([^\]]+)\]$/i', '$2', $text);
        $text = preg_replace('/^\[(TITLE|HEADLINE|H1|SUBTITLE|SUBHEADER|H2|AUTHOR_MEMBERSHIP|MEMBERSHIP|MEMBER_ID|AUTHOR_NAME|WRITING_DATE|DATE|BIO)\]$/i', '', (string) $text);

        return trim((string) $text);
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

    private function preview_transient_key(string $token): string
    {
        return 'aiw_preview_' . get_current_user_id() . '_' . $token;
    }

    private function store_preview_data(string $token, array $payload): void
    {
        set_transient($this->preview_transient_key($token), $payload, 2 * HOUR_IN_SECONDS);
    }

    private function get_preview_data(string $token): ?array
    {
        $payload = get_transient($this->preview_transient_key($token));
        return is_array($payload) ? $payload : null;
    }

    private function delete_preview_data(string $token): void
    {
        delete_transient($this->preview_transient_key($token));
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

    private function redirect_to_preview(string $preview_token, string $message = ''): void
    {
        $args = ['page' => 'aiw-import-wizard', 'aiw_preview_token' => rawurlencode($preview_token)];
        if ($message !== '') {
            $args['aiw_message'] = rawurlencode($message);
        }

        $url = add_query_arg($args, admin_url('admin.php'));
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
