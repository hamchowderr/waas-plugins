<?php

namespace WaaSClient\Features;

class RolesManager
{
    public function __construct()
    {
        add_action('init', [$this, 'activate_enabled_plugins']);

        if (defined(PluginBootstrap::TENANT_ROLES_CONSTANT)) {
            add_filter('option_' . PluginBootstrap::TENANT_ROLES, [$this, 'get_roles_from_constant']);
            add_filter('default_option_' . PluginBootstrap::TENANT_ROLES, [$this, 'get_roles_from_constant']);
        }
    }

    public function activate_enabled_plugins(): void
    {
        if (getenv('WPCS_IS_TENANT') !== 'true') {
            // This is not a WaaS store. Either we're in a version or some local environment
            return;
        }
        if (!file_exists(AdminRolesSettings::ROLES_FILE_PATH)) {
            // This is a WaaS store but the Plugin is not yet setup or the Version is not deployed
            add_action('admin_notices', [$this, 'waas_no_roles_json_found_admin_notice']);
            return;
        }

        $user_roles = get_option(PluginBootstrap::TENANT_ROLES, []);
        $roles_plugins = json_decode(file_get_contents(AdminRolesSettings::ROLES_FILE_PATH), true);

        if (empty($user_roles)) {
            // Someone created a tenant without a role. Either they are testing, made a mistake or are not familiar with the plugins.
            // Probably best to just notify them and leave it for now.
            add_action('admin_notices', $this->get_waas_no_role_defined_admin_notice($roles_plugins));
            return;
        }

        $enabled_plugins = [];
        foreach ($user_roles as $user_role) {
            if (!isset($roles_plugins[$user_role])) continue;
            $enabled_plugins = array_merge($enabled_plugins, $roles_plugins[$user_role]['plugins']);
        }

        $all_plugins = array_keys(get_plugins());
        $enabled_plugins = array_unique($enabled_plugins);
        $disabled_plugins = array_diff($all_plugins, $enabled_plugins);

        $plugins_requiring_enabling = array_values(array_filter($enabled_plugins, fn ($plugin) => !is_plugin_active($plugin)));
        activate_plugins($plugins_requiring_enabling);

        $disabled_plugins = array_filter($disabled_plugins, fn ($item) => $item !== PluginBootstrap::PLUGIN_NAME && is_plugin_active($item));
        deactivate_plugins($disabled_plugins);
    }

    public function get_roles_from_constant()
    {
        return array_map('trim', explode(',', constant(PluginBootstrap::TENANT_ROLES_CONSTANT)));
    }

    public function waas_no_roles_json_found_admin_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
?>
            <div class="notice notice-error">
                <p><strong><?php esc_html_e('WaaS Client: this website has no plan roles.', WAAS_CLIENT_TEXTDOMAIN); ?></strong></p>
                <p><?php esc_html_e('Its template version has no roles set up, so no plugins are turned on or off for its plan. Set up roles on the template website under WaaS Client > Plan roles, then deploy that version.', WAAS_CLIENT_TEXTDOMAIN); ?></p>
            </div>
<?php
    }

    public function get_waas_no_role_defined_admin_notice($roles)
    {
        return function () use ($roles) {
            if (!current_user_can('manage_options')) {
                return;
            }
?>
            <div class="notice notice-error">
                <p><strong><?php esc_html_e('WaaS Client: this website has no plan role.', WAAS_CLIENT_TEXTDOMAIN); ?></strong></p>
                <p><?php esc_html_e('This happens when a website is created by hand instead of through a purchase, so no plugins are turned on or off for it.', WAAS_CLIENT_TEXTDOMAIN); ?></p>
                <p><?php
                    /* translators: %s: a PHP constant name */
                    echo esc_html(sprintf(__('To try a role, define the PHP constant %s on this website with one or more of these roles, separated by commas:', WAAS_CLIENT_TEXTDOMAIN), PluginBootstrap::TENANT_ROLES_CONSTANT));
                ?></p>
                <ul>
                    <?php foreach (array_keys((array) $roles) as $key) : ?>
                        <li><code><?php echo esc_html($key); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            </div>
<?php
        };
    }
}
