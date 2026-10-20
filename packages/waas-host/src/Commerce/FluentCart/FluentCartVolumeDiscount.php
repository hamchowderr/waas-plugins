<?php

namespace WaaSHost\Commerce\FluentCart;

use WaaSHost\Features\VolumeDiscount;

/**
 * Applies the volume discount (VolumeDiscount) to the website plans in a FluentCart cart.
 *
 * The discount is a coupon that exists only in memory: fluent_cart/coupon/resolve_coupons turns a tier's code into a
 * coupon only while the cart's customer qualifies for that tier, so typing a code gets nothing a customer isn't owed,
 * and a code left in a cart that stops qualifying (a site cancelled, a plan removed, the setting turned off) drops out
 * the next time FluentCart re-checks the cart's coupons, which it does when placing the order. Being a coupon,
 * FluentCart carries it onto the order and, as a recurring coupon, into the subscription's renewal price.
 */
class FluentCartVolumeDiscount
{
    /** checkout_data key set when the customer removes the discount, so it isn't put straight back. */
    public const DECLINED = 'waas_volume_discount_declined';

    private FluentCartAdapter $adapter;
    private FluentCartStore $store;
    /** @var array<string, int> live websites per customer, for this request */
    private array $live_sites = [];
    /** @var array<int, bool> */
    private array $plan_products = [];
    private bool $syncing = false;

    public function __construct(FluentCartAdapter $adapter, FluentCartStore $store)
    {
        $this->adapter = $adapter;
        $this->store = $store;
    }

    public function register(): void
    {
        add_filter('fluent_cart/coupon/resolve_coupons', [$this, 'resolve_coupons'], 10, 3);
        add_filter('fluent_cart/coupon/will_skip_item', [$this, 'skip_item'], 10, 2);
        // Before FluentCart's shipping (9) and tax (10) listeners, so they total the discounted cart.
        add_action('fluent_cart/cart/cart_data_items_updated', [$this, 'sync_cart'], 5, 1);
        add_filter('fluent_cart/checkout/before_patch_checkout_data', [$this, 'sync_on_email_change'], 5, 2);
    }

    /**
     * Adds the tier's coupon for a code in $codes when the cart qualifies for it. Saved coupons that share a tier's
     * code are left out, so a coupon made in FluentCart's admin can't stand in for the discount.
     *
     * @param \FluentCart\Framework\Support\Collection $coupons the saved coupons FluentCart found for $codes
     * @param array $context ['cart' => Cart] while pricing a cart, ['order' => Order] while recording an order's coupons
     */
    public function resolve_coupons($coupons, $codes, $context = [])
    {
        $requested = [];
        foreach ((array) $codes as $code) {
            $tier = VolumeDiscount::tier_with_code((string) $code);
            if ($tier !== null) {
                $requested[$tier['code']] = $tier;
            }
        }
        if (!$requested) {
            return $coupons;
        }

        $coupons = $coupons->filter(fn ($coupon) => !isset($requested[(string) $coupon->code]))->values();
        if (!VolumeDiscount::is_enabled()) {
            return $coupons;
        }

        $cart = $context['cart'] ?? null;
        if ($cart === null) {
            if (!empty($context['order'])) {
                // Recording a placed order: the cart's discount was checked when the order was priced, this only labels it.
                foreach ($requested as $tier) {
                    $coupons->push($this->make_coupon($tier));
                }
            }

            // Without a cart or an order there is no customer to check, so no discount.
            return $coupons;
        }

        $tier = $this->cart_tier($cart);
        if ($tier !== null && isset($requested[$tier['code']])) {
            $coupons->push($this->make_coupon($tier));
        }

        return $coupons;
    }

    /** The discount is for website plans only, not other products in the same cart. */
    public function skip_item($skip, $args)
    {
        $coupon = $args['coupon'] ?? null;
        if ($skip || !$coupon || VolumeDiscount::tier_with_code((string) $coupon->code) === null) {
            return $skip;
        }

        return !$this->is_plan_product((int) ($args['item']['post_id'] ?? 0));
    }

