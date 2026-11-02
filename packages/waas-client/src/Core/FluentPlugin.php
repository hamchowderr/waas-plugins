<?php

namespace WaaSClient\Core;

use WP_Error;

/**
 * Turns on the Fluent plugin a site-moving switch copies into, when it is installed but off (a site moved in gets the
 * store template's plugins switched off). A plugin turned on in the middle of a request isn't fully started until the
 * next one, so the switch stops and asks to be run again.
 */
class FluentPlugin
{
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
            'waas_plugin_turned_on',
            /* translators: %s: plugin name, such as FluentCart. */
            sprintf(__('%s was switched off, so it has been turned on. Run this again to carry on.', 'waas-client'), $name),
            ['status' => 409, 'turned_on' => $file]
        );
    }
}
