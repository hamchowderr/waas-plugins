<?php

namespace AnyPressClient\Core;

use WP_Error;

/**
 * Turns on the Fluent plugin a site-moving switch copies into, when it is installed but off (a site moved in gets the
 * store template's plugins switched off). A plugin turned on in the middle of a request isn't fully started until the
 * next one, so the switch stops and asks to be run again.
 */
class FluentPlugin
{
    /**
     * Whether the switches may turn plugins on by themselves. AnyPress Move (scripts/build-move.sh) carries its own
     * copy of this class that never does: a wordpress.org plugin must not change other plugins' activation, so there
     * the switch's check asks the site owner to turn the plugin on instead.
     */
    public static function turns_on(): bool
    {
        return true;
    }

    /**
     * Null when there is nothing to do (the plugin is already running, or isn't installed, or couldn't be turned on:
     * the switch's own check then says what's wrong). Otherwise an error asking for the switch to be run again.
     *
     * @param string $file  The plugin's main file, as in active_plugins ("fluent-cart/fluent-cart.php").
     * @param string $name  Its name, for the message.
     * @param bool   $ready Whether the switch can already use it.
     */
    public static function turn_on(string $file, string $name, bool $ready): ?WP_Error
    {
        if ($ready) {
            return null;
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!array_key_exists($file, get_plugins()) || is_plugin_active($file)) {
            return null;
        }
        if (is_wp_error(activate_plugin($file))) {
            return null;
        }

        return new WP_Error(
            'anypress_plugin_turned_on',
            /* translators: %s: plugin name, such as FluentCart. */
            sprintf(__('%s was switched off, so it has been turned on. Run this again to carry on.', 'anypress-client'), $name),
            ['status' => 409, 'turned_on' => $file]
        );
    }

    /** Turns on a plugin that is installed but off, without asking for another run (FluentCart's Migrator add-on). */
    public static function turn_on_quietly(string $file): void
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (array_key_exists($file, get_plugins()) && !is_plugin_active($file)) {
            activate_plugin($file);
        }
    }
}