    /**
     * Puts the tier's code on the cart, swaps it when the tier changes, and takes it off when the cart stops
     * qualifying. Runs whenever FluentCart changes the cart's items or coupons, loads the checkout and prices the
     * order being placed.
     *
     * @param array $data ['cart' => Cart, 'scope' => string, 'scope_data' => mixed]
     */
    public function sync_cart($data): void
    {
        $cart = $data['cart'] ?? null;
        if ($this->syncing || !$cart || empty($cart->exists)) {
            return;
        }

        $scope = (string) ($data['scope'] ?? '');
        // Only coupon changes carry codes; other scopes carry an item or a variation id.
        $scope_codes = in_array($scope, ['remove_coupon', 'apply_coupons'], true)
            ? array_map('strval', array_filter((array) ($data['scope_data'] ?? []), 'is_scalar'))
            : [];
        $current = $this->volume_codes((array) $cart->coupons);

        if ($scope === 'remove_coupon' && $this->volume_codes($scope_codes)) {
            $this->set_declined($cart, true);
            return;
        }
        if ($scope === 'apply_coupons' && $this->volume_codes($scope_codes) && $current) {
            $this->set_declined($cart, false); // typed back in
        }

        $tier = $this->cart_tier($cart);
        $wanted = $tier !== null ? [$tier['code']] : [];
        if ($wanted && $this->is_declined($cart) && $current !== $wanted) {
            $wanted = [];
        }
        if ($current === $wanted) {
            return;
        }

        $this->syncing = true;
        try {
            if ($current) {
                $cart->removeCoupon($current);
            }
            if ($wanted) {
                $cart->applyCoupon($wanted);
            }
        } finally {
            $this->syncing = false;
        }
    }

    /**
     * A guest's discount follows the email they type at checkout. FluentCart saves the email after this filter, and
     * tax and shipping are recalculated from $fill_data after it, so the cart is synced here with the new email and
     * the discounted items handed on.
     *
     * @param array $fill_data ['checkout_data' => array, 'cart_data' => array, 'hook_changes' => array]
     * @param array $args ['cart' => Cart, 'changes' => array, ...]
     */
    public function sync_on_email_change($fill_data, $args)
    {
        $cart = $args['cart'] ?? null;
        $email = (string) ($args['changes']['billing_email'] ?? '');
        if (!$cart || $email === '' || !VolumeDiscount::is_enabled() || $this->user_id($cart) > 0) {
            return $fill_data;
        }

        $before = (array) $cart->coupons;
        $cart->email = $email;
        $this->sync_cart(['cart' => $cart, 'scope' => 'email_changed']);
        if ((array) $cart->coupons === $before) {
            return $fill_data;
        }

        $fill_data['cart_data'] = $cart->cart_data;
        $fill_data['checkout_data']['__per_coupon_discounts'] = $cart->checkout_data['__per_coupon_discounts'] ?? [];
        // Any truthy hook change re-renders the cart summary on the checkout page.
        $fill_data['hook_changes']['waas_volume_discount'] = true;

        return $fill_data;
    }

    /**
     * The tier a cart qualifies for: the customer's live websites plus the website plans in the cart. Null when the
     * discount is off, the cart has no website plan or no customer FluentCart can identify, and for carts that pay for
     * an existing subscription (renewals, upgrades), which don't add a website.
     *
     * @return array{sites: int, percent: float, code: string}|null
     */
    public function cart_tier($cart): ?array
    {
        if (!VolumeDiscount::is_enabled()) {
            return null;
        }

        $checkout_data = (array) ($cart->checkout_data ?? []);
        if (($checkout_data['is_locked'] ?? '') === 'yes' || !empty($checkout_data['upgrade_data'])) {
            return null;
        }

        $new_sites = count(array_filter($this->store->get_cart_line_product_ids($cart), fn ($id) => $this->is_plan_product($id)));
        if ($new_sites === 0) {
            return null;
        }

        $live_sites = $this->live_sites($cart);
        if ($live_sites === null) {
            return null;
        }

        return VolumeDiscount::tier_for($live_sites + $new_sites);
    }

