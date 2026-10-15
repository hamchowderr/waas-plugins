<?php

namespace WaaSHost\Features;

use Exception;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;

class UserAccountSubscriptionsSettings
{
    public const DOMAIN_NONCE_FIELD = 'waas_domain_nonce';
    public const DOMAIN_NONCE_ACTION = 'waas_update_domain_';
    public const MAIL_NONCE_FIELD = 'waas_mail_nonce';
    public const MAIL_NONCE_ACTION = 'waas_mail_domain_';

    private ProvisioningApi $provisioningApi;
    private ConfigService $config;
    private WebsiteSettings $website_settings;
    private string $domain_error = '';
    private string $mail_error = '';

    public function __construct(ProvisioningApi $provisioningApi, ?ConfigService $config = null)
    {
        $this->provisioningApi = $provisioningApi;
        $this->config = $config ?? new ConfigService();
        $this->website_settings = new WebsiteSettings($provisioningApi);

        add_action('waas_after_subscription_details_html', [$this, 'render_edit_domain'], 10, 1);
        add_action('remove_tenant_old_domain', [$this, 'remove_tenant_old_domain'], 1, 2);
        add_filter('wcs_view_subscription_actions', [$this, 'remove_subscription_actions'], 10, 1);
    }

    public function render_edit_domain($subscription_id)
    {
        if(!apply_filters('waas_display_edit_domain_fields', true, $subscription_id))
        {
            return;
        }

        $this->handle_update_subscription_domain($subscription_id);
        $commerce = Commerce::require_adapter();
        $domain_name = $commerce->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META);
        $base_domain_name = $commerce->get_subscription_meta($subscription_id, Tenant::BASE_DOMAIN_NAME_META);

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
        $custom_domain = (string) $commerce->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META);
        if ($custom_domain !== '') {
            $this->render_connect_button($subscription_id, $custom_domain, 'website');
        }
        $this->render_mail_sender($subscription_id);
    }

    /**
     * Which address the store's email (receipts, password resets) comes from, and a form to send it
     * from the customer's own domain: the DNS records to add, and its status until the platform has
     * verified them. Hidden when the API can't be reached or doesn't offer sending domains.
     */
    public function render_mail_sender($subscription_id)
    {
        $this->handle_mail_domain($subscription_id);
        $commerce = Commerce::adapter();
        $external_id = $commerce === null ? '' : (string) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META);
        if ($external_id === '') {
            return;
        }
        try {
            $mail_domain = $this->provisioningApi->get_mail_domain($external_id);
        } catch (Exception $e) {
            return;
        }

        $nonce_field = wp_nonce_field(self::MAIL_NONCE_ACTION . $subscription_id, self::MAIL_NONCE_FIELD, true, false);
        echo '<h4>Email Sender</h4>';
        if ($this->mail_error !== '') {
            echo '<p class="woocommerce-error">' . esc_html($this->mail_error) . '</p>';
        }

        if (!$mail_domain) {
            echo '<p>Your store sends its email (receipts, password resets) from our address. '
                . 'Add your own domain to send it from no-reply at that domain instead.</p>';
            echo "<form method='post' action=''>
                $nonce_field
                <p class='woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide'>
                    <label for='mail_domain_name'>Sending Domain</label>
                    <input type='text' placeholder='example.com' class='woocommerce-Input input-text' name='mail_domain_name' id='mail_domain_name' value=''>
                </p>
                <button class='button' type='submit'>Use this domain</button>
               </form><br /><br />";
            return;
        }

        $status = (string) ($mail_domain->status ?? 'pending');
        $labels = ['pending' => 'Waiting for DNS records', 'verified' => 'Sending', 'removing' => 'Being removed'];
        echo '<p>Sends from <b>' . esc_html('no-reply@' . $mail_domain->domainName) . '</b> ('
            . esc_html($labels[$status] ?? $labels['pending']) . ')</p>';

        if ($status === 'removing') {
            echo '<p>' . esc_html('Your store goes back to sending from our address within 10 minutes.') . '</p>';
            return;
        }
        if ($status === 'pending') {
            echo '<p>Add these records at your domain\'s DNS host. They are checked every 10 minutes and your store '
                . 'switches over on its own once they are found. Until then it sends from our address.</p>';
            $this->render_connect_button($subscription_id, (string) $mail_domain->domainName, 'email');
        }
        $records = $mail_domain->records ?? null;
        if (is_array($records) && $records) {
            echo '<table class="waas-mail-records"><thead><tr><th>Type</th><th>Name</th><th>Value</th><th>Priority</th></tr></thead><tbody>';
            foreach ($records as $record) {
                echo '<tr><td>' . esc_html((string) ($record->type ?? '')) . '</td>'
                    . '<td><code>' . esc_html((string) ($record->name ?? '')) . '</code></td>'
                    . '<td><code>' . esc_html((string) ($record->value ?? '')) . '</code></td>'
                    . '<td>' . esc_html(isset($record->priority) ? (string) $record->priority : '') . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html('The DNS records could not be loaded right now. Reload the page to try again.') . '</p>';
        }
        echo "<form method='post' action=''>
                $nonce_field
                <input type='hidden' name='mail_domain_remove' value='1'>
                <button class='button' type='submit'>Stop using this domain</button>
               </form><br /><br />";
    }

    /**
     * "Connect automatically" when the domain's DNS host supports Domain Connect and has the platform's
     * template: it opens the host's page, which adds the records once the customer approves. Otherwise
     * nothing is shown and the records above are added by hand.
     */
    public function render_connect_button($subscription_id, string $domain_name, string $service)
    {
        $commerce = Commerce::adapter();
        $external_id = $commerce === null ? '' : (string) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META);
        if ($external_id === '') {
            return;
        }
        try {
            $link = $this->website_settings->domain_connect_link($subscription_id, $domain_name, $service);
        } catch (WebsiteSettingsError $e) {
            return;
        }
        if ($link === null) {
            return;
        }
        $provider = $link['dns_provider'] !== '' ? $link['dns_provider'] : 'your DNS host';
        echo '<p><a class="button" href="' . esc_url($link['url']) . '" target="_blank" rel="noopener">'
            . esc_html('Connect automatically at ' . $provider) . '</a> '
            . esc_html('or add the records by hand.') . '</p>';
    }

    /** Adds or removes the store's sending domain from the form above; only its owner may. */
    public function handle_mail_domain($subscription_id)
    {
        $remove = isset($_POST['mail_domain_remove']);
        if (!$remove && !isset($_POST['mail_domain_name'])) {
            return;
        }

        $nonce = isset($_POST[self::MAIL_NONCE_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::MAIL_NONCE_FIELD])) : '';
        if (!wp_verify_nonce($nonce, self::MAIL_NONCE_ACTION . $subscription_id)) {
            return;
        }

        $commerce = Commerce::adapter();
        $owner_id = $commerce === null ? 0 : $commerce->get_customer_id($subscription_id);
        if ($owner_id === 0 || $owner_id !== get_current_user_id()) {
            return;
        }
        $external_id = (string) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META);
        if ($external_id === '') {
            return;
        }

        try {
            if ($remove) {
                $this->website_settings->remove_email_sender($subscription_id);
                return;
            }
            $this->website_settings->set_email_sender($subscription_id, wp_unslash($_POST['mail_domain_name']));
        } catch (WebsiteSettingsError $e) {
            $this->mail_error = $e->getMessage();
        }
    }

    /** DNS records for a custom domain, from the storefront's DNS settings. With neither set, the customer is told to contact support. */
    public function render_dns_instructions()
    {
        $a_records = $this->config->get_dns_a_records();
        $cname_target = $this->config->get_dns_cname_target();
        if (!$a_records && $cname_target === '') {
            echo '<div class="waas-ip-instructions"><p>'
                . esc_html('Custom domains are not set up on this store yet. Please contact support to connect your domain.')
                . '</p></div>';
            return;
        }

        echo '<div class="waas-ip-instructions">';
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

        try {
            $this->website_settings->change_address($subscription_id, wp_unslash($_POST['domain_name']));
        } catch (WebsiteSettingsError $e) {
            // The form has only ever shown input errors; a failed API call leaves the address as it was.
            if ($e->is_invalid_input()) {
                $this->domain_error = $e->getMessage();
            }
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
        $this->provisioningApi->delete_tenant_domain([
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