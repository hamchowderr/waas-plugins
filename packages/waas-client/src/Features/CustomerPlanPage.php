<?php

namespace WaaSClient\Features;

/**
 * Read-only "Your plan" page on customer websites (Dashboard > Your plan): the plan role(s) and the plugins
 * the plan turns on. Gated on manage_options, not activate_plugins, because the plugin capabilities may be
 * removed from administrators on customer websites, and then this page is the only place that explains it.
 */
class CustomerPlanPage
{
    const PAGE = 'waas-your-plan';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_page']);
        add_action('admin_enqueue_scripts', [$this, 'add_styles']);
        add_action('pre_current_active_plugins', [$this, 'plugins_screen_notice']);
    }

    public function add_page()
    {
        add_dashboard_page(
            __('Your plan', WAAS_CLIENT_TEXTDOMAIN),
            __('Your plan', WAAS_CLIENT_TEXTDOMAIN),
            'manage_options',
            self::PAGE,
            [$this, 'render']
        );
    }

    public function add_styles($hook)
    {
        if (strpos((string) $hook, self::PAGE) === false) {
            return;
        }

        wp_enqueue_style('waas-admin-styles', WAAS_PLUGIN_DIR_URI . 'assets/style.css', [], WAAS_CLIENT_VERSION);
    }

    /** One line above the Plugins list pointing to the plan page. */
    public function plugins_screen_notice()
    {
        if (!current_user_can('manage_options') || !defined(PluginBootstrap::TENANT_ROLES_CONSTANT)) {
            return;
        }

        printf(
            '<div class="notice notice-info inline"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('Plugins on this website are managed by your plan: they are turned on or off automatically.', WAAS_CLIENT_TEXTDOMAIN),
            esc_url(admin_url('index.php?page=' . self::PAGE)),
            esc_html__('See your plan', WAAS_CLIENT_TEXTDOMAIN)
        );
    }

    public function render()
    {
        $user_roles = array_values(array_filter(array_map('strval', (array) get_option(PluginBootstrap::TENANT_ROLES, []))));
        $roles = file_exists(AdminRolesSettings::ROLES_FILE_PATH)
            ? json_decode((string) file_get_contents(AdminRolesSettings::ROLES_FILE_PATH), true)
            : null;
        $roles = is_array($roles) ? $roles : [];
        $plan = RolesManager::plan_for($user_roles, $roles);
        $wp_plugins = get_plugins();
        $storefront = (string) get_option('waas_host_website_url', '');
?>
        <div class="wrap waas-client-admin">
            <h1><?php esc_html_e('Your plan', WAAS_CLIENT_TEXTDOMAIN); ?></h1>

            <?php if ($plan['matched']) : ?>
                <p class="waas-lead"><?php esc_html_e('The plugins on this website are managed by your plan. The plan decides which plugins are on; they are turned on or off automatically, so changes on the Plugins screen do not last.', WAAS_CLIENT_TEXTDOMAIN); ?></p>
            <?php elseif ($plan['missing']) : ?>
                <div class="notice notice-warning inline"><p>
                    <?php echo esc_html(sprintf(
                        /* translators: %s: comma-separated role names */
                        __('Your plan (%s) is not set up on this website yet, so its plugins are not being managed and nothing has been changed. Contact the team that sold you this website.', WAAS_CLIENT_TEXTDOMAIN),
                        implode(', ', $plan['missing'])
                    )); ?>
                </p></div>
            <?php else : ?>
                <div class="notice notice-warning inline"><p>
                    <?php esc_html_e('This website has no plan role, so its plugins are not managed by a plan. If you think this is wrong, contact the team that sold you this website.', WAAS_CLIENT_TEXTDOMAIN); ?>
                </p></div>
            <?php endif; ?>

            <?php foreach ($plan['matched'] as $role_slug) :
                $role = $roles[$role_slug];
                $plugins = array_values(array_filter((array) ($role['plugins'] ?? []), fn ($file) => is_string($file) && $file !== PluginBootstrap::PLUGIN_NAME));
            ?>
                <h2><?php echo esc_html($role['title'] ?? $role_slug); ?></h2>
                <?php if (!$plugins) : ?>
                    <p><?php esc_html_e('This plan turns on no extra plugins.', WAAS_CLIENT_TEXTDOMAIN); ?></p>
                <?php else : ?>
                    <table class="widefat striped waas-plan-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Plugin included in your plan', WAAS_CLIENT_TEXTDOMAIN); ?></th>
                                <th scope="col"><?php esc_html_e('Version', WAAS_CLIENT_TEXTDOMAIN); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plugins as $plugin_file) : ?>
                                <tr>
                                    <td><?php echo esc_html($wp_plugins[$plugin_file]['Name'] ?? $plugin_file); ?></td>
                                    <td><?php echo esc_html($wp_plugins[$plugin_file]['Version'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($storefront !== '') : ?>
                <p><?php
                    /* translators: %s: link to the storefront */
                    printf(esc_html__('Need more plugins? You can change your plan at %s.', WAAS_CLIENT_TEXTDOMAIN), '<a href="' . esc_url($storefront) . '" target="_blank" rel="noopener">' . esc_html(preg_replace('#^https?://#', '', $storefront)) . '</a>');
                ?></p>
            <?php endif; ?>
        </div>
<?php
    }
}
