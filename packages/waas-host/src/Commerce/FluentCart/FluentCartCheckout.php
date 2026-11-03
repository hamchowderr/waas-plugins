<?php

namespace WaaSHost\Commerce\FluentCart;

use WaaSHost\Core\Exceptions\InvalidDomainException;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;

/**
 * The "Website name" field on FluentCart's checkout, shown when the cart holds a website plan, and an optional
 * "What does your business do, and who is it for?" box, which the new website's assistant starts from.
 * The name becomes the store's subdomain, so it is checked against this storefront's stores and with the
 * provisioning API. When the API can't answer, the name is accepted (and marked unverified on the order).
 */
class FluentCartCheckout
{
    public const FIELD = Tenant::WEBSITE_NAME_META;
    public const ABOUT_FIELD = Tenant::BUSINESS_ABOUT_META;
    /** The most characters of the business answer kept. */
    public const ABOUT_MAX = 500;

    private ProvisioningApi $provisioning_api;
    private FluentCartAdapter $adapter;
    private FluentCartStore $store;
    /** @var array<string, true> names accepted in this request without the API's answer */
    private array $unverified = [];

    public function __construct(ProvisioningApi $provisioning_api, FluentCartAdapter $adapter, ?FluentCartStore $store = null)
    {
        $this->provisioning_api = $provisioning_api;
        $this->adapter = $adapter;
        $this->store = $store ?? new FluentCartStore();

        add_filter('fluent_cart/checkout_renderer/billing_fields', [$this, 'add_field'], 10, 2);
        add_filter('fluent_cart/checkout/validate_data', [$this, 'validate'], 10, 2);
        add_action('fluent_cart/checkout/prepare_other_data', [$this, 'save'], 10, 1);
    }

    public function cart_has_plan($cart): bool
    {
        if (!$cart) {
            return false;
        }

        foreach ($this->store->get_cart_product_ids($cart) as $product_id) {
            if ($this->adapter->is_plan_product($product_id)) {
                return true;
            }
        }

        return false;
    }

    public function add_field($fields, $args)
    {
        if (!$this->cart_has_plan($args['cart'] ?? null)) {
            return $fields;
        }

        $label = __('Website name', 'waas-host');
        $fields[self::FIELD] = [
            'id' => self::FIELD,
            'name' => self::FIELD,
            'type' => 'text',
            'data-type' => 'text',
            'label' => '',
            'required' => 'yes',
            'value' => '',
            'placeholder' => $label,
            'aria-label' => $label,
        ];
        $about = __('What does your business do, and who is it for? (optional)', 'waas-host');
        $fields[self::ABOUT_FIELD] = [
            'id' => self::ABOUT_FIELD,
            'name' => self::ABOUT_FIELD,
            'type' => 'textarea',
            'data-type' => 'textarea',
            'label' => '',
            'required' => 'no',
            'value' => '',
            'placeholder' => $about,
            'aria-label' => $about,
        ];

        return $fields;
    }

    public function validate($errors, $args)
    {
        if (!$this->cart_has_plan($args['cart'] ?? null)) {
            return $errors;
        }

        $website_name = trim(sanitize_text_field((string) ($args['data'][self::FIELD] ?? '')));
        if ($website_name === '') {
            $errors[self::FIELD]['required'] = __('Please enter a name for your website.', 'waas-host');
            return $errors;
        }

        $root_domain = get_option('waas_root_domain', '');
        if ($root_domain === '') {
            return $errors; // the provisioning API picks a unique domain itself
        }

        $slug = sanitize_title_with_dashes(remove_accents($website_name));
        if ($slug === '') {
            $errors[self::FIELD]['invalid'] = __('Please use letters or numbers in the website name.', 'waas-host');
            return $errors;
        }

        $domain_name = $slug . '.' . $root_domain;
        if ($this->adapter->is_domain_taken($domain_name)) {
            $errors[self::FIELD]['taken'] = __('Website name is already taken! Please use a different one.', 'waas-host');
            return $errors;
        }

        try {
            if (!$this->provisioning_api->domain_available($domain_name)) {
                $errors[self::FIELD]['taken'] = __('Website name is already taken! Please use a different one.', 'waas-host');
            }
        } catch (InvalidDomainException $e) {
            $errors[self::FIELD]['invalid'] = __('The resulting domain is not valid.', 'waas-host');
        } catch (\Exception $e) {
            // Fail open: take the order (no store here has the name). If the API later finds it taken, the customer
            // is asked for a new one (NameConflicts).
            error_log('AnyPress Host: website name availability check failed, accepting "' . $website_name . '" unverified: ' . $e->getMessage());
            $this->unverified[$website_name] = true;
        }

        return $errors;
    }

    /** @param array $data cart, order, request_data, ... */
    public function save($data)
    {
        $order = $data['order'] ?? null;
        $website_name = trim(sanitize_text_field((string) ($data['request_data'][self::FIELD] ?? '')));
        if (!$order || $website_name === '' || !$this->cart_has_plan($data['cart'] ?? null)) {
            return;
        }

        $order->updateMeta(self::FIELD, $website_name);
        if (isset($this->unverified[$website_name])) {
            $order->updateMeta(Tenant::WEBSITE_NAME_UNVERIFIED_META, 'yes');
        }
        $about = self::about((string) ($data['request_data'][self::ABOUT_FIELD] ?? ''));
        if ($about !== '') {
            $order->updateMeta(self::ABOUT_FIELD, $about);
        }
    }

    /** The business answer as one tidy paragraph, at most ABOUT_MAX characters. */
    public static function about(string $answer): string
    {
        $answer = trim((string) preg_replace('/\s+/', ' ', sanitize_textarea_field($answer)));

        return function_exists('mb_substr') ? mb_substr($answer, 0, self::ABOUT_MAX) : substr($answer, 0, self::ABOUT_MAX);
    }
}
