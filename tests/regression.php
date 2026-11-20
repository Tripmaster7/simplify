<?php

// Standalone regression checks; no WordPress installation or database is required.
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);

$options = [];
$settings_errors = [];
$can_publish = true;
$preview_payload = [];
$deleted_previews = 0;
$registered_filters = [];
$registered_actions = [];
$test_current_user = null;
$test_page = null;
$no_cache_headers = 0;

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['registered_actions'][$hook] = $callback;
}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['registered_filters'][$hook] = ['callback' => $callback, 'accepted_args' => $accepted_args];
}
function __($text, $domain = '') { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function esc_js($text) { return addslashes($text); }
function esc_html__($text, $domain = '') { return esc_html($text); }
function esc_html_e($text, $domain = '') { echo esc_html($text); }
function wp_strip_all_tags($text) { return strip_tags($text); }
function wp_kses_post($text) { return $text; }
function do_blocks($text) { return $text; }
function sanitize_text_field($text) { return trim(strip_tags($text)); }
function wp_unslash($value) { return $value; }
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function add_settings_error($setting, $code, $message, $type = 'error') {
    $GLOBALS['settings_errors'][] = compact('setting', 'code', 'message', 'type');
}
function current_user_can($capability) { return $capability !== 'publish_posts' || $GLOBALS['can_publish']; }
function user_can($user, $capability) { return !empty($user->capabilities[$capability]); }
function wp_get_current_user() { return $GLOBALS['test_current_user'] ?? new WP_User(); }
function is_user_logged_in() { return wp_get_current_user()->ID > 0; }
function is_page() { return $GLOBALS['test_page'] instanceof WP_Post; }
function get_queried_object() { return $GLOBALS['test_page']; }
function has_shortcode($content, $tag) { return str_contains($content, '[' . $tag . ']'); }
function nocache_headers() { $GLOBALS['no_cache_headers']++; }
function get_current_user_id() { return 1; }
function get_transient($key) { return $GLOBALS['preview_payload']; }
function delete_transient($key) { $GLOBALS['deleted_previews']++; }
function check_admin_referer($action) {}
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function untrailingslashit($value) { return rtrim($value, '/\\'); }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_safe_redirect($url) { throw new Test_Redirect($url); }
function wp_nonce_field($action) {}
function get_post_status_object($status) { return (object) ['label' => $status]; }
function get_edit_post_link($id, $context = '') { return admin_url('post.php?post=' . $id); }
function selected($actual, $expected, $echo = true) {
    $value = $actual === $expected ? ' selected="selected"' : '';
    if ($echo) { echo $value; }
    return $value;
}
function submit_button($label, $type, $name, $wrap, $attributes = []) {
    echo '<input type="submit" name="' . esc_attr($name) . '" value="' . esc_attr($label) . '"';
    foreach ($attributes as $key => $value) {
        echo ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
    }
    echo ' />';
}

class WP_User {
    public int $ID = 1;
    public string $user_login = '01234';
    public array $roles = ['editor'];
    public array $capabilities = ['edit_posts' => true, 'upload_files' => true];
}
class WP_Post {
    public string $post_content = '';
}
class Test_Redirect extends RuntimeException {}

require __DIR__ . '/../article-import-wizard/includes/class-aiw-docx-parser.php';
require __DIR__ . '/../article-import-wizard/includes/class-aiw-settings.php';
require __DIR__ . '/../article-import-wizard/includes/class-aiw-image-mapper.php';
require __DIR__ . '/../article-import-wizard/includes/class-aiw-author-service.php';
require __DIR__ . '/../article-import-wizard/includes/class-aiw-wizard-controller.php';
require __DIR__ . '/../article-import-wizard/includes/class-aiw-simple-admin.php';

class Test_Author_Service extends AIW_Author_Service {
    public function find_matches_by_identifier(string $identifier): array {
        return [new WP_User()];
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function parse_document(array $paragraphs): array
{
    $path = tempnam(sys_get_temp_dir(), 'aiw-test-');
    try {
        $zip = new ZipArchive();
        expect($zip->open($path, ZipArchive::OVERWRITE) === true, 'Could not create DOCX fixture.');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . implode('', $paragraphs) . '</w:body></w:document>');
        $zip->close();
        $result = (new AIW_DOCX_Parser())->parse($path);
        expect($result['error'] === '', 'DOCX parsing failed: ' . $result['error']);
        return $result;
    } finally {
        unlink($path);
    }
}

function paragraph(string $text, string $properties = ''): string
{
    return '<w:p>' . $properties . '<w:r><w:t>' . esc_html($text) . '</w:t></w:r></w:p>';
}

function render_preview(string $scenario): string
{
    $GLOBALS['can_publish'] = $scenario !== 'no-publish';
    $default_post_status = str_ends_with($scenario, '-publish') ? 'publish' : 'draft';
    $preview_token = 'test-preview';
    $help_url = 'https://example.test/help';
    $preview_data = [
        'update_post_id' => str_starts_with($scenario, 'update-') ? 42 : 0,
        'update_post_status' => 'publish',
        'restriction_start' => '[restrict]',
        'restriction_end' => '[/restrict]',
        'membership_number' => '01234',
        'attributed_author_name' => 'Example Author',
        'category_names' => ['Test'],
        'post_title' => 'Example Article',
        'source_filename' => 'example.docx',
        'final_author_bio' => '',
        'working_content' => '<p>Example body</p>',
    ];
    ob_start();
    require __DIR__ . '/../article-import-wizard/templates/wizard-step-upload.php';
    return ob_get_clean();
}

if (($argv[1] ?? '') === '--render-preview') {
    echo render_preview($argv[2] ?? 'new-draft');
    exit;
}

$tests = [
    'authenticated membership login page reloads open the wizard' => function () {
        $admin = new AIW_Simple_Admin();
        $admin->register();
        $callback = $GLOBALS['registered_actions']['template_redirect'] ?? null;
        expect($callback !== null, 'Authenticated login-page redirect is not registered.');
        try {
            $GLOBALS['test_page'] = new WP_Post();
            $GLOBALS['test_page']->post_content = '<!-- wp:shortcode -->[login_form]<!-- /wp:shortcode -->';
            $GLOBALS['options'][AIW_Settings::OPTION_SIMPLE_ADMIN] = false;
            $_GET = [];
            foreach (['author', 'editor'] as $role) {
                $GLOBALS['test_current_user'] = new WP_User();
                $GLOBALS['test_current_user']->roles = [$role];
                $GLOBALS['no_cache_headers'] = 0;
                try {
                    $callback();
                    throw new RuntimeException('Authenticated login-page reload was not redirected.');
                } catch (Test_Redirect $redirect) {
                    expect($redirect->getMessage() === admin_url('admin.php?page=aiw-import-wizard'), 'Login-page redirect used the wrong destination.');
                    expect($GLOBALS['no_cache_headers'] === 1, 'User-specific redirect must not be cached.');
                }
            }
        } finally {
            $GLOBALS['test_current_user'] = null;
            $GLOBALS['test_page'] = null;
            unset($GLOBALS['options'][AIW_Settings::OPTION_SIMPLE_ADMIN]);
        }
    },
    'login-page redirect leaves other pages roles and recovery flows alone' => function () {
        $admin = new AIW_Simple_Admin();
        $admin->register();
        $callback = $GLOBALS['registered_actions']['template_redirect'] ?? null;
        expect($callback !== null, 'Authenticated login-page redirect is not registered.');
        try {
            $GLOBALS['test_page'] = new WP_Post();
            $GLOBALS['test_current_user'] = new WP_User();
            $_GET = [];
            $callback();
            $GLOBALS['test_page'] = null;
            $callback();
            $GLOBALS['test_page'] = new WP_Post();
            $GLOBALS['test_page']->post_content = '[login_form]';
            foreach ([['administrator'], ['administrator', 'author'], ['subscriber'], ['contributor']] as $roles) {
                $GLOBALS['test_current_user']->roles = $roles;
                $callback();
            }
            $GLOBALS['test_current_user']->roles = ['author'];
            $GLOBALS['test_current_user']->ID = 0;
            $callback();
            $GLOBALS['test_current_user']->ID = 1;
            $GLOBALS['options'][AIW_Settings::OPTION_WIZARD_ROLES] = ['editor'];
            $callback();
            unset($GLOBALS['options'][AIW_Settings::OPTION_WIZARD_ROLES]);
            $GLOBALS['test_current_user']->capabilities['upload_files'] = false;
            $callback();
            $GLOBALS['test_current_user']->capabilities['upload_files'] = true;
            foreach (['lostpassword', 'lostpassword_reset', 'lostpassword_checkemail'] as $action) {
                $_GET = ['rcp_action' => $action];
                $callback();
            }
        } finally {
            $_GET = [];
            $GLOBALS['test_current_user'] = null;
            $GLOBALS['test_page'] = null;
            unset($GLOBALS['options'][AIW_Settings::OPTION_WIZARD_ROLES]);
        }
    },
    'membership login form redirects authors and editors instead of returning to login' => function () {
        $admin = new AIW_Simple_Admin();
        $admin->register();
        $filter = $GLOBALS['registered_filters']['rcp_login_redirect_url'] ?? null;
        expect($filter !== null, 'Restrict Content Pro login redirect hook is missing.');
        expect($filter['accepted_args'] === 2, 'Membership login hook must receive the authenticated user.');
        foreach (['author', 'editor'] as $role) {
            $user = new WP_User();
            $user->roles = [$role];
            $GLOBALS['options'][AIW_Settings::OPTION_SIMPLE_ADMIN] = false;
            $result = ($filter['callback'])('https://example.test/member-login/', $user);
            expect($result === admin_url('admin.php?page=aiw-import-wizard'), 'Membership login returned to its own form.');
        }
        unset($GLOBALS['options'][AIW_Settings::OPTION_SIMPLE_ADMIN]);
    },
    'membership login retains redirects for ineligible users' => function () {
        $admin = new AIW_Simple_Admin();
        $admin->register();
        $filter = $GLOBALS['registered_filters']['rcp_login_redirect_url'] ?? null;
        expect($filter !== null, 'Restrict Content Pro login redirect hook is missing.');
        $original = 'https://example.test/member-login/';
        foreach ([['administrator'], ['administrator', 'editor'], ['subscriber'], ['contributor']] as $roles) {
            $user = new WP_User();
            $user->roles = $roles;
            expect(($filter['callback'])($original, $user) === $original, 'Membership redirect changed for an ineligible role.');
        }
        $user = new WP_User();
        $user->capabilities['manage_options'] = true;
        expect(($filter['callback'])($original, $user) === $original, 'Membership login redirected an administrator.');
        $user->capabilities['manage_options'] = false;
        $GLOBALS['options'][AIW_Settings::OPTION_WIZARD_ROLES] = ['author'];
        expect(($filter['callback'])($original, $user) === $original, 'Membership login bypassed wizard role access.');
        unset($GLOBALS['options'][AIW_Settings::OPTION_WIZARD_ROLES]);
        $user->capabilities['upload_files'] = false;
        expect(($filter['callback'])($original, $user) === $original, 'Membership login bypassed wizard capabilities.');
        expect(($filter['callback'])($original, null) === $original, 'Membership login changed a failed login redirect.');
    },
    'author and editor logins open the wizard independently of menu simplification' => function () {
        $admin = new AIW_Simple_Admin();
        foreach (['author', 'editor'] as $role) {
            $user = new WP_User();
            $user->roles = [$role];
            foreach ([true, false] as $simplified) {
                $GLOBALS['options'][AIW_Settings::OPTION_SIMPLE_ADMIN] = $simplified;
                foreach (['', admin_url(), untrailingslashit(admin_url()), admin_url('index.php')] as $requested) {
                    expect($admin->login_redirect(admin_url(), $requested, $user) === admin_url('admin.php?page=aiw-import-wizard'), 'Eligible login did not open the wizard.');
                }
                $deep_link = admin_url('post.php?post=42&action=edit');
                expect($admin->login_redirect($deep_link, $deep_link, $user) === $deep_link, 'Explicit article link was overridden.');
            }
        }
        unset($GLOBALS['options'][AIW_Settings::OPTION_SIMPLE_ADMIN]);
    },
    'login redirect excludes administrators other roles and failed logins' => function () {
        $admin = new AIW_Simple_Admin();
        $original = admin_url('profile.php');
        foreach ([['administrator'], ['administrator', 'editor'], ['subscriber'], ['contributor'], ['custom_editor']] as $roles) {
            $user = new WP_User();
            $user->roles = $roles;
            expect($admin->login_redirect($original, '', $user) === $original, 'Other role redirect was changed.');
        }
        $user = new WP_User();
        $user->capabilities['manage_options'] = true;
        expect($admin->login_redirect($original, '', $user) === $original, 'Administrative editor was redirected.');
        foreach ([null, new stdClass()] as $failed_user) {
            expect($admin->login_redirect($original, '', $failed_user) === $original, 'Failed login redirect was changed.');
        }
    },
    'login redirect respects wizard role and capability restrictions' => function () {
        $admin = new AIW_Simple_Admin();
        $user = new WP_User();
        $original = admin_url();
        $GLOBALS['options'][AIW_Settings::OPTION_WIZARD_ROLES] = ['author'];
        expect($admin->login_redirect($original, '', $user) === $original, 'Editor without wizard role access was redirected.');
        unset($GLOBALS['options'][AIW_Settings::OPTION_WIZARD_ROLES]);
        $user->capabilities['upload_files'] = false;
        expect($admin->login_redirect($original, '', $user) === $original, 'User without upload permission was redirected.');
        $user->ID = 0;
        expect($admin->login_redirect($original, '', $user) === $original, 'Anonymous user was redirected.');
    },
    'fallback title is removed only from its source paragraph' => function () {
        $result = parse_document([paragraph('Example title'), paragraph('Body text'), paragraph('Example title')]);
        expect($result['title'] === 'Example title', 'Wrong extracted title.');
        expect($result['content'] === "<p>Body text</p>\n<p>Example title</p>", 'Title was duplicated or later matching text was removed.');
    },
    'explicit titles preserve the first body paragraph' => function () {
        foreach ([
            [paragraph('[TITLE: Explicit title]'), paragraph('Opening body')],
            [paragraph('Explicit title', '<w:pPr><w:pStyle w:val="Title"/></w:pPr>'), paragraph('Opening body')],
            [paragraph('Opening body'), paragraph('[TITLE: Explicit title]')],
        ] as $paragraphs) {
            $result = parse_document($paragraphs);
            expect($result['title'] === 'Explicit title', 'Explicit title was lost.');
            expect($result['content'] === '<p>Opening body</p>', 'Explicit title consumed body text.');
        }
    },
    'list fallback does not remove a list' => function () {
        $list = '<w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr>';
        $result = parse_document([paragraph('First item', $list), paragraph('Second item', $list), paragraph('Closing body')]);
        expect(str_contains($result['content'], '<li>First item</li>'), 'First list item was removed.');
        expect(str_contains($result['content'], '<li>Second item</li>'), 'Second list item was removed.');
    },
    'malformed restriction settings are rejected without losing previous scope' => function () {
        $settings = new AIW_Settings();
        $valid = '[restrict subscription="2" message="Members only"]';
        $GLOBALS['options'][AIW_Settings::OPTION_RESTRICTION_START] = $valid;
        foreach ([
            '[restrict subscription=1 [message="Members only"]',
            '[restrict', '[restrict message="unfinished]', '[restrict /]',
            '[restrict]extra', '[restrict][/restrict]', '[[restrict]]',
        ] as $invalid) {
            expect(AIW_Settings::closing_shortcode_for($invalid) === '', 'Malformed opening shortcode accepted: ' . $invalid);
            $GLOBALS['settings_errors'] = [];
            expect($settings->sanitize_restriction_start($invalid) === $valid, 'Previous subscription scope was lost.');
            expect(count($GLOBALS['settings_errors']) === 1, 'No settings error was reported.');
        }
        expect($settings->sanitize_restriction_start($valid) === $valid, 'Valid shortcode attributes were changed.');
    },
    'closing shortcode must match the complete opening shortcode' => function () {
        $settings = new AIW_Settings();
        $GLOBALS['options'][AIW_Settings::OPTION_RESTRICTION_START] = '[custom_restrict level=2]';
        expect($settings->sanitize_restriction_end('[/restrict]') === '[/custom_restrict]', 'Mismatched closing shortcode accepted.');
        expect($settings->sanitize_restriction_end('') === '[/custom_restrict]', 'Missing closing shortcode was not derived.');
        expect($settings->sanitize_restriction_end('[/custom_restrict]') === '[/custom_restrict]', 'Valid closing shortcode changed.');
        $GLOBALS['options'][AIW_Settings::OPTION_RESTRICTION_START] = '[restrict [message="Members only"]';
        $GLOBALS['options'][AIW_Settings::OPTION_RESTRICTION_END] = '[/restrict]';
        $GLOBALS['settings_errors'] = [];
        expect($settings->sanitize_restriction_end('[/other]') === '[/restrict]', 'Invalid opening lost the previous closing setting.');
        expect(count($GLOBALS['settings_errors']) === 1, 'Invalid opening did not report a closing settings error.');
    },
    'unused pictures are reported separately from missing pictures' => function () {
        $mapper = new AIW_Image_Mapper();
        $images = [1 => ['url' => 'https://example.test/one.jpg'], 2 => ['url' => 'https://example.test/two.jpg']];
        $missing = $unused = [];
        $html = $mapper->replace_placeholders('[Bild 1: Caption] [Image 1] [Bild 3]', $images, $missing, $unused);
        expect($missing === [3], 'Missing image reporting changed.');
        expect($unused === [2], 'Unused picture was not reported.');
        expect(substr_count($html, '<img ') === 2, 'Repeated image markers did not render.');
        expect(str_contains($html, '<figcaption class="wp-element-caption">Caption</figcaption>'), 'Caption was lost.');
        $mapper->replace_placeholders('<p>No markers</p>', $images, $missing, $unused);
        expect($unused === [1, 2], 'Uploads without any markers were not reported.');
        $mapper->replace_placeholders('', $images, $missing, $unused);
        expect($unused === [1, 2], 'Empty content did not report unused pictures.');
    },
    'malformed preview settings stop before document or upload processing' => function () {
        foreach ([
            ['[restrict [message="Members only"]', '[/restrict]'],
            ['[restrict subscription="2"]', '[/other]'],
        ] as [$start, $end]) {
            $_POST = [
                'aiw_membership_number' => '01234',
                'aiw_restriction_start' => $start,
                'aiw_restriction_end' => $end,
            ];
            $controller = (new ReflectionClass(AIW_Wizard_Controller::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(AIW_Wizard_Controller::class, 'author_service'))->setValue($controller, new Test_Author_Service());
            try {
                $controller->handle_preview_import();
                throw new RuntimeException('Malformed preview settings were accepted.');
            } catch (Test_Redirect $redirect) {
                parse_str(parse_url($redirect->getMessage(), PHP_URL_QUERY), $args);
                expect(str_contains(rawurldecode($args['aiw_error'] ?? ''), 'restriction shortcodes'), 'Preview reached document/upload processing before validating restrictions.');
            }
        }
    },
    'old malformed previews cannot be confirmed' => function () {
        $GLOBALS['preview_payload'] = [
            'restriction_start' => '[restrict [message="Members only"]',
            'restriction_end' => '[/restrict]',
        ];
        $GLOBALS['deleted_previews'] = 0;
        $_POST = ['aiw_preview_token' => 'old-preview'];
        $controller = (new ReflectionClass(AIW_Wizard_Controller::class))->newInstanceWithoutConstructor();
        try {
            $controller->handle_create_draft();
            throw new RuntimeException('Malformed preview was accepted.');
        } catch (Test_Redirect $redirect) {
            parse_str(parse_url($redirect->getMessage(), PHP_URL_QUERY), $args);
            expect(str_contains(rawurldecode($args['aiw_error'] ?? ''), 'restriction shortcodes'), 'Wrong error for malformed preview.');
            expect($GLOBALS['deleted_previews'] === 0, 'Malformed preview was consumed before validation.');
        }
    },
    'configured publication defaults and non-publisher behavior are preserved' => function () {
        foreach (['new-draft', 'new-publish', 'update-publish', 'no-publish'] as $scenario) {
            $html = render_preview($scenario);
            if ($scenario === 'no-publish') {
                expect(!str_contains($html, '<select id="aiw_import_status"'), 'Non-publisher can select publication.');
                expect(str_contains($html, 'name="aiw_import_status" value="draft"'), 'Non-publisher does not submit draft.');
            } else {
                $expected = str_ends_with($scenario, '-publish') ? 'publish' : 'draft';
                expect(str_contains($html, 'value="' . $expected . '" selected="selected"'), 'Configured default was not selected.');
            }
        }
    },
];

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: $name\n";
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL: $name: {$error->getMessage()}\n");
    }
}
exit($failures > 0 ? 1 : 0);
