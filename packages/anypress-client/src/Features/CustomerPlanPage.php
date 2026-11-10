<?php

namespace AnyPressClient\Features;

use AnyPressClient\Core\Brand;
use AnyPressClient\Crm\HighLevelLink;

/**
 * Read-only "Your plan" page on customer websites (Dashboard > Your plan): the plan role(s), the plugins the plan
 * turns on and what each is for, and on HighLevel sites what is connected to HighLevel. Gated on manage_options, not activate_plugins, because the plugin capabilities may be
 * removed from administrators on customer websites, and then this page is the only place that explains it.
 */
class CustomerPlanPage
{
    public const PAGE = 'anypress-your-plan';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_page']);
        add_action('admin_enqueue_scripts', [$this, 'add_styles']);
        add_action('pre_current_active_plugins', [$this, 'plugins_screen_notice']);
    }

    public function add_page()
    {
        add_dashboard_page(
            __('Your plan', 'anypress-client'),
            __('Your plan', 'anypress-client'),
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

        wp_enqueue_style('anypress-admin-styles', ANYPRESS_PLUGIN_DIR_URI . 'assets/style.css', [], ANYPRESS_CLIENT_VERSION);
    }

    /** One line above the Plugins list pointing to the plan page. */
    public function plugins_screen_notice()
    {
        if (!current_user_can('manage_options') || !PluginBootstrap::has_constant(PluginBootstrap::TENANT_ROLES_CONSTANT)) {
            return;
        }

        printf(
            '<div class="notice notice-info inline"><p>%s <a href="%s">%s</a></p></div>',
            self::own_plugins()
                ? esc_html__('The plugins that come with this website are turned on automatically. Plugins you add yourself stay as you set them.', 'anypress-client')
                : esc_html__('Plugins on this website are managed by your plan: they are turned on or off automatically.', 'anypress-client'),
            esc_url(admin_url('index.php?page=' . self::PAGE)),
            esc_html__('See your plan', 'anypress-client')
        );
    }

    /** This site's type lets the owner add plugins of their own (see RolesManager). */
    private static function own_plugins(): bool
    {
        $user_roles = array_values(array_filter(array_map('strval', (array) get_option(PluginBootstrap::TENANT_ROLES, []))));
        $roles = file_exists(AdminRolesSettings::ROLES_FILE_PATH)
            ? json_decode((string) file_get_contents(AdminRolesSettings::ROLES_FILE_PATH), true)
            : null;

        return RolesManager::plan_for($user_roles, is_array($roles) ? $roles : [])['own_plugins'];
    }

    /** Whether LeadConnector (HighLevel's plugin) has been connected to a sub-account in its own settings. */
    public static function leadconnector_connected(): bool
    {
        $options = get_option('lead_connector_plugin_options');
        if (!is_array($options)) {
            return false;
        }
        $location = (string) ($options['location_id'] ?? '');

        return (string) ($options['oauth_access_token'] ?? '') !== '' || ($location !== '' && $location !== 'leadconnector_disconnect');
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
        $storefront = (string) get_option('anypress_host_website_url', '');
?>
        <div class="wrap anypress-client-admin">
            <h1><?php esc_html_e('Your plan', 'anypress-client'); ?></h1>

            <?php if ($plan['matched'] && $plan['own_plugins']) : ?>
                <p class="anypress-lead"><?php esc_html_e('These plugins come with your website and are kept on for you, so turning one off on the Plugins screen does not last. You can add plugins of your own too: they stay as you set them. Before adding one, check the list below: what you need may already be here.', 'anypress-client'); ?></p>
            <?php elseif ($plan['matched']) : ?>
                <p class="anypress-lead"><?php esc_html_e('The plugins on this website are managed by your plan. The plan decides which plugins are on; they are turned on or off automatically, so changes on the Plugins screen do not last.', 'anypress-client'); ?></p>
            <?php elseif ($plan['missing']) : ?>
                <div class="notice notice-warning inline"><p>
                    <?php echo esc_html(sprintf(
                        /* translators: %s: comma-separated role names */
                        __('Your plan (%s) is not set up on this website yet, so its plugins are not being managed and nothing has been changed. Contact the team that sold you this website.', 'anypress-client'),
                        implode(', ', $plan['missing'])
                    )); ?>
                </p></div>
            <?php else : ?>
                <div class="notice notice-warning inline"><p>
                    <?php esc_html_e('This website has no plan role, so its plugins are not managed by a plan. If you think this is wrong, contact the team that sold you this website.', 'anypress-client'); ?>
                </p></div>
            <?php endif; ?>

            <?php foreach ($plan['matched'] as $role_slug) :
                $role = $roles[$role_slug];
                $plugins = array_values(array_filter((array) ($role['plugins'] ?? []), fn ($file) => is_string($file) && $file !== PluginBootstrap::PLUGIN_NAME));
                $about = array_filter((array) ($role['about'] ?? []), 'is_string');
            ?>
                <h2><?php echo esc_html($role['title'] ?? $role_slug); ?></h2>
                <?php if (!$plugins) : ?>
                    <p><?php esc_html_e('This plan turns on no extra plugins.', 'anypress-client'); ?></p>
                <?php else : ?>
                    <table class="widefat striped anypress-plan-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Plugin included in your plan', 'anypress-client'); ?></th>
                                <?php if ($about) : ?>
                                    <th scope="col"><?php esc_html_e('What it is for', 'anypress-client'); ?></th>
                                <?php endif; ?>
                                <th scope="col"><?php esc_html_e('Version', 'anypress-client'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plugins as $plugin_file) : ?>
                                <tr>
                                    <td><?php echo esc_html($wp_plugins[$plugin_file]['Name'] ?? $plugin_file); ?></td>
                                    <?php if ($about) : ?>
                                        <td><?php echo esc_html($about[$plugin_file] ?? ''); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo esc_html($wp_plugins[$plugin_file]['Version'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if (HighLevelLink::applies()) : $this->render_highlevel();
            endif; ?>

            <?php Languages::render_section(); ?>

            <?php McpServer::render_section(); ?>

            <?php if ($storefront !== '' && !$plan['own_plugins']) : ?>
                <p><?php
                    /* translators: %s: link to the storefront */
                    printf(esc_html__('Need more plugins? You can change your plan at %s.', 'anypress-client'), '<a href="' . esc_url($storefront) . '" target="_blank" rel="noopener">' . esc_html(preg_replace('#^https?://#', '', $storefront)) . '</a>');
                ?></p>
            <?php endif; ?>
        </div>
<?php
    }

    /** What is connected to HighLevel: WP Fusion (by AnyPress, to the site's sub-account) and LeadConnector (by the owner). */
    private function render_highlevel(): void
    {
        $link = HighLevelLink::status();
        $leadconnector = self::leadconnector_connected();
?>
        <h2><?php esc_html_e('Connected to HighLevel', 'anypress-client'); ?></h2>
        <table class="widefat striped anypress-plan-table">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Contacts (WP Fusion)', 'anypress-client'); ?></th>
                    <td><?php
                        if ($link['connected']) {
                            /* translators: %s: HighLevel sub-account (location) id */
                            echo esc_html(sprintf(__('Connected. New users and sign-ups go to your HighLevel sub-account (%s) as contacts. Nothing to set up.', 'anypress-client'), $link['location_id']));
                        } else {
                            /* translators: %s: the website seller's brand */
                            echo esc_html(sprintf(__('Not connected yet. %s connects it to the sub-account that made this site by itself, within the hour. Nothing to set up.', 'anypress-client'), Brand::name()));
                        }
                    ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Forms, chat and calendars (LeadConnector)', 'anypress-client'); ?></th>
                    <td><?php if ($leadconnector) : ?>
                        <?php esc_html_e('Connected.', 'anypress-client'); ?>
                        <?php else : ?>
                        <?php esc_html_e('Not connected yet. To show HighLevel forms, the chat widget or calendars on this site, connect it to your sub-account:', 'anypress-client'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=leadconnector-plugin')); ?>"><?php esc_html_e('Open LeadConnector', 'anypress-client'); ?></a>
                        <?php endif; ?></td>
                </tr>
            </tbody>
        </table>
<?php
    }
}
