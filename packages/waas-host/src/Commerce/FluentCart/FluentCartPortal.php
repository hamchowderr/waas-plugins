<?php

namespace WaaSHost\Commerce\FluentCart;

use WaaSHost\Core\Tenant;
use WaaSHost\Features\AccountStyles;
use WaaSHost\Features\NameConflicts;
use WaaSHost\Features\Notifications;
use WaaSHost\Features\TenantsSubscriptionManager;
use WaaSHost\Features\SingleLoginService;
use WaaSHost\Features\UserAccountSubscriptionsSettings;

/**
 * The customer's websites in FluentCart's customer portal: a "My website" tab (right after
 * Dashboard) with status, one-click login and the domain form, a notice above every other
 * portal page while a website is ready, and links on each subscription. The form lives on
 * its own tab because the subscription panel strips form inputs.
 */
class FluentCartPortal
{
    public const ENDPOINT = 'website';

    /** Lucide "globe", in the 20px outline style of FluentCart's own menu icons. */
    private const ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>';

    private FluentCartAdapter $adapter;
    private FluentCartStore $store;
    private UserAccountSubscriptionsSettings $domain_settings;
    private ?FluentCartEmailPacks $email_packs;

    public function __construct(FluentCartAdapter $adapter, FluentCartStore $store, UserAccountSubscriptionsSettings $domain_settings, ?FluentCartEmailPacks $email_packs = null)
    {
        $this->adapter = $adapter;
        $this->store = $store;
        $this->domain_settings = $domain_settings;
        $this->email_packs = $email_packs;

        add_action('fluent_cart/init', [$this, 'register_endpoint']);
        add_filter('fluent_cart/customer_portal/subscription_data', [$this, 'add_subscription_actions'], 10, 2);
        add_filter('waas_choose_website_name_url', [$this, 'website_page_url']);
        add_filter('waas_website_page_url', [$this, 'website_page_url']);
        // FluentCart puts custom tabs just before Profile; this runs after that and moves ours up.
        add_filter('fluent_cart/global_customer_menu_items', [$this, 'move_menu_item_after_dashboard'], 20);
        // Before FluentCart's own content (priority 10), so the notice sits above the page.
        add_action('fluent_cart/customer_app', [$this, 'render_ready_notice'], 5);
        // The AnyPress look and a narrower page, on every portal page.
        add_action('fluent_cart/customer_app', [AccountStyles::class, 'print_once'], 1);
    }

    /**
     * The Website page, for links in emails (website ready, choose a new name): a customer who is logged out logs in
     * through the portal, which then goes to its redirect_to (by default the portal's first page).
     */
    public function website_page_url(): string
    {
        $url = $this->store->get_portal_url(self::ENDPOINT);

        return add_query_arg('redirect_to', rawurlencode($url), $url);
    }

    public function register_endpoint()
    {
        if (!function_exists('fluent_cart_api')) {
            return;
        }

        fluent_cart_api()->addCustomerDashboardEndpoint(self::ENDPOINT, [
            'title' => __('My website', WAAS_HOST_TEXTDOMAIN),
            'icon_svg' => self::ICON_SVG,
            'render_callback' => [$this, 'render'],
        ]);
    }

    /** @param array<string, array> $items FluentCart's customer menu, keyed by slug. */
    public function move_menu_item_after_dashboard($items)
    {
        if (!is_array($items) || !isset($items[self::ENDPOINT], $items['dashboard'])) {
            return $items;
        }

        $website = $items[self::ENDPOINT];
        unset($items[self::ENDPOINT]);

        $moved = [];
        foreach ($items as $slug => $item) {
            $moved[$slug] = $item;
            if ($slug === 'dashboard') {
                $moved[self::ENDPOINT] = $website;
            }
        }

        return $moved;
    }

