<?php

namespace WaaSHost\Features;

use Exception;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;

class UserAccountSubscriptionsSettings
{
    public const DOMAIN_NONCE_FIELD = 'wpcs_domain_nonce';
    public const DOMAIN_NONCE_ACTION = 'wpcs_update_domain_';

    private WPCSService $wpcsService;
    private ConfigService $config;
    private string $domain_error = '';

    public function __construct(WPCSService $wpcsService, ?ConfigService $config = null)
    {
        $this->wpcsService = $wpcsService;
        $this->config = $config ?? new ConfigService();

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

    /** DNS records for a custom domain, from the storefront's DNS settings. With neither set, the customer is told to contact support. */
    public function render_dns_instructions()
    {
        $a_records = $this->config->get_dns_a_records();
        $cname_target = $this->config->get_dns_cname_target();
        if (!$a_records && $cname_target === '') {
            echo '<div class="wpcs-ip-instructions"><p>'
                . esc_html('Custom domains are not set up on this store yet. Please contact support to connect your domain.')
                . '</p></div>';
            return;
        }

        echo '<div class="wpcs-ip-instructions">';
        echo '<p>Before verifying a domain, make sure that its DNS contains the following settings.</p>';
        if ($a_records) {
            echo '<p>For the domain apex add A records with <b>each of the following IPs</b> as their values:</p>';
            echo '<pre>' . esc_html(implode("\n", $a_records)) . '</pre>';
        }
        if ($cname_target !== '') {
            echo '<p>If you are verifying a subdomain, create a CNAME record with the value:</p>';
            echo '<pre>' . esc_html($cname_target) . '</pre>';
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