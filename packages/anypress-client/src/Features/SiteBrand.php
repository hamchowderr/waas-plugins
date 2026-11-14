<?php

namespace AnyPressClient\Features;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The site's own name and logo where WordPress shows its own: the login page shows the site's logo (Site Logo, else
 * Site Icon, else its name as text) linked to its home page instead of the WordPress logo linked to wordpress.org, and
 * the login and wp-admin tab titles end with the site's name instead of "WordPress", and pages and feeds leave out
 * WordPress's generator tag, which names the WordPress version.
 */
final class SiteBrand
{
    public static function init(): void
    {
        add_filter('login_headerurl', [self::class, 'login_url']);
        add_filter('login_headertext', [self::class, 'site_name']);
        add_action('login_enqueue_scripts', [self::class, 'login_logo']);
        add_filter('login_title', [self::class, 'title']);
        add_filter('admin_title', [self::class, 'title']);
        add_action('login_footer', [self::class, 'powered_by']);
        add_filter('the_generator', '__return_empty_string');
    }

    /**
     * A small "Powered by AnyPress" under the login form on AnyPress's own stores. Reseller stores show nothing, so their
     * clients see only the reseller; the template website (not a store) shows nothing either.
     */
    public static function powered_by(): void
    {
        if (!PluginBootstrap::is_store() || \AnyPressClient\Core\Brand::reseller() !== '') {
            return;
        }
        printf(
            '<p class="anypress-powered-by" style="text-align:center;margin:24px 0 0;font-size:13px;color:#50575e">'
            . '<a href="%1$s" style="color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:6px">'
            . '<img src="%2$s" width="18" height="18" alt="" style="display:block"> %3$s <strong>%4$s</strong></a></p>',
            esc_url('https://anypress.io'),
            esc_attr(\AnyPressClient\Core\Brand::menu_icon()),
            esc_html__('Powered by', 'anypress-client'),
            esc_html__('AnyPress', 'anypress-client')
        );
    }

    public static function login_url(): string
    {
        return home_url('/');
    }

    public static function site_name(): string
    {
        return get_bloginfo('name', 'display');
    }

    /** "Log In ‹ Site — WordPress" becomes "Log In ‹ Site", in the login page's and every admin page's tab. */
    public static function title($title): string
    {
        return preg_replace('/\s*(&#8212;|&mdash;|—)\s*WordPress$/u', '', (string) $title);
    }

    /** The site's Site Logo (block themes keep it in the site_logo option), else its Site Icon, at least 128px. */
    public static function logo_url(): string
    {
        $logo = (int) get_option('site_logo') ?: (int) get_theme_mod('custom_logo');
        $url = $logo ? wp_get_attachment_image_url($logo, 'medium') : false;

        return $url ?: (string) get_site_icon_url(192);
    }

    public static function login_logo(): void
    {
        $url = self::logo_url();
        if ($url !== '') {
            $css = '.login h1 a{background-image:url("' . esc_url($url) . '");background-size:contain;background-position:center;'
                . 'width:100%;max-width:240px;height:84px}';
        } else {
            // No logo: the site's name as text, in place of the WordPress logo.
            $css = '.login h1 a{background:none;width:auto;height:auto;text-indent:0;overflow:visible;font-size:24px;'
                . 'line-height:1.3;font-weight:600;color:#1d2327;text-decoration:none}';
        }
        wp_register_style('anypress-client-login-brand', false, [], ANYPRESS_CLIENT_VERSION);
        wp_enqueue_style('anypress-client-login-brand');
        wp_add_inline_style('anypress-client-login-brand', $css);
    }
}
