<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Download for InDesign": a ZIP with the article as an InCopy story (.icml) for the InDesign
 * article template, plus the uploaded pictures. The article comes either from a wizard preview
 * (before the import is completed) or from an imported post, which keeps its print data in
 * post meta.
 */
class AIW_InDesign_Export
{
    public const ACTION = 'aiw_indesign_download';

    /** Post meta with the article data the ICML is built from; written on import. */
    public const PRINT_META = '_aiw_print_article';

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle_download']);
        add_filter('post_row_actions', [$this, 'add_row_action'], 10, 2);
    }

    /**
     * Collect the print data of an article. Pass the clean article HTML from the DOCX parser,
     * without subtitle heading, restriction shortcodes, image blocks or bio box.
     *
     * @param array<int,int> $inline_image_ids Attachment IDs keyed by picture slot.
     */
    public static function print_data(
        string $title,
        string $subtitle,
        string $travel_period,
        string $content,
        string $author_name,
        string $bio,
        int $headline_image_id,
        array $inline_image_ids,
        int $author_image_id
    ): array {
        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'travel_period' => $travel_period,
            'content' => $content,
            'author_name' => $author_name,
            'bio' => $bio,
            'headline_image_id' => $headline_image_id,
            'inline_image_ids' => array_map('intval', $inline_image_ids),
            'author_image_id' => $author_image_id,
        ];
    }

    public static function preview_download_url(string $preview_token): string
    {
        return wp_nonce_url(
            add_query_arg(['action' => self::ACTION, 'aiw_preview_token' => $preview_token], admin_url('admin-post.php')),
            self::ACTION
        );
    }

    public static function post_download_url(int $post_id): string
    {
        return wp_nonce_url(
            add_query_arg(['action' => self::ACTION, 'post_id' => $post_id], admin_url('admin-post.php')),
            self::ACTION
        );
    }

    public static function post_has_print_data(int $post_id): bool
    {
        return is_array(get_post_meta($post_id, self::PRINT_META, true));
    }

    /**
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public function add_row_action(array $actions, WP_Post $post): array
    {
        if (
            $post->post_type !== 'post'
            || !AIW_Settings::indesign_enabled()
            || !AIW_Settings::user_can_use_wizard()
            || !current_user_can('edit_post', $post->ID)
            || !self::post_has_print_data($post->ID)
        ) {
            return $actions;
        }

        $actions['aiw_indesign'] = '<a href="' . esc_url(self::post_download_url($post->ID)) . '">'
            . esc_html__('InDesign', 'article-import-wizard') . '</a>';

        return $actions;
    }

    public function handle_download(): void
    {
        if (!AIW_Settings::user_can_use_wizard() || !AIW_Settings::indesign_enabled()) {
            wp_die(esc_html__('Unauthorized.', 'article-import-wizard'));
        }

        check_admin_referer(self::ACTION);

        $preview_token = isset($_GET['aiw_preview_token']) ? sanitize_text_field(wp_unslash($_GET['aiw_preview_token'])) : '';
        $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;

        if ($preview_token !== '') {
            $preview = get_transient(AIW_Wizard_Controller::preview_transient_key($preview_token));
            $article = is_array($preview) && isset($preview['print_article']) && is_array($preview['print_article'])
                ? $preview['print_article']
                : null;
            if ($article === null) {
                $this->fail(__('Preview data expired. Please run preview again.', 'article-import-wizard'));
            }
        } elseif ($post_id > 0) {
            if (!current_user_can('edit_post', $post_id)) {
                wp_die(esc_html__('Unauthorized.', 'article-import-wizard'));
            }

            $article = get_post_meta($post_id, self::PRINT_META, true);
            if (!is_array($article)) {
                $this->fail(__('This article has no InDesign data. Import it again with the wizard to download it for InDesign.', 'article-import-wizard'));
            }
        } else {
            $this->fail(__('The InDesign package could not be created.', 'article-import-wizard'));
        }

        $zip_path = $this->build_package($article);
        if ($zip_path === '') {
            $this->fail($this->build_error !== '' ? $this->build_error : __('The InDesign package could not be created.', 'article-import-wizard'));
        }

        $basename = $this->file_basename((string) ($article['title'] ?? ''));

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $basename . '.zip"');
        header('Content-Length: ' . (string) filesize($zip_path));
        header('X-Content-Type-Options: nosniff');
        readfile($zip_path);
        @unlink($zip_path);
        exit;
    }

    /** Error of the last build_package() call, shown to the editor. */
    private string $build_error = '';

    /**
     * With an article template the package holds the filled InDesign document; otherwise an
     * InCopy story to place by hand. The pictures are in "Links", as InDesign packages them.
     *
     * @return string Path of the temporary ZIP file, or '' on failure (see $build_error).
     */
    private function build_package(array $article): string
    {
        $this->build_error = '';
        if (!class_exists('ZipArchive')) {
            return '';
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $zip_path = wp_tempnam('aiw-indesign.zip');
        if (!is_string($zip_path) || $zip_path === '') {
            return '';
        }

        $basename = $this->file_basename((string) ($article['title'] ?? ''));
        $pictures = $this->package_pictures($article);
        $writer = new AIW_ICML_Writer(AIW_Settings::get_indesign_styles());
        $text = [
            'title' => (string) ($article['title'] ?? ''),
            'subtitle' => (string) ($article['subtitle'] ?? ''),
            'author_name' => (string) ($article['author_name'] ?? ''),
            'travel_period' => (string) ($article['travel_period'] ?? ''),
            'content' => (string) ($article['content'] ?? ''),
            'bio' => (string) ($article['bio'] ?? ''),
        ];

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::OVERWRITE) !== true) {
            @unlink($zip_path);
            return '';
        }

        $template = AIW_Settings::get_indesign_template_path();
        if ($template !== '') {
            $result = (new AIW_IDML_Builder($writer))->build($template, $text, $pictures);
            if ($result['error'] !== '' || $result['idml'] === '') {
                $zip->close();
                @unlink($zip_path);
                $this->build_error = $result['error'];
                return '';
            }

            $zip->addFromString($basename . '.idml', $result['idml']);
            if (!empty($result['warnings'])) {
                $zip->addFromString('Hinweise.txt', implode("\r\n", $result['warnings']) . "\r\n");
            }
        } else {
            $zip->addFromString($basename . '.icml', $writer->build($text));
        }

        foreach ($pictures as $picture) {
            $zip->addFile($picture['file'], $picture['link']);
        }

        if (!$zip->close()) {
            @unlink($zip_path);
            return '';
        }

        return $zip_path;
    }

    /**
     * The article's pictures, keyed by their template placeholder.
     *
     * @return array<string,array{file:string,link:string}>
     */
    private function package_pictures(array $article): array
    {
        $attachments = [];
        if (!empty($article['headline_image_id'])) {
            $attachments['AUFMACHERBILD'] = ['Aufmacherbild', (int) $article['headline_image_id']];
        }
        foreach ((array) ($article['inline_image_ids'] ?? []) as $slot => $attachment_id) {
            $attachments['BILD ' . (int) $slot] = ['Bild-' . (int) $slot, (int) $attachment_id];
        }
        if (!empty($article['author_image_id'])) {
            $attachments['AUTORENBILD'] = ['Autorenbild', (int) $article['author_image_id']];
        }

        $pictures = [];
        foreach ($attachments as $placeholder => [$name, $attachment_id]) {
            $file = $this->print_image_path($attachment_id);
            if ($file !== '') {
                $pictures[$placeholder] = [
                    'file' => $file,
                    'link' => 'Links/' . $name . '.' . strtolower((string) pathinfo($file, PATHINFO_EXTENSION)),
                ];
            }
        }

        return $pictures;
    }

    /**
     * The best file of an attachment for print. WordPress scales large uploads down ("-scaled");
     * then the full-size original is used. A rotated copy ("-rotated") is kept, because its
     * original may still be lying on its side.
     */
    private function print_image_path(int $attachment_id): string
    {
        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
            return '';
        }

        $file = get_attached_file($attachment_id);
        if (!is_string($file) || $file === '' || !file_exists($file)) {
            return '';
        }

        if (preg_match('/-scaled\.[a-z0-9]+$/i', $file) === 1 && function_exists('wp_get_original_image_path')) {
            $original = wp_get_original_image_path($attachment_id);
            if (is_string($original) && $original !== '' && file_exists($original)) {
                return $original;
            }
        }

        return $file;
    }

    private function file_basename(string $title): string
    {
        $name = sanitize_file_name(remove_accents($title));
        $name = (string) preg_replace('/\.+/', '-', $name);

        return trim($name, '-') !== '' ? trim($name, '-') : 'artikel';
    }

    private function fail(string $message): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => 'aiw-import-wizard', 'aiw_error' => rawurlencode($message)],
            admin_url('admin.php')
        ));
        exit;
    }
}
