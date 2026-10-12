<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\WooCommerce\WooCommerceAdapter;
use WaaSHost\Core\Tenant;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Exceptions\InvalidDomainException;
use WaaSHost\Core\StoreProduct;
use WP_Error;

/**
 * The required "Website name" field at checkout, shown when the cart has a website plan (and isn't a renewal,
 * resubscribe or plan switch). The name is checked against existing stores and the API's availability check, and
 * saved on the order as WPCS_WEBSITE_NAME_META. When the API can't answer, the name is still accepted (and marked
 * unverified on the order) as long as no store in this storefront has it.
 *
 * Block checkout (WooCommerce's default): an additional checkout field (woocommerce_register_additional_checkout_field)
 * that is shown and required only when the cart's waas-host Store API data says needs_website_name. Classic
 * (shortcode) checkout: a billing field.
 */
class UserWcTenantsCheckout
{
    public const FIELD_ID = 'waas-host/website-name';
    public const STORE_API_NAMESPACE = 'waas-host';

    private ProvisioningApi $provisioningApi;
    private WooCommerceAdapter $commerce;
    /** @var array<string, true> names accepted in this request without the API's answer */
    private array $unverified = [];

    public function __construct(ProvisioningApi $provisioningApi, WooCommerceAdapter $commerce)
    {
        $this->provisioningApi = $provisioningApi;
        $this->commerce = $commerce;

        add_action('woocommerce_blocks_loaded', [$this, 'register_cart_data']);
        add_action('woocommerce_init', [$this, 'register_block_checkout_field']);
        add_action('woocommerce_set_additional_field_value', [$this, 'save_block_checkout_field'], 10, 4);

        add_filter('woocommerce_checkout_fields', [$this, 'render_waas_checkout_fields']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate_classic_checkout_field'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'save_classic_checkout_field'], 10, 2);
    }

    /**
     * True when the cart has a website plan bought new (not a renewal, resubscribe or plan switch).
     */
    public function cart_needs_website_name(): bool
    {
        $cart = function_exists('WC') ? WC()->cart : null;
        if (!$cart) {
            return false;
        }

        foreach ($cart->get_cart() as $item) {
            if (!(new StoreProduct($item['product_id']))->is_store_product()) {
                continue;
            }
            if (isset($item['subscription_renewal']) || isset($item['subscription_resubscribe']) || isset($item['subscription_switch'])) {
                return false;
            }
            return true;
        }

        return false;
    }

    public function register_cart_data(): void
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        woocommerce_store_api_register_endpoint_data([
            'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
            'namespace' => self::STORE_API_NAMESPACE,
            'data_callback' => fn () => ['needs_website_name' => $this->cart_needs_website_name()],
            'schema_callback' => fn () => [
                'needs_website_name' => [
                    'description' => 'Whether checkout asks for a website name.',
                    'type' => 'boolean',
                    'readonly' => true,
                ],
            ],
            'schema_type' => ARRAY_A,
        ]);
    }

    public function register_block_checkout_field(): void
    {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        $needs_website_name = [
            'properties' => [
                self::STORE_API_NAMESPACE => [
                    'properties' => ['needs_website_name' => ['const' => true]],
                    'required' => ['needs_website_name'],
                ],
            ],
            'required' => [self::STORE_API_NAMESPACE],
        ];

        woocommerce_register_additional_checkout_field([
            'id' => self::FIELD_ID,
            'label' => __('Website name', WAAS_HOST_TEXTDOMAIN),
            'location' => 'order',
            'type' => 'text',
            'required' => ['cart' => ['properties' => ['extensions' => $needs_website_name]]],
            'hidden' => ['cart' => ['properties' => ['extensions' => ['not' => $needs_website_name]]]],
            'sanitize_callback' => fn ($value) => sanitize_text_field((string) $value),
            'validate_callback' => function ($value) {
                $error = (string) $value === '' ? null : $this->check_website_name((string) $value);

                return $error === null ? true : new WP_Error('waas_website_name', $error);
            },
        ]);
    }

