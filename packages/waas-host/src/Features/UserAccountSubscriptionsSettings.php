<?php

namespace WaaSHost\Features;

use Exception;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;

class UserAccountSubscriptionsSettings
{
    public const DOMAIN_NONCE_FIELD = 'wpcs_domain_nonce';
    public const DOMAIN_NONCE_ACTION = 'wpcs_update_domain_';
    public const DNS_A_RECORDS_OPTION = 'wpcs_host_settings_dns_a_records';
    public const DNS_CNAME_TARGET_OPTION = 'wpcs_host_settings_dns_cname_target';

    /** What stores hosted on WPCS used, by region; only shown when the DNS settings are empty. */
    private const LEGACY_WPCS_DNS = [
        'us1' => ['a_records' => ['54.166.55.112', '54.163.0.37', '174.129.101.20'], 'cname' => 'public.us1.wpcs.io'],
        'eu1' => ['a_records' => ['54.74.209.56', '54.75.81.37', '54.216.187.86'], 'cname' => 'public.eu1.wpcs.io'],
    ];

    private WPCSService $wpcsService;
    private string $domain_error = '';

    public function __construct(WPCSService $wpcsService)
    {
        $this->wpcsService = $wpcsService;

        add_action('wpcs_after_subscription_details_html', [$this, 'render_edit_domain'], 10, 1);
        add_action('remove_tenant_old_domain', [$this, 'remove_tenant_old_domain'], 1, 2);
        add_filter('wcs_view_subscription_actions', [$this, 'remove_subscription_actions'], 10, 1);
    }

    public function render_edit_domain($subscription_id)
    {
        if(!apply_filters('wpcs_display_edit_domain_fields', true, $subscription_id))
        {
            return;
        }

        $this->handle_update_subscription_domain($subscription_id);
        $commerce = Commerce::require_adapter();
        $domain_name = $commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_DOMAIN_NAME_META);
        $base_domain_name = $commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_BASE_DOMAIN_NAME_META);

        $domain_name = esc_attr($domain_name ?: $base_domain_name);
        $nonce_field = wp_nonce_field(self::DOMAIN_NONCE_ACTION . $subscription_id, self::DOMAIN_NONCE_FIELD, true, false);

        echo '<h4>Website Details</h4>';
        if ($this->domain_error !== '') {
            echo '<p class="woocommerce-error">' . esc_html($this->domain_error) . '</p>';
        }
        echo "<form method='post' action=''>
                $nonce_field
                <p class='woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide'>
                    <label for='account_email'>Domain Name (optional)</label>
                    <input type='text' placeholder='example.com' class='woocommerce-Input woocommerce-Input--email input-text' name='domain_name' id='domain_name' value='$domain_name'>
	            </p>
	            <button class='button' type='submit'>Update</button>
	           </form><br /><br />";

        $this->render_dns_instructions();
    }

    /**
     * Where customers point their own domain: A-record IPs for an apex domain, a CNAME target for a subdomain.
     * Set under Settings → Custom domain DNS. Without them, stores on the old WPCS hosting keep the WPCS
     * values for their region.
     *
     * @return array{a_records: string[], cname: string}
     */
    public static function dns_targets(): array
    {
        $a_records = self::parse_ip_list((string) get_option(self::DNS_A_RECORDS_OPTION, ''));
        $cname = trim((string) get_option(self::DNS_CNAME_TARGET_OPTION, ''));

        if (!$a_records && $cname === '') {
            $legacy = self::LEGACY_WPCS_DNS[strtolower((string) get_option('wpcs_credentials_region_setting', ''))] ?? null;
            if ($legacy !== null) {
                return $legacy;
            }
        }

        return ['a_records' => $a_records, 'cname' => $cname];
    }

    /** @return string[] the valid IP addresses in a comma, space or newline separated list */
    public static function parse_ip_list(string $list): array
    {
        $ips = preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false)));
    }

    public function render_dns_instructions(): void
    {
        $targets = self::dns_targets();

        echo '<div class="wpcs-ip-instructions">';
        if (!$targets['a_records'] && $targets['cname'] === '') {
            echo '<p>' . esc_html__('Custom domains are not set up on this store yet. Please contact support to connect your domain.', WPCS_WAAS_HOST_TEXTDOMAIN) . '</p></div>';
            return;
        }

        echo '<p>' . esc_html__('Before verifying a domain, make sure that its DNS contains the following settings.', WPCS_WAAS_HOST_TEXTDOMAIN) . '</p>';
        if ($targets['a_records']) {
            echo '<p>' . esc_html__('For the domain apex add A records with each of the following IPs as their values:', WPCS_WAAS_HOST_TEXTDOMAIN) . '</p>';
            echo '<pre>' . esc_html(implode("\n", $targets['a_records'])) . '</pre>';
        }
        if ($targets['cname'] !== '') {
            echo '<p>' . esc_html__('If you are verifying a subdomain, create a CNAME record with the value:', WPCS_WAAS_HOST_TEXTDOMAIN) . '</p>';
            echo '<pre>' . esc_html($targets['cname']) . '</pre>';
        }
        echo '</div>';
    }

    public function handle_update_subscription_domain($subscription_id)
    {
        if (!isset($_POST['domain_name'])) {
            return;
        }

        // Only the subscription's owner, from this form (not a forged cross-site POST), may change the domain.
        $nonce = isset($_POST[self::DOMAIN_NONCE_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::DOMAIN_NONCE_FIELD])) : '';
        if (!wp_verify_nonce($nonce, self::DOMAIN_NONCE_ACTION . $subscription_id)) {
            return;
        }

        $commerce = Commerce::adapter();
        $owner_id = $commerce === null ? 0 : $commerce->get_customer_id($subscription_id);
        if ($owner_id === 0 || $owner_id !== get_current_user_id()) {
            return;
        }

        $domain = strtolower(trim(sanitize_text_field(wp_unslash($_POST['domain_name']))));
        if (!self::is_valid_hostname($domain)) {
            $this->domain_error = 'Please enter a valid domain name, like example.com.';
            return;
        }

        $tenant_external_id = $commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META);
        $tenant_current_domain_name = $commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_DOMAIN_NAME_META);

        if ($domain === $tenant_current_domain_name) {
            return;
        }

        try {
            $this->wpcsService->add_tenant_domain([
                'external_id' => $tenant_external_id,
                'domain_name' => $domain,
            ]);

            $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_DOMAIN_NAME_META, $domain);

            if ($tenant_current_domain_name) {
                wp_schedule_single_event(time() + 300, 'remove_tenant_old_domain', [$tenant_external_id, $tenant_current_domain_name]);
            }
        } catch (Exception $e) {
        }
    }

    public static function is_valid_hostname(string $domain): bool
    {
        return strlen($domain) <= 253
            && strpos($domain, '.') !== false
            && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    public function remove_tenant_old_domain($external_id, $old_domain_name)
    {
        $this->wpcsService->delete_tenant_domain([
            'external_id' => $external_id,
            'old_domain_name' => $old_domain_name,
        ]);
    }

    public function remove_subscription_actions($actions)
    {
        if (isset($actions['resubscribe'])) {
            unset($actions['resubscribe']);
        }

        return $actions;
    }
}