    /** Live websites of the cart's customer, null when FluentCart can't tell who the customer is. */
    private function live_sites($cart): ?int
    {
        $user_id = $this->user_id($cart);
        if ($user_id > 0) {
            $key = 'user:' . $user_id;
            if (!isset($this->live_sites[$key])) {
                $this->live_sites[$key] = $this->count_live_sites($this->adapter->get_customer_subscription_ids($user_id));
            }

            return $this->live_sites[$key];
        }

        $customer = $this->store->guess_cart_customer($cart);
        if ($customer === null) {
            return null;
        }

        $key = 'customer:' . $customer['id'];
        if (!isset($this->live_sites[$key])) {
            $ids = $customer['user_id'] > 0
                ? $this->adapter->get_customer_subscription_ids($customer['user_id'])
                : $this->store->find_customer_subscription_ids($customer['id']);
            $this->live_sites[$key] = $this->count_live_sites($ids);
        }

        return $this->live_sites[$key];
    }

    /** The signed-in customer, or the account the cart was started under. */
    private function user_id($cart): int
    {
        return is_user_logged_in() ? (int) get_current_user_id() : (int) ($cart->user_id ?? 0);
    }

    /** Live websites among $subscription_ids: website plans in an active status. Also used by FluentCartVolumeRepricing. */
    public function count_live_sites(array $subscription_ids): int
    {
        $product_ids = $this->store->get_active_subscription_product_ids($subscription_ids);

        return count(array_filter($product_ids, fn ($id) => $this->is_plan_product($id)));
    }

    public function is_plan_product(int $product_id): bool
    {
        if ($product_id <= 0) {
            return false;
        }
        if (!isset($this->plan_products[$product_id])) {
            $this->plan_products[$product_id] = $this->adapter->is_plan_product($product_id);
        }

        return $this->plan_products[$product_id];
    }

    /** @return string[] the tier codes among $codes */
    private function volume_codes(array $codes): array
    {
        return array_values(array_filter(array_map('strval', $codes), fn ($code) => VolumeDiscount::tier_with_code($code) !== null));
    }

    private function is_declined($cart): bool
    {
        return !empty(((array) ($cart->checkout_data ?? []))[self::DECLINED]);
    }

    private function set_declined($cart, bool $declined): void
    {
        $checkout_data = (array) ($cart->checkout_data ?? []);
        if (!empty($checkout_data[self::DECLINED]) === $declined) {
            return;
        }

        if ($declined) {
            $checkout_data[self::DECLINED] = 'yes';
        } else {
            unset($checkout_data[self::DECLINED]);
        }
        $cart->checkout_data = $checkout_data;
        $cart->save();
    }

    private function make_coupon(array $tier)
    {
        return $this->store->make_coupon([
            'code' => $tier['code'],
            /* translators: %s: percent off */
            'title' => sprintf(__('Volume discount (%s%%)', WAAS_HOST_TEXTDOMAIN), $tier['percent']),
            'status' => 'active',
            'type' => 'percentage',
            'amount' => $tier['percent'],
            // Combines with the shop's own stackable coupons.
            'stackable' => 'yes',
            'show_on_checkout' => 'no',
            'use_count' => 0,
            'conditions' => [
                // Renewals keep the discount, not only the first payment.
                'is_recurring' => 'yes',
                'max_uses' => 0,
                'max_per_customer' => 0,
                'min_purchase_amount' => 0,
                'max_purchase_amount' => 0,
                'max_discount_amount' => 0,
            ],
        ]);
    }
}