    /**
     * Copies the block checkout field to the order meta that store creation reads.
     *
     * @param \WC_Data $wc_object
     */
    public function save_block_checkout_field($key, $value, $group, $wc_object): void
    {
        $value = sanitize_text_field((string) $value);
        if ($key === self::FIELD_ID && $value !== '' && $wc_object instanceof \WC_Order) {
            $wc_object->update_meta_data(Tenant::WEBSITE_NAME_META, $value);
            $this->mark_unverified($wc_object, $value);
        }
    }

    public function render_waas_checkout_fields($fields)
    {
        if ($this->cart_needs_website_name()) {
            $fields['billing'][Tenant::WEBSITE_NAME_META] = [
                'label' => __('Website name', WAAS_HOST_TEXTDOMAIN),
                'required' => true,
                'priority' => 30,
                'class' => apply_filters('waas_website_name_checkout_field_classes', ['form-row-wide']),
            ];
        }

        return $fields;
    }

    /**
     * @param array $data
     * @param WP_Error $errors
     */
    public function validate_classic_checkout_field($data, $errors): void
    {
        $website_name = (string) ($data[Tenant::WEBSITE_NAME_META] ?? '');
        if ($website_name === '') {
            // Not asked for, or left empty (WooCommerce reports required fields itself).
            return;
        }

        $error = $this->check_website_name($website_name);
        if ($error !== null) {
            $errors->add('waas_website_name', $error);
        }
    }

    /**
     * @param \WC_Order $order
     * @param array $data
     */
    public function save_classic_checkout_field($order, $data): void
    {
        if (isset($data[Tenant::WEBSITE_NAME_META])) {
            $value = sanitize_text_field((string) $data[Tenant::WEBSITE_NAME_META]);
            $order->update_meta_data(Tenant::WEBSITE_NAME_META, $value);
            $this->mark_unverified($order, $value);
        }
    }

    /** @param \WC_Order $order */
    private function mark_unverified($order, string $website_name): void
    {
        if (isset($this->unverified[$website_name])) {
            $order->update_meta_data(Tenant::WEBSITE_NAME_UNVERIFIED_META, 'yes');
        }
    }

    /**
     * Checks that {name}.{root domain} is a valid, free domain.
     *
     * @return string|null the message for the customer, or null if the name can be used
     */
    public function check_website_name(string $website_name): ?string
    {
        $tenant_root_domain = (string) get_option('wpcs_host_settings_root_domain', '');
        if ($tenant_root_domain === '') {
            // Without a root domain the provisioning API picks a unique domain itself.
            return null;
        }

        $label = sanitize_title_with_dashes(remove_accents($website_name));
        if ($label === '' || strlen($label) > 63) {
            return __('Please use a website name of 1 to 63 letters, digits and dashes.', WAAS_HOST_TEXTDOMAIN);
        }
        $domain_name = $label . '.' . $tenant_root_domain;

        if ($this->commerce->is_domain_taken($domain_name)) {
            return __('Website name is already taken! Please use a different one.', WAAS_HOST_TEXTDOMAIN);
        }

        try {
            if ($this->provisioningApi->domain_available($domain_name)) {
                return null;
            }

            return __('Website name is already taken! Please use a different one.', WAAS_HOST_TEXTDOMAIN);
        } catch (InvalidDomainException $e) {
            return __('The resulting domain is not valid.', WAAS_HOST_TEXTDOMAIN);
        } catch (\Throwable $e) {
            // Fail open: take the order. The name passed the storefront's own duplicate check above; if the API
            // later finds it taken, the customer is asked for a new one (NameConflicts).
            error_log('WaaS Host: website name availability check failed, accepting "' . $website_name . '" unverified: ' . $e->getMessage());
            $this->unverified[$website_name] = true;
            return null;
        }
    }
}
