<?php

namespace AnyPressHost\Features;

use AnyPressHost\Commerce\FluentCart\FluentCartDomains;
use AnyPressHost\Core\ConfigService;
use AnyPressHost\Core\Tenant;

/**
 * The settings page's fields outside the Emails section: each option's registration, the sections and fields of every
 * tab, and how a field is drawn.
 */
class SettingsFields
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'add_anypress_admin_settings']);
        add_action('init', [$this, 'register_anypress_admin_settings']);
    }

    public function register_anypress_admin_settings()
    {
        // A storefront connected through wp-config has no key fields on the page, and options.php would blank them.
        if (!AdminSettings::is_ready_made()) {
            register_setting('anypress-admin', 'anypress_api_base_url', [
                'sanitize_callback' => 'esc_url_raw',
                // Shown filled in; ConfigService::get_api_base_url() also reads an empty value as the AnyPress API.
                'default' => ConfigService::ANYPRESS_API_URL,
            ]);
            register_setting('anypress-admin', 'anypress_api_key', [
                'sanitize_callback' => 'sanitize_text_field'
            ]);
            register_setting('anypress-admin', 'anypress_api_secret', [
                'sanitize_callback' => 'sanitize_text_field'
            ]);
        }
        // AnyPress sets these for a ready-made storefront; they aren't on its settings page, so they can't be posted.
        if (!AdminSettings::is_ready_made()) {
            // Shown filled in, so saving this page keeps the assistant on unless the field is cleared.
            register_setting('anypress-admin', 'anypress_assistant_url', [
                'sanitize_callback' => [ConfigService::class, 'sanitize_assistant_url'],
                'default' => (new ConfigService())->default_assistant_url(),
            ]);

            register_setting('anypress-admin-addresses', 'anypress_root_domain', [
                'sanitize_callback' => 'sanitize_text_field'
            ]);
            register_setting('anypress-admin-addresses', 'anypress_dns_a_records', [
                'sanitize_callback' => [ConfigService::class, 'sanitize_ip_list']
            ]);
            register_setting('anypress-admin-addresses', 'anypress_dns_cname_target', [
                'sanitize_callback' => [ConfigService::class, 'sanitize_hostname']
            ]);
            register_setting('anypress-admin-access', Tenant::DEFAULT_USER_ROLE, [
                'default' => 'administrator',
                'sanitize_callback' => [$this, 'sanitize_user_role']
            ]);
            register_setting('anypress-admin-access', AdminSettings::SHOULD_REMOVE_ADMINISTRATOR_PLUGIN_CAPABILITIES_OPTION, [
                'default' => 'on'
            ]);
        }
        register_setting('anypress-admin-cancellations', TenantsSubscriptionManager::DELETION_GRACE_DAYS_OPTION, [
            'default' => TenantsSubscriptionManager::DEFAULT_DELETION_GRACE_DAYS,
            'sanitize_callback' => fn ($value) => max(1, absint($value)),
        ]);
        // Off by default: turning it on offers domains to every customer.
        register_setting('anypress-admin-domains', FluentCartDomains::ENABLED_OPTION, [
            'default' => ''
        ]);
        register_setting('anypress-admin-domains', FluentCartDomains::EXTRA_OPTION, [
            'default' => 0,
            'sanitize_callback' => fn ($value) => min(1000, absint($value)),
        ]);
        // Off by default: turning it on changes prices on the live shop.
        register_setting('anypress-admin-discount', VolumeDiscount::ENABLED_OPTION, [
            'default' => ''
        ]);
    }

    /** "Set in wp-config.php as ANYPRESS_<NAME>." when the constant is defined, else $hint. */
    private static function constant_hint(string $name, string $hint): string
    {
        if (!ConfigService::has_constant($name)) {
            return $hint;
        }

        /* translators: %s: a wp-config.php constant name */
        return sprintf(__('Set in wp-config.php as %s, so it can\'t be changed here.', 'anypress-host'), 'ANYPRESS_' . $name);
    }

    private function add_field(string $id, string $title, string $page, string $section, array $args)
    {
        $args['id'] = $id;
        if (!in_array($args['type'], ['checkbox'], true)) {
            $args['label_for'] = $id;
        }

        add_settings_field($id, $title, [$this, 'render_settings_field'], $page, $section, $args);
    }

    public function add_anypress_admin_settings()
    {
        // A ready-made storefront shows these as set by AnyPress instead (render_managed_settings()).
        if (!AdminSettings::is_ready_made()) {
            // Connection
            add_settings_section(
                'api_credentials',
                __('AnyPress API', 'anypress-host'),
                fn () => print('<p>' . esc_html__('This storefront creates, pauses and restores customer websites through the AnyPress API. Make a key for it on the API keys page of your AnyPress dashboard.', 'anypress-host') . '</p>'),
                'anypress-admin'
            );

            $this->add_field('anypress_api_base_url', __('API address', 'anypress-host'), 'anypress-admin', 'api_credentials', [
                'type' => 'url',
                'class_name' => 'regular-text code',
                'placeholder' => 'https://api.anypress.io',
                'constant' => 'API_BASE_URL',
                'hint' => self::constant_hint('API_BASE_URL', __('Leave it as https://api.anypress.io unless you run your own AnyPress API.', 'anypress-host')),
            ]);
            $this->add_field('anypress_api_key', __('API key', 'anypress-host'), 'anypress-admin', 'api_credentials', [
                'type' => 'text',
                'class_name' => 'regular-text code',
                'constant' => 'API_KEY',
                'hint' => self::constant_hint('API_KEY', ''),
            ]);
            $this->add_field('anypress_api_secret', __('API secret', 'anypress-host'), 'anypress-admin', 'api_credentials', [
                'type' => 'password',
                'class_name' => 'regular-text code',
                'constant' => 'API_SECRET',
                'hint' => self::constant_hint('API_SECRET', ''),
            ]);
            $this->add_field('anypress_assistant_url', __('Website assistant', 'anypress-host'), 'anypress-admin', 'api_credentials', [
                'type' => 'text',
                'class_name' => 'regular-text code',
                'placeholder' => ConfigService::ASSISTANT_ON_WEBSITE,
                'constant' => 'ASSISTANT_URL',
                'hint' => self::constant_hint('ASSISTANT_URL', __('Each ready website on the customer\'s account page gets an "Ask the website assistant" button that opens it already signed in. "website" opens the assistant on the customer\'s own website, at /.assistant (the AnyPress API\'s default). Or enter the address of your own assistant. Clear the field to hide the button.', 'anypress-host')),
            ]);

            // Website addresses
            add_settings_section(
                'anypress_host_settings',
                __('Website addresses', 'anypress-host'),
                fn () => print('<p>' . esc_html__('Where new websites live, and the DNS records customers add to use their own domain.', 'anypress-host') . '</p>'),
                'anypress-admin-addresses'
            );

            $this->add_field('anypress_root_domain', __('Website domain', 'anypress-host'), 'anypress-admin-addresses', 'anypress_host_settings', [
                'type' => 'text',
                'class_name' => 'regular-text code',
                'placeholder' => 'sites.example.com',
                'hint' => __('Each new website gets an address under this domain: with sites.example.com, a website named shop is at shop.sites.example.com.', 'anypress-host'),
            ]);
            $this->add_field('anypress_dns_a_records', __('IP address for custom domains', 'anypress-host'), 'anypress-admin-addresses', 'anypress_host_settings', [
                'type' => 'text',
                'class_name' => 'regular-text code',
                'placeholder' => '203.0.113.10',
                'constant' => 'DNS_A_RECORDS',
                'hint' => self::constant_hint('DNS_A_RECORDS', __('Customers add this as an A record for their own domain. Separate several with commas. Leave empty and the customer\'s domain page tells them to contact support.', 'anypress-host')),
            ]);
            $this->add_field('anypress_dns_cname_target', __('Address for www and other subdomains', 'anypress-host'), 'anypress-admin-addresses', 'anypress_host_settings', [
                'type' => 'text',
                'class_name' => 'regular-text code',
                'placeholder' => 'sites.example.com',
                'constant' => 'DNS_CNAME_TARGET',
                'hint' => self::constant_hint('DNS_CNAME_TARGET', __('Customers add this as a CNAME record for a subdomain such as www.', 'anypress-host')),
            ]);

            // Customer access
            add_settings_section(
                'anypress_store_access',
                __('Customer access', 'anypress-host'),
                fn () => print('<p>' . esc_html__('What customers can do on their own website. Changes apply to websites created from now on.', 'anypress-host') . '</p>'),
                'anypress-admin-access'
            );

            $this->add_field(Tenant::DEFAULT_USER_ROLE, __('Customer\'s role on their website', 'anypress-host'), 'anypress-admin-access', 'anypress_store_access', [
                'type' => 'role',
                'hint' => __('The role the customer gets when they sign in to their website from their account page.', 'anypress-host'),
            ]);
            $this->add_field(AdminSettings::SHOULD_REMOVE_ADMINISTRATOR_PLUGIN_CAPABILITIES_OPTION, __('Plugins', 'anypress-host'), 'anypress-admin-access', 'anypress_store_access', [
                'type' => 'checkbox',
                'label' => __('Plugins come from the plan only', 'anypress-host'),
                'hint' => __('Customers can\'t install, turn on or delete plugins. Websites that already exist keep what they have.', 'anypress-host'),
            ]);
        }

        // Cancellations
        add_settings_section(
            'anypress_store_cancellation',
            __('Cancellations', 'anypress-host'),
            fn () => print('<p>' . esc_html__('What happens to a website when its subscription ends.', 'anypress-host') . '</p>'),
            'anypress-admin-cancellations'
        );

        $this->add_field(TenantsSubscriptionManager::DELETION_GRACE_DAYS_OPTION, __('Keep an offline website for', 'anypress-host'), 'anypress-admin-cancellations', 'anypress_store_cancellation', [
            'type' => 'number',
            'class_name' => 'small-text',
            'min' => 1,
            'suffix' => __('days', 'anypress-host'),
            'hint' => __('When a subscription is cancelled or expires, its website is backed up and taken offline, then deleted after this many days. If the customer pays again before then, the website comes back as it was.', 'anypress-host'),
        ]);

        // Volume discount
        add_settings_section(
            'anypress_volume_discount',
            __('Volume discount', 'anypress-host'),
            fn () => print('<p>' . esc_html__('A discount on new websites for customers who already have several. Their live websites are counted together with the one they are buying.', 'anypress-host') . '</p>'),
            'anypress-admin-discount'
        );

        $this->add_field(VolumeDiscount::ENABLED_OPTION, __('Volume discount', 'anypress-host'), 'anypress-admin-discount', 'anypress_volume_discount', [
            'type' => 'checkbox',
            'label' => __('Discount website plans for customers with several live websites', 'anypress-host'),
            'tiers' => true,
            'hint' => __('Applied at checkout to signed-in customers and to anyone using an existing customer\'s email, on the first payment and every renewal. When a customer moves to another tier, their other plans get the new price from their next renewal, with no charge now (plans billed by Stripe or by the store; others get a note on the subscription to change by hand). Needs FluentCart.', 'anypress-host'),
        ]);

        $this->add_domain_settings();
    }

    private function add_domain_settings(): void
    {
        add_settings_section(
            'anypress_domains',
            __('Domains', 'anypress-host'),
            fn () => print('<p>' . esc_html__('Customers can buy their own domain on their Website page, at your checkout and in your brand. It is registered in their name, set up for their website, and renews every year with its subscription. Prices are in US dollars.', 'anypress-host') . '</p>'),
            'anypress-admin-domains'
        );

        $this->add_field(FluentCartDomains::ENABLED_OPTION, __('Sell domains', 'anypress-host'), 'anypress-admin-domains', 'anypress_domains', [
            'type' => 'checkbox',
            'label' => __('Offer domains on the customer\'s Website page', 'anypress-host'),
            'hint' => __('Each domain is charged to your AnyPress account at its AnyPress price when it is registered and each time it renews. Needs FluentCart and a payment method that takes subscriptions.', 'anypress-host'),
        ]);
        $this->add_field(FluentCartDomains::EXTRA_OPTION, __('Add to each year\'s price', 'anypress-host'), 'anypress-admin-domains', 'anypress_domains', [
            'type' => 'number',
            'class_name' => 'small-text',
            'min' => 0,
            'suffix' => __('dollars', 'anypress-host'),
            'hint' => __('Your margin on top of the AnyPress price, which already includes registration. 0 sells domains at the AnyPress price.', 'anypress-host'),
        ]);
    }

    public function render_settings_field($args)
    {
        $id = $args['id'];
        $value = get_option($id);
        $disabled = !empty($args['constant']) && ConfigService::has_constant($args['constant']);
        if ($disabled && $args['type'] !== 'password') {
            $constant = ConfigService::constant($args['constant']);
            $value = is_array($constant) ? implode(', ', $constant) : (string) $constant;
        }

        switch ($args['type']) {
            case 'checkbox':
                printf(
                    '<label for="%1$s"><input type="checkbox" id="%1$s" name="%1$s" %2$s> %3$s</label>',
                    esc_attr($id),
                    checked($value, 'on', false),
                    esc_html($args['label'] ?? '')
                );
                if (!empty($args['tiers'])) {
                    $this->render_discount_tiers();
                }
                break;
            case 'role':
                $roles = [
                    'administrator' => __('Administrator', 'anypress-host'),
                    'editor' => __('Editor', 'anypress-host'),
                    'author' => __('Author', 'anypress-host'),
                ];
                $value = (string) ($value ?: 'administrator');
                if (!isset($roles[$value])) {
                    $roles[$value] = $value;
                }
                $describedby = !empty($args['hint']) ? ' aria-describedby="' . esc_attr($id . '-description') . '"' : '';
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($id) . '"' . $describedby . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
                foreach ($roles as $role => $label) {
                    printf('<option value="%s"%s>%s</option>', esc_attr($role), selected($value, $role, false), esc_html($label));
                }
                echo '</select>';
                break;
            default:
                $attributes = [
                    'type' => $args['type'],
                    'id' => $id,
                    'name' => $id,
                    'value' => $disabled && $args['type'] === 'password' ? '' : (string) $value,
                    'class' => $args['class_name'] ?? 'regular-text',
                ];
                if (!empty($args['placeholder'])) {
                    $attributes['placeholder'] = $disabled && $args['type'] === 'password' ? '••••••••' : $args['placeholder'];
                }
                if (isset($args['min'])) {
                    $attributes['min'] = $args['min'];
                }
                if (!empty($args['hint']) || !empty($args['hint_html'])) {
                    $attributes['aria-describedby'] = $id . '-description';
                }

                $html = '';
                foreach ($attributes as $att => $att_value) {
                    $html .= sprintf(' %s="%s"', $att, esc_attr((string) $att_value));
                }
                echo '<input' . $html . ($disabled ? ' disabled' : '') . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every attribute value is esc_attr() above.
                if (!empty($args['suffix'])) {
                    echo ' ' . esc_html($args['suffix']);
                }
                break;
        }

        if (!empty($args['hint'])) {
            echo '<p class="description" id="' . esc_attr($id . '-description') . '">' . esc_html($args['hint']) . '</p>';
        } elseif (!empty($args['hint_html'])) {
            echo '<p class="description" id="' . esc_attr($id . '-description') . '">' . wp_kses($args['hint_html'], ['code' => []]) . '</p>';
        }
    }

    /** The volume discount tiers as a small table, so sellers see what customers get before turning it on. */
    private function render_discount_tiers(): void
    {
        echo '<table class="ap-tiers"><thead><tr><th>' . esc_html__('Live websites', 'anypress-host') . '</th><th>' . esc_html__('Discount', 'anypress-host') . '</th></tr></thead><tbody>';
        $tiers = VolumeDiscount::tiers();
        foreach ($tiers as $i => $tier) {
            $next = $tiers[$i + 1]['sites'] ?? null;
            $range = $next === null ? sprintf('%d+', $tier['sites']) : sprintf('%d–%d', $tier['sites'], $next - 1);
            echo '<tr><td>' . esc_html($range) . '</td><td>' . esc_html(VolumeDiscount::format_percent($tier['percent']) . '%') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public function sanitize_user_role($value)
    {
        $trimmed = trim($value);
        return empty($trimmed) ? 'administrator' : $trimmed;
    }
}
