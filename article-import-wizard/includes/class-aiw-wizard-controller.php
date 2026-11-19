<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIW_Wizard_Controller
{
    public const MAX_INLINE_IMAGE_SLOTS = 20;

    /**
     * Post meta set on media uploaded during preview; removed once the media is attached to a draft.
     */
    public const PREVIEW_UPLOAD_META = '_aiw_preview_upload';

    public const CLEANUP_HOOK = 'aiw_cleanup_preview_uploads';

    /**
     * Preview transients live for 2 hours; unattached uploads older than this are safe to delete.
     */
    private const PREVIEW_UPLOAD_MAX_AGE = DAY_IN_SECONDS;

    private AIW_Author_Service $author_service;
    private AIW_DOCX_Parser $docx_parser;
    private AIW_Image_Mapper $image_mapper;
    private AIW_Link_Validator $link_validator;
    private AIW_Notifier $notifier;

    public function __construct(
        AIW_Author_Service $author_service,
        AIW_DOCX_Parser $docx_parser,
        AIW_Image_Mapper $image_mapper,
        AIW_Link_Validator $link_validator,
        AIW_Notifier $notifier
    ) {
        $this->author_service = $author_service;
        $this->docx_parser = $docx_parser;
        $this->image_mapper = $image_mapper;
        $this->link_validator = $link_validator;
        $this->notifier = $notifier;
    }

    public function register(): void
    {
        add_action('admin_post_aiw_preview_import', [$this, 'handle_preview_import']);
        add_action('admin_post_aiw_create_draft', [$this, 'handle_create_draft']);
        add_action('wp_ajax_aiw_validate_membership', [$this, 'handle_validate_membership']);
        add_action(self::CLEANUP_HOOK, [$this, 'cleanup_abandoned_preview_uploads']);
    }

    /**
     * Delete media that was uploaded for a preview which never became a draft
     * (preview regenerated, expired, or abandoned).
     */
    public function cleanup_abandoned_preview_uploads(): void
    {
        $attachment_ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => 0,
            'posts_per_page' => 100,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [
                [
                    'key' => self::PREVIEW_UPLOAD_META,
                    'value' => time() - self::PREVIEW_UPLOAD_MAX_AGE,
                    'compare' => '<',
                    'type' => 'NUMERIC',
                ],
            ],
        ]);

        foreach ($attachment_ids as $attachment_id) {
            wp_delete_attachment((int) $attachment_id, true);
        }
    }

    public function handle_validate_membership(): void
    {
        if (!$this->can_access_wizard()) {
            wp_send_json_error(['message' => __('Unauthorized.', 'article-import-wizard')], 403);
        }

        check_ajax_referer('aiw_validate_membership', 'nonce');

        $identifier = isset($_POST['membership_number'])
            ? sanitize_text_field(wp_unslash($_POST['membership_number']))
            : '';

        $matches = $this->author_service->find_matches_by_identifier($identifier);
        if (count($matches) === 0 || (count($matches) > 1 && AIW_Settings::use_fallback_for_ambiguous_author())) {
            $fallback_author = $this->author_service->get_fallback_author();
            if ($fallback_author instanceof WP_User) {
                wp_send_json_success([
                    'name' => $fallback_author->display_name,
                    'membership_number' => (string) $fallback_author->user_login,
                    'fallback' => true,
                ]);
            }

            wp_send_json_error([
                'message' => __('No author found and no logged-in author is available.', 'article-import-wizard'),
            ]);
        }

        if (count($matches) > 1) {
            $names = array_values(array_map(static function (WP_User $user): string {
                return (string) $user->display_name;
            }, $matches));
            wp_send_json_error([
                'message' => sprintf(
                    __('Several authors match. Please be more specific: %s', 'article-import-wizard'),
                    implode(', ', $names)
                ),
            ]);
        }

        $author = $matches[0];
        wp_send_json_success([
            'name' => $author->display_name,
            'membership_number' => (string) $author->user_login,
        ]);
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
        $default_post_status = AIW_Settings::get_default_post_status();
        $default_category_ids = AIW_Settings::get_default_category_ids();
        $help_url = AIW_PLUGIN_URL . 'docs/editorial-cheat-sheet-print.html';
        $available_categories = get_categories([
            'hide_empty' => false,
            'taxonomy' => 'category',
        ]);
        $can_create_categories = current_user_can('manage_categories');

        include AIW_PLUGIN_DIR . 'templates/wizard-step-upload.php';
    }

    public function handle_preview_import(): void
    {
        if (!$this->can_access_wizard()) {
            wp_die(esc_html__('Unauthorized.', 'article-import-wizard'));
        }

        check_admin_referer('aiw_preview_import_nonce');

        $author_identifier = isset($_POST['aiw_membership_number'])
            ? sanitize_text_field(wp_unslash($_POST['aiw_membership_number']))
            : '';

        $author_matches = $this->author_service->find_matches_by_identifier($author_identifier);
        $author_warning = '';
        if (count($author_matches) > 1 && !AIW_Settings::use_fallback_for_ambiguous_author()) {
            $this->redirect_with_error(__('Several authors match this name. Please enter a more specific name or the membership number.', 'article-import-wizard'));
        }

        if (count($author_matches) !== 1) {
            $attributed_author = $this->author_service->get_fallback_author();
            if (!$attributed_author instanceof WP_User) {
                $this->redirect_with_error(__('No author found and no logged-in author is available.', 'article-import-wizard'));
            }

            if (count($author_matches) > 1) {
                $author_warning = sprintf(
                    /* translators: 1: entered author name, 2: fallback author */
                    __('Several authors match "%1$s". The article is attributed to the default author %2$s.', 'article-import-wizard'),
                    $author_identifier,
                    (string) $attributed_author->display_name
                );
            } elseif ($author_identifier !== '') {
                $author_warning = sprintf(
                    /* translators: 1: entered author name, 2: fallback author */
                    __('No author matches "%1$s". The article is attributed to the default author %2$s.', 'article-import-wizard'),
                    $author_identifier,
                    (string) $attributed_author->display_name
                );
            }
        } else {
            $attributed_author = $author_matches[0];
        }

        $membership_number = (string) $attributed_author->user_login;
        if ($membership_number === '') {
            $this->redirect_with_error(__('The selected author account has no username.', 'article-import-wizard'));
        }

        $post_title = isset($_POST['aiw_post_title'])
            ? sanitize_text_field(wp_unslash($_POST['aiw_post_title']))
            : '';

        $travel_date = isset($_POST['aiw_travel_date'])
            ? sanitize_text_field(wp_unslash($_POST['aiw_travel_date']))
            : '';

        $post_content = isset($_POST['aiw_post_content'])
            ? wp_kses_post(wp_unslash($_POST['aiw_post_content']))
            : '';

        $restriction_start = trim(isset($_POST['aiw_restriction_start'])
            ? sanitize_text_field(wp_unslash($_POST['aiw_restriction_start']))
            : AIW_Settings::get_option_string(AIW_Settings::OPTION_RESTRICTION_START, AIW_Settings::DEFAULT_RESTRICTION_START));

        $restriction_end = trim(isset($_POST['aiw_restriction_end'])
            ? sanitize_text_field(wp_unslash($_POST['aiw_restriction_end']))
            : AIW_Settings::get_option_string(AIW_Settings::OPTION_RESTRICTION_END, AIW_Settings::DEFAULT_RESTRICTION_END));

        // Every article is restricted and every restriction must be closed before the public bio.
        if ($restriction_end === '') {
            $restriction_end = AIW_Settings::closing_shortcode_for($restriction_start);
        }

        if ($restriction_start === '' || $restriction_end === '') {
            $this->redirect_with_error(__('The restriction start and end shortcodes are required. Please set them under Settings > Article Import Wizard.', 'article-import-wizard'));
        }

        $inline_image_count = isset($_POST['aiw_inline_image_count'])
            ? (int) wp_unslash($_POST['aiw_inline_image_count'])
            : AIW_Settings::get_option_int(AIW_Settings::OPTION_INLINE_IMAGE_SLOTS, 3);
        $inline_image_count = max(0, min(self::MAX_INLINE_IMAGE_SLOTS, $inline_image_count));

        $broken_link_mode = isset($_POST['aiw_broken_link_mode'])
            ? sanitize_text_field(wp_unslash($_POST['aiw_broken_link_mode']))
            : AIW_Settings::get_option_string(AIW_Settings::OPTION_REPLACE_BROKEN_LINKS_MODE, 'replace');
        if (!in_array($broken_link_mode, ['replace', 'flag_only'], true)) {
            $broken_link_mode = 'replace';
        }

        $submitted_author_bio = isset($_POST['aiw_author_bio'])
            ? sanitize_textarea_field(wp_unslash($_POST['aiw_author_bio']))
            : '';

        $category_ids = $this->extract_category_ids_from_post();
        $new_category_names = $this->extract_new_category_names_from_post();
        if (!empty($new_category_names) && !current_user_can('manage_categories')) {
            $this->redirect_with_error(__('You do not have permission to create categories.', 'article-import-wizard'));
        }

        if ($travel_date !== '' && !$this->is_valid_iso_date($travel_date)) {
            $this->redirect_with_error(__('Please select a valid travel date.', 'article-import-wizard'));
        }

        if (empty($category_ids) && empty($new_category_names)) {
            $this->redirect_with_error(__('Please select at least one category.', 'article-import-wizard'));
        }


        $doc_parse = $this->parse_uploaded_docx();
        if (!empty($doc_parse['error'])) {
            $this->redirect_with_error((string) $doc_parse['error']);
        }

        $doc_metadata = isset($doc_parse['metadata']) && is_array($doc_parse['metadata']) ? $doc_parse['metadata'] : [];
        $metadata_warnings = [];
        if ($author_warning !== '') {
            $metadata_warnings[] = $author_warning;
        }

        if (!empty($doc_metadata['author_membership']) && (string) $doc_metadata['author_membership'] !== $membership_number) {
            $metadata_warnings[] = __('DOCX membership number does not match the wizard membership number.', 'article-import-wizard');
        }

        $duplicate_markers = [
            'title' => 'TITLE',
            'subtitle' => 'SUBTITLE',
            'author_membership' => 'AUTHOR_MEMBERSHIP',
            'writing_date' => 'WRITING_DATE',
            'bio' => 'BIO',
        ];
        foreach ($duplicate_markers as $key => $marker) {
            if (!empty($doc_metadata[$key . '_duplicate'])) {
                $metadata_warnings[] = sprintf(
                    /* translators: %s: DOCX marker name, e.g. TITLE */
                    __('The DOCX contains more than one [%s] marker. The first value was used.', 'article-import-wizard'),
                    $marker
                );
            }
        }

        if (!empty($doc_metadata['bio_unclosed'])) {
            $metadata_warnings[] = __('The [BIO: marker is not closed with "]". The bio was not imported and its text was kept in the article. Add "]" at the end of the bio.', 'article-import-wizard');
        }

        // [WRITING_DATE: ...] becomes the post date. Without the marker WordPress uses the publish date.
        $post_date = '';
        if (!empty($doc_metadata['writing_date'])) {
            $raw_writing_date = trim((string) $doc_metadata['writing_date']);
            $post_date = $this->parse_writing_date($raw_writing_date);

            if ($post_date === '') {
                $metadata_warnings[] = sprintf(
                    /* translators: %s: the date text found in the DOCX */
                    __('[WRITING_DATE: %s] is not a valid date, so the post date was not set. Use the format 2025-01-15 or 15.01.2025.', 'article-import-wizard'),
                    $raw_writing_date
                );
            } elseif ($post_date > current_time('Y-m-d')) {
                $metadata_warnings[] = sprintf(
                    /* translators: %s: post date */
                    __('The writing date %s is in the future. Publishing the draft will schedule it for that date.', 'article-import-wizard'),
                    $post_date
                );
            }
        }

        if ($post_title === '' && !empty($doc_parse['title'])) {
            $post_title = $this->normalize_title_text((string) $doc_parse['title']);
        }

        if ($post_content === '' && !empty($doc_parse['content'])) {
            $post_content = wp_kses_post((string) $doc_parse['content']);
        }

        if (!empty($doc_parse['subtitle'])) {
            $post_content = '<h2>' . esc_html($this->normalize_title_text((string) $doc_parse['subtitle'])) . '</h2>' . "\n" . $post_content;
        }

        if ($post_title === '' || $post_content === '') {
            $this->redirect_with_error(__('Title and content are required (either manually or from DOCX).', 'article-import-wizard'));
        }

        // Re-upload of an already imported article: same title and same author. Title, author and
        // status of the existing article stay as they are; everything else is updated.
        $match_count = 0;
        $existing_article = $this->find_existing_article($post_title, (int) $attributed_author->ID, $match_count);
        if ($existing_article instanceof WP_Post) {
            if (!current_user_can('edit_post', $existing_article->ID)) {
                $this->redirect_with_error(sprintf(
                    /* translators: 1: post ID, 2: post title */
                    __('This upload matches the existing article #%1$d "%2$s", which you are not allowed to edit. Please ask an editor to import the update.', 'article-import-wizard'),
                    $existing_article->ID,
                    $existing_article->post_title
                ));
            }

            $metadata_warnings = array_merge($metadata_warnings, $this->describe_update_risks($existing_article, $match_count));
            $post_title = $existing_article->post_title;
        }

        // All validation passed: only now perform side effects (new terms, media uploads).
        if (!empty($new_category_names)) {
            $created_category_ids = $this->create_categories_from_names($new_category_names);
            if (is_wp_error($created_category_ids)) {
                $this->redirect_with_error($created_category_ids->get_error_message());
            }

            $category_ids = array_values(array_unique(array_merge($category_ids, $created_category_ids)));
        }

        if (empty($category_ids)) {
            $this->redirect_with_error(__('Please select at least one category.', 'article-import-wizard'));
        }

        $operator_user_id = get_current_user_id();

        $upload_warnings = [];
        $headline_image_id = $this->upload_single_image('aiw_headline_image', 0, $upload_warnings);
        $inline_image_ids = $this->upload_inline_images($inline_image_count, 0, $upload_warnings);
        $author_image_id = $this->upload_single_image('aiw_author_image', 0, $upload_warnings);
        $metadata_warnings = array_merge($metadata_warnings, $upload_warnings);

        // On an update, picture slots without a new upload keep the picture they had before.
        $previous_inline_slots = $existing_article instanceof WP_Post ? $this->get_inline_image_slots($existing_article->ID) : [];
        $previous_headline_image_id = $existing_article instanceof WP_Post
            ? (int) get_post_thumbnail_id($existing_article->ID)
            : 0;
        $inline_image_slots = $inline_image_ids + $previous_inline_slots;
        ksort($inline_image_slots);
        $reused_image_slots = array_keys(array_diff_key($previous_inline_slots, $inline_image_ids));

        $image_map = $this->build_inline_image_map($inline_image_slots);
        $missing_image_slots = [];
        $content_with_images = $this->image_mapper->replace_placeholders($post_content, $image_map, $missing_image_slots);

        $working_content = $this->build_restricted_article_body($content_with_images, $restriction_start, $metadata_warnings);

        // A bio typed into the wizard wins; otherwise a [BIO: ...] marker from the DOCX is used.
        // Either one is saved to the author's profile on draft creation.
        if ($submitted_author_bio === '' && !empty($doc_metadata['bio'])) {
            $submitted_author_bio = sanitize_textarea_field((string) $doc_metadata['bio']);
        }

        $stored_author_bio = (string) get_user_meta((int) $attributed_author->ID, 'aiw_author_bio', true);
        $final_author_bio = $submitted_author_bio !== '' ? $submitted_author_bio : $stored_author_bio;

        $stored_author_image_id = (int) get_user_meta((int) $attributed_author->ID, 'aiw_author_image_id', true);
        $final_author_image_id = $author_image_id > 0 ? $author_image_id : $stored_author_image_id;

        $working_content = $this->append_bio_box_from_data(
            $working_content,
            (string) $attributed_author->display_name,
            $final_author_bio,
            $final_author_image_id,
            $restriction_end,
            AIW_Settings::show_bio_box()
        );

        $links = $this->extract_links_from_content($working_content);
        if (isset($doc_parse['links']) && is_array($doc_parse['links'])) {
            $links = array_merge($links, $doc_parse['links']);
        }
        $links = AIW_Link_Validator::normalize_links($links);

        $timeout_ms = AIW_Settings::get_option_int(AIW_Settings::OPTION_LINK_CHECK_TIMEOUT, 6000);
        $link_validation = $this->link_validator->validate_links($links, $timeout_ms);

        if (!empty($link_validation['skipped'])) {
            $metadata_warnings[] = sprintf(
                /* translators: %d: number of links that were not checked */
                __('%d link(s) were not checked because the link check took too long. Please check them manually.', 'article-import-wizard'),
                count($link_validation['skipped'])
            );
        }

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
            'unchecked_links' => $link_validation['skipped'],
            'missing_image_slots' => array_values(array_unique($missing_image_slots)),
            'broken_link_mode' => $broken_link_mode,
            'writing_date' => $post_date,
            'doc_author' => isset($doc_parse['doc_author']) ? (string) $doc_parse['doc_author'] : '',
            'metadata_warnings' => $metadata_warnings,
        ];

        $preview_payload = [
            'membership_number' => $membership_number,
            'attributed_author_user_id' => (int) $attributed_author->ID,
            'attributed_author_name' => (string) $attributed_author->display_name,
            'operator_user_id' => (int) $operator_user_id,
            'post_title' => $post_title,
            'travel_date' => $travel_date,
            'post_date' => $post_date,
            'working_content' => $working_content,
            'restriction_start' => $restriction_start,
            'restriction_end' => $restriction_end,
            'source_filename' => $source_filename,
            'broken_link_mode' => $broken_link_mode,
            'headline_image_id' => (int) $headline_image_id,
            'previous_headline_image_id' => (int) $previous_headline_image_id,
            'inline_image_ids' => array_map('intval', $inline_image_ids),
            'inline_image_slots' => array_map('intval', $inline_image_slots),
            'reused_image_slots' => $reused_image_slots,
            'update_post_id' => $existing_article instanceof WP_Post ? (int) $existing_article->ID : 0,
            'update_post_status' => $existing_article instanceof WP_Post ? (string) $existing_article->post_status : '',
            'author_image_id' => (int) $author_image_id,
            'final_author_image_id' => (int) $final_author_image_id,
            'submitted_author_bio' => $submitted_author_bio,
            'final_author_bio' => $final_author_bio,
            'category_ids' => $category_ids,
            'category_names' => $this->resolve_category_names($category_ids),
            'validation_report' => $validation_report,
            'invalid_links_count' => count($link_validation['invalid']),
            'metadata_warnings' => $metadata_warnings,
            'created_at' => gmdate('c'),
        ];

        $token = wp_generate_password(20, false, false);
        $this->store_preview_data($token, $preview_payload);

        $this->redirect_to_preview(
            $token,
            $existing_article instanceof WP_Post
                ? __('Preview generated. This upload updates an existing article. Review and confirm the update.', 'article-import-wizard')
                : __('Preview generated. Review and confirm draft creation.', 'article-import-wizard')
        );
    }

    /**
     * Find a previously imported article with the same title (ignoring case and whitespace) by the
     * same author. Trashed articles are ignored. If several match, the most recently modified wins.
     */
    private function find_existing_article(string $title, int $author_id, int &$match_count): ?WP_Post
    {
        $match_count = 0;
        $wanted = $this->normalize_title_for_match($title);
        if ($wanted === '' || $author_id <= 0) {
            return null;
        }

        $candidates = get_posts([
            'post_type' => 'post',
            'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
            'author' => $author_id,
            'meta_key' => '_aiw_imported',
            'meta_value' => '1',
            'posts_per_page' => -1,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);

        $match = null;
        foreach ($candidates as $candidate) {
            if ($candidate instanceof WP_Post && $this->normalize_title_for_match($candidate->post_title) === $wanted) {
                $match_count++;
                $match ??= $candidate;
            }
        }

        return $match;
    }

    private function normalize_title_for_match(string $title): string
    {
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = (string) preg_replace('/\s+/u', ' ', $title);

        return mb_strtolower(trim($title));
    }

    /**
     * Preview warnings that tell the editor what confirming the update will do.
     *
     * @return string[]
     */
    private function describe_update_risks(WP_Post $article, int $match_count): array
    {
        $warnings = [];

        if ($match_count > 1) {
            $warnings[] = sprintf(
                /* translators: 1: number of matching articles, 2: post ID that will be updated */
                __('%1$d imported articles have this title and author. The most recently changed one (#%2$d) will be updated.', 'article-import-wizard'),
                $match_count,
                $article->ID
            );
        }

        if (in_array($article->post_status, ['publish', 'private'], true)) {
            $warnings[] = __('This article is published. Confirming changes it live; the previous version is kept as a revision.', 'article-import-wizard');
        }

        $last_import = (string) get_post_meta($article->ID, '_aiw_updated_at', true);
        if ($last_import === '') {
            $last_import = (string) get_post_meta($article->ID, '_aiw_imported_at', true);
        }

        $last_import_time = $last_import !== '' ? strtotime($last_import) : false;
        $modified_time = strtotime($article->post_modified_gmt . ' UTC');
        if ($last_import_time !== false && $modified_time !== false && $modified_time > $last_import_time + 60) {
            $warnings[] = sprintf(
                /* translators: %s: date and time of the last manual edit */
                __('The article was edited in WordPress on %s, after its last import. Those edits will be overwritten; they stay available as a revision.', 'article-import-wizard'),
                mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $article->post_modified)
            );
        }

        return $warnings;
    }

    /**
     * @return array<int,int> Inline picture attachment IDs by slot number, as saved on the article.
     */
    private function get_inline_image_slots(int $post_id): array
    {
        $stored = get_post_meta($post_id, '_aiw_inline_image_slots', true);
        if (!is_array($stored)) {
            return [];
        }

        $slots = [];
        foreach ($stored as $slot => $attachment_id) {
            $slot = (int) $slot;
            $attachment_id = (int) $attachment_id;
            if ($slot > 0 && $attachment_id > 0 && get_post_type($attachment_id) === 'attachment') {
                $slots[$slot] = $attachment_id;
            }
        }

        return $slots;
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

        // Claim the preview before doing any work so a double-submitted form cannot create two drafts.
        $this->delete_preview_data($preview_token);

        $operator_user_id = get_current_user_id();

        $attributed_author_user_id = isset($preview['attributed_author_user_id']) ? (int) $preview['attributed_author_user_id'] : 0;
        if ($attributed_author_user_id <= 0) {
            $this->redirect_with_error(__('Attributed author is missing in preview payload.', 'article-import-wizard'));
        }

        $category_ids = isset($preview['category_ids']) && is_array($preview['category_ids'])
            ? array_values(array_filter(array_map('intval', $preview['category_ids']), static function (int $id): bool {
                return $id > 0;
            }))
            : [];

        if (empty($category_ids)) {
            $this->redirect_with_error(__('At least one category is required. Please generate preview again.', 'article-import-wizard'));
        }

        $update_post_id = isset($preview['update_post_id']) ? (int) $preview['update_post_id'] : 0;
        $is_update = $update_post_id > 0 && empty($_POST['aiw_create_new']);
        $confirmed_update_post_id = isset($_POST['aiw_confirm_update_post_id'])
            ? (int) wp_unslash($_POST['aiw_confirm_update_post_id'])
            : 0;
        if ($is_update && $confirmed_update_post_id !== $update_post_id) {
            $this->store_preview_data($preview_token, $preview);
            $this->redirect_with_error(__('The article selected for update changed. Please review the preview again.', 'article-import-wizard'));
        }

        if ($is_update) {
            // Re-check at confirmation time: the article may have been trashed, reassigned or locked
            // for this user since the preview was generated.
            $existing = get_post($update_post_id);
            if (
                !$existing instanceof WP_Post
                || $existing->post_status === 'trash'
                || (int) $existing->post_author !== $attributed_author_user_id
                || !current_user_can('edit_post', $update_post_id)
            ) {
                $this->redirect_with_error(__('The article to update is no longer available or you may not edit it. Please generate the preview again.', 'article-import-wizard'));
            }

            // Title, author and status are immutable on update; only these fields change.
            $post_data = [
                'ID' => $update_post_id,
                'post_content' => (string) $preview['working_content'],
                'post_category' => $category_ids,
            ];
        } else {
            $requested_status = isset($_POST['aiw_import_status'])
                ? sanitize_key(wp_unslash($_POST['aiw_import_status']))
                : 'draft';

            if ($requested_status === 'publish' && !current_user_can('publish_posts')) {
                $this->store_preview_data($preview_token, $preview);
                $this->redirect_with_error(__('You do not have permission to publish articles. The import was not completed.', 'article-import-wizard'));
            }

            $post_status = $requested_status === 'publish' ? 'publish' : 'draft';
            $post_data = [
                'post_type' => 'post',
                'post_status' => $post_status,
                'post_title' => (string) $preview['post_title'],
                'post_content' => (string) $preview['working_content'],
                'post_author' => $attributed_author_user_id,
                'post_category' => $category_ids,
            ];
        }

        // The writing date becomes the post date. Setting post_date_gmt explicitly fixes the date on
        // a draft; otherwise WordPress would replace it with the publish date. On an update without
        // a writing date, the existing date is kept.
        $post_date = isset($preview['post_date']) ? (string) $preview['post_date'] : '';
        if ($post_date !== '' && $this->is_valid_iso_date($post_date)) {
            $post_data['post_date'] = $post_date . ' 00:00:00';
            $post_data['post_date_gmt'] = get_gmt_from_date($post_data['post_date']);
            $post_data['edit_date'] = true;
        }

        $post_id = $is_update ? wp_update_post($post_data, true) : wp_insert_post($post_data, true);

        if (is_wp_error($post_id) || (int) $post_id <= 0) {
            // Give the editor the chance to retry without re-uploading everything.
            $this->store_preview_data($preview_token, $preview);
            $this->redirect_with_error(is_wp_error($post_id) ? $post_id->get_error_message() : __('The article could not be saved.', 'article-import-wizard'));
        }

        $headline_image_id = isset($preview['headline_image_id']) ? (int) $preview['headline_image_id'] : 0;
        if ($headline_image_id > 0) {
            $this->attach_preview_upload($headline_image_id, (int) $post_id);
            set_post_thumbnail($post_id, $headline_image_id);
        } elseif (!$is_update && $post_id > 0) {
            $previous_headline_image_id = isset($preview['previous_headline_image_id'])
                ? (int) $preview['previous_headline_image_id']
                : 0;
            if ($previous_headline_image_id > 0 && get_post_type($previous_headline_image_id) === 'attachment') {
                wp_update_post([
                    'ID' => $previous_headline_image_id,
                    'post_parent' => (int) $post_id,
                ]);
                set_post_thumbnail($post_id, $previous_headline_image_id);
            }
        }

        $inline_image_ids = isset($preview['inline_image_ids']) && is_array($preview['inline_image_ids'])
            ? $preview['inline_image_ids']
            : [];
        foreach ($inline_image_ids as $inline_image_id) {
            $this->attach_preview_upload((int) $inline_image_id, (int) $post_id);
        }

        $submitted_author_bio = isset($preview['submitted_author_bio']) ? (string) $preview['submitted_author_bio'] : '';
        if ($submitted_author_bio !== ''
            && AIW_Settings::may_update_author_profile((string) get_user_meta($attributed_author_user_id, 'aiw_author_bio', true))) {
            update_user_meta($attributed_author_user_id, 'aiw_author_bio', $submitted_author_bio);
        }

        $author_image_id = isset($preview['author_image_id']) ? (int) $preview['author_image_id'] : 0;
        if ($author_image_id > 0) {
            $this->attach_preview_upload($author_image_id, (int) $post_id);
            if (AIW_Settings::may_update_author_profile((int) get_user_meta($attributed_author_user_id, 'aiw_author_image_id', true))) {
                update_user_meta($attributed_author_user_id, 'aiw_author_image_id', $author_image_id);
            }
        }

        $validation_report = isset($preview['validation_report']) && is_array($preview['validation_report'])
            ? $preview['validation_report']
            : [];

        update_post_meta($post_id, '_aiw_imported', 1);
        update_post_meta($post_id, '_aiw_import_status', 'completed');
        update_post_meta($post_id, '_aiw_attributed_author_user_id', $attributed_author_user_id);
        if ($is_update) {
            // The original importer and import time are kept for the audit trail.
            update_post_meta($post_id, '_aiw_updated_by_user_id', (int) $operator_user_id);
            update_post_meta($post_id, '_aiw_updated_at', gmdate('c'));
            update_post_meta($post_id, '_aiw_update_count', (int) get_post_meta($post_id, '_aiw_update_count', true) + 1);
        } else {
            update_post_meta($post_id, '_aiw_imported_by_user_id', (int) $operator_user_id);
            update_post_meta($post_id, '_aiw_imported_at', gmdate('c'));
        }
        update_post_meta($post_id, '_aiw_inline_image_slots', isset($preview['inline_image_slots']) && is_array($preview['inline_image_slots']) ? array_map('intval', $preview['inline_image_slots']) : []);
        update_post_meta($post_id, '_aiw_doc_source_filename', isset($preview['source_filename']) ? (string) $preview['source_filename'] : '');
        update_post_meta($post_id, '_aiw_validation_report', wp_json_encode($validation_report));
        update_post_meta($post_id, '_aiw_restriction_shortcode_start', isset($preview['restriction_start']) ? (string) $preview['restriction_start'] : '');
        update_post_meta($post_id, '_aiw_restriction_shortcode_end', isset($preview['restriction_end']) ? (string) $preview['restriction_end'] : '');
        update_post_meta($post_id, '_aiw_category_ids', $category_ids);
        update_post_meta($post_id, '_aiw_travel_date', isset($preview['travel_date']) ? (string) $preview['travel_date'] : '');

        if (AIW_Settings::notifications_enabled()) {
            $notify_email = AIW_Settings::get_option_string(AIW_Settings::OPTION_NOTIFY_EMAIL, get_option('admin_email', ''));
            $this->notifier->send_import_complete_email($post_id, $notify_email, [
                'membership_number' => isset($preview['membership_number']) ? (string) $preview['membership_number'] : '',
                'attributed_author' => isset($preview['attributed_author_name']) ? (string) $preview['attributed_author_name'] : '',
                'invalid_links' => isset($preview['invalid_links_count']) ? (int) $preview['invalid_links_count'] : 0,
                'operator_user_id' => (int) $operator_user_id,
                'updated' => $is_update,
            ]);
        }

        $edit_url = get_edit_post_link($post_id, '');
        $message = sprintf(
            $is_update
                ? __('Article #%1$s updated. Attributed author: %2$s', 'article-import-wizard')
                : ((isset($post_status) && $post_status === 'publish')
                    ? __('Article #%1$s published. Attributed author: %2$s', 'article-import-wizard')
                    : __('Draft #%1$s created. Attributed author: %2$s', 'article-import-wizard')),
            $post_id,
            isset($preview['attributed_author_name']) ? (string) $preview['attributed_author_name'] : ''
        );

        $this->redirect_with_message($message, $edit_url);
    }

    private function can_access_wizard(): bool
    {
        return AIW_Settings::user_can_use_wizard();
    }

    /**
     * Parse a [WRITING_DATE] value into Y-m-d. Accepts 2025-01-15, 15.01.2025 / 15.1.2025, and an
     * ISO date-time such as 2025-01-15T10:30:00Z (the time is ignored).
     * Returns '' when the value is not a real calendar date.
     */
    private function parse_writing_date(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T\s].*)?$/', $value, $parts) === 1) {
            [$year, $month, $day] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
        } elseif (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})$/', $value, $parts) === 1) {
            [$day, $month, $year] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
        } else {
            return '';
        }

        if (!checkdate($month, $day, $year)) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function is_valid_iso_date(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
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
        if (!is_uploaded_file($tmp_name)) {
            return $this->empty_doc_parse_payload(__('The uploaded DOCX temp file could not be verified.', 'article-import-wizard'));
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

    private function append_bio_box_from_data(string $content, string $display_name, string $bio, int $image_id, string $restriction_end, bool $show_bio_box = true): string
    {
        $restriction_end = trim($restriction_end);
        if (!$show_bio_box) {
            $parts = [];
            if ($content !== '') {
                $parts[] = $content;
            }
            $parts[] = $this->wrap_shortcode_block($restriction_end);

            return implode("\n\n", $parts);
        }

        $image_html = '';
        if ($image_id > 0) {
            $candidate = wp_get_attachment_image($image_id, 'medium', false, ['class' => 'aiw-bio-box__image']);
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
        // Blank lines separate bio paragraphs; single line breaks stay line breaks.
        foreach (preg_split('/\R\s*\R/u', trim($bio)) ?: [] as $bio_paragraph) {
            $bio_paragraph = trim($bio_paragraph);
            if ($bio_paragraph !== '') {
                $bio_html .= '<p class="aiw-bio-box__text">' . preg_replace('/\R/u', '<br>', $this->link_urls_in_text($bio_paragraph)) . '</p>';
            }
        }
        $bio_html .= '</div></div>';

        // The restricted part always ends right after the article body; the bio box is always public.
        $parts = [];
        if ($content !== '') {
            $parts[] = $content;
        }
        $parts[] = $this->wrap_shortcode_block($restriction_end);
        $parts[] = $this->wrap_raw_html_block($bio_html);

        return implode("\n\n", $parts);
    }

    /**
     * Convert the article body to blocks and insert the restriction start shortcode: at the
     * [RESTRICT] anchor if the document has one, otherwise automatically (see
     * find_automatic_restriction_position()).
     */
    /**
     * Escape plain text and turn web addresses (https://..., http://..., www....) into links.
     * Sentence punctuation after an address ("...example.com.") is kept outside the link.
     */
    private function link_urls_in_text(string $text): string
    {
        $parts = preg_split('~((?:https?://|www\.)[^\s<>"]+)~iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return esc_html($text);
        }

        $html = '';
        foreach ($parts as $index => $part) {
            // Odd indexes are the captured addresses.
            if ($index % 2 === 0) {
                $html .= esc_html($part);
                continue;
            }

            $url = AIW_Link_Validator::normalize_link($part);
            $trailing = (string) substr($part, strlen($url));
            if ($url === '' || preg_match('~^(?:https?://|www\.)[^./]+\.[^/]~i', $url) !== 1) {
                $html .= esc_html($part);
                continue;
            }

            $href = stripos($url, 'www.') === 0 ? 'https://' . $url : $url;
            $html .= '<a href="' . esc_url($href) . '">' . esc_html($url) . '</a>' . esc_html($trailing);
        }

        return $html;
    }

    private function build_restricted_article_body(string $content, string $restriction_start, array &$metadata_warnings): string
    {
        $start_block = $this->wrap_shortcode_block($restriction_start);
        $content = $this->normalize_restriction_markers($content);

        $blocks = null;
        if ($this->contains_restriction_anchor($content)) {
            $blocks = $this->convert_to_blocks_at_anchor($content, $start_block);
        }

        if ($blocks === null) {
            $blocks = $this->convert_to_blocks($content);
            [$insert_after, $paragraph_number] = $this->find_automatic_restriction_position($blocks);
            array_splice($blocks, $insert_after + 1, 0, [$start_block]);

            if ($paragraph_number > 0) {
                $metadata_warnings[] = sprintf(
                    /* translators: %d: paragraph number after which the restricted part starts */
                    __('No [RESTRICT] marker found. The restricted part starts automatically after paragraph %d.', 'article-import-wizard'),
                    $paragraph_number
                );
            }
        }

        if (end($blocks) === $start_block) {
            $metadata_warnings[] = __('The restricted part is empty because nothing follows the restriction start. Add a [RESTRICT] marker earlier in the article.', 'article-import-wizard');
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Without a [RESTRICT] marker the restricted part starts after the configured number of text paragraphs, or
     * after the paragraph in which the text reaches the configured character limit,
     * whichever comes first. Headings, images and lists are not counted.
     *
     * @param string[] $blocks
     * @return array{0:int,1:int} Index of the block to insert after (-1 = at the top), and the
     *                            number of the paragraph it follows (0 = no text paragraph found,
     *                            in which case the start goes after the last block).
     */
    private function find_automatic_restriction_position(array $blocks): array
    {
        $paragraph_limit = AIW_Settings::get_restriction_paragraphs();
        $character_limit = AIW_Settings::get_restriction_characters();
        $paragraph_count = 0;
        $characters = 0;
        $last_paragraph_index = null;

        foreach ($blocks as $index => $block) {
            if (preg_match('/^<!--\s*wp:paragraph\b/', $block) !== 1) {
                continue;
            }

            $text = trim(html_entity_decode(wp_strip_all_tags($block), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === '') {
                continue;
            }

            $paragraph_count++;
            $characters += mb_strlen($text);
            $last_paragraph_index = $index;

            if ($paragraph_count >= $paragraph_limit || $characters >= $character_limit) {
                return [$index, $paragraph_count];
            }
        }

        if ($last_paragraph_index !== null) {
            return [$last_paragraph_index, $paragraph_count];
        }

        return [count($blocks) - 1, 0];
    }

    /**
     * @return string[]|null Blocks with the start shortcode at the anchor, or null if the anchor
     *                       could not be located in an element.
     */
    private function convert_to_blocks_at_anchor(string $content, string $start_block): ?array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="aiw-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $document->getElementById('aiw-root');
        if (!$root instanceof DOMElement) {
            return null;
        }

        $blocks = [];
        $anchor_inserted = false;

        foreach (iterator_to_array($root->childNodes) as $node) {
            if ($node instanceof DOMText && trim($node->wholeText) === '') {
                continue;
            }

            if ($node instanceof DOMElement) {
                $segments = $this->convert_dom_element_to_blocks_with_restriction($node, $start_block, $anchor_inserted);
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

        return $anchor_inserted ? $blocks : null;
    }

    private function convert_dom_element_to_blocks_with_restriction(DOMElement $node, string $start_block, bool &$anchor_inserted): array
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

            $blocks[] = $start_block;

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

    /**
     * @return string[]
     */
    private function convert_to_blocks(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
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

        return $blocks;
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
            if ($node->getElementsByTagName('figure')->length > 0) {
                return $this->convert_mixed_paragraph_node_to_blocks($node);
            }

            return [$this->wrap_paragraph_block($this->dom_node_inner_html($node))];
        }

        if ($tag === 'h1' || $tag === 'h2' || $tag === 'h3' || $tag === 'h4' || $tag === 'h5' || $tag === 'h6') {
            return [$this->wrap_heading_block((int) substr($tag, 1), $this->dom_node_inner_html($node))];
        }

        if ($tag === 'figure' || strpos((string) $node->getAttribute('class'), 'wp-block-image') !== false) {
            return [$this->wrap_image_block($node)];
        }

        if ($tag === 'ul' || $tag === 'ol') {
            return [$this->wrap_list_block($node)];
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

        return "<!-- wp:heading {\"level\":{$level}} -->\n<h{$level} class=\"wp-block-heading\">" . trim($inner_html) . "</h{$level}>\n<!-- /wp:heading -->";
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
            $block .= '<figcaption class="wp-element-caption">' . esc_html($caption) . '</figcaption>';
        }
        $block .= '</figure><!-- /wp:image -->';

        return $block;
    }

    /**
     * List block with one list-item block per <li>, the format the block editor saves. Nested
     * lists become list blocks inside their parent list item.
     */
    private function wrap_list_block(DOMElement $node): string
    {
        $ordered = strtolower($node->tagName) === 'ol';
        $tag = $ordered ? 'ol' : 'ul';

        $items = '';
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement || strtolower($child->tagName) !== 'li') {
                continue;
            }

            $content = '';
            $nested = '';
            foreach ($child->childNodes as $part) {
                if ($part instanceof DOMElement && in_array(strtolower($part->tagName), ['ul', 'ol'], true)) {
                    $nested .= $this->wrap_list_block($part);
                    continue;
                }

                $content .= $node->ownerDocument->saveHTML($part);
            }

            $items .= "<!-- wp:list-item -->\n<li>" . trim($content) . $nested . "</li>\n<!-- /wp:list-item -->";
        }

        return '<!-- wp:list' . ($ordered ? ' {"ordered":true}' : '') . " -->\n"
            . '<' . $tag . ' class="wp-block-list">' . $items . '</' . $tag . '>'
            . "\n<!-- /wp:list -->";
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
        $text = preg_replace('/^\[(TITLE|HEADLINE|H1|SUBTITLE|SUBHEADER|H2|AUTHOR_MEMBERSHIP|MEMBERSHIP|MEMBER_ID|WRITING_DATE|DATE|BIO)\s*:\s*([^\]]+)\]$/i', '$2', $text);
        $text = preg_replace('/^\[(TITLE|HEADLINE|H1|SUBTITLE|SUBHEADER|H2|AUTHOR_MEMBERSHIP|MEMBERSHIP|MEMBER_ID|WRITING_DATE|DATE|BIO)\]$/i', '', (string) $text);

        return trim((string) $text);
    }

    /**
     * @param array<int,int> $attachment_ids Attachment IDs keyed by their 1-based slot number.
     */
    private function build_inline_image_map(array $attachment_ids): array
    {
        $map = [];

        foreach ($attachment_ids as $slot => $attachment_id) {
            $url = wp_get_attachment_url((int) $attachment_id);
            if (!is_string($url) || $url === '') {
                continue;
            }

            $alt = (string) get_post_meta((int) $attachment_id, '_wp_attachment_image_alt', true);
            $map[(int) $slot] = [
                'id' => (int) $attachment_id,
                'url' => $url,
                'alt' => $alt,
            ];
        }

        return $map;
    }

    /**
     * @param string[] $warnings Receives a message when a selected file could not be uploaded.
     */
    private function upload_single_image(string $field_name, int $post_id, array &$warnings): int
    {
        if (!isset($_FILES[$field_name]['name']) || !is_string($_FILES[$field_name]['name']) || $_FILES[$field_name]['name'] === '') {
            return 0;
        }

        $this->include_media_admin_files();
        $attachment_id = media_handle_upload($field_name, $post_id);

        if (is_wp_error($attachment_id)) {
            $warnings[] = sprintf(
                /* translators: 1: uploaded file name, 2: error message */
                __('Image "%1$s" could not be uploaded: %2$s', 'article-import-wizard'),
                sanitize_file_name(wp_unslash($_FILES[$field_name]['name'])),
                $attachment_id->get_error_message()
            );
            return 0;
        }

        $this->mark_preview_upload((int) $attachment_id);
        $this->normalize_uploaded_image_orientation((int) $attachment_id);

        return (int) $attachment_id;
    }

    /**
     * @param string[] $warnings
     * @return array<int,int> Attachment IDs keyed by slot number, so [Bild 2] always gets slot 2
     *                        even when slot 1 was left empty.
     */
    private function upload_inline_images(int $limit, int $post_id, array &$warnings): array
    {
        if ($limit <= 0) {
            return [];
        }

        $attachment_ids = [];
        for ($slot = 1; $slot <= $limit; $slot++) {
            $attachment_id = $this->upload_single_image('aiw_inline_image_' . $slot, $post_id, $warnings);
            if ($attachment_id > 0) {
                $attachment_ids[$slot] = $attachment_id;
            }
        }

        return $attachment_ids;
    }

    private function mark_preview_upload(int $attachment_id): void
    {
        update_post_meta($attachment_id, self::PREVIEW_UPLOAD_META, time());
    }

    private function attach_preview_upload(int $attachment_id, int $post_id): void
    {
        if ($attachment_id <= 0) {
            return;
        }

        wp_update_post([
            'ID' => $attachment_id,
            'post_parent' => $post_id,
        ]);
        delete_post_meta($attachment_id, self::PREVIEW_UPLOAD_META);
    }

    private function include_media_admin_files(): void
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }

    private function normalize_uploaded_image_orientation(int $attachment_id): void
    {
        if ($attachment_id <= 0) {
            return;
        }

        $file_path = get_attached_file($attachment_id);
        if (!is_string($file_path) || $file_path === '' || !file_exists($file_path)) {
            return;
        }

        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            return;
        }

        // WordPress already rotates photos on upload. This is a safety net for images core left
        // unrotated; maybe_exif_rotate() returns true only when it actually changed the image.
        $result = $editor->maybe_exif_rotate();
        if ($result !== true) {
            return;
        }

        $saved = $editor->save($file_path);
        if (is_wp_error($saved)) {
            return;
        }

        $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
        if (is_array($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }
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

    private function convert_mixed_paragraph_node_to_blocks(DOMElement $paragraph): array
    {
        $blocks = [];
        $buffer = '';

        foreach (iterator_to_array($paragraph->childNodes) as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'figure') {
                $buffer = trim($buffer);
                if ($buffer !== '') {
                    $blocks[] = $this->wrap_paragraph_block($buffer);
                    $buffer = '';
                }

                $blocks[] = $this->wrap_image_block($child);
                continue;
            }

            $buffer .= $paragraph->ownerDocument->saveHTML($child);
        }

        $buffer = trim($buffer);
        if ($buffer !== '') {
            $blocks[] = $this->wrap_paragraph_block($buffer);
        }

        if (empty($blocks)) {
            return [$this->wrap_paragraph_block($this->dom_node_inner_html($paragraph))];
        }

        return $blocks;
    }

    private function replace_invalid_links_in_content(string $content, array $invalid_urls): string
    {
        if ($content === '' || empty($invalid_urls)) {
            return $content;
        }

        $marker = '<span class="aiw-check-link" style="color:#cc0000;font-weight:700;">CHECK LINK</span>';

        // Longest first, so a broken URL that is a prefix of another broken URL cannot split it.
        usort($invalid_urls, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });

        foreach ($invalid_urls as $invalid_url) {
            // URLs are validated in decoded form, but the content stores them HTML-encoded:
            // &amp; in text (esc_html) and &#038; in imported link hrefs (esc_url).
            $variants = array_unique([
                $invalid_url,
                esc_html($invalid_url),
                htmlspecialchars($invalid_url, ENT_QUOTES, 'UTF-8'),
                str_replace('&', '&#038;', $invalid_url),
            ]);

            foreach ($variants as $variant) {
                $quoted = preg_quote($variant, '/');

                $content = (string) preg_replace(
                    '/<a\b[^>]*href=["\']' . $quoted . '["\'][^>]*>.*?<\/a>/is',
                    $marker,
                    $content
                );

                // Bare URL in text. It must not be inside an attribute or another URL, and it must
                // end here: the next character is whitespace, a tag, the end, or sentence punctuation
                // that is itself followed by a boundary. This keeps "https://x.com/a" from matching
                // inside "https://x.com/abc".
                $content = (string) preg_replace(
                    '/(?<![\w"\'=\/])' . $quoted . '(?=$|[\s<]|[.,;:!?)\]}\'"*]+(?:$|[\s<]))/iu',
                    $marker,
                    $content
                );
            }
        }

        return $content;
    }

    private function preview_transient_key(string $token): string
    {
        return 'aiw_preview_' . get_current_user_id() . '_' . $token;
    }

    private function extract_category_ids_from_post(): array
    {
        if (!isset($_POST['aiw_category_ids'])) {
            return [];
        }

        $raw = wp_unslash($_POST['aiw_category_ids']);
        if (!is_array($raw)) {
            return [];
        }

        $category_ids = [];
        foreach ($raw as $value) {
            $category_id = (int) $value;
            if ($category_id <= 0) {
                continue;
            }

            if (!term_exists($category_id, 'category')) {
                continue;
            }

            $category_ids[] = $category_id;
        }

        return array_values(array_unique($category_ids));
    }

    private function extract_new_category_names_from_post(): array
    {
        if (!isset($_POST['aiw_new_category_names'])) {
            return [];
        }

        $raw = wp_unslash($_POST['aiw_new_category_names']);
        if (!is_array($raw)) {
            return [];
        }

        $names = [];
        foreach ($raw as $value) {
            $name = sanitize_text_field((string) $value);
            if ($name === '') {
                continue;
            }

            $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    private function create_categories_from_names(array $names)
    {
        $created_ids = [];

        foreach ($names as $name) {
            $existing = term_exists($name, 'category');
            if (is_array($existing) && isset($existing['term_id'])) {
                $created_ids[] = (int) $existing['term_id'];
                continue;
            }

            if (is_int($existing)) {
                $created_ids[] = $existing;
                continue;
            }

            $insert = wp_insert_term($name, 'category');
            if (is_wp_error($insert)) {
                return $insert;
            }

            if (is_array($insert) && isset($insert['term_id'])) {
                $created_ids[] = (int) $insert['term_id'];
            }
        }

        return array_values(array_unique(array_filter($created_ids, static function (int $id): bool {
            return $id > 0;
        })));
    }

    private function resolve_category_names(array $category_ids): array
    {
        $names = [];
        foreach ($category_ids as $category_id) {
            $term = get_term((int) $category_id, 'category');
            if ($term && !is_wp_error($term) && isset($term->name)) {
                $names[] = (string) $term->name;
            }
        }

        return $names;
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
