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
        register_setting('aiw_settings_group', self::OPTION_RESTRICTION_START, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '[restrict]'
        ]);

        register_setting('aiw_settings_group', self::OPTION_RESTRICTION_END, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '[/restrict]'
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
            ['option' => self::OPTION_RESTRICTION_START]
        );

        add_settings_field(
            self::OPTION_RESTRICTION_END,
            __('Restriction End Shortcode', 'article-import-wizard'),
            [$this, 'render_text_field'],
            'aiw-settings',
            'aiw_main_section',
            ['option' => self::OPTION_RESTRICTION_END]
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
        echo '<input type="text" class="regular-text" name="' . esc_attr($option) . '" value="' . esc_attr($value) . '" />';
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
