<?php

namespace AnyPressClient\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The name this plugin shows on the website. AnyPress by default; on a reseller's websites the AnyPress API saves the
 * reseller's brand in the anypress_brand_name option, and the plugin goes by that name instead (in the plugins list, its
 * menu, its notices and the website's MCP server), so the reseller's clients see only the reseller.
 */
final class Brand
{
    public const OPTION = 'anypress_brand_name';
    public const DEFAULT = 'AnyPress';

    /** The reseller's brand, or '' on a website that has none. */
    public static function reseller(): string
    {
        $name = get_option(self::OPTION, '');

        return is_string($name) ? trim(wp_strip_all_tags($name)) : '';
    }

    public static function name(): string
    {
        $reseller = self::reseller();

        return $reseller !== '' ? $reseller : self::DEFAULT;
    }

    /** "AnyPress Client", or "<brand> Client" on a reseller's website. */
    public static function plugin_name(): string
    {
        /* translators: %s: the brand name, such as AnyPress */
        return sprintf(__('%s Client', 'anypress-client'), self::name());
    }

    /** The AnyPress ribbon for the admin menu, as a data URI (WordPress shows it as an image). */
    public static function menu_icon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="14 12 80 76">'
            . '<defs><linearGradient id="l" x1="8" y1="80" x2="66" y2="40" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#FFB338"/><stop offset=".55" stop-color="#FF7A2A"/><stop offset="1" stop-color="#F2413A"/></linearGradient>'
            . '<linearGradient id="r" x1="56" y1="14" x2="86" y2="86" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#FF5A33"/><stop offset=".5" stop-color="#EE2F5E"/><stop offset="1" stop-color="#D21FA4"/></linearGradient></defs>'
            . '<path d="M48.5 17.5Q51 14 55 15L56 27 50.5 48H62.3L66.2 59 48 64 44 77.5H20Q16 77.5 18.5 75Q28 68 32 58Z" fill="url(#l)"/>'
            . '<path d="M51 15H62Q65.5 15 66.8 18.5L90 80Q91.5 84 87.5 84H77.5Q75 84 74 81.5L55 27Z" fill="url(#r)"/></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public static function init(): void
    {
        add_filter('all_plugins', [self::class, 'plugins_list']);
    }

    /**
     * The plugins list reads the plugin's header, so on a reseller's website its name, author and description are
     * swapped there for the reseller's; the header itself (and the update channel) stays the same.
     *
     * @param array<string, array<string, string>> $plugins
     * @return array<string, array<string, string>>
     */
    public static function plugins_list($plugins)
    {
        if (!is_array($plugins) || !defined('ANYPRESS_CLIENT_BASENAME') || !isset($plugins[ANYPRESS_CLIENT_BASENAME])) {
            return $plugins;
        }
        $reseller = self::reseller();
        if ($reseller === '') {
            return $plugins;
        }
        $plugin = $plugins[ANYPRESS_CLIENT_BASENAME];
        $plugin['Name'] = self::plugin_name();
        $plugin['Title'] = $plugin['Name'];
        $plugin['Author'] = $reseller;
        $plugin['AuthorName'] = $reseller;
        $plugin['AuthorURI'] = '';
        $plugin['PluginURI'] = '';
        /* translators: %s: the brand name */
        $plugin['Description'] = sprintf(__('Runs this website for %s: one-click login, the plugins of its plan, and editing the site\'s design and content with AI apps.', 'anypress-client'), $reseller);
        $plugins[ANYPRESS_CLIENT_BASENAME] = $plugin;

        return $plugins;
    }
}
