<?php

namespace AnyPressClient\Features;

use AnyPressClient\Core\Brand;

class RolesManager
{
    public function __construct()
    {
        add_action('init', [$this, 'activate_enabled_plugins']);

        if (PluginBootstrap::has_constant(PluginBootstrap::TENANT_ROLES_CONSTANT)) {
            add_filter('option_' . PluginBootstrap::TENANT_ROLES, [$this, 'get_roles_from_constant']);
            add_filter('default_option_' . PluginBootstrap::TENANT_ROLES, [$this, 'get_roles_from_constant']);
        }
    }

    public function activate_enabled_plugins(): void
    {
        if (!PluginBootstrap::is_store()) {
            // This is not an AnyPress store. Either we're in a version or some local environment
            return;
        }
        if (!file_exists(AdminRolesSettings::ROLES_FILE_PATH)) {
            // This is an AnyPress store but the Plugin is not yet setup or the Version is not deployed
            add_action('admin_notices', [$this, 'anypress_no_roles_json_found_admin_notice']);
            return;
        }

        $user_roles = array_values(array_filter(array_map('strval', (array) get_option(PluginBootstrap::TENANT_ROLES, []))));
        $roles_plugins = json_decode((string) file_get_contents(AdminRolesSettings::ROLES_FILE_PATH), true);

        if (!is_array($roles_plugins)) {
            // roles.json is broken. Without it we can't tell which plugins the plan wants, so change nothing:
            // deactivating everything here would take the website down.
            add_action('admin_notices', [$this, 'anypress_invalid_roles_json_admin_notice']);
            return;
        }

        if (empty($user_roles)) {
            // Someone created a tenant without a role. Either they are testing, made a mistake or are not familiar with the plugins.
            // Probably best to just notify them and leave it for now.
            add_action('admin_notices', $this->get_anypress_no_role_defined_admin_notice($roles_plugins));
            return;
        }

        $plan = self::plan_for($user_roles, $roles_plugins);

        if (!$plan['matched']) {
            // None of the website's roles exist in roles.json (renamed or deleted on the template). An empty plugin
            // list here would deactivate every plugin, so stop and tell the admin instead.
            add_action('admin_notices', $this->get_anypress_unknown_roles_admin_notice($plan['missing'], array_keys($roles_plugins), true));
            return;
        }

        if ($plan['missing']) {
            add_action('admin_notices', $this->get_anypress_unknown_roles_admin_notice($plan['missing'], array_keys($roles_plugins), false));
        }

        // Plus the MCP Adapter, always on.
        $enabled_plugins = McpServer::with_adapter($plan['plugins']);
        $all_plugins = array_keys(get_plugins());
        $disabled_plugins = array_diff($all_plugins, $enabled_plugins);
        if ($plan['own_plugins']) {
            // A site type that lets the owner add plugins (HighLevel sites): only the template's own plugins follow the
            // plan; plugins the owner installed stay as the owner set them.
            $disabled_plugins = array_intersect($disabled_plugins, self::managed_plugins($roles_plugins));
        }

        $plugins_requiring_enabling = array_values(array_filter($enabled_plugins, fn ($plugin) => !is_plugin_active($plugin)));
        activate_plugins($plugins_requiring_enabling);

        $disabled_plugins = array_filter($disabled_plugins, fn ($item) => $item !== PluginBootstrap::PLUGIN_NAME && is_plugin_active($item));
        deactivate_plugins($disabled_plugins);
    }

    /**
     * Which of the website's roles exist in roles.json, which don't, and the plugins the found ones turn on.
     *
     * own_plugins: one of the found roles lets the owner add plugins of their own ("ownPlugins": true in roles.json).
     *
     * @return array{matched: string[], missing: string[], plugins: string[], own_plugins: bool}
     */
    public static function plan_for(array $user_roles, array $roles_plugins): array
    {
        $matched = [];
        $missing = [];
        $plugins = [];
        $own_plugins = false;

        foreach ($user_roles as $user_role) {
            if (!isset($roles_plugins[$user_role]) || !is_array($roles_plugins[$user_role])) {
                $missing[] = $user_role;
                continue;
            }
            $matched[] = $user_role;
            $plugins = array_merge($plugins, array_filter((array) ($roles_plugins[$user_role]['plugins'] ?? []), 'is_string'));
            $own_plugins = $own_plugins || ($roles_plugins[$user_role]['ownPlugins'] ?? false) === true;
        }

        return ['matched' => $matched, 'missing' => $missing, 'plugins' => array_values(array_unique($plugins)), 'own_plugins' => $own_plugins];
    }

