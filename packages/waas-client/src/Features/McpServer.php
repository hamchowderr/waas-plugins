<?php

namespace WaaSClient\Features;

/**
 * Lets AI apps work on the website over MCP, through WordPress's own MCP Adapter plugin (wordpress.org/plugins/mcp-adapter).
 * Stores come with the adapter installed but off; the owner turns it on or off on the "Your plan" page, and the plan
 * keeps it that way (RolesManager adds it to the plan's plugins while it is on).
 *
 * While it is on, the website has an "AnyPress website" MCP server at /wp-json/mcp/anypress whose tools are the site
 * abilities (SiteAbilities), one tool each. An app signs in as a WordPress user (an application password) and can only
 * do what that user may: the abilities check the user's own capabilities, and the server only lets in users who can
 * edit pages.
 */
class McpServer
{
    const OPTION = 'waas_client_mcp';
    const PLUGIN = 'mcp-adapter/mcp-adapter.php';
    const ACTION = 'waas_client_mcp';
    const ROUTE_NAMESPACE = 'mcp';
    const ROUTE = 'anypress';

    public function __construct()
    {
        add_action('mcp_adapter_init', [$this, 'create_server']);
        add_action('admin_post_' . self::ACTION, [$this, 'save']);
    }

    public static function is_on(): bool
    {
        return get_option(self::OPTION, '0') === '1';
    }

    /** Whether this website has the adapter plugin at all (stores made before it came with the template don't). */
    public static function is_installed(): bool
    {
        return file_exists(WP_PLUGIN_DIR . '/' . self::PLUGIN);
    }

    /**
     * The plan's plugins plus the adapter while the owner has it on.
     *
     * @param string[] $plugins
     * @return string[]
     */
    public static function with_switch(array $plugins): array
    {
        if (self::is_on() && self::is_installed() && !in_array(self::PLUGIN, $plugins, true)) {
            $plugins[] = self::PLUGIN;
        }

        return $plugins;
    }

    public static function url(): string
    {
        return rest_url(self::ROUTE_NAMESPACE . '/' . self::ROUTE);
    }

    /** @param \WP\MCP\Core\McpAdapter $adapter */
    public function create_server($adapter): void
    {
        $tools = [];
        foreach (wp_get_abilities() as $ability) {
            if ($ability->get_category() === \WaaSClient\Api\SiteAbilities::CATEGORY) {
                $tools[] = $ability->get_name();
            }
        }

        $adapter->create_server(
            'anypress',
            self::ROUTE_NAMESPACE,
            self::ROUTE,
            'AnyPress website',
            'Read and change this website\'s pages, starter patterns, colours and fonts, header, footer, menus and images. Every page change keeps a revision.',
            WAAS_CLIENT_VERSION,
            [\WP\MCP\Transport\HttpTransport::class],
            \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
            null,
            $tools,
            [],
            [],
            fn () => current_user_can('edit_pages')
        );
    }

    /** The "Your plan" page's Turn on / Turn off button. */
    public function save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Only administrators can change this.', 'waas-client'), 403);
        }
        check_admin_referer(self::ACTION);

        $on = isset($_POST['mcp']) && sanitize_key(wp_unslash($_POST['mcp'])) === 'on';
        update_option(self::OPTION, $on ? '1' : '0');

        // The plan turns it on or off on the next page load; do it now too, so the next page already shows it.
        if (self::is_installed()) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            if ($on) {
                activate_plugin(self::PLUGIN);
            } else {
                deactivate_plugins(self::PLUGIN);
            }
        }

        wp_safe_redirect(admin_url('index.php?page=' . CustomerPlanPage::PAGE . '#ai-apps'));
        exit;
    }

    /** The "AI apps" section of the "Your plan" page. */
    public static function render_section(): void
    {
        if (!self::is_installed()) {
            return;
        }
        $on = self::is_on();
?>
        <h2 id="ai-apps"><?php esc_html_e('AI apps (MCP)', 'waas-client'); ?></h2>
        <?php if ($on) : ?>
            <p><?php esc_html_e('AI apps can work on this website through WordPress\'s MCP Adapter. They can read and change pages, design, header, footer and menus, and add images, and only as far as the WordPress user they sign in as is allowed to. Every page change is saved as a revision you can go back to.', 'waas-client'); ?></p>
            <p>
                <?php esc_html_e('Server address:', 'waas-client'); ?>
                <code><?php echo esc_html(self::url()); ?></code>
            </p>
            <p><?php echo wp_kses(sprintf(
                /* translators: %s: link to the user's profile, where application passwords are made */
                __('The app signs in with your username and an application password. Make one for each app in <a href="%s">your profile</a>, and revoke it there to stop that app.', 'waas-client'),
                esc_url(admin_url('profile.php#application-passwords-section'))
            ), ['a' => ['href' => []]]); ?></p>
        <?php else : ?>
            <p><?php esc_html_e('Turn this on to let AI apps that speak MCP (the Model Context Protocol) work on this website\'s pages and design through WordPress\'s MCP Adapter. It is off until you turn it on.', 'waas-client'); ?></p>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <input type="hidden" name="mcp" value="<?php echo esc_attr($on ? 'off' : 'on'); ?>">
            <?php wp_nonce_field(self::ACTION); ?>
            <p><button type="submit" class="button<?php echo $on ? '' : ' button-primary'; ?>"><?php $on ? esc_html_e('Turn off', 'waas-client') : esc_html_e('Turn on', 'waas-client'); ?></button></p>
        </form>
<?php
    }
}
