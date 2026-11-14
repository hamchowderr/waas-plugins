<?php

namespace AnyPressHost\Features;

use AnyPressHost\Commerce\Commerce;
use AnyPressHost\Core\ConfigService;

/**
 * The AnyPress menu and its Overview page: setup steps until the storefront is ready, then what the seller needs
 * day to day (website orders, websites that were paid for but not built, the connection). Running the websites
 * themselves (suspend, backups, domains) happens in the AnyPress dashboard, which this page links to.
 */
class AdminHome
{
    public const SLUG = 'anypress-admin';
    /** How many of the newest website orders the Overview lists. */
    public const RECENT_ORDERS = 8;

    private ConfigService $config_service;

    public function __construct($config_service)
    {
        $this->config_service = $config_service;

        add_action('admin_menu', [$this, 'add_anypress_admin_page'], 11);
        add_filter('fluent_toolkit/unified_apps', [$this, 'add_fluent_toolkit_app']);

        new GettingStartedChecklist($config_service);
    }

    /** The AnyPress ribbon for the admin menu, as a data URI (WordPress shows it as an image). */
    public static function menu_icon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="14 12 80 76">'
            . '<defs><linearGradient id="l" x1="8" y1="80" x2="66" y2="40" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#FFB338"/><stop offset=".55" stop-color="#FF7A2A"/><stop offset="1" stop-color="#F2413A"/></linearGradient>'
            . '<linearGradient id="r" x1="56" y1="14" x2="86" y2="86" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#FF5A33"/><stop offset=".5" stop-color="#EE2F5E"/><stop offset="1" stop-color="#D21FA4"/></linearGradient></defs>'
            . '<path d="M48.5 17.5Q51 14 55 15L56 27 50.5 48H62.3L66.2 59 48 64 44 77.5H20Q16 77.5 18.5 75Q28 68 32 58Z" fill="url(#l)"/>'
            . '<path d="M51 15H62Q65.5 15 66.8 18.5L90 80Q91.5 84 87.5 84H77.5Q75 84 74 81.5L55 27Z" fill="url(#r)"/></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function add_anypress_admin_page()
    {
        $cap = 'manage_options';
        add_menu_page(
            __('AnyPress', 'anypress-host'),
            __('AnyPress', 'anypress-host'),
            $cap,
            self::SLUG,
            [$this, 'render_anypress_admin_page'],
            self::menu_icon(),
            10
        );

        add_submenu_page(
            self::SLUG,
            __('AnyPress', 'anypress-host'),
            __('Overview', 'anypress-host'),
            $cap,
            self::SLUG,
            [$this, 'render_anypress_admin_page']
        );
    }

    /**
     * FluentKit's shared Fluent sidebar: AnyPress joins CRM, Commerce and the rest when its unified UI is on. Without
     * FluentKit nothing calls this, and the AnyPress menu stays where it is.
     */
    public function add_fluent_toolkit_app($apps)
    {
        if (!is_array($apps)) {
            $apps = [];
        }

        // A file, not the menu's data URI: FluentKit prints the icon through esc_url(), which drops data: links.
        $icon = plugins_url('assets/images/anypress-icon.svg', dirname(__DIR__, 2) . '/anypress-host.php');
        $apps[self::SLUG] = [
            'disabled' => !current_user_can('manage_options'),
            'title' => __('AnyPress', 'anypress-host'),
            'icon' => $icon,
            'logo' => $icon,
            'items' => [
                'overview' => ['title' => __('Overview', 'anypress-host'), 'url' => admin_url('admin.php?page=' . self::SLUG)],
                'settings' => ['title' => __('Settings', 'anypress-host'), 'url' => AdminSettings::url()],
            ],
            'has_dark_mode' => false,
            'dashboard_url' => admin_url('admin.php?page=' . self::SLUG),
        ];
        // FluentKit puts its sidebar on the pages whose slug is an app; Settings is a second page of the same app.
        // FluentKit reads the open page's own entry for the page title, the phone menu and which sidebar app is open,
        // so Settings repeats the app's entry and only the open page's entry is listed in the sidebar.
        $on_settings = ($_GET['page'] ?? '') === AdminSettings::SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $apps[AdminSettings::SLUG] = ['parent_slug' => self::SLUG, 'disabled' => !$on_settings || $apps[self::SLUG]['disabled']] + $apps[self::SLUG];
        $apps[self::SLUG]['disabled'] = $on_settings || $apps[self::SLUG]['disabled'];

        return $apps;
    }

