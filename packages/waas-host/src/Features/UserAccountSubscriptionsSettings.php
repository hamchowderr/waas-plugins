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

        $current = (string) ($domain_name ?: $base_domain_name);
        $domain_name = esc_attr($current);
        $nonce_field = wp_nonce_field(self::DOMAIN_NONCE_ACTION . $subscription_id, self::DOMAIN_NONCE_FIELD, true, false);

        AccountStyles::print_once();
        echo '<details class="waas-acct-panel"' . ($this->domain_error !== '' ? ' open' : '') . '>';
        echo '<summary>' . esc_html('Use your own domain') . ($current !== '' ? ' <small>' . esc_html('Now: ' . $current) . '</small>' : '') . '</summary>';
        echo '<div class="waas-acct-panel-body">';
        $has_records = $this->config->get_dns_a_records() || $this->config->get_dns_cname_target() !== '';
        if ($has_records) {
            echo '<p class="waas-acct-step"><b>1.</b> ' . esc_html('Add these records where you bought your domain (look for its DNS settings).') . '</p>';
        }
        $this->render_dns_instructions();
        $custom_domain = (string) $commerce->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META);
        if ($custom_domain !== '') {
            $this->render_connect_button($subscription_id, $custom_domain, 'website');
        }
        if ($has_records) {
            echo '<p class="waas-acct-step"><b>2.</b> ' . esc_html('Then type the domain here. New records can take up to an hour to work; if it says they are missing, try again later.') . '</p>';
        }
        if ($this->domain_error !== '') {
            echo '<p class="woocommerce-error waas-acct-error">' . esc_html($this->domain_error) . '</p>';
        }
        echo "<form method='post' action='' class='waas-acct-form'>
                $nonce_field
                <label for='domain_name'>Your domain
                    <input type='text' placeholder='example.com' class='input-text' name='domain_name' id='domain_name' value='$domain_name'>
                </label>
                <button class='button waas-acct-btn is-primary fct-btn fct-btn-primary' type='submit'>Use this domain</button>
               </form>";
        echo '</div></details>';

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
        $status = $mail_domain ? (string) ($mail_domain->status ?? 'pending') : '';
        $labels = ['pending' => 'Waiting for DNS records', 'verified' => 'Sending', 'removing' => 'Being removed'];

        AccountStyles::print_once();
        echo '<details class="waas-acct-panel"' . ($this->mail_error !== '' || $status === 'pending' ? ' open' : '') . '>';
        echo '<summary>' . esc_html('Send email from your own domain')
            . ($mail_domain ? ' <small>' . esc_html($labels[$status] ?? $labels['pending']) . '</small>' : '') . '</summary>';
        echo '<div class="waas-acct-panel-body">';
        if ($this->mail_error !== '') {
            echo '<p class="woocommerce-error waas-acct-error">' . esc_html($this->mail_error) . '</p>';
        }
        $this->render_mail_sender_body($subscription_id, $mail_domain, $status, $nonce_field);
        echo '</div></details>';
    }

    /** Inside the email panel: the form to add a domain, or the domain's status and its records. */
    private function render_mail_sender_body($subscription_id, $mail_domain, string $status, string $nonce_field): void
    {
        if (!$mail_domain) {
            echo '<p>Your store sends its email (receipts, password resets) from our address. '
                . 'Add your own domain to send it from no-reply at that domain instead.</p>';
            echo "<form method='post' action='' class='waas-acct-form'>
                $nonce_field
                <label for='mail_domain_name'>Sending domain
                    <input type='text' placeholder='example.com' class='input-text' name='mail_domain_name' id='mail_domain_name' value=''>
                </label>
                <button class='button waas-acct-btn is-primary fct-btn fct-btn-primary' type='submit'>Use this domain</button>
               </form>";
            return;
        }

        echo '<p>Sends from <b>' . esc_html('no-reply@' . $mail_domain->domainName) . '</b></p>';

        if ($status === 'removing') {
            echo '<p>' . esc_html('Your store goes back to sending from our address within 10 minutes.') . '</p>';
            return;
        }
        if ($status === 'pending') {
            echo '<p>Add these records where you bought your domain (in its DNS settings). They are checked every 10 minutes and your store '
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
        echo "<form method='post' action='' class='waas-acct-form'>
                $nonce_field
                <input type='hidden' name='mail_domain_remove' value='1'>
                <button class='button waas-acct-btn fct-btn fct-btn-secondary' type='submit'>Stop using this domain</button>
               </form>";
    }

    /**
     * "Connect automatically" when the domain's DNS host supports Domain Connect and has the platform's
     * template: it opens the host's page, which adds the records once the customer approves. When the API
     * says why it can't (an older DKIM key on the domain, say), that reason instead. Otherwise nothing is
     * shown and the records above are added by hand.
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
        if ($link['conflict'] !== null) {
            echo '<p class="waas-dns-conflict"><strong>' . esc_html('One-click setup is off for this domain.') . '</strong> '
                . esc_html($link['conflict']) . '</p>';
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
        if ($a_records) {
            echo '<p class="waas-acct-note">' . esc_html('For a domain like example.com, add an A record for each of these:') . '</p><div class="waas-acct-records">';
            foreach ($a_records as $ip) {
                echo '<div class="waas-acct-record"><b>A</b><code>' . esc_html($ip) . '</code></div>';
            }
            echo '</div>';
        }
        if ($cname_target !== '') {
            echo '<p class="waas-acct-note">' . esc_html('For one like shop.example.com, add a CNAME record:') . '</p>'
                . '<div class="waas-acct-records"><div class="waas-acct-record"><b>CNAME</b><code>' . esc_html($cname_target) . '</code></div></div>';
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