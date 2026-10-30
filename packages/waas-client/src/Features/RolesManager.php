<?php

namespace WaaSClient\Features;

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
            // This is not a WaaS store. Either we're in a version or some local environment
            return;
        }
        if (!file_exists(AdminRolesSettings::ROLES_FILE_PATH)) {
            // This is a WaaS store but the Plugin is not yet setup or the Version is not deployed
            add_action('admin_notices', [$this, 'waas_no_roles_json_found_admin_notice']);
            return;
        }

        $user_roles = array_values(array_filter(array_map('strval', (array) get_option(PluginBootstrap::TENANT_ROLES, []))));
        $roles_plugins = json_decode((string) file_get_contents(AdminRolesSettings::ROLES_FILE_PATH), true);

        if (!is_array($roles_plugins)) {
            // roles.json is broken. Without it we can't tell which plugins the plan wants, so change nothing:
            // deactivating everything here would take the website down.
            add_action('admin_notices', [$this, 'waas_invalid_roles_json_admin_notice']);
            return;
        }

        if (empty($user_roles)) {
            // Someone created a tenant without a role. Either they are testing, made a mistake or are not familiar with the plugins.
            // Probably best to just notify them and leave it for now.
            add_action('admin_notices', $this->get_waas_no_role_defined_admin_notice($roles_plugins));
            return;
        }

        $plan = self::plan_for($user_roles, $roles_plugins);

        if (!$plan['matched']) {
            // None of the website's roles exist in roles.json (renamed or deleted on the template). An empty plugin
            // list here would deactivate every plugin, so stop and tell the admin instead.
            add_action('admin_notices', $this->get_waas_unknown_roles_admin_notice($plan['missing'], array_keys($roles_plugins), true));
            return;
        }

        if ($plan['missing']) {
            add_action('admin_notices', $this->get_waas_unknown_roles_admin_notice($plan['missing'], array_keys($roles_plugins), false));
        }

        $enabled_plugins = $plan['plugins'];
        $all_plugins = array_keys(get_plugins());
        $disabled_plugins = array_diff($all_plugins, $enabled_plugins);

        $plugins_requiring_enabling = array_values(array_filter($enabled_plugins, fn ($plugin) => !is_plugin_active($plugin)));
        activate_plugins($plugins_requiring_enabling);

        $disabled_plugins = array_filter($disabled_plugins, fn ($item) => $item !== PluginBootstrap::PLUGIN_NAME && is_plugin_active($item));
        deactivate_plugins($disabled_plugins);
    }

    /**
     * Which of the website's roles exist in roles.json, which don't, and the plugins the found ones turn on.
     *
     * @return array{matched: string[], missing: string[], plugins: string[]}
     */
    public static function plan_for(array $user_roles, array $roles_plugins): array
    {
        $matched = [];
        $missing = [];
        $plugins = [];

        foreach ($user_roles as $user_role) {
            if (!isset($roles_plugins[$user_role]) || !is_array($roles_plugins[$user_role])) {
                $missing[] = $user_role;
                continue;
            }
            $matched[] = $user_role;
            $plugins = array_merge($plugins, array_filter((array) ($roles_plugins[$user_role]['plugins'] ?? []), 'is_string'));
        }

        return ['matched' => $matched, 'missing' => $missing, 'plugins' => array_values(array_unique($plugins))];
    }

    public function get_roles_from_constant()
    {
        return array_map('trim', explode(',', (string) PluginBootstrap::constant(PluginBootstrap::TENANT_ROLES_CONSTANT)));
    }

    public function waas_no_roles_json_found_admin_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
?>
            <div class="notice notice-error">
                <p><strong><?php esc_html_e('WaaS Client: this website has no plan roles.', 'waas-client'); ?></strong></p>
                <p><?php esc_html_e('Its template version has no roles set up, so no plugins are turned on or off for its plan. Set up roles on the template website under WaaS Client > Plan roles, then deploy that version.', 'waas-client'); ?></p>
            </div>
<?php
    }

    public function waas_invalid_roles_json_admin_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
?>
            <div class="notice notice-error">
                <p><strong><?php esc_html_e('WaaS Client: this website\'s plan roles could not be read.', 'waas-client'); ?></strong></p>
                <p><?php esc_html_e('The roles file that came with its template version is damaged, so no plugins were turned on or off and the website stays as it was. Save the plan roles again on the template website and deploy that version.', 'waas-client'); ?></p>
            </div>
<?php
    }

    /**
     * @param string[] $missing   the website's roles that roles.json doesn't have
     * @param string[] $available the roles roles.json does have
     * @param bool     $stopped   true when nothing was changed because no role matched
     */
    public function get_waas_unknown_roles_admin_notice(array $missing, array $available, bool $stopped)
    {
        return function () use ($missing, $available, $stopped) {
            if (!current_user_can('manage_options')) {
                return;
            }
?>
            <div class="notice <?php echo $stopped ? 'notice-error' : 'notice-warning'; ?>">
                <p><strong><?php echo esc_html(sprintf(
                    /* translators: %s: comma-separated role names */
                    _n('WaaS Client: plan role "%s" was not found in this website\'s roles.', 'WaaS Client: plan roles "%s" were not found in this website\'s roles.', count($missing), 'waas-client'),
                    implode('", "', $missing)
                )); ?></strong></p>
                <p><?php echo $stopped
                    ? esc_html__('No plugins were turned on or off, so the website stays as it was. Add the role on the template website (WaaS Client > Plan roles) and deploy that version, or move this website to a plan whose role exists.', 'waas-client')
                    : esc_html__('Plugins were set from the plan\'s other roles only. Add the missing role on the template website and deploy that version.', 'waas-client'); ?></p>
                <?php if ($available) : ?>
                    <p><?php esc_html_e('Roles this website has:', 'waas-client'); ?>
                        <?php echo implode(', ', array_map(fn ($key) => '<code>' . esc_html((string) $key) . '</code>', $available)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each key is esc_html() above. ?></p>
                <?php endif; ?>
            </div>
<?php
        };
    }

    public function get_waas_no_role_defined_admin_notice($roles)
    {
        return function () use ($roles) {
            if (!current_user_can('manage_options')) {
                return;
            }
?>
            <div class="notice notice-error">
                <p><strong><?php esc_html_e('WaaS Client: this website has no plan role.', 'waas-client'); ?></strong></p>
                <p><?php esc_html_e('This happens when a website is created by hand instead of through a purchase, so no plugins are turned on or off for it.', 'waas-client'); ?></p>
                <p><?php
                    /* translators: %s: a PHP constant name */
                    echo esc_html(sprintf(__('To try a role, define the PHP constant %s on this website with one or more of these roles, separated by commas:', 'waas-client'), PluginBootstrap::TENANT_ROLES_CONSTANT));
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