    /**
     * The AnyPress dashboard, where sellers run their websites. Empty for a storefront on another provisioning API,
     * which has no such dashboard. Filter: anypress_dashboard_url.
     */
    public function dashboard_url(string $path = ''): string
    {
        $host = (string) wp_parse_url((string) $this->config_service->get_api_base_url(), PHP_URL_HOST);
        $url = ($host === 'anypress.io' || substr($host, -strlen('.anypress.io')) === '.anypress.io') ? 'https://app.anypress.io' : '';
        $url = (string) apply_filters('anypress_dashboard_url', $url);

        return $url === '' ? '' : untrailingslashit($url) . $path;
    }

    /** The storefront's name, its website domain, and buttons, above every AnyPress page. */
    public static function render_header(string $title, ?ConfigService $config = null): void
    {
        $root_domain = (string) get_option('anypress_root_domain', '');
        $account_url = (string) apply_filters('anypress_account_page_url', '');
        ?>
        <div class="ap-top">
            <div class="ap-title">
                <img src="<?php echo esc_attr(self::menu_icon()); ?>" alt="" width="36" height="36">
                <div>
                    <h1><?php echo esc_html($title); ?></h1>
                    <p><?php
                        echo esc_html($root_domain !== ''
                            /* translators: 1: this storefront's name, 2: the domain its websites are on */
                            ? sprintf(__('%1$s · sells websites on %2$s', 'anypress-host'), get_bloginfo('name'), $root_domain)
                            : get_bloginfo('name'));
                    ?></p>
                </div>
            </div>
            <div class="ap-btns">
                <?php if ($account_url !== '') : ?>
                    <a class="ap-btn" href="<?php echo esc_url($account_url); ?>"><?php esc_html_e('View account page', 'anypress-host'); ?></a>
                <?php endif; ?>
                <?php
                // The cart can make the plans for the seller, or open them once they exist (FluentCartStarterPlans).
                [$plan_label, $plan_url] = (array) apply_filters('anypress_plan_button', [__('Add a website plan', 'anypress-host'), GettingStartedChecklist::new_plan_product_url()]) + ['', ''];
                ?>
                <a class="ap-btn is-ink" href="<?php echo esc_url($plan_url); ?>"><?php echo esc_html($plan_label); ?></a>
            </div>
        </div>
        <nav class="ap-tabs" aria-label="<?php esc_attr_e('AnyPress pages', 'anypress-host'); ?>">
            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which admin page is open.
            $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
            foreach (self::tabs() as $slug => [$label, $url]) {
                printf('<a href="%s"%s>%s</a>', esc_url($url), $page === $slug ? ' aria-current="page"' : '', esc_html($label));
            }
            ?>
        </nav>
        <?php
    }

    /**
     * The AnyPress pages, as tabs under the header: slug => [label, url]. Filter anypress_admin_tabs adds pages such
     * as Care, which then get the AnyPress look too.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tabs(): array
    {
        $tabs = [
            self::SLUG => [__('Overview', 'anypress-host'), admin_url('admin.php?page=' . self::SLUG)],
        ];
        $tabs = (array) apply_filters('anypress_admin_tabs', $tabs);
        // Settings stays last.
        $tabs[AdminSettings::SLUG] = [__('Settings', 'anypress-host'), AdminSettings::url()];

        return $tabs;
    }

    /** True on the AnyPress admin pages, which show the API's alerts in place instead of as notices. */
    public static function is_plugin_page(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which admin page is open.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        return $page !== '' && array_key_exists($page, self::tabs());
    }

