<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin settings (Settings > Article Import Wizard). The page and every option in it require
 * manage_options, so only administrators can change them.
 */
class AIW_Settings
{
    public const OPTION_RESTRICTION_START = 'aiw_restriction_shortcode_start';
    public const OPTION_RESTRICTION_END = 'aiw_restriction_shortcode_end';
    public const OPTION_RESTRICTION_CHARACTERS = 'aiw_auto_restriction_characters';
    public const OPTION_RESTRICTION_PARAGRAPHS = 'aiw_auto_restriction_paragraphs';
    public const OPTION_DEFAULT_POST_STATUS = 'aiw_default_post_status';
    public const OPTION_DEFAULT_CATEGORY_IDS = 'aiw_default_category_ids';
    public const OPTION_INLINE_IMAGE_SLOTS = 'aiw_inline_image_slots';
    public const OPTION_LINK_CHECK_TIMEOUT = 'aiw_link_check_timeout_ms';
    public const OPTION_REPLACE_BROKEN_LINKS_MODE = 'aiw_replace_broken_links_mode';
    public const OPTION_DEFAULT_PUBLISHER = 'aiw_default_publisher_user_id';
    public const OPTION_AMBIGUOUS_AUTHOR = 'aiw_ambiguous_author_mode';
    public const OPTION_MEMBERSHIP_PATTERN = 'aiw_membership_number_pattern';
    public const OPTION_PROFILE_UPDATE = 'aiw_author_profile_update';
    public const OPTION_SHOW_BIO_BOX = 'aiw_show_bio_box';
    public const OPTION_WIZARD_ROLES = 'aiw_wizard_roles';
    public const OPTION_SIMPLE_ADMIN = 'aiw_simple_admin_menu';
    public const OPTION_NOTIFY_ENABLED = 'aiw_notify_enabled';
    public const OPTION_NOTIFY_EMAIL = 'aiw_notify_email';
    public const OPTION_BACK_LINK_PARENT_IDS = 'aiw_back_link_parent_ids';

    public const DEFAULT_RESTRICTION_START = '[restrict]';
    public const DEFAULT_RESTRICTION_END = '[/restrict]';

    public const DEFAULT_RESTRICTION_CHARACTERS = 1000;
    public const MIN_RESTRICTION_CHARACTERS = 100;
    public const MAX_RESTRICTION_CHARACTERS = 10000;

    public const DEFAULT_RESTRICTION_PARAGRAPHS = 2;
    public const MIN_RESTRICTION_PARAGRAPHS = 1;
    public const MAX_RESTRICTION_PARAGRAPHS = 10;

    /** Regular expression (without delimiters) a whole membership number must match. */
    public const DEFAULT_MEMBERSHIP_PATTERN = '0\d{4}';

    /** Roles that can be chosen as the default publisher. */
    public const DEFAULT_PUBLISHER_ROLES = ['editor', 'author'];

    private const POST_STATUS_CHOICES = ['draft', 'publish'];
    private const AMBIGUOUS_AUTHOR_CHOICES = ['error', 'fallback'];
    private const PROFILE_UPDATE_CHOICES = ['always', 'if_empty', 'never'];
    private const BROKEN_LINK_CHOICES = ['replace', 'flag_only'];

    /**
     * The closing shortcode for a start shortcode: "[restrict level=2]" -> "[/restrict]".
     * Returns '' when no shortcode tag can be read from the start shortcode.
     */
    public static function closing_shortcode_for(string $start_shortcode): string
    {
        if (preg_match('/^\[\s*([A-Za-z0-9_-]+)/', trim($start_shortcode), $matches) !== 1) {
            return '';
        }

        return '[/' . $matches[1] . ']';
    }

