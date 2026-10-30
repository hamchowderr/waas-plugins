<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\StoreProduct;
use WaaSHost\Core\Tenant;

/**
 * The AnyPress menu and its Overview page: setup steps until the storefront is ready, then what the seller needs
 * day to day (website orders, websites that were paid for but not built, the connection). Running the websites
 * themselves (suspend, backups, domains) happens in the AnyPress dashboard, which this page links to.
 */
class AdminHome
{
    public const SLUG = 'waas-admin';
    /** How many of the newest website orders the Overview lists. */
    public const RECENT_ORDERS = 8;

    private ConfigService $config_service;

    public function __construct($config_service)
    {
        $this->config_service = $config_service;

        add_action('admin_menu', [$this, 'add_waas_admin_page'], 11);
        add_filter('fluent_toolkit/unified_apps', [$this, 'add_fluent_toolkit_app']);

        add_filter('waas_getting_started_checklist', [$this, 'check_api_creds']);
        add_filter('waas_getting_started_checklist', [$this, 'check_required_plugins']);
        add_filter('waas_getting_started_checklist', [$this, 'check_tenant_roles']);
        add_filter('waas_getting_started_checklist', [$this, 'check_woo_waas_product']);
    }

    public function check_api_creds($checklist_items)
    {
        $checklist_items['api_credentials']['is_done'] = $this->config_service->check_credentials();
        if (ConfigService::has_constant('API_KEY') && ConfigService::has_constant('API_SECRET')) {
            $checklist_items['api_credentials']['description'] = __('Connected for you when this storefront was made.', 'waas-host');
        }
        return $checklist_items;
    }

    public function check_required_plugins($checklist_items)
    {
        // Is WooCommerce installed and active?
        $woocommerce_active = is_plugin_active('woocommerce/woocommerce.php');

        $subs_for_wc_active = is_plugin_active('subscriptions-for-woocommerce/subscriptions-for-woocommerce.php');
        $wc_subs_active = is_plugin_active('woocommerce-subscriptions/woocommerce-subscriptions.php');

        // FluentCart has subscriptions built in; WooCommerce needs one of the two subscription plugins.
        $fluentcart_active = defined('FLUENTCART_VERSION');

        $checklist_items['required_plugins_installed']['is_done'] = $fluentcart_active || ($woocommerce_active && ($subs_for_wc_active || $wc_subs_active));

        return $checklist_items;
    }

    public function check_tenant_roles($checklist_items)
    {
        $roles = get_option(PluginBootstrap::ROLES_WP_OPTION);
        $checklist_items['setup_tenant_roles']['is_done'] = $roles && count(array_keys((array)$roles)) > 0;

        return $checklist_items;
    }