    public function render_anypress_admin_page()
    {
        $checklist_items = apply_filters('anypress_getting_started_checklist', GettingStartedChecklist::default_checklist());
        $done = count(array_filter($checklist_items, fn ($item) => !empty($item['is_done'])));
        $total = count($checklist_items);

        $has_credentials = $this->config_service->check_credentials();
        $reachable = $has_credentials && get_option(\AnyPressHost\Core\ProvisioningApi::CAN_REACH_API_OPTION_NAME, false);
        $websites = AdminWebsites::websites();
        $attention = AdminWebsites::needs_attention();
        $dashboard = $this->dashboard_url();
        $counts = ['live' => 0, 'building' => 0, 'failed' => 0, 'offline' => 0];
        foreach ($websites as $website) {
            $counts[AdminWebsites::state_group($website['state'])]++;
        }
?>
        <div class="wrap ap-admin">
            <?php self::render_header(__('Overview', 'anypress-host'), $this->config_service); ?>
            <hr class="wp-header-end">
            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows the outcome of "Create my plans", which checked its own nonce.
            if (isset($_GET['plans_made'])) :
                $plans_made = absint(wp_unslash($_GET['plans_made'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                ?>
                <div class="ap-banner <?php echo $plans_made ? 'is-ok' : 'is-warn'; ?>" role="status"><span><?php
                    echo esc_html($plans_made
                        /* translators: %d: number of plans made */
                        ? sprintf(_n('Made %d website plan. It is on sale on your storefront now; change its price or picture in FluentCart.', 'Made %d website plans. They are on sale on your storefront now; change any price or picture in FluentCart.', $plans_made, 'anypress-host'), $plans_made)
                        : __('No plans were made. Load your site types first, or check that every site type already has a plan.', 'anypress-host'));
                ?></span></div>
            <?php endif; ?>

            <?php if ($done < $total) : ?>
                <section class="ap-card ap-setup">
                    <div class="ap-card-head">
                        <h2><?php esc_html_e('Set up this storefront', 'anypress-host'); ?></h2>
                        <span class="ap-muted"><?php
                            /* translators: 1: steps done, 2: all steps */
                            echo esc_html(sprintf(__('%1$d of %2$d done', 'anypress-host'), $done, $total));
                        ?></span>
                    </div>
                    <div class="ap-progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo (int) $total; ?>" aria-valuenow="<?php echo (int) $done; ?>"><i style="width:<?php echo (int) round($done / max(1, $total) * 100); ?>%"></i></div>
                    <ol class="ap-steps">
                        <?php
                        $n = 0;
                        $first_todo = 1 + (int) array_search(false, array_map(fn ($item) => !empty($item['is_done']), array_values($checklist_items)), true);
                        foreach ($checklist_items as $item) :
                            $n++;
                            $is_done = !empty($item['is_done']); ?>
                            <li class="<?php echo $is_done ? 'is-done' : 'is-todo'; ?>">
                                <span class="ap-dot" aria-hidden="true"><?php echo $is_done ? '✓' : (int) $n; ?></span>
                                <div>
                                    <strong><?php echo esc_html($item['label'] ?? ''); ?></strong>
                                    <span class="screen-reader-text"><?php echo $is_done ? esc_html__('(done)', 'anypress-host') : esc_html__('(to do)', 'anypress-host'); ?></span>
                                    <?php if (!empty($item['description'])) : ?>
                                        <p><?php echo esc_html($item['description']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$is_done && !empty($item['action_url'])) : ?>
                                    <a class="ap-btn is-sm<?php echo $n === $first_todo ? ' is-ink' : ''; ?>" href="<?php echo esc_url($item['action_url']); ?>"><?php echo esc_html($item['action_label'] ?? __('Set up', 'anypress-host')); ?></a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>

            <?php if ($websites) : ?>
                <div class="ap-tiles">
                    <?php foreach (
    [
                        'live' => __('Live websites', 'anypress-host'),
                        'building' => __('Being built', 'anypress-host'),
                        'failed' => __('Need attention', 'anypress-host'),
                        'offline' => __('Offline', 'anypress-host'),
                    ] as $group => $label
) : ?>
                        <div class="ap-tile<?php echo $group === 'failed' && $counts[$group] ? ' is-alert' : ''; ?>">
                            <b><?php echo (int) $counts[$group]; ?></b><span><?php echo esc_html($label); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="ap-columns">
                <div class="ap-stack">
                    <?php if ($attention) : ?>
                        <section class="ap-card">
                            <h2><?php esc_html_e('Needs attention', 'anypress-host'); ?></h2>
                            <div class="ap-attn">
                                <?php foreach ($attention as $item) :
                                    $order_url = AdminWebsites::admin_subscription_url($item['subscription_id']); ?>
                                    <div class="ap-attn-item">
                                        <div><strong><?php echo esc_html($item['title']); ?></strong><p><?php echo esc_html($item['detail']); ?></p></div>
                                        <div class="ap-btns">
                                            <?php foreach ($item['actions'] as $label => $url) : ?>
                                                <a class="ap-btn is-sm is-ink" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                                            <?php endforeach; ?>
                                            <?php if ($order_url !== '') : ?>
                                                <a class="ap-btn is-sm" href="<?php echo esc_url($order_url); ?>"><?php esc_html_e('Subscription', 'anypress-host'); ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                    <section class="ap-card">
                        <div class="ap-card-head">
                            <h2><?php esc_html_e('Recent website orders', 'anypress-host'); ?></h2>
                            <?php if ($dashboard !== '') : ?>
                                <a class="ap-btn is-sm" href="<?php echo esc_url($dashboard . '/stores'); ?>" target="_blank" rel="noopener"><?php esc_html_e('All websites in the dashboard', 'anypress-host'); ?> <span aria-hidden="true">↗</span></a>
                            <?php endif; ?>
                        </div>
                        <?php if (!$websites) : ?>
                            <p class="ap-empty"><?php esc_html_e('No websites sold yet. When someone buys a website plan, the order shows here while the website is built.', 'anypress-host'); ?></p>
                        <?php else : ?>
                            <table class="ap-list">
                                <thead><tr>
                                    <th scope="col"><?php esc_html_e('Website', 'anypress-host'); ?></th>
                                    <th scope="col"><?php esc_html_e('Customer', 'anypress-host'); ?></th>
                                    <th scope="col"><?php esc_html_e('Status', 'anypress-host'); ?></th>
                                    <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Links', 'anypress-host'); ?></span></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach (array_slice($websites, 0, self::RECENT_ORDERS, true) as $subscription_id => $website) :
                                    $order_url = AdminWebsites::admin_subscription_url($subscription_id); ?>
                                    <tr>
                                        <td class="ap-c-site"><?php echo esc_html($website['name']); ?>
                                            <small><?php
                                                /* translators: %s: subscription number */
                                                echo esc_html(($website['domain'] !== '' ? $website['domain'] . ' · ' : '') . sprintf(__('subscription #%s', 'anypress-host'), $subscription_id));
                                            ?></small></td>
                                        <td class="ap-c-cust"><?php echo esc_html(Commerce::require_adapter()->get_customer_name($subscription_id)); ?></td>
                                        <td class="ap-c-status"><?php echo AdminWebsites::state_pill($website['state']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in state_pill(). ?></td>
                                        <td class="ap-c-act"><span class="ap-btns">
                                            <?php if ($website['domain'] !== '' && AdminWebsites::state_group($website['state']) === 'live') : ?>
                                                <a class="ap-btn is-sm" href="<?php echo esc_url('https://' . $website['domain']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Visit', 'anypress-host'); ?></a>
                                            <?php endif; ?>
                                            <?php if ($order_url !== '') : ?>
                                                <a class="ap-btn is-sm" href="<?php echo esc_url($order_url); ?>"><?php esc_html_e('Subscription', 'anypress-host'); ?></a>
                                            <?php endif; ?>
                                        </span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </section>
                </div>

                <section class="ap-card ap-health">
                    <h2><?php esc_html_e('Connection', 'anypress-host'); ?></h2>
                    <?php $this->render_health($has_credentials, $reachable); ?>
                </section>
            </div>
        </div>
<?php
    }

    private function render_health(bool $has_credentials, bool $reachable): void
    {
        $webhook = Webhooks::stored();
        $roles = (array) get_option(PluginBootstrap::ROLES_WP_OPTION, []);
        $root_domain = (string) get_option('anypress_root_domain', '');
        $dns_set = $this->config_service->get_dns_a_records() && $this->config_service->get_dns_cname_target() !== '';
        // A ready-made storefront's addresses are AnyPress's to set, so it gets no button to a page it doesn't have.
        $fix_addresses = AdminSettings::is_ready_made()
            ? '<span class="ap-pill">' . esc_html__('Set by AnyPress', 'anypress-host') . '</span>'
            : '<a class="ap-btn is-sm" href="' . esc_url(AdminSettings::url('addresses')) . '">' . esc_html__('Set', 'anypress-host') . '</a>';

        $rows = [];
        if (!$has_credentials) {
            $rows[] = [__('AnyPress API', 'anypress-host'), __('Add your API key and secret', 'anypress-host'), '<a class="ap-btn is-sm" href="' . esc_url(AdminSettings::url('connection')) . '">' . esc_html__('Connect', 'anypress-host') . '</a>'];
        } else {
            $rows[] = [
                __('AnyPress API', 'anypress-host'),
                (string) wp_parse_url((string) $this->config_service->get_api_base_url(), PHP_URL_HOST),
                $reachable ? '<span class="ap-pill is-ok">' . esc_html__('Connected', 'anypress-host') . '</span>' : '<span class="ap-pill is-bad">' . esc_html__('Can\'t connect', 'anypress-host') . '</span>',
            ];
            $rows[] = [
                __('Live updates', 'anypress-host'),
                Webhooks::is_registered()
                    ? ($webhook['last_event_at']
                        /* translators: %s: how long ago, such as "4 mins" */
                        ? sprintf(__('Last event %s ago', 'anypress-host'), human_time_diff((int) $webhook['last_event_at']))
                        : __('No events yet', 'anypress-host'))
                    : __('Websites being built are checked every few seconds instead', 'anypress-host'),
                Webhooks::is_registered() ? '<span class="ap-pill is-ok">' . esc_html__('On', 'anypress-host') . '</span>' : '<span class="ap-pill is-warn">' . esc_html__('Off', 'anypress-host') . '</span>',
            ];
        }
        $rows[] = [
            __('Site types', 'anypress-host'),
            $roles
                /* translators: %d: number of site types */
                ? sprintf(_n('%d site type loaded', '%d site types loaded', count($roles), 'anypress-host'), count($roles))
                : __('None loaded yet', 'anypress-host'),
            '<a class="ap-btn is-sm" href="' . esc_url(\AnyPressHost\Api\RolesController::get_refresh_url()) . '">' . esc_html__('Reload', 'anypress-host') . '</a>',
        ];
        $rows[] = [
            __('Website addresses', 'anypress-host'),
            $root_domain !== '' ? '*.' . $root_domain : __('Not set', 'anypress-host'),
            $root_domain !== '' ? '<span class="ap-pill is-ok">' . esc_html__('Set', 'anypress-host') . '</span>' : $fix_addresses,
        ];
        $rows[] = [
            __('Custom domains', 'anypress-host'),
            $dns_set ? __('DNS records shown to customers', 'anypress-host') : __('Customers get no DNS records to add', 'anypress-host'),
            $dns_set ? '<span class="ap-pill is-ok">' . esc_html__('Set', 'anypress-host') . '</span>' : $fix_addresses,
        ];
        $versions = 'AnyPress Host ' . (defined('ANYPRESS_HOST_VERSION') ? ANYPRESS_HOST_VERSION : '');
        if (defined('ANYPRESS_PAYMENTS_VERSION')) {
            $versions .= ' · AnyPress Payments ' . ANYPRESS_PAYMENTS_VERSION;
        }
        $rows[] = [__('Versions', 'anypress-host'), $versions, ''];

        foreach ($rows as [$label, $detail, $status]) {
            echo '<div class="ap-health-row"><div>' . esc_html($label) . '<small>' . esc_html($detail) . '</small></div><div>'
                . wp_kses($status, ['span' => ['class' => true], 'a' => ['class' => true, 'href' => true]]) . '</div></div>';
        }
    }
}