    public function register(): void
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_settings_page']);
    }

    public function register_settings_page(): void
    {
        add_options_page(
            __('Article Import Wizard Settings', 'article-import-wizard'),
            __('Article Import Wizard', 'article-import-wizard'),
            'manage_options',
            'aiw-settings',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Article Import Wizard Settings', 'article-import-wizard'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('aiw_settings_group');
                do_settings_sections('aiw-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function register_settings(): void
    {
        // Both shortcodes are required: every imported article is restricted, and the restriction
        // must be closed before the public author bio. They are registered in this order so the end
        // sanitizer can read the just-saved start shortcode.
        $this->register_option(self::OPTION_RESTRICTION_START, 'string', [$this, 'sanitize_restriction_start'], self::DEFAULT_RESTRICTION_START);
        $this->register_option(self::OPTION_RESTRICTION_END, 'string', [$this, 'sanitize_restriction_end'], self::DEFAULT_RESTRICTION_END);
        $this->register_option(self::OPTION_RESTRICTION_CHARACTERS, 'integer', [$this, 'sanitize_restriction_characters'], self::DEFAULT_RESTRICTION_CHARACTERS);
        $this->register_option(self::OPTION_RESTRICTION_PARAGRAPHS, 'integer', [$this, 'sanitize_restriction_paragraphs'], self::DEFAULT_RESTRICTION_PARAGRAPHS);
        $this->register_option(self::OPTION_DEFAULT_POST_STATUS, 'string', [$this, 'sanitize_post_status'], 'draft');
        $this->register_option(self::OPTION_DEFAULT_CATEGORY_IDS, 'array', [$this, 'sanitize_category_ids'], []);
        $this->register_option(self::OPTION_INLINE_IMAGE_SLOTS, 'integer', [$this, 'sanitize_inline_image_slots'], 3);
        $this->register_option(self::OPTION_LINK_CHECK_TIMEOUT, 'integer', [$this, 'sanitize_timeout'], 6000);
        $this->register_option(self::OPTION_REPLACE_BROKEN_LINKS_MODE, 'string', [$this, 'sanitize_replace_mode'], 'replace');
        $this->register_option(self::OPTION_DEFAULT_PUBLISHER, 'integer', [$this, 'sanitize_default_publisher'], 0);
        $this->register_option(self::OPTION_AMBIGUOUS_AUTHOR, 'string', [$this, 'sanitize_ambiguous_author'], 'error');
        $this->register_option(self::OPTION_MEMBERSHIP_PATTERN, 'string', [$this, 'sanitize_membership_pattern'], self::DEFAULT_MEMBERSHIP_PATTERN);
        $this->register_option(self::OPTION_PROFILE_UPDATE, 'string', [$this, 'sanitize_profile_update'], 'always');
        $this->register_option(self::OPTION_SHOW_BIO_BOX, 'boolean', 'rest_sanitize_boolean', true);
        $this->register_option(self::OPTION_WIZARD_ROLES, 'array', [$this, 'sanitize_wizard_roles'], []);
        $this->register_option(self::OPTION_SIMPLE_ADMIN, 'boolean', 'rest_sanitize_boolean', true);
        $this->register_option(self::OPTION_NOTIFY_ENABLED, 'boolean', 'rest_sanitize_boolean', true);
        $this->register_option(self::OPTION_NOTIFY_EMAIL, 'string', 'sanitize_email', get_option('admin_email', ''));
        $this->register_option(self::OPTION_BACK_LINK_PARENT_IDS, 'array', [$this, 'sanitize_back_link_parent_ids'], []);

        // Restricted content.
        $this->add_section('aiw_restriction_section', __('Restricted Content', 'article-import-wizard'));

        $this->add_field(self::OPTION_RESTRICTION_START, __('Restriction Start Shortcode', 'article-import-wizard'), 'render_text_field', 'aiw_restriction_section', [
            'required' => true,
            'description' => __('Required. Inserted at the [RESTRICT] marker, or automatically after the number of paragraphs or characters set below.', 'article-import-wizard'),
        ]);
        $this->add_field(self::OPTION_RESTRICTION_END, __('Restriction End Shortcode', 'article-import-wizard'), 'render_text_field', 'aiw_restriction_section', [
            'required' => true,
            'description' => __('Required. Always inserted after the last paragraph, before the author bio, so the bio stays public.', 'article-import-wizard'),
        ]);
        $this->add_field(self::OPTION_RESTRICTION_PARAGRAPHS, __('Paragraphs Before Restriction', 'article-import-wizard'), 'render_number_field', 'aiw_restriction_section', [
            'min' => self::MIN_RESTRICTION_PARAGRAPHS,
            'max' => self::MAX_RESTRICTION_PARAGRAPHS,
            'default' => self::DEFAULT_RESTRICTION_PARAGRAPHS,
            'description' => __('Only used without a [RESTRICT] marker: the restricted part starts after this many text paragraphs at the latest. Headings, images and lists are not counted.', 'article-import-wizard'),
        ]);
        $this->add_field(self::OPTION_RESTRICTION_CHARACTERS, __('Characters Before Restriction', 'article-import-wizard'), 'render_number_field', 'aiw_restriction_section', [
            'min' => self::MIN_RESTRICTION_CHARACTERS,
            'max' => self::MAX_RESTRICTION_CHARACTERS,
            'default' => self::DEFAULT_RESTRICTION_CHARACTERS,
            'description' => __('Only used without a [RESTRICT] marker: the restricted part starts earlier, after the paragraph in which the article text reaches this many characters (spaces included).', 'article-import-wizard'),
        ]);

        // Import defaults.
        $this->add_section('aiw_main_section', __('Import Defaults', 'article-import-wizard'));

        $this->add_field(self::OPTION_DEFAULT_POST_STATUS, __('Default Status After Review', 'article-import-wizard'), 'render_select_field', 'aiw_main_section', [
            'default' => 'draft',
            'choices' => [
                'draft' => __('Save as draft', 'article-import-wizard'),
                'publish' => __('Publish immediately', 'article-import-wizard'),
            ],
            'description' => __('Preselected in the wizard for users who may publish. Users without publishing permission always create drafts.', 'article-import-wizard'),
        ]);
        add_settings_field(
            self::OPTION_DEFAULT_CATEGORY_IDS,
            __('Default Categories', 'article-import-wizard'),
            [$this, 'render_default_categories_field'],
            'aiw-settings',
            'aiw_main_section'
        );
        $this->add_field(self::OPTION_INLINE_IMAGE_SLOTS, __('Default Inline Image Slots', 'article-import-wizard'), 'render_number_field', 'aiw_main_section', [
            'min' => 0,
            'max' => 20,
            'default' => 3,
        ]);
        $this->add_field(self::OPTION_LINK_CHECK_TIMEOUT, __('Link Check Timeout (ms)', 'article-import-wizard'), 'render_number_field', 'aiw_main_section', [
            'min' => 1000,
            'max' => 20000,
            'default' => 6000,
        ]);
        $this->add_field(self::OPTION_REPLACE_BROKEN_LINKS_MODE, __('Broken Link Handling', 'article-import-wizard'), 'render_select_field', 'aiw_main_section', [
            'default' => 'replace',
            'choices' => [
                'replace' => __('Replace with CHECK LINK', 'article-import-wizard'),
                'flag_only' => __('Flag only (keep original)', 'article-import-wizard'),
            ],
        ]);

        // Authors.
        $this->add_section('aiw_author_section', __('Authors', 'article-import-wizard'));

        add_settings_field(
            self::OPTION_DEFAULT_PUBLISHER,
            __('Default Publisher', 'article-import-wizard'),
            [$this, 'render_default_publisher_field'],
            'aiw-settings',
            'aiw_author_section'
        );
        $this->add_field(self::OPTION_AMBIGUOUS_AUTHOR, __('Several Matching Authors', 'article-import-wizard'), 'render_select_field', 'aiw_author_section', [
            'default' => 'error',
            'choices' => [
                'error' => __('Show an error and ask for a more specific name', 'article-import-wizard'),
                'fallback' => __('Use the default publisher', 'article-import-wizard'),
            ],
        ]);
        $this->add_field(self::OPTION_MEMBERSHIP_PATTERN, __('Membership Number Format', 'article-import-wizard'), 'render_text_field', 'aiw_author_section', [
            'default' => self::DEFAULT_MEMBERSHIP_PATTERN,
            'description' => __('Regular expression the whole membership number must match. An entry in this format is looked up as a username only, never as a name. Default: 0\d{4} (a 0 followed by four digits).', 'article-import-wizard'),
        ]);
        $this->add_field(self::OPTION_PROFILE_UPDATE, __('Update Author Profile', 'article-import-wizard'), 'render_select_field', 'aiw_author_section', [
            'default' => 'always',
            'choices' => [
                'always' => __('Always save the new bio and image', 'article-import-wizard'),
                'if_empty' => __('Only if the profile has none yet', 'article-import-wizard'),
                'never' => __('Never change the profile', 'article-import-wizard'),
            ],
            'description' => __('Controls whether a bio or author image from an import is saved to the author\'s profile for reuse. The article itself always uses the uploaded bio and image.', 'article-import-wizard'),
        ]);
        $this->add_field(self::OPTION_SHOW_BIO_BOX, __('Author Bio Box', 'article-import-wizard'), 'render_checkbox_field', 'aiw_author_section', [
            'default' => true,
            'label' => __('Add the author bio box at the end of imported articles', 'article-import-wizard'),
        ]);

        // Access.
        $this->add_section('aiw_access_section', __('Access', 'article-import-wizard'));

        add_settings_field(
            self::OPTION_WIZARD_ROLES,
            __('Who May Use the Wizard', 'article-import-wizard'),
            [$this, 'render_wizard_roles_field'],
            'aiw-settings',
            'aiw_access_section'
        );
        $this->add_field(self::OPTION_SIMPLE_ADMIN, __('Simplified Admin Menu', 'article-import-wizard'), 'render_checkbox_field', 'aiw_access_section', [
            'default' => true,
            'label' => __('Show only Posts, Media and Article Import to everyone except administrators', 'article-import-wizard'),
            'description' => __('Also hides the dashboard and most of the top toolbar, and opens the import wizard after login. Administrators always see the full menu. This only tidies up the screen; what users may do is still decided by their role.', 'article-import-wizard'),
        ]);

        // Notifications.
        $this->add_section('aiw_notify_section', __('Notifications', 'article-import-wizard'));

        $this->add_field(self::OPTION_NOTIFY_ENABLED, __('Import Notifications', 'article-import-wizard'), 'render_checkbox_field', 'aiw_notify_section', [
            'default' => true,
            'label' => __('Send an email after each completed import', 'article-import-wizard'),
        ]);
        $this->add_field(self::OPTION_NOTIFY_EMAIL, __('Notification Email', 'article-import-wizard'), 'render_email_field', 'aiw_notify_section');

        // Page navigation.
        $this->add_section('aiw_navigation_section', __('Page Navigation', 'article-import-wizard'));

        add_settings_field(
            self::OPTION_BACK_LINK_PARENT_IDS,
            __('Back Link on Sub-Pages', 'article-import-wizard'),
            [$this, 'render_back_link_parents_field'],
            'aiw-settings',
            'aiw_navigation_section'
        );
    }

    private function register_option(string $option, string $type, callable $sanitize, $default): void
    {
        register_setting('aiw_settings_group', $option, [
            'type' => $type,
            'sanitize_callback' => $sanitize,
            'default' => $default,
        ]);
    }

    private function add_section(string $id, string $title): void
    {
        add_settings_section($id, $title, '__return_false', 'aiw-settings');
    }

    private function add_field(string $option, string $title, string $renderer, string $section, array $args = []): void
    {
        $args['option'] = $option;
        $args['label_for'] = $renderer === 'render_checkbox_field' ? null : $option;
        add_settings_field($option, $title, [$this, $renderer], 'aiw-settings', $section, array_filter($args, static function ($value): bool {
            return $value !== null;
        }));
    }

    // ---------------------------------------------------------------------------------------------
    // Field renderers
    // ---------------------------------------------------------------------------------------------

    private function render_description(array $args): void
    {
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html((string) $args['description']) . '</p>';
        }
    }

    public function render_text_field(array $args): void
    {
        $option = (string) ($args['option'] ?? '');
        $value = (string) get_option($option, (string) ($args['default'] ?? ''));
        $required = !empty($args['required']) ? ' required' : '';
        echo '<input type="text" class="regular-text" id="' . esc_attr($option) . '" name="' . esc_attr($option) . '" value="' . esc_attr($value) . '"' . $required . ' />';
        $this->render_description($args);
    }

    public function render_email_field(array $args): void
    {
        $option = (string) ($args['option'] ?? '');
        $value = (string) get_option($option, '');
        echo '<input type="email" class="regular-text" id="' . esc_attr($option) . '" name="' . esc_attr($option) . '" value="' . esc_attr($value) . '" />';
        $this->render_description($args);
    }

    public function render_number_field(array $args): void
    {
        $option = (string) ($args['option'] ?? '');
        $value = self::get_option_int($option, (int) ($args['default'] ?? 0));
        $min = isset($args['min']) ? (int) $args['min'] : 0;
        $max = isset($args['max']) ? (int) $args['max'] : 100;

        echo '<input type="number" id="' . esc_attr($option) . '" name="' . esc_attr($option) . '" value="' . esc_attr((string) $value) . '" min="' . esc_attr((string) $min) . '" max="' . esc_attr((string) $max) . '" />';
        $this->render_description($args);
    }

    public function render_select_field(array $args): void
    {
        $option = (string) ($args['option'] ?? '');
        $value = self::get_option_string($option, (string) ($args['default'] ?? ''));

        echo '<select id="' . esc_attr($option) . '" name="' . esc_attr($option) . '">';
        foreach ((array) ($args['choices'] ?? []) as $choice => $label) {
            echo '<option value="' . esc_attr((string) $choice) . '"' . selected($value, (string) $choice, false) . '>' . esc_html((string) $label) . '</option>';
        }
        echo '</select>';
        $this->render_description($args);
    }

    public function render_checkbox_field(array $args): void
    {
        $option = (string) ($args['option'] ?? '');
        $checked = self::get_option_bool($option, !empty($args['default']));

        echo '<label><input type="checkbox" name="' . esc_attr($option) . '" value="1"' . checked($checked, true, false) . ' /> '
            . esc_html((string) ($args['label'] ?? '')) . '</label>';
        $this->render_description($args);
    }

    public function render_default_categories_field(): void
    {
        $selected = self::get_default_category_ids();
        $categories = get_categories(['hide_empty' => false, 'taxonomy' => 'category']);

        if (empty($categories)) {
            echo '<p class="description">' . esc_html__('No categories found.', 'article-import-wizard') . '</p>';
            return;
        }

        echo '<fieldset style="max-height:12em;overflow:auto;">';
        foreach ($categories as $category) {
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_DEFAULT_CATEGORY_IDS) . '[]" value="' . esc_attr((string) $category->term_id) . '"' . checked(in_array((int) $category->term_id, $selected, true), true, false) . ' /> '
                . esc_html((string) $category->name) . '</label><br />';
        }
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__('Preselected in the wizard. Editors can still change the selection for each import.', 'article-import-wizard') . '</p>';
    }

    public function render_default_publisher_field(): void
    {
        $selected = self::get_default_publisher_id();
        $users = get_users([
            'role__in' => self::DEFAULT_PUBLISHER_ROLES,
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => ['ID', 'display_name', 'user_login'],
        ]);

        echo '<select id="' . esc_attr(self::OPTION_DEFAULT_PUBLISHER) . '" name="' . esc_attr(self::OPTION_DEFAULT_PUBLISHER) . '">';
        echo '<option value="0"' . selected($selected, 0, false) . '>' . esc_html__('Logged-in user', 'article-import-wizard') . '</option>';
        foreach ($users as $user) {
            echo '<option value="' . esc_attr((string) $user->ID) . '"' . selected($selected, (int) $user->ID, false) . '>'
                . esc_html($user->display_name . ' (' . $user->user_login . ')') . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Used as the public author when no author is entered or no matching author is found. Lists all editors and authors.', 'article-import-wizard') . '</p>';
    }

    public function render_wizard_roles_field(): void
    {
        $selected = self::get_wizard_roles();

        echo '<fieldset>';
        foreach (self::get_selectable_wizard_roles() as $role => $name) {
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_WIZARD_ROLES) . '[]" value="' . esc_attr($role) . '"' . checked(in_array($role, $selected, true), true, false) . ' /> '
                . esc_html(translate_user_role($name)) . '</label><br />';
        }
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__('Administrators always have access. If no role is selected, every role that may write posts and upload files can use the wizard.', 'article-import-wizard') . '</p>';
    }

    public function render_back_link_parents_field(): void
    {
        $selected = self::get_back_link_parent_ids();
        $candidate_ids = array_unique(array_merge($this->get_pages_with_sub_pages(), $selected));

        if (empty($candidate_ids)) {
            echo '<p class="description">' . esc_html__('No pages with sub-pages found.', 'article-import-wizard') . '</p>';
            return;
        }

        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'any',
            'post__in' => $candidate_ids,
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        echo '<fieldset>';
        foreach ($pages as $page) {
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_BACK_LINK_PARENT_IDS) . '[]" value="' . esc_attr((string) $page->ID) . '"' . checked(in_array((int) $page->ID, $selected, true), true, false) . ' /> '
                . esc_html(get_the_title($page) !== '' ? get_the_title($page) : '#' . $page->ID) . '</label><br />';
        }
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__('Sub-pages of the selected pages show a link back to them at the top and bottom, for example the chapters of a guide linking back to its table of contents.', 'article-import-wizard') . '</p>';
    }

    /**
     * IDs of pages that have at least one sub-page (in any status except trash).
     *
     * @return int[]
     */
    private function get_pages_with_sub_pages(): array
    {
        $parents = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'post_parent__not_in' => [0],
            'fields' => 'id=>parent',
            'no_found_rows' => true,
        ]);

        return array_values(array_unique(array_filter(array_map('intval', $parents))));
    }

    // ---------------------------------------------------------------------------------------------
    // Sanitizers
    // ---------------------------------------------------------------------------------------------

    public function sanitize_restriction_start($value): string
    {
        $value = trim(sanitize_text_field((string) $value));
        if (self::closing_shortcode_for($value) !== '') {
            return $value;
        }

        add_settings_error(
            self::OPTION_RESTRICTION_START,
            'aiw_restriction_start_invalid',
            __('The restriction start shortcode is required and must be a shortcode such as [restrict]. The default was restored.', 'article-import-wizard')
        );

        return self::DEFAULT_RESTRICTION_START;
    }

    public function sanitize_restriction_end($value): string
    {
        $value = trim(sanitize_text_field((string) $value));
        if ($value !== '') {
            return $value;
        }

        $start = self::get_option_string(self::OPTION_RESTRICTION_START, self::DEFAULT_RESTRICTION_START);
        $derived = self::closing_shortcode_for($start);

        add_settings_error(
            self::OPTION_RESTRICTION_END,
            'aiw_restriction_end_derived',
            sprintf(
                /* translators: %s: closing shortcode, e.g. [/restrict] */
                __('The restriction end shortcode is required. It was set to %s to match the start shortcode.', 'article-import-wizard'),
                $derived !== '' ? $derived : self::DEFAULT_RESTRICTION_END
            ),
            'warning'
        );

        return $derived !== '' ? $derived : self::DEFAULT_RESTRICTION_END;
    }

    public function sanitize_restriction_characters($value): int
    {
        return self::clamp((int) $value, self::MIN_RESTRICTION_CHARACTERS, self::MAX_RESTRICTION_CHARACTERS);
    }

    public function sanitize_restriction_paragraphs($value): int
    {
        return self::clamp((int) $value, self::MIN_RESTRICTION_PARAGRAPHS, self::MAX_RESTRICTION_PARAGRAPHS);
    }

    public function sanitize_post_status($value): string
    {
        return self::choice((string) $value, self::POST_STATUS_CHOICES, 'draft');
    }

    public function sanitize_category_ids($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value), static function (int $id): bool {
            return $id > 0 && term_exists($id, 'category');
        })));
    }

    public function sanitize_inline_image_slots($value): int
    {
        return self::clamp((int) $value, 0, 20);
    }

    public function sanitize_timeout($value): int
    {
        return self::clamp((int) $value, 1000, 20000);
    }

    public function sanitize_replace_mode($value): string
    {
        return self::choice((string) $value, self::BROKEN_LINK_CHOICES, 'replace');
    }

    public function sanitize_default_publisher($value): int
    {
        $user_id = (int) $value;
        return self::is_valid_publisher($user_id) ? $user_id : 0;
    }

    public function sanitize_ambiguous_author($value): string
    {
        return self::choice((string) $value, self::AMBIGUOUS_AUTHOR_CHOICES, 'error');
    }

    public function sanitize_membership_pattern($value): string
    {
        // Not sanitize_text_field() or wp_unslash(): options.php has already unslashed the value,
        // and both would mangle regex syntax such as \d, < or %.
        $value = trim((string) $value);
        if ($value === '') {
            return self::DEFAULT_MEMBERSHIP_PATTERN;
        }

        if (self::compile_membership_pattern($value) === null) {
            add_settings_error(
                self::OPTION_MEMBERSHIP_PATTERN,
                'aiw_membership_pattern_invalid',
                __('The membership number format is not a valid regular expression. The previous format was kept.', 'article-import-wizard')
            );
            return self::get_membership_pattern();
        }

        return $value;
    }

    public function sanitize_profile_update($value): string
    {
        return self::choice((string) $value, self::PROFILE_UPDATE_CHOICES, 'always');
    }

    public function sanitize_wizard_roles($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $allowed = array_keys(self::get_selectable_wizard_roles());
        return array_values(array_intersect($allowed, array_map('sanitize_key', $value)));
    }

    public function sanitize_back_link_parent_ids($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = array_unique(array_filter(array_map('intval', $value), static function (int $id): bool {
            return $id > 0 && get_post_type($id) === 'page';
        }));

        return array_values($ids);
    }

    // ---------------------------------------------------------------------------------------------
    // Getters used by the rest of the plugin
    // ---------------------------------------------------------------------------------------------

    public static function get_restriction_characters(): int
    {
        return self::clamp(
            self::get_option_int(self::OPTION_RESTRICTION_CHARACTERS, self::DEFAULT_RESTRICTION_CHARACTERS),
            self::MIN_RESTRICTION_CHARACTERS,
            self::MAX_RESTRICTION_CHARACTERS
        );
    }

    public static function get_restriction_paragraphs(): int
    {
        return self::clamp(
            self::get_option_int(self::OPTION_RESTRICTION_PARAGRAPHS, self::DEFAULT_RESTRICTION_PARAGRAPHS),
            self::MIN_RESTRICTION_PARAGRAPHS,
            self::MAX_RESTRICTION_PARAGRAPHS
        );
    }

    public static function get_default_post_status(): string
    {
        return self::choice(self::get_option_string(self::OPTION_DEFAULT_POST_STATUS, 'draft'), self::POST_STATUS_CHOICES, 'draft');
    }

    /**
     * @return int[]
     */
    public static function get_default_category_ids(): array
    {
        $value = get_option(self::OPTION_DEFAULT_CATEGORY_IDS, []);
        return is_array($value) ? array_values(array_filter(array_map('intval', $value))) : [];
    }

    /**
     * The configured default publisher's user ID, or 0 when none is set or the user is no
     * longer an editor or author.
     */
    public static function get_default_publisher_id(): int
    {
        $user_id = self::get_option_int(self::OPTION_DEFAULT_PUBLISHER, 0);
        return self::is_valid_publisher($user_id) ? $user_id : 0;
    }

    public static function use_fallback_for_ambiguous_author(): bool
    {
        return self::get_option_string(self::OPTION_AMBIGUOUS_AUTHOR, 'error') === 'fallback';
    }

    public static function get_membership_pattern(): string
    {
        $pattern = self::get_option_string(self::OPTION_MEMBERSHIP_PATTERN, self::DEFAULT_MEMBERSHIP_PATTERN);
        return self::compile_membership_pattern($pattern) !== null ? $pattern : self::DEFAULT_MEMBERSHIP_PATTERN;
    }

    public static function is_membership_number(string $value): bool
    {
        $regex = self::compile_membership_pattern(self::get_membership_pattern());
        return $regex !== null && preg_match($regex, $value) === 1;
    }

    /**
     * Whether an import may save a new bio or author image to a profile that currently holds
     * $current (empty string or 0 when the profile has none).
     */
    public static function may_update_author_profile($current): bool
    {
        $mode = self::choice(self::get_option_string(self::OPTION_PROFILE_UPDATE, 'always'), self::PROFILE_UPDATE_CHOICES, 'always');
        if ($mode === 'never') {
            return false;
        }

        return $mode === 'always' || empty($current);
    }

    public static function show_bio_box(): bool
    {
        return self::get_option_bool(self::OPTION_SHOW_BIO_BOX, true);
    }

    public static function simple_admin_enabled(): bool
    {
        return self::get_option_bool(self::OPTION_SIMPLE_ADMIN, true);
    }

    public static function notifications_enabled(): bool
    {
        return self::get_option_bool(self::OPTION_NOTIFY_ENABLED, true);
    }

    /**
     * @return string[]
     */
    public static function get_wizard_roles(): array
    {
        $value = get_option(self::OPTION_WIZARD_ROLES, []);
        return is_array($value) ? array_values(array_filter(array_map('strval', $value))) : [];
    }

    /**
     * The wizard needs edit_posts and upload_files. Administrators always pass; other users also
     * need one of the configured roles, unless none is configured.
     */
    public static function user_can_use_wizard(?WP_User $user = null): bool
    {
        $user = $user ?? wp_get_current_user();
        if (!$user instanceof WP_User || $user->ID <= 0) {
            return false;
        }

        if (!user_can($user, 'edit_posts') || !user_can($user, 'upload_files')) {
            return false;
        }

        $roles = self::get_wizard_roles();
        if (empty($roles) || user_can($user, 'manage_options')) {
            return true;
        }

        return array_intersect($roles, (array) $user->roles) !== [];
    }

    /**
     * @return int[]
     */
    public static function get_back_link_parent_ids(): array
    {
        $value = get_option(self::OPTION_BACK_LINK_PARENT_IDS, []);
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $value), static function (int $id): bool {
            return $id > 0;
        }));
    }

    public static function get_option_string(string $option, string $default): string
    {
        $value = get_option($option, $default);
        return is_string($value) ? $value : $default;
    }

    public static function get_option_int(string $option, int $default): int
    {
        $value = get_option($option, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function get_option_bool(string $option, bool $default): bool
    {
        $value = get_option($option, $default);
        return is_bool($value) ? $value : (bool) $value;
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    /**
     * Roles other than administrator that have the capabilities the wizard needs.
     *
     * @return array<string,string> role slug => role name
     */
    private static function get_selectable_wizard_roles(): array
    {
        $roles = [];
        foreach (wp_roles()->roles as $slug => $role) {
            $caps = (array) ($role['capabilities'] ?? []);
            if ($slug !== 'administrator' && !empty($caps['edit_posts']) && !empty($caps['upload_files'])) {
                $roles[(string) $slug] = (string) $role['name'];
            }
        }

        return $roles;
    }

    private static function is_valid_publisher(int $user_id): bool
    {
        if ($user_id <= 0) {
            return false;
        }

        $user = get_userdata($user_id);
        return $user instanceof WP_User
            && array_intersect(self::DEFAULT_PUBLISHER_ROLES, (array) $user->roles) !== [];
    }

    /**
     * The full, anchored regex for a membership pattern, or null if the pattern does not compile.
     */
    private static function compile_membership_pattern(string $pattern): ?string
    {
        $regex = '~^(?:' . str_replace('~', '\~', $pattern) . ')$~u';
        return @preg_match($regex, '') === false ? null : $regex;
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private static function choice(string $value, array $choices, string $default): string
    {
        return in_array($value, $choices, true) ? $value : $default;
    }
}
