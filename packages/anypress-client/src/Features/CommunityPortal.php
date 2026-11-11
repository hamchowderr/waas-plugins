<?php

namespace AnyPressClient\Features;

/**
 * Two FluentCommunity set-ups that owners otherwise do with code snippets.
 *
 * - The community as the home page: a "Community" choice under Settings > Reading. When it is on, the community opens
 *   at the site's own address instead of /portal, through FluentCommunity's FLUENT_COMMUNITY_PORTAL_SLUG constant (set
 *   to empty), which owners can't add to wp-config.php themselves. Off by default.
 * - Where members land after signing in: FluentAuth's Login Redirects (its own settings screen) send people to a page
 *   by role, but only from WordPress's login page. FluentCommunity's own sign-in sends everyone to the community feed.
 *   When a member signs in there with no particular page asked for, the same FluentAuth rules decide where they land.
 *
 * Runs on every request (admin-ajax and REST too), since FluentCommunity builds its links and answers its sign-in form
 * there. The work is one autoloaded option read, plus a filter that only runs when someone signs in.
 */
class CommunityPortal
{
    public const OPTION = 'anypress_community_home';
    public const CONSTANT = 'FLUENT_COMMUNITY_PORTAL_SLUG';
    private const HELPER = 'FluentCommunity\App\Services\Helper';

    public function __construct()
    {
        if (self::is_home() && !defined(self::CONSTANT)) {
            define(self::CONSTANT, '');
        }

        add_filter('fluent_community/auth/after_login_redirect_url', [$this, 'landing_page'], 20, 2);
        add_action('admin_init', [$this, 'add_setting']);
        add_action('update_option_' . self::OPTION, [self::class, 'rebuild_links'], 10, 0);
        add_action('add_option_' . self::OPTION, [self::class, 'rebuild_links'], 10, 0);
    }

    /** Whether the owner chose to show the community on the home page. */
    public static function is_home(): bool
    {
        return get_option(self::OPTION) === 'yes';
    }

    /**
     * FluentAuth's sign-in rule for this member, when FluentCommunity is about to send them to the feed only because
     * no page was asked for. Any other page (a post, a course the member was on) is kept.
     */
    public function landing_page($url, $user)
    {
        if (!$user instanceof \WP_User || !is_string($url) || !class_exists(self::HELPER)) {
            return $url;
        }

        $feed = call_user_func([self::HELPER, 'baseUrl']);
        if (untrailingslashit($url) !== untrailingslashit((string) $feed)) {
            return $url;
        }

        return self::ruled_page($user, $url);
    }

    /**
     * The page WordPress's own login_redirect filter gives this user for a sign-in that would open wp-admin, which is
     * when FluentAuth applies its Login Redirects (by role, or its default). The feed when no rule sends them elsewhere.
     */
    public static function ruled_page(\WP_User $user, string $feed): string
    {
        $admin = admin_url();
        $ruled = apply_filters('login_redirect', $admin, $admin, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter, answered by FluentAuth.

        if (!is_string($ruled) || $ruled === '' || untrailingslashit($ruled) === untrailingslashit($admin)) {
            return $feed;
        }

        return wp_validate_redirect($ruled, $feed);
    }

    /** The "Community" choice under Settings > Reading, on sites with FluentCommunity. */
    public function add_setting(): void
    {
        if (!class_exists(self::HELPER)) {
            return;
        }

        register_setting('reading', self::OPTION, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => 'no',
        ]);
        add_settings_field(self::OPTION, __('Community', 'anypress-client'), [$this, 'render_setting'], 'reading', 'default', ['label_for' => self::OPTION]);
    }

    public static function sanitize($value): string
    {
        return $value === 'yes' ? 'yes' : 'no';
    }

    public function render_setting(): void
    {
        $fixed = defined(self::CONSTANT) && !self::is_home();
        printf(
            '<input type="hidden" name="%1$s" value="no"><label><input type="checkbox" id="%1$s" name="%1$s" value="yes" %2$s %3$s> %4$s</label>',
            esc_attr(self::OPTION),
            checked(self::is_home(), true, false),
            disabled($fixed, true, false),
            esc_html__('Show the community on the home page', 'anypress-client')
        );
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %s: the site's address */
            __('The community opens at %s, in place of the home page, instead of at its own address. Your other pages keep their addresses.', 'anypress-client'),
            untrailingslashit(home_url())
        )) . '</p>';
        if ($fixed) {
            echo '<p class="description">' . esc_html__('The community\'s address is set in wp-config.php on this site.', 'anypress-client') . '</p>';
        }
    }

    /** WordPress rebuilds its links on the next page load, when FluentCommunity has its new address. */
    public static function rebuild_links(): void
    {
        delete_option('rewrite_rules');
    }
}