    /** The plugins the template manages: every plugin any role in roles.json lists, and the MCP Adapter. */
    public static function managed_plugins(array $roles_plugins): array
    {
        $managed = [McpServer::PLUGIN];
        foreach ($roles_plugins as $role) {
            if (is_array($role)) {
                $managed = array_merge($managed, array_filter((array) ($role['plugins'] ?? []), 'is_string'));
            }
        }

        return array_values(array_unique($managed));
    }

    public function get_roles_from_constant()
    {
        return array_map('trim', explode(',', (string) PluginBootstrap::constant(PluginBootstrap::TENANT_ROLES_CONSTANT)));
    }

    public function anypress_no_roles_json_found_admin_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
?>
            <div class="notice notice-error">
                <p><strong><?php echo esc_html(sprintf(/* translators: %s: the plugin's name */ __('%s: this website has no plan roles.', 'anypress-client'), Brand::plugin_name())); ?></strong></p>
                <p><?php esc_html_e('Its template version has no roles set up, so no plugins are turned on or off for its plan. Set up roles on the template website under AnyPress Client > Plan roles, then deploy that version.', 'anypress-client'); ?></p>
            </div>
<?php
    }

    public function anypress_invalid_roles_json_admin_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
?>
            <div class="notice notice-error">
                <p><strong><?php echo esc_html(sprintf(/* translators: %s: the plugin's name */ __('%s: this website\'s plan roles could not be read.', 'anypress-client'), Brand::plugin_name())); ?></strong></p>
                <p><?php esc_html_e('The roles file that came with its template version is damaged, so no plugins were turned on or off and the website stays as it was. Save the plan roles again on the template website and deploy that version.', 'anypress-client'); ?></p>
            </div>
<?php
    }

    /**
     * @param string[] $missing   the website's roles that roles.json doesn't have
     * @param string[] $available the roles roles.json does have
     * @param bool     $stopped   true when nothing was changed because no role matched
     */
    public function get_anypress_unknown_roles_admin_notice(array $missing, array $available, bool $stopped)
    {
        return function () use ($missing, $available, $stopped) {
            if (!current_user_can('manage_options')) {
                return;
            }
?>
            <div class="notice <?php echo $stopped ? 'notice-error' : 'notice-warning'; ?>">
                <p><strong><?php echo esc_html(sprintf(
                    /* translators: 1: the plugin's name, 2: comma-separated role names */
                    _n('%1$s: plan role "%2$s" was not found in this website\'s roles.', '%1$s: plan roles "%2$s" were not found in this website\'s roles.', count($missing), 'anypress-client'),
                    Brand::plugin_name(),
                    implode('", "', $missing)
                )); ?></strong></p>
                <p><?php echo $stopped
                    ? esc_html__('No plugins were turned on or off, so the website stays as it was. Add the role on the template website (AnyPress Client > Plan roles) and deploy that version, or move this website to a plan whose role exists.', 'anypress-client')
                    : esc_html__('Plugins were set from the plan\'s other roles only. Add the missing role on the template website and deploy that version.', 'anypress-client'); ?></p>
                <?php if ($available) : ?>
                    <p><?php esc_html_e('Roles this website has:', 'anypress-client'); ?>
                        <?php echo implode(', ', array_map(fn ($key) => '<code>' . esc_html((string) $key) . '</code>', $available)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each key is esc_html() above. ?></p>
                <?php endif; ?>
            </div>
<?php
        };
    }

    public function get_anypress_no_role_defined_admin_notice($roles)
    {
        return function () use ($roles) {
            if (!current_user_can('manage_options')) {
                return;
            }
?>
            <div class="notice notice-error">
                <p><strong><?php echo esc_html(sprintf(/* translators: %s: the plugin's name */ __('%s: this website has no plan role.', 'anypress-client'), Brand::plugin_name())); ?></strong></p>
                <p><?php esc_html_e('This happens when a website is created by hand instead of through a purchase, so no plugins are turned on or off for it.', 'anypress-client'); ?></p>
                <p><?php
                    /* translators: %s: a PHP constant name */
                    echo esc_html(sprintf(__('To try a role, define the PHP constant %s on this website with one or more of these roles, separated by commas:', 'anypress-client'), PluginBootstrap::TENANT_ROLES_CONSTANT));
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