    /** A short notice above the other portal pages for each ready website, with its login and the Website tab. */
    public function render_ready_notice()
    {
        if (apply_filters('fluent_cart/customer_portal/active_tab', '') === self::ENDPOINT) {
            return; // the Website tab shows it all already
        }

        foreach ($this->current_user_websites() as $subscription_id) {
            if ($this->adapter->get_subscription_meta($subscription_id, Tenant::TENANT_STATE) !== Tenant::READY) {
                continue;
            }
            $domain = $this->adapter->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META)
                ?: $this->adapter->get_subscription_meta($subscription_id, Tenant::BASE_DOMAIN_NAME_META);

            AccountStyles::print_once();
            echo '<div class="waas-website-ready-notice waas-acct-notice">';
            $name = '<strong>' . esc_html((string) ($domain ?: __('Your website', WAAS_HOST_TEXTDOMAIN))) . '</strong>';
            $suspend_at = $this->suspend_at($subscription_id);
            echo '<span class="waas-acct-status is-' . ($suspend_at ? 'attention' : 'ready') . '"><i aria-hidden="true"></i>'
                . esc_html($suspend_at ? __('Cancelled', WAAS_HOST_TEXTDOMAIN) : __('Online', WAAS_HOST_TEXTDOMAIN)) . '</span>';
            echo '<p>' . ($suspend_at
                ? sprintf(
                    /* translators: 1: the website's domain, 2: date */
                    esc_html__('Your website %1$s is active until %2$s.', WAAS_HOST_TEXTDOMAIN),
                    $name,
                    esc_html(Notifications::format_date($suspend_at))
                )
                : sprintf(
                    /* translators: %s: the website's domain */
                    esc_html__('Your website %s is ready.', WAAS_HOST_TEXTDOMAIN),
                    $name
                )) . '</p>';
            echo '<div class="waas-acct-actions">';
            echo '<a class="waas-acct-btn is-primary fct-btn fct-btn-primary" target="_blank" rel="noopener" href="' . esc_url(SingleLoginService::get_login_link($subscription_id)) . '">'
                . esc_html__('Log in to your website', WAAS_HOST_TEXTDOMAIN) . '</a>';
            echo '<a class="waas-acct-btn" href="' . esc_url($this->store->get_portal_url(self::ENDPOINT)) . '">' . esc_html__('Manage website', WAAS_HOST_TEXTDOMAIN) . '</a>';
            echo '</div>';
            echo '</div>';
        }
    }

    /** Subscriptions of the current user. @return int[] */
    private function current_user_subscription_ids(): array
    {
        return $this->adapter->get_customer_subscription_ids(get_current_user_id());
    }

    /** Subscriptions of the current user that have a website. @return int[] */
    public function current_user_websites(): array
    {
        return array_values(array_filter($this->current_user_subscription_ids(), function ($subscription_id) {
            return Tenant::shown_to_customer(
                $this->adapter->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META),
                $this->adapter->get_subscription_meta($subscription_id, Tenant::TENANT_STATE)
            );
        }));
    }

    public function render()
    {
        $websites = $this->current_user_websites();

        // A new name submitted for a website that is no longer listed (its subscription stopped meanwhile).
        $result_for = NameConflicts::result_subscription_id();
        if ($result_for !== null && !in_array((int) $result_for, $websites, true) && in_array((int) $result_for, $this->current_user_subscription_ids(), true)) {
            NameConflicts::render_result($result_for);
        }

        if (!$websites) {
            echo '<p>' . esc_html__('You have no websites yet. They appear here a few minutes after you subscribe to a website plan.', WAAS_HOST_TEXTDOMAIN) . '</p>';
            return;
        }

        AccountStyles::print_once();
        echo '<div class="waas-acct">';
        foreach ($websites as $subscription_id) {
            $this->render_website($subscription_id);
        }
        echo '</div>';
    }

    /** One website's card: its status in a word and a line, the main buttons, then its settings folded away. */
    private function render_website($subscription_id): void
    {
        $domain = $this->adapter->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META)
            ?: $this->adapter->get_subscription_meta($subscription_id, Tenant::BASE_DOMAIN_NAME_META);
        $status = $this->adapter->get_subscription_meta($subscription_id, Tenant::TENANT_STATE) ?: Tenant::PROVISIONING;
        $suspend_at = $status === Tenant::READY ? $this->suspend_at($subscription_id) : 0;
        [$tone, $label, $lead] = $this->describe($status, $suspend_at);

        echo '<section class="waas-acct-site waas-website">';
        echo '<span class="waas-acct-status is-' . esc_attr($tone) . '"><i aria-hidden="true"></i>'
            . '<span class="screen-reader-text">' . esc_html__('Status:', WAAS_HOST_TEXTDOMAIN) . ' </span>' . esc_html($label) . '</span>';
        echo '<h3>' . esc_html((string) ($domain ?: __('Your website', WAAS_HOST_TEXTDOMAIN))) . '</h3>';
        if ($domain && $status === Tenant::READY) {
            echo '<a class="waas-acct-visit" target="_blank" rel="noopener" href="' . esc_url('https://' . $domain) . '">'
                . esc_html__('Visit your website', WAAS_HOST_TEXTDOMAIN) . ' &#8599;</a>';
        }
        NameConflicts::render_result($subscription_id);
        if ($lead !== '') {
            echo '<p class="waas-acct-lead">' . esc_html($lead) . '</p>';
        }
        if ($status === Tenant::NEEDS_NEW_NAME) {
            NameConflicts::render_form($subscription_id);
        }

        echo '<div class="waas-acct-actions">';
        if ($status === Tenant::READY) {
            echo '<a class="waas-acct-btn is-primary fct-btn fct-btn-primary" target="_blank" rel="noopener" href="' . esc_url(SingleLoginService::get_login_link($subscription_id)) . '">'
                . esc_html__('Log in to your website', WAAS_HOST_TEXTDOMAIN) . '</a>';
        }
        echo '<a class="waas-acct-btn fct-btn fct-btn-secondary" href="' . esc_url($this->adapter->get_subscription_url($subscription_id)) . '">'
            . esc_html__('Manage or cancel plan', WAAS_HOST_TEXTDOMAIN) . '</a>';
        echo '</div>';

        // The domain and email settings, once the website is up (they change a running website).
        if ($status === Tenant::READY && !empty($this->adapter->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META))) {
            $this->domain_settings->render_edit_domain($subscription_id);
        }
        $this->render_email_pack_links($subscription_id);
        echo '</section>';
    }

    /**
     * The website's state for its owner: a tone for the pill, a few words, and one line on what it means.
     * @return array{0: string, 1: string, 2: string}
     */
    private function describe(string $status, int $suspend_at): array
    {
        if ($suspend_at) {
            $date = Notifications::format_date($suspend_at);
            return [
                'attention',
                /* translators: %s: date */
                sprintf(__('Active until %s', WAAS_HOST_TEXTDOMAIN), $date),
                sprintf(
                    /* translators: %d: number of days */
                    __('Your subscription is cancelled. Your website stays online until then; after that it is taken offline, and deleted %d days later.', WAAS_HOST_TEXTDOMAIN),
                    TenantsSubscriptionManager::get_deletion_grace_days()
                ),
            ];
        }

        switch ($status) {
            case Tenant::READY:
                return ['ready', __('Online', WAAS_HOST_TEXTDOMAIN), __('Your website is live. Log in to change its pages, photos and products.', WAAS_HOST_TEXTDOMAIN)];
            case Tenant::QUEUED:
            case Tenant::PROVISIONING:
                return ['busy', Tenant::customer_status_label(Tenant::QUEUED), __('This usually takes a few minutes. We will email you when it is ready.', WAAS_HOST_TEXTDOMAIN)];
            case Tenant::LINKING_DOMAIN:
                return ['busy', __('Connecting your domain', WAAS_HOST_TEXTDOMAIN), __('This takes a few minutes. Your website stays online meanwhile.', WAAS_HOST_TEXTDOMAIN)];
            case Tenant::FAILED:
                return ['busy', Tenant::customer_status_label($status), __('Setting up your website is taking longer than usual. We will email you when it is ready.', WAAS_HOST_TEXTDOMAIN)];
            case Tenant::NEEDS_NEW_NAME:
                return ['attention', Tenant::customer_status_label($status), ''];
            case Tenant::SUSPENDED:
                return ['off', __('Offline', WAAS_HOST_TEXTDOMAIN), __('Your website is offline. Manage your plan to bring it back, or write to us if you are not sure why.', WAAS_HOST_TEXTDOMAIN)];
            default:
                return ['busy', Tenant::customer_status_label($status), ''];
        }
    }

    /** "Buy more emails": checkout for an email pack, for this website. Nothing when the shop sells no pack. */
    private function render_email_pack_links($subscription_id): void
    {
        $links = $this->email_packs ? $this->email_packs->links($subscription_id) : [];
        if (!$links) {
            return;
        }

        $html = [];
        foreach ($links as $link) {
            $html[] = '<a class="waas-buy-emails" href="' . esc_url($link['url']) . '">' . esc_html($link['label']) . '</a>';
        }
        echo '<p class="waas-acct-emails">' . esc_html__('Sending lots of email?', WAAS_HOST_TEXTDOMAIN) . ' ' . implode(' &middot; ', $html) . '. <small>' . esc_html__('Extra emails never expire. They are used once the plan\'s monthly emails run out, and resume paused campaigns.', WAAS_HOST_TEXTDOMAIN) . '</small></p>';
    }

    /** When a cancelled subscription's website goes offline (end of its paid period); 0 when not planned. */
    private function suspend_at($subscription_id): int
    {
        return (int) $this->adapter->get_subscription_meta($subscription_id, Tenant::TENANT_SUSPEND_AT_META);
    }

    public function add_subscription_actions($data, $args)
    {
        $subscription = $args['subscription'] ?? null;
        if (!$subscription || empty($this->adapter->get_subscription_meta($subscription->id, Tenant::TENANT_EXTERNAL_ID_META))) {
            return $data;
        }

        $actions = $data['custom_actions'] ?? [];
        if ($this->adapter->get_subscription_meta($subscription->id, Tenant::TENANT_STATE) === Tenant::READY) {
            $actions[] = [
                'key' => 'waas_login',
                'label' => __('Log in to website', WAAS_HOST_TEXTDOMAIN),
                'url' => SingleLoginService::get_login_link($subscription->id),
            ];
        }
        $actions[] = [
            'key' => 'waas_manage',
            'label' => __('Manage website', WAAS_HOST_TEXTDOMAIN),
            'url' => $this->store->get_portal_url(self::ENDPOINT),
        ];
        $data['custom_actions'] = $actions;

        return $data;
    }
}