    public function check_woo_waas_product($checklist_items)
    {
        // WooCommerce products only; other carts' adapters mark this item done through the same filter.
        if (!function_exists('wc_get_product')) {
            return $checklist_items;
        }

        $products = get_posts([
            'post_type' => 'product',
            'meta_query' => [StoreProduct::get_waas_product_meta_query()],
        ]);

        $checklist_items['create_woo_waas_product']['is_done'] = count($products) > 0;

        return $checklist_items;
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

    public function add_waas_admin_page()
    {
        $cap = 'manage_options';
        add_menu_page(
            __('AnyPress', 'waas-host'),
            __('AnyPress', 'waas-host'),
            $cap,
            self::SLUG,
            [$this, 'render_waas_admin_page'],
            self::menu_icon(),
            10
        );

        add_submenu_page(
            self::SLUG,
            __('AnyPress', 'waas-host'),
            __('Overview', 'waas-host'),
            $cap,
            self::SLUG,
            [$this, 'render_waas_admin_page']
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
        $icon = plugins_url('assets/images/anypress-icon.svg', dirname(__DIR__, 2) . '/index.php');
        $apps[self::SLUG] = [
            'disabled' => !current_user_can('manage_options'),
            'title' => __('AnyPress', 'waas-host'),
            'icon' => $icon,
            'logo' => $icon,
            'items' => [
                'overview' => ['title' => __('Overview', 'waas-host'), 'url' => admin_url('admin.php?page=' . self::SLUG)],
                'settings' => ['title' => __('Settings', 'waas-host'), 'url' => AdminSettings::url()],
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

    /** Where the store owner creates a plan product: the active cart's product editor. */
    private static function new_plan_product_url(): string
    {
        if (defined('FLUENTCART_VERSION')) {
            return admin_url('admin.php?page=fluent-cart#/products');
        }

        return admin_url('post-new.php?post_type=product');
    }

    /**
     * The AnyPress dashboard, where sellers run their websites. Empty for a storefront on another provisioning API,
     * which has no such dashboard. Filter: waas_dashboard_url.
     */
    public function dashboard_url(string $path = ''): string
    {
        $host = (string) wp_parse_url((string) $this->config_service->get_api_base_url(), PHP_URL_HOST);
        $url = ($host === 'anypress.io' || substr($host, -strlen('.anypress.io')) === '.anypress.io') ? 'https://app.anypress.io' : '';
        $url = (string) apply_filters('waas_dashboard_url', $url);

        return $url === '' ? '' : untrailingslashit($url) . $path;
    }

    public static function default_checklist(): array
    {
        return [
            "api_credentials" => [
                "label" => __('Connect to AnyPress', 'waas-host'),
                "description" => __('Paste the API key and secret for this storefront. They are on the API keys page of your AnyPress dashboard.', 'waas-host'),
                "action_label" => __('Connect', 'waas-host'),
                "action_url" => AdminSettings::url('connection'),
                "is_done" => false,
            ],
            "required_plugins_installed" => [
                "label" => __('Install a cart', 'waas-host'),
                "description" => __('FluentCart, or WooCommerce with WooCommerce Subscriptions (or Subscriptions for WooCommerce), takes the orders and the renewals.', 'waas-host'),
                "action_label" => __('Add FluentCart', 'waas-host'),
                "action_url" => admin_url('plugin-install.php?s=fluentcart&tab=search&type=term'),
                "is_done" => false,
            ],
            "setup_tenant_roles" => [
                "label" => __('Load your plans', 'waas-host'),
                "description" => __('Each plan decides which plugins a website gets. The plans are set up on your template website and loaded from there.', 'waas-host'),
                "action_label" => __('Load plans', 'waas-host'),
                "action_url" => \WaaSHost\Api\RolesController::get_refresh_url(),
                "is_done" => false,
            ],
            "create_woo_waas_product" => [
                "label" => __('Create your website plans', 'waas-host'),
                "description" => defined('FLUENTCART_VERSION')
                    ? __('Each plan is a FluentCart subscription product. Buying one creates the customer\'s website.', 'waas-host')
                    : __('A subscription product marked "WaaS product". Buying it creates the customer\'s website.', 'waas-host'),
                "action_label" => __('Add a plan', 'waas-host'),
                "action_url" => self::new_plan_product_url(),
                "is_done" => false,
            ],
        ];
    }

    /** The storefront's name, its website domain, and buttons, above every AnyPress page. */
    public static function render_header(string $title, ConfigService $config): void
    {
        $root_domain = (string) get_option('waas_root_domain', '');
        $account_url = (string) apply_filters('waas_account_page_url', '');
        ?>
        <div class="ap-top">
            <div class="ap-title">
                <img src="<?php echo esc_attr(self::menu_icon()); ?>" alt="" width="36" height="36">
                <div>
                    <h1><?php echo esc_html($title); ?></h1>
                    <p><?php
                        echo esc_html($root_domain !== ''
                            /* translators: 1: this storefront's name, 2: the domain its websites are on */
                            ? sprintf(__('%1$s · sells websites on %2$s', 'waas-host'), get_bloginfo('name'), $root_domain)
                            : get_bloginfo('name'));
                    ?></p>
                </div>
            </div>
            <div class="ap-btns">
                <?php if ($account_url !== '') : ?>
                    <a class="ap-btn" href="<?php echo esc_url($account_url); ?>"><?php esc_html_e('View account page', 'waas-host'); ?></a>
                <?php endif; ?>
                <?php
                // The cart can make the plans for the seller, or open them once they exist (FluentCartStarterPlans).
                [$plan_label, $plan_url] = (array) apply_filters('waas_plan_button', [__('Add a website plan', 'waas-host'), self::new_plan_product_url()]) + ['', ''];
                ?>
                <a class="ap-btn is-ink" href="<?php echo esc_url($plan_url); ?>"><?php echo esc_html($plan_label); ?></a>
            </div>
        </div>
        <nav class="ap-tabs" aria-label="<?php esc_attr_e('AnyPress pages', 'waas-host'); ?>">
            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which admin page is open.
            $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
            foreach ([self::SLUG => [__('Overview', 'waas-host'), admin_url('admin.php?page=' . self::SLUG)], AdminSettings::SLUG => [__('Settings', 'waas-host'), AdminSettings::url()]] as $slug => [$label, $url]) {
                printf('<a href="%s"%s>%s</a>', esc_url($url), $page === $slug ? ' aria-current="page"' : '', esc_html($label));
            }
            ?>
        </nav>
        <?php
    }

    /** True on the AnyPress admin pages, which show the API's alerts in place instead of as notices. */
    public static function is_plugin_page(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which admin page is open.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        return in_array($page, [self::SLUG, AdminSettings::SLUG], true);
    }

    /**
     * Websites this storefront sold, newest first: subscription id => [name, domain, state, failed].
     *
     * @return array<int|string, array{name: string, domain: string, state: string}>
     */
    public static function websites(): array
    {
        $commerce = Commerce::adapter();
        if ($commerce === null) {
            return [];
        }

        $websites = [];
        foreach ($commerce->find_subscriptions_by_meta(Tenant::WEBSITE_NAME_META, ['']) as $subscription_id => $name) {
            $failed = $commerce->get_subscription_meta($subscription_id, StoreJobs::FAILURE_META);
            $websites[$subscription_id] = [
                'name' => (string) $name,
                'domain' => (string) $commerce->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META),
                'state' => is_array($failed) && $failed ? Tenant::FAILED : (string) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_STATE),
            ];
        }
        uksort($websites, fn ($a, $b) => is_numeric($a) && is_numeric($b) ? (int) $b <=> (int) $a : strcmp((string) $b, (string) $a));

        return $websites;
    }

    /** Which count a website's state goes into on the Overview: live, building, failed or offline. */
    public static function state_group(string $state): string
    {
        switch ($state) {
            case Tenant::READY:
                return 'live';
            case Tenant::FAILED:
            case Tenant::NEEDS_NEW_NAME:
                return 'failed';
            case Tenant::SUSPENDED:
            case Tenant::DELETED:
                return 'offline';
            default:
                return 'building';
        }
    }

    /** A website's state as a pill: what the seller should read, not the API's internal name. */
    public static function state_pill(string $state): string
    {
        $labels = [
            'live' => __('Live', 'waas-host'),
            'building' => __('Building', 'waas-host'),
            'failed' => $state === Tenant::NEEDS_NEW_NAME ? __('Needs a new name', 'waas-host') : __('Failed', 'waas-host'),
            'offline' => $state === Tenant::DELETED ? __('Deleted', 'waas-host') : __('Offline', 'waas-host'),
        ];
        $group = self::state_group($state);
        $class = ['live' => 'is-ok', 'building' => 'is-warn', 'failed' => 'is-bad', 'offline' => ''][$group];

        return '<span class="ap-pill ' . esc_attr($class) . '">' . esc_html($labels[$group]) . '</span>';
    }

    /**
     * What needs the seller: store jobs that kept failing (Try again) and changes the API reports it could not
     * finish (Dismiss once checked).
     *
     * @return array<int, array{subscription_id: int|string, title: string, detail: string, actions: array<string, string>}>
     */
    public static function needs_attention(): array
    {
        $commerce = Commerce::adapter();
        if ($commerce === null) {
            return [];
        }

        $items = [];
        foreach ($commerce->find_subscriptions_by_meta(StoreJobs::FAILURE_META, ['']) as $subscription_id => $failure) {
            if (!is_array($failure) || !$failure) {
                continue;
            }
            $items[] = [
                'subscription_id' => $subscription_id,
                'title' => self::website_label($subscription_id),
                'detail' => sprintf(
                    /* translators: 1: date and time, 2: number of tries, 3: the API's error message */
                    __('Couldn\'t be set up or updated. Last try %1$s, after %2$d tries: %3$s', 'waas-host'),
                    wp_date('j M H:i', (int) ($failure['at'] ?? 0)),
                    (int) ($failure['attempts'] ?? 0),
                    (string) ($failure['error'] ?? '')
                ),
                'actions' => [
                    __('Try again', 'waas-host') => wp_nonce_url(
                        admin_url('admin-post.php?action=' . StoreJobs::RETRY_ACTION . '&subscription_id=' . rawurlencode((string) $subscription_id)),
                        StoreJobs::RETRY_ACTION . '_' . $subscription_id
                    ),
                ],
            ];
        }
        foreach ($commerce->find_subscriptions_by_meta(Webhooks::TENANT_FAILURE_META, ['']) as $subscription_id => $failure) {
            if (!is_array($failure) || !$failure) {
                continue;
            }
            $items[] = [
                'subscription_id' => $subscription_id,
                'title' => self::website_label($subscription_id),
                'detail' => sprintf(
                    /* translators: 1: what the API was doing, such as "restore", 2: date and time */
                    __('The API couldn\'t finish "%1$s" (%2$s). Check the website in the AnyPress dashboard, then dismiss this.', 'waas-host'),
                    (string) ($failure['action'] ?? ''),
                    wp_date('j M H:i', (int) ($failure['at'] ?? 0))
                ),
                'actions' => [
                    __('Dismiss', 'waas-host') => wp_nonce_url(
                        admin_url('admin-post.php?action=' . Webhooks::DISMISS_FAILURE_ACTION . '&subscription_id=' . rawurlencode((string) $subscription_id)),
                        Webhooks::DISMISS_FAILURE_ACTION . '_' . $subscription_id
                    ),
                ],
            ];
        }

        return $items;
    }

    /** "Order #12 · shop.example.com", or the website name while it has no address yet. */
    private static function website_label($subscription_id): string
    {
        $commerce = Commerce::require_adapter();
        $domain = (string) $commerce->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META);
        $name = $domain !== '' ? $domain : (string) $commerce->get_subscription_meta($subscription_id, Tenant::WEBSITE_NAME_META);

        /* translators: 1: subscription number, 2: the website's address */
        return sprintf(__('Subscription #%1$s · %2$s', 'waas-host'), $subscription_id, $name);
    }

    /** The subscription in the cart's own admin, where it has one. */
    public static function admin_subscription_url($subscription_id): string
    {
        $commerce = Commerce::adapter();
        if ($commerce !== null && method_exists($commerce, 'get_admin_subscription_url')) {
            return (string) $commerce->get_admin_subscription_url($subscription_id);
        }

        return '';
    }

    public function render_waas_admin_page()
    {
        $checklist_items = apply_filters('waas_getting_started_checklist', self::default_checklist());
        $done = count(array_filter($checklist_items, fn ($item) => !empty($item['is_done'])));
        $total = count($checklist_items);

        $has_credentials = $this->config_service->check_credentials();
        $reachable = $has_credentials && get_option(\WaaSHost\Core\ProvisioningApi::CAN_REACH_API_OPTION_NAME, false);
        $websites = self::websites();
        $attention = self::needs_attention();
        $dashboard = $this->dashboard_url();
        $counts = ['live' => 0, 'building' => 0, 'failed' => 0, 'offline' => 0];
        foreach ($websites as $website) {
            $counts[self::state_group($website['state'])]++;
        }
?>
        <div class="wrap ap-admin">
            <?php self::render_header(__('Overview', 'waas-host'), $this->config_service); ?>
            <hr class="wp-header-end">
            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows the outcome of "Create my plans", which checked its own nonce.
            if (isset($_GET['plans_made'])) :
                $plans_made = absint(wp_unslash($_GET['plans_made'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                ?>
                <div class="ap-banner <?php echo $plans_made ? 'is-ok' : 'is-warn'; ?>" role="status"><span><?php
                    echo esc_html($plans_made
                        /* translators: %d: number of plans made */
                        ? sprintf(_n('Made %d website plan. It is on sale on your storefront now; change its price or picture in FluentCart.', 'Made %d website plans. They are on sale on your storefront now; change any price or picture in FluentCart.', $plans_made, 'waas-host'), $plans_made)
                        : __('No plans were made. Load your plans first, or check that every plan already has a product.', 'waas-host'));
                ?></span></div>
            <?php endif; ?>

            <?php if ($done < $total) : ?>
                <section class="ap-card ap-setup">
                    <div class="ap-card-head">
                        <h2><?php esc_html_e('Set up this storefront', 'waas-host'); ?></h2>
                        <span class="ap-muted"><?php
                            /* translators: 1: steps done, 2: all steps */
                            echo esc_html(sprintf(__('%1$d of %2$d done', 'waas-host'), $done, $total));
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
                                    <span class="screen-reader-text"><?php echo $is_done ? esc_html__('(done)', 'waas-host') : esc_html__('(to do)', 'waas-host'); ?></span>
                                    <?php if (!empty($item['description'])) : ?>
                                        <p><?php echo esc_html($item['description']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$is_done && !empty($item['action_url'])) : ?>
                                    <a class="ap-btn is-sm<?php echo $n === $first_todo ? ' is-ink' : ''; ?>" href="<?php echo esc_url($item['action_url']); ?>"><?php echo esc_html($item['action_label'] ?? __('Set up', 'waas-host')); ?></a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>

            <?php if ($websites) : ?>
                <div class="ap-tiles">
                    <?php foreach ([
                        'live' => __('Live websites', 'waas-host'),
                        'building' => __('Being built', 'waas-host'),
                        'failed' => __('Need attention', 'waas-host'),
                        'offline' => __('Offline', 'waas-host'),
                    ] as $group => $label) : ?>
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
                            <h2><?php esc_html_e('Needs attention', 'waas-host'); ?></h2>
                            <div class="ap-attn">
                                <?php foreach ($attention as $item) :
                                    $order_url = self::admin_subscription_url($item['subscription_id']); ?>
                                    <div class="ap-attn-item">
                                        <div><strong><?php echo esc_html($item['title']); ?></strong><p><?php echo esc_html($item['detail']); ?></p></div>
                                        <div class="ap-btns">
                                            <?php foreach ($item['actions'] as $label => $url) : ?>
                                                <a class="ap-btn is-sm is-ink" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                                            <?php endforeach; ?>
                                            <?php if ($order_url !== '') : ?>
                                                <a class="ap-btn is-sm" href="<?php echo esc_url($order_url); ?>"><?php esc_html_e('Subscription', 'waas-host'); ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                    <section class="ap-card">
                        <div class="ap-card-head">
                            <h2><?php esc_html_e('Recent website orders', 'waas-host'); ?></h2>
                            <?php if ($dashboard !== '') : ?>
                                <a class="ap-btn is-sm" href="<?php echo esc_url($dashboard . '/stores'); ?>" target="_blank" rel="noopener"><?php esc_html_e('All websites in the dashboard', 'waas-host'); ?> <span aria-hidden="true">↗</span></a>
                            <?php endif; ?>
                        </div>
                        <?php if (!$websites) : ?>
                            <p class="ap-empty"><?php esc_html_e('No websites sold yet. When someone buys a website plan, the order shows here while the website is built.', 'waas-host'); ?></p>
                        <?php else : ?>
                            <table class="ap-list">
                                <thead><tr>
                                    <th scope="col"><?php esc_html_e('Website', 'waas-host'); ?></th>
                                    <th scope="col"><?php esc_html_e('Customer', 'waas-host'); ?></th>
                                    <th scope="col"><?php esc_html_e('Status', 'waas-host'); ?></th>
                                    <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Links', 'waas-host'); ?></span></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach (array_slice($websites, 0, self::RECENT_ORDERS, true) as $subscription_id => $website) :
                                    $order_url = self::admin_subscription_url($subscription_id); ?>
                                    <tr>
                                        <td class="ap-c-site"><?php echo esc_html($website['name']); ?>
                                            <small><?php
                                                /* translators: %s: subscription number */
                                                echo esc_html(($website['domain'] !== '' ? $website['domain'] . ' · ' : '') . sprintf(__('subscription #%s', 'waas-host'), $subscription_id));
                                            ?></small></td>
                                        <td class="ap-c-cust"><?php echo esc_html(Commerce::require_adapter()->get_customer_name($subscription_id)); ?></td>
                                        <td class="ap-c-status"><?php echo self::state_pill($website['state']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in state_pill(). ?></td>
                                        <td class="ap-c-act"><span class="ap-btns">
                                            <?php if ($website['domain'] !== '' && self::state_group($website['state']) === 'live') : ?>
                                                <a class="ap-btn is-sm" href="<?php echo esc_url('https://' . $website['domain']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Visit', 'waas-host'); ?></a>
                                            <?php endif; ?>
                                            <?php if ($order_url !== '') : ?>
                                                <a class="ap-btn is-sm" href="<?php echo esc_url($order_url); ?>"><?php esc_html_e('Subscription', 'waas-host'); ?></a>
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
                    <h2><?php esc_html_e('Connection', 'waas-host'); ?></h2>
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
        $root_domain = (string) get_option('waas_root_domain', '');
        $dns_set = $this->config_service->get_dns_a_records() && $this->config_service->get_dns_cname_target() !== '';
        // A ready-made storefront's addresses are AnyPress's to set, so it gets no button to a page it doesn't have.
        $fix_addresses = AdminSettings::is_ready_made()
            ? '<span class="ap-pill">' . esc_html__('Set by AnyPress', 'waas-host') . '</span>'
            : '<a class="ap-btn is-sm" href="' . esc_url(AdminSettings::url('addresses')) . '">' . esc_html__('Set', 'waas-host') . '</a>';

        $rows = [];
        if (!$has_credentials) {
            $rows[] = [__('AnyPress API', 'waas-host'), __('Add your API key and secret', 'waas-host'), '<a class="ap-btn is-sm" href="' . esc_url(AdminSettings::url('connection')) . '">' . esc_html__('Connect', 'waas-host') . '</a>'];
        } else {
            $rows[] = [
                __('AnyPress API', 'waas-host'),
                (string) wp_parse_url((string) $this->config_service->get_api_base_url(), PHP_URL_HOST),
                $reachable ? '<span class="ap-pill is-ok">' . esc_html__('Connected', 'waas-host') . '</span>' : '<span class="ap-pill is-bad">' . esc_html__('Can\'t connect', 'waas-host') . '</span>',
            ];
            $rows[] = [
                __('Live updates', 'waas-host'),
                Webhooks::is_registered()
                    ? ($webhook['last_event_at']
                        /* translators: %s: how long ago, such as "4 mins" */
                        ? sprintf(__('Last event %s ago', 'waas-host'), human_time_diff((int) $webhook['last_event_at']))
                        : __('No events yet', 'waas-host'))
                    : __('Websites being built are checked every few seconds instead', 'waas-host'),
                Webhooks::is_registered() ? '<span class="ap-pill is-ok">' . esc_html__('On', 'waas-host') . '</span>' : '<span class="ap-pill is-warn">' . esc_html__('Off', 'waas-host') . '</span>',
            ];
        }
        $rows[] = [
            __('Plans', 'waas-host'),
            $roles
                /* translators: %d: number of plans */
                ? sprintf(_n('%d plan loaded', '%d plans loaded', count($roles), 'waas-host'), count($roles))
                : __('None loaded yet', 'waas-host'),
            '<a class="ap-btn is-sm" href="' . esc_url(\WaaSHost\Api\RolesController::get_refresh_url()) . '">' . esc_html__('Reload', 'waas-host') . '</a>',
        ];
        $rows[] = [
            __('Website addresses', 'waas-host'),
            $root_domain !== '' ? '*.' . $root_domain : __('Not set', 'waas-host'),
            $root_domain !== '' ? '<span class="ap-pill is-ok">' . esc_html__('Set', 'waas-host') . '</span>' : $fix_addresses,
        ];
        $rows[] = [
            __('Custom domains', 'waas-host'),
            $dns_set ? __('DNS records shown to customers', 'waas-host') : __('Customers get no DNS records to add', 'waas-host'),
            $dns_set ? '<span class="ap-pill is-ok">' . esc_html__('Set', 'waas-host') . '</span>' : $fix_addresses,
        ];
        $versions = 'WaaS Host ' . (defined('WAAS_HOST_VERSION') ? WAAS_HOST_VERSION : '');
        if (defined('WAAS_PAYMENTS_VERSION')) {
            $versions .= ' · WaaS Payments ' . WAAS_PAYMENTS_VERSION;
        }
        $rows[] = [__('Versions', 'waas-host'), $versions, ''];

        foreach ($rows as [$label, $detail, $status]) {
            echo '<div class="ap-health-row"><div>' . esc_html($label) . '<small>' . esc_html($detail) . '</small></div><div>'
                . wp_kses($status, ['span' => ['class' => true], 'a' => ['class' => true, 'href' => true]]) . '</div></div>';
        }
    }
}
