<?php

namespace AnyPressHost\Features;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the storefront's account names private from visitors who aren't signed in: the REST users list
 * (/wp/v2/users), the users part of the sitemap and "?author=<id>" lookups, which all give away the login names
 * people sign in with. Signed-in people, and the block editor's author list, work as before. It also leaves out
 * WordPress's generator tag (in the page head and feeds), which names the WordPress version.
 */
final class PrivateUsers
{
    public static function init(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'rest'], 10, 3);
        add_filter('wp_sitemaps_add_provider', [self::class, 'sitemap'], 10, 2);
        add_action('template_redirect', [self::class, 'author_lookup'], 1);
        add_filter('the_generator', '__return_empty_string');
    }

    /**
     * @param mixed $result
     * @param mixed $server
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public static function rest($result, $server, $request)
    {
        if ($result !== null || is_user_logged_in()) {
            return $result;
        }
        if (preg_match('#^/wp/v2/users(/|$)#', (string) $request->get_route()) !== 1) {
            return $result;
        }
        return new \WP_Error('rest_user_cannot_view', __('Sorry, you are not allowed to list users.', 'anypress-host'), ['status' => rest_authorization_required_code()]);
    }

    /**
     * @param mixed $provider
     * @return mixed
     */
    public static function sitemap($provider, string $name)
    {
        return $name === 'users' ? false : $provider;
    }

    /** "?author=1" would redirect to /author/<login>/; visitors go to the home page instead. */
    public static function author_lookup(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of a public query string.
        if (is_user_logged_in() || !isset($_GET['author']) || is_admin()) {
            return;
        }
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
}
