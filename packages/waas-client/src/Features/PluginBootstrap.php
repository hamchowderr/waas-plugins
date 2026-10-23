<?php

namespace WaaSClient\Features;

class PluginBootstrap
{
    /** Option holding the store's plan roles (filled from the WAAS_TENANT_ROLES constant on stores). */
    const TENANT_ROLES = 'waas_tenant_roles';
    const TENANT_ROLES_CONSTANT = 'WAAS_TENANT_ROLES';
    const API_V1_NAMESPACE = 'waas-client/v1';
    const PLUGIN_NAME = 'waas-client/index.php';
    const PLUGIN_VERSION = '2.0.0';
    const TENANT_NO_ADMINISTRATOR_PLUGIN_CAPS = 'WAAS_TENANT_NO_ADMINISTRATOR_PLUGIN_CAPS';
    const REMOVED_ADMINISTRATOR_PLUGIN_CAPS = 'waas_removed_administrator_plugin_caps';

    /**
     * Stores built before WaaS Client 2.4.0 get their environment flag and wp-config constants under the old
     * platform's prefix, and saved two options under it; this client reads both spellings and moves the options.
     */
    const LEGACY_PREFIX = 'WPCS_';
    const LEGACY_OPTIONS = [
        'WPCS_TENANT_ROLES' => self::TENANT_ROLES,
        'WPCS_REMOVED_ADMINISTRATOR_PLUGIN_CAPS' => self::REMOVED_ADMINISTRATOR_PLUGIN_CAPS,
    ];

    public static function init()
    {
        self::move_legacy_options();
        add_action('init', [__CLASS__, 'set_or_remove_admin_plugin_caps']);
        add_filter('plugin_action_links', [__CLASS__, 'show_plugins_managed_by_waas_client'], PHP_INT_MAX, 2);
    }

    /** True on a store (the API sets WAAS_IS_TENANT); false on version sites and local copies. */
    public static function is_store(): bool
    {
        return getenv('WAAS_IS_TENANT') === 'true' || getenv(self::LEGACY_PREFIX . 'IS_TENANT') === 'true';
    }

    /** Whether the WAAS_ constant, or its old-prefix spelling, is defined. */
    public static function has_constant(string $waas_name): bool
    {
        return self::constant_name($waas_name) !== null;
    }

    /** The constant's value, the WAAS_ spelling first; null when neither is defined. */
    public static function constant(string $waas_name)
    {
        $name = self::constant_name($waas_name);
        return $name === null ? null : constant($name);
    }

    private static function constant_name(string $waas_name): ?string
    {
        if (defined($waas_name)) {
            return $waas_name;
        }
        $legacy = self::LEGACY_PREFIX . substr($waas_name, strlen('WAAS_'));
        return defined($legacy) ? $legacy : null;
    }

    public static function move_legacy_options(): void
    {
        $missing = new \stdClass();
        foreach (self::LEGACY_OPTIONS as $old => $new) {
            $value = get_option($old, $missing);
            if ($value === $missing) {
                continue;
            }
            if (get_option($new, $missing) === $missing) {
                add_option($new, $value);
            }
            delete_option($old);
        }
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

    public static function show_plugins_managed_by_waas_client($actions, $plugin_file) {
        // We're not running in a tenant, so show the activation links.
        if(!self::is_store()) {
            return $actions;
        }

        // Tenant roles have not been defined, so the WaaS Client won't do anything.
        if(!self::has_constant(self::TENANT_ROLES_CONSTANT)) {
            return $actions;
        }

        // The WaaS Client itself should always have the deactivate button available.
        if($plugin_file === WAAS_CLIENT_BASENAME) {
            return $actions;
        }

        unset($actions['activate']);
        unset($actions['deactivate']);

        return array_merge($actions, [
            'waas_active' => is_plugin_active( $plugin_file ) ? __('Activated by the WaaS Client', WAAS_CLIENT_TEXTDOMAIN) : __('Deactivated by the WaaS Client', WAAS_CLIENT_TEXTDOMAIN),
        ]);
    }
}
