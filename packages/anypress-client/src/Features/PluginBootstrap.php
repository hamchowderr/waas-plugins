<?php

namespace AnyPressClient\Features;

class PluginBootstrap
{
    /** Option holding the store's plan roles (filled from the ANYPRESS_TENANT_ROLES constant on stores). */
    public const TENANT_ROLES = 'anypress_tenant_roles';
    public const TENANT_ROLES_CONSTANT = 'ANYPRESS_TENANT_ROLES';
    public const API_V1_NAMESPACE = 'anypress-client/v1';
    public const PLUGIN_NAME = 'anypress-client/anypress-client.php';
    public const TENANT_NO_ADMINISTRATOR_PLUGIN_CAPS = 'ANYPRESS_TENANT_NO_ADMINISTRATOR_PLUGIN_CAPS';
    public const REMOVED_ADMINISTRATOR_PLUGIN_CAPS = 'anypress_removed_administrator_plugin_caps';


    public static function init()
    {
        add_action('init', [__CLASS__, 'set_or_remove_admin_plugin_caps']);
        add_filter('plugin_action_links', [__CLASS__, 'show_plugins_managed_by_anypress_client'], PHP_INT_MAX, 2);
    }

    /** True on a store (the API sets ANYPRESS_IS_TENANT); false on version sites and local copies. */
    public static function is_store(): bool
    {
        return getenv('ANYPRESS_IS_TENANT') === 'true';
    }

    /** Whether the constant is defined. */
    public static function has_constant(string $anypress_name): bool
    {
        return self::constant_name($anypress_name) !== null;
    }

    /** The constant's value; null when it is not defined. */
    public static function constant(string $anypress_name)
    {
        $name = self::constant_name($anypress_name);
        return $name === null ? null : constant($name);
    }

    private static function constant_name(string $anypress_name): ?string
    {
        return defined($anypress_name) ? $anypress_name : null;
    }

    public static function set_or_remove_admin_plugin_caps() {
        $plugins_capabilities = ['activate_plugins', 'delete_plugins', 'install_plugins', 'update_plugins', 'edit_plugins', 'upload_plugins'];
        $caps_removed = get_option(self::REMOVED_ADMINISTRATOR_PLUGIN_CAPS, false);

        if (self::constant(self::TENANT_NO_ADMINISTRATOR_PLUGIN_CAPS) == 'true' && self::is_store()) {
            if (!$caps_removed) {
                $role = get_role('administrator');
                foreach ($plugins_capabilities as $capability) {
                    $role->remove_cap($capability);
                }

                update_option(self::REMOVED_ADMINISTRATOR_PLUGIN_CAPS, true);
            }

            return;
        }

        if ($caps_removed) {
            $role = get_role('administrator');
            foreach ($plugins_capabilities as $capability) {
                $role->add_cap($capability);
            }

            delete_option(self::REMOVED_ADMINISTRATOR_PLUGIN_CAPS);
        }
    }

    public static function show_plugins_managed_by_anypress_client($actions, $plugin_file) {
        // We're not running in a tenant, so show the activation links.
        if(!self::is_store()) {
            return $actions;
        }

        // Tenant roles have not been defined, so the AnyPress Client won't do anything.
        if(!self::has_constant(self::TENANT_ROLES_CONSTANT)) {
            return $actions;
        }

        // The AnyPress Client itself should always have the deactivate button available.
        if($plugin_file === ANYPRESS_CLIENT_BASENAME) {
            return $actions;
        }

        unset($actions['activate']);
        unset($actions['deactivate']);

        return array_merge($actions, [
            'anypress_active' => is_plugin_active($plugin_file) ? sprintf(/* translators: %s: the plugin's name */ __('Activated by %s', 'anypress-client'), \AnyPressClient\Core\Brand::plugin_name()) : sprintf(/* translators: %s: the plugin's name */ __('Deactivated by %s', 'anypress-client'), \AnyPressClient\Core\Brand::plugin_name()),
        ]);
    }
}
