<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIW_Settings
{
    public const OPTION_RESTRICTION_START = 'aiw_restriction_shortcode_start';
    public const OPTION_RESTRICTION_END = 'aiw_restriction_shortcode_end';
    public const OPTION_INLINE_IMAGE_SLOTS = 'aiw_inline_image_slots';
    public const OPTION_NOTIFY_EMAIL = 'aiw_notify_email';
    public const OPTION_LINK_CHECK_TIMEOUT = 'aiw_link_check_timeout_ms';
    public const OPTION_REPLACE_BROKEN_LINKS_MODE = 'aiw_replace_broken_links_mode';

    public const DEFAULT_RESTRICTION_START = '[restrict]';
    public const DEFAULT_RESTRICTION_END = '[/restrict]';

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
        register_setting('aiw_settings_group', self::OPTION_RESTRICTION_START, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_restriction_start'],
            'default' => self::DEFAULT_RESTRICTION_START
        ]);

        register_setting('aiw_settings_group', self::OPTION_RESTRICTION_END, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_restriction_end'],
            'default' => self::DEFAULT_RESTRICTION_END
        ]);

        register_setting('aiw_settings_group', self::OPTION_INLINE_IMAGE_SLOTS, [
            'type' => 'integer',
            'sanitize_callback' => [$this, 'sanitize_inline_image_slots'],
            'default' => 3
        ]);

        register_setting('aiw_settings_group', self::OPTION_NOTIFY_EMAIL, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => get_option('admin_email', '')
        ]);

        register_setting('aiw_settings_group', self::OPTION_LINK_CHECK_TIMEOUT, [
            'type' => 'integer',
            'sanitize_callback' => [$this, 'sanitize_timeout'],
            'default' => 6000
        ]);

        register_setting('aiw_settings_group', self::OPTION_REPLACE_BROKEN_LINKS_MODE, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_replace_mode'],
            'default' => 'replace'
        ]);

        add_settings_section(
            'aiw_main_section',
            __('Import Defaults', 'article-import-wizard'),
            '__return_false',
            'aiw-settings'
        );

        add_settings_field(
            self::OPTION_RESTRICTION_START,
            __('Restriction Start Shortcode', 'article-import-wizard'),
            [$this, 'render_text_field'],
            'aiw-settings',
            'aiw_main_section',
            [
                'option' => self::OPTION_RESTRICTION_START,
                'required' => true,
                'description' => __('Required. Inserted at the [RESTRICT] marker, or automatically after the 2nd paragraph (earlier if the first paragraph reaches 1,000 characters).', 'article-import-wizard'),
            ]
        );

        add_settings_field(
            self::OPTION_RESTRICTION_END,
            __('Restriction End Shortcode', 'article-import-wizard'),
            [$this, 'render_text_field'],
            'aiw-settings',
            'aiw_main_section',
            [
                'option' => self::OPTION_RESTRICTION_END,
                'required' => true,
                'description' => __('Required. Always inserted after the last paragraph, before the author bio, so the bio stays public.', 'article-import-wizard'),
            ]
        );

        add_settings_field(
            self::OPTION_INLINE_IMAGE_SLOTS,
            __('Default Inline Image Slots', 'article-import-wizard'),
            [$this, 'render_number_field'],
            'aiw-settings',
            'aiw_main_section',
            ['option' => self::OPTION_INLINE_IMAGE_SLOTS, 'min' => 0, 'max' => 20]
        );

        add_settings_field(
            self::OPTION_NOTIFY_EMAIL,
            __('Notification Email', 'article-import-wizard'),
            [$this, 'render_email_field'],
            'aiw-settings',
            'aiw_main_section',
            ['option' => self::OPTION_NOTIFY_EMAIL]
        );

        add_settings_field(
            self::OPTION_LINK_CHECK_TIMEOUT,
            __('Link Check Timeout (ms)', 'article-import-wizard'),
            [$this, 'render_number_field'],
            'aiw-settings',
            'aiw_main_section',
            ['option' => self::OPTION_LINK_CHECK_TIMEOUT, 'min' => 1000, 'max' => 20000]
        );

        add_settings_field(
            self::OPTION_REPLACE_BROKEN_LINKS_MODE,
            __('Broken Link Handling', 'article-import-wizard'),
            [$this, 'render_mode_field'],
            'aiw-settings',
            'aiw_main_section'
        );
    }

    public function render_text_field(array $args): void
    {
        $option = (string) ($args['option'] ?? '');
        $value = (string) get_option($option, '');
        $required = !empty($args['required']) ? ' required' : '';
        echo '<input type="text" class="regular-text" name="' . esc_attr($option) . '" value="' . esc_attr($value) . '"' . $required . ' />';

        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html((string) $args['description']) . '</p>';
        }
    }

    public function render_email_field(array $args): void
    {
        $option = (string) ($args['option'] ?? '');
        $value = (string) get_option($option, '');
        echo '<input type="email" class="regular-text" name="' . esc_attr($option) . '" value="' . esc_attr($value) . '" />';
    }

    public function render_number_field(array $args): void
    {
        $option = (string) ($args['option'] ?? '');
        $value = (int) get_option($option, 0);
        $min = isset($args['min']) ? (int) $args['min'] : 0;
        $max = isset($args['max']) ? (int) $args['max'] : 100;

        echo '<input type="number" name="' . esc_attr($option) . '" value="' . esc_attr((string) $value) . '" min="' . esc_attr((string) $min) . '" max="' . esc_attr((string) $max) . '" />';
    }

    public function render_mode_field(): void
    {
        $value = (string) get_option(self::OPTION_REPLACE_BROKEN_LINKS_MODE, 'replace');
        ?>
        <select name="<?php echo esc_attr(self::OPTION_REPLACE_BROKEN_LINKS_MODE); ?>">
            <option value="replace" <?php selected($value, 'replace'); ?>><?php esc_html_e('Replace with CHECK LINK', 'article-import-wizard'); ?></option>
            <option value="flag_only" <?php selected($value, 'flag_only'); ?>><?php esc_html_e('Flag only (keep original)', 'article-import-wizard'); ?></option>
        </select>
        <?php
    }

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

    public function sanitize_inline_image_slots($value): int
    {
        $value = (int) $value;
        if ($value < 0) {
            return 0;
        }
        if ($value > 20) {
            return 20;
        }
        return $value;
    }

    public function sanitize_timeout($value): int
    {
        $value = (int) $value;
        if ($value < 1000) {
            return 1000;
        }
        if ($value > 20000) {
            return 20000;
        }
        return $value;
    }

    public function sanitize_replace_mode($value): string
    {
        $value = (string) $value;
        return in_array($value, ['replace', 'flag_only'], true) ? $value : 'replace';
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
}
