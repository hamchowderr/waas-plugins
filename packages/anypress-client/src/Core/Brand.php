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
        $plugin['Name'] = $plugin['Title'] = self::plugin_name();
        $plugin['Author'] = $plugin['AuthorName'] = $reseller;
        $plugin['AuthorURI'] = '';
        $plugin['PluginURI'] = '';
        /* translators: %s: the brand name */
        $plugin['Description'] = sprintf(__('Runs this website for %s: one-click login, the plugins of its plan, and editing the site\'s design and content with AI apps.', 'anypress-client'), $reseller);
        $plugins[ANYPRESS_CLIENT_BASENAME] = $plugin;

        return $plugins;
    }
}
