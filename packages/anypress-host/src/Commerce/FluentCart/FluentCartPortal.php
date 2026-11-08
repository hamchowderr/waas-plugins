<?php

namespace AnyPressHost\Commerce\FluentCart;

use AnyPressHost\Core\Tenant;
use AnyPressHost\Features\AccountStyles;
use AnyPressHost\Features\NameConflicts;
use AnyPressHost\Features\Notifications;
use AnyPressHost\Features\TenantsSubscriptionManager;
use AnyPressHost\Api\AssistantSignIn;
use AnyPressHost\Features\SingleLoginService;
use AnyPressHost\Features\UserAccountSubscriptionsSettings;

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
    private ?FluentCartDomains $domains;

    public function __construct(FluentCartAdapter $adapter, FluentCartStore $store, UserAccountSubscriptionsSettings $domain_settings, ?FluentCartEmailPacks $email_packs = null, ?FluentCartDomains $domains = null)
    {
        $this->domains = $domains;
        $this->adapter = $adapter;
        $this->store = $store;
        $this->domain_settings = $domain_settings;
        $this->email_packs = $email_packs;

        add_action('fluent_cart/init', [$this, 'register_endpoint']);
        add_filter('fluent_cart/customer_portal/subscription_data', [$this, 'add_subscription_actions'], 10, 2);
        add_filter('anypress_choose_website_name_url', [$this, 'website_page_url']);
        add_filter('anypress_website_page_url', [$this, 'website_page_url']);
        add_filter('anypress_account_page_url', fn () => $this->store->get_portal_url(self::ENDPOINT));
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
            'title' => __('My website', 'anypress-host'),
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
            echo '<div class="anypress-website-ready-notice anypress-acct-notice">';
            $name = '<strong>' . esc_html((string) ($domain ?: __('Your website', 'anypress-host'))) . '</strong>';
            $suspend_at = $this->suspend_at($subscription_id);
            echo '<span class="anypress-acct-status is-' . ($suspend_at ? 'attention' : 'ready') . '"><i aria-hidden="true"></i>'
                . esc_html($suspend_at ? __('Cancelled', 'anypress-host') : __('Online', 'anypress-host')) . '</span>';
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $name is escaped where it is built; the rest is esc_html__().
            echo '<p>' . ($suspend_at
                ? sprintf(
                    /* translators: 1: the website's domain, 2: date */
                    esc_html__('Your website %1$s is active until %2$s.', 'anypress-host'),
                    $name,
                    esc_html(Notifications::format_date($suspend_at))
                )
                : sprintf(
                    /* translators: %s: the website's domain */
                    esc_html__('Your website %s is ready.', 'anypress-host'),
                    $name
                )) . '</p>';
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<div class="anypress-acct-actions">';
            echo '<a class="anypress-acct-btn is-primary fct-btn fct-btn-primary" target="_blank" rel="noopener" href="' . esc_url(SingleLoginService::get_login_link($subscription_id)) . '">'
                . esc_html__('Log in to your website', 'anypress-host') . '</a>';
            echo '<a class="anypress-acct-btn" href="' . esc_url($this->store->get_portal_url(self::ENDPOINT)) . '">' . esc_html__('Manage website', 'anypress-host') . '</a>';
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
            echo '<p>' . esc_html__('You have no websites yet. They appear here a few minutes after you subscribe to a website plan.', 'anypress-host') . '</p>';
            return;
        }

        // Removed websites last, so the first (highlighted) card is one the owner can use.
        usort($websites, fn ($a, $b) => (int) ($this->adapter->get_subscription_meta($a, Tenant::TENANT_STATE) === Tenant::DELETED)
            <=> (int) ($this->adapter->get_subscription_meta($b, Tenant::TENANT_STATE) === Tenant::DELETED));

        AccountStyles::print_once();
        echo '<div class="anypress-acct">';
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

        echo '<section class="anypress-acct-site anypress-website">';
        echo '<span class="anypress-acct-status is-' . esc_attr($tone) . '"><i aria-hidden="true"></i>'
            . '<span class="screen-reader-text">' . esc_html__('Status:', 'anypress-host') . ' </span>' . esc_html($label) . '</span>';
        // A long address breaks after a dot on a phone, not in the middle of a word.
        echo '<h3>' . ($domain ? implode('.<wbr>', array_map('esc_html', explode('.', (string) $domain))) : esc_html__('Your website', 'anypress-host')) . '</h3>';
        if ($domain && $status === Tenant::READY) {
            echo '<a class="anypress-acct-visit" target="_blank" rel="noopener" href="' . esc_url('https://' . $domain) . '">'
                . esc_html__('Visit your website', 'anypress-host') . ' &#8599;</a>';
        }
        NameConflicts::render_result($subscription_id);
        if ($lead !== '') {
            echo '<p class="anypress-acct-lead">' . esc_html($lead) . '</p>';
        }
        if ($status === Tenant::NEEDS_NEW_NAME) {
            NameConflicts::render_form($subscription_id);
        }

        echo '<div class="anypress-acct-actions">';
        if ($status === Tenant::READY) {
            echo '<a class="anypress-acct-btn is-primary fct-btn fct-btn-primary" target="_blank" rel="noopener" href="' . esc_url(SingleLoginService::get_login_link($subscription_id)) . '">'
                . esc_html__('Log in to your website', 'anypress-host') . '</a>';
            $assistant = AssistantSignIn::link($subscription_id);
            if ($assistant !== '') {
                echo '<a class="anypress-acct-btn fct-btn fct-btn-secondary" target="_blank" rel="noopener" href="' . esc_url($assistant) . '">'
                    . esc_html__('Ask the website assistant', 'anypress-host') . '</a>';
            }
        }
        echo '<a class="anypress-acct-btn fct-btn fct-btn-secondary" href="' . esc_url($this->adapter->get_subscription_url($subscription_id)) . '">'
            . esc_html__('Manage or cancel plan', 'anypress-host') . '</a>';
        echo '</div>';

        // The domain and email settings, once the website is up (they change a running website).
        if ($status === Tenant::READY && !empty($this->adapter->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META))) {
            $this->domain_settings->render_edit_domain($subscription_id);
        }
        if ($this->domains) {
            $this->domains->render_section($subscription_id);
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
                sprintf(__('Active until %s', 'anypress-host'), $date),
                sprintf(
                    /* translators: %d: number of days */
                    __('Your subscription is cancelled. Your website stays online until then; after that it is taken offline, and deleted %d days later.', 'anypress-host'),
                    TenantsSubscriptionManager::get_deletion_grace_days()
                ),
            ];
        }

        switch ($status) {
            case Tenant::READY:
                return ['ready', __('Online', 'anypress-host'), __('Your website is live. Log in to change its pages, photos and products.', 'anypress-host')];
            case Tenant::QUEUED:
            case Tenant::PROVISIONING:
                return ['busy', Tenant::customer_status_label(Tenant::QUEUED), __('This usually takes a few minutes. We will email you when it is ready.', 'anypress-host')];
            case Tenant::LINKING_DOMAIN:
                return ['busy', __('Connecting your domain', 'anypress-host'), __('This takes a few minutes. Your website stays online meanwhile.', 'anypress-host')];
            case Tenant::FAILED:
                return ['busy', Tenant::customer_status_label($status), __('Setting up your website is taking longer than usual. We will email you when it is ready.', 'anypress-host')];
            case Tenant::NEEDS_NEW_NAME:
                return ['attention', Tenant::customer_status_label($status), ''];
            case Tenant::SUSPENDED:
                return ['off', __('Offline', 'anypress-host'), __('Your website is offline. Manage your plan to bring it back, or write to us if you are not sure why.', 'anypress-host')];
            case Tenant::DELETED:
                return ['off', __('Removed', 'anypress-host'), __('This website was removed. Write to us if you want it back: we keep a backup for 90 days.', 'anypress-host')];
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
            $html[] = '<a class="anypress-buy-emails" href="' . esc_url($link['url']) . '">' . esc_html($link['label']) . '</a>';
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link in $html is escaped as it is built.
        echo '<p class="anypress-acct-emails">' . esc_html__('Sending lots of email?', 'anypress-host') . ' ' . implode(' &middot; ', $html) . '. <small>' . esc_html__('Extra emails never expire. They are used once the plan\'s monthly emails run out, and resume paused campaigns.', 'anypress-host') . '</small></p>';
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
                'key' => 'anypress_login',
                'label' => __('Log in to website', 'anypress-host'),
                'url' => SingleLoginService::get_login_link($subscription->id),
            ];
        }
        $actions[] = [
            'key' => 'anypress_manage',
            'label' => __('Manage website', 'anypress-host'),
            'url' => $this->store->get_portal_url(self::ENDPOINT),
        ];
        $data['custom_actions'] = $actions;

        return $data;
    }
}
