<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reduces the WordPress admin to what non-technical editors need: Posts, Media and the import
 * wizard. Administrators (manage_options) always keep the full admin. Controlled by the
 * "Simplified admin menu" setting. Login redirects for authors and editors are independent of
 * this setting.
 *
 * This only tidies up the interface. What users may do is still decided by their WordPress role.
 */
class AIW_Simple_Admin
{
    /** Top-level menu slugs that stay visible. */
    private const VISIBLE_MENUS = ['edit.php', 'upload.php', 'aiw-import-wizard'];

    /** Admin bar entries that stay visible: the site link and the account menu (profile, log out). */
    private const VISIBLE_ADMIN_BAR_NODES = ['site-name', 'view-site', 'my-account', 'user-actions', 'user-info', 'edit-profile', 'logout', 'top-secondary', 'menu-toggle'];

    public function register(): void
    {
        // Late, so menus added by other plugins are removed too.
        add_action('admin_menu', [$this, 'trim_menu'], PHP_INT_MAX);
        add_action('admin_bar_menu', [$this, 'trim_admin_bar'], PHP_INT_MAX);
        add_action('load-index.php', [$this, 'redirect_dashboard']);
        add_action('template_redirect', [$this, 'redirect_login_page']);
        add_filter('login_redirect', [$this, 'login_redirect'], 10, 3);
        add_filter('rcp_login_redirect_url', [$this, 'rcp_login_redirect'], 10, 2);
    }

    public static function applies_to(?WP_User $user = null): bool
    {
        $user = $user ?? wp_get_current_user();

        return $user instanceof WP_User
            && $user->ID > 0
            && !user_can($user, 'manage_options')
            // Subscribers and similar roles never see Posts anyway; leave their screens alone.
            && user_can($user, 'edit_posts')
            && AIW_Settings::simple_admin_enabled();
    }

    public function trim_menu(): void
    {
        if (!self::applies_to()) {
            return;
        }

        global $menu;
        foreach ((array) $menu as $item) {
            $slug = isset($item[2]) ? (string) $item[2] : '';
            if ($slug !== '' && !in_array($slug, self::VISIBLE_MENUS, true)) {
                remove_menu_page($slug);
            }
        }
    }

    public function trim_admin_bar(WP_Admin_Bar $admin_bar): void
    {
        if (!self::applies_to()) {
            return;
        }

        foreach (array_keys((array) $admin_bar->get_nodes()) as $id) {
            if (!in_array((string) $id, self::VISIBLE_ADMIN_BAR_NODES, true)) {
                $admin_bar->remove_node((string) $id);
            }
        }
    }

    /**
     * The dashboard is hidden, so whoever lands on it goes to the wizard, or to the post list if
     * the wizard is not available to them.
     */
    public function redirect_dashboard(): void
    {
        if (!self::applies_to()) {
            return;
        }

        wp_safe_redirect(self::start_url(wp_get_current_user()));
        exit;
    }

    /**
     * @param string           $redirect_to
     * @param string           $requested
     * @param WP_User|WP_Error $user
     */
    public function login_redirect($redirect_to, $requested, $user)
    {
        if (!self::should_redirect_login($user)) {
            return $redirect_to;
        }

        // Keep explicit deep links (for example from a notification email) other than the dashboard.
        $dashboard = admin_url();
        if ($requested !== '' && untrailingslashit((string) $requested) !== untrailingslashit($dashboard) && (string) $requested !== admin_url('index.php')) {
            return $redirect_to;
        }

        return self::start_url($user);
    }

    public function rcp_login_redirect($redirect_to, $user)
    {
        // RCP bypasses WordPress's login_redirect and defaults to returning to the login form.
        return $this->login_redirect($redirect_to, '', $user);
    }

    public function redirect_login_page(): void
    {
        if (
            !is_page()
            || !is_user_logged_in()
            || isset($_GET['rcp_action'])
            || isset($_GET['action'])
            || !self::should_redirect_login(wp_get_current_user())
        ) {
            return;
        }

        $page = get_queried_object();
        if (!$page instanceof WP_Post || !has_shortcode($page->post_content, 'login_form')) {
            return;
        }

        // Frontend login handlers can reload the form instead of using either redirect filter.
        nocache_headers();
        wp_safe_redirect(self::start_url(wp_get_current_user()));
        exit;
    }

    private static function should_redirect_login($user): bool
    {
        return $user instanceof WP_User
            && !user_can($user, 'manage_options')
            && !in_array('administrator', (array) $user->roles, true)
            && array_intersect(['author', 'editor'], (array) $user->roles) !== []
            && AIW_Settings::user_can_use_wizard($user);
    }

    private static function start_url(WP_User $user): string
    {
        return AIW_Settings::user_can_use_wizard($user)
            ? admin_url('admin.php?page=aiw-import-wizard')
            : admin_url('edit.php');
    }
}